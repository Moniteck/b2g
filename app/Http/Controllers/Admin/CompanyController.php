<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyRequest;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Quote;
use App\Models\User;
use App\Services\Admin\MembershipService;
use App\Services\System\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Empresas del grupo. Crear, editar, eliminar y administrar miembros de cualquier
 * empresa es de la raíz; un administrador de empresa solo ve sus empresas (lectura)
 * y gestiona sus miembros desde Usuarios. El token de Blessing nunca viaja al navegador.
 */
class CompanyController extends Controller
{
    public function __construct(private CompanyContext $context) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Company::class);

        $isRoot = $request->user()->isRoot();
        $search = $request->string('search')->trim()->value();

        $companies = Company::query()
            ->select(['id', 'name', 'code', 'tax_id', 'contact_email', 'is_active', 'blessing_base_url', 'logo_path', 'updated_at'])
            ->whereIn('id', $this->context->allowedCompanyIds())
            ->withCount('users')
            ->when($search, fn (Builder $query, string $term) => $query->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")->orWhere('tax_id', 'like', "%{$term}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'tax_id' => $company->tax_id,
                'contact_email' => $company->contact_email,
                'is_active' => $company->is_active,
                'blessing' => filled($company->blessing_base_url),
                'users_count' => $company->users_count,
                'logo_url' => $company->logoUrl(),
                'my_role' => $request->user()->companyRole($company->id),
            ]);

        return Inertia::render('Admin/Companies/Index', [
            // `companyRecords` y no `companies`: esa es la prop compartida del selector de empresa (B-12).
            'companyRecords' => $companies,
            'filters' => ['search' => $search],
            'can' => ['manage' => $isRoot],
        ]);
    }

    public function show(Request $request, Company $company): Response
    {
        $this->authorize('view', $company);

        $isRoot = $request->user()->isRoot();

        $members = $company->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email', 'users.is_root'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'is_root' => (bool) $user->is_root,
            ]);

        return Inertia::render('Admin/Companies/Show', [
            'company' => $this->payload($company),
            'members' => $members,
            'stats' => [
                'quotes' => Quote::query()->withoutGlobalScopes()->where('company_id', $company->id)->count(),
                'contracts' => Contract::query()->withoutGlobalScopes()->where('company_id', $company->id)->count(),
            ],
            'roles' => Permissions::ROLE_LABELS,
            'can' => [
                'manage' => $isRoot,
                'members' => $request->user()->can('manageMembers', $company),
                'delete' => $isRoot,
            ],
        ]);
    }

    public function store(CompanyRequest $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', Company::class);

        $company = Company::create($this->attributes($request) + ['is_active' => $request->boolean('is_active', true)]);
        $audit->record('empresa.creada', $company, ['name' => $company->name], companyId: $company->id);

        return redirect()->route('companies.show', $company)->with('success', "Empresa {$company->name} creada. Agregue a sus miembros.");
    }

    public function update(CompanyRequest $request, Company $company, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manage-companies');

        $company->fill($this->attributes($request, $company));
        $changes = $company->getDirty();
        $company->save();

        if (array_key_exists('blessing_token', $changes)) {
            $changes['blessing_token'] = $company->hasBlessingToken() ? '[cambiado]' : '[eliminado]';
        }

        if ($changes !== []) {
            $audit->record('empresa.actualizada', $company, $changes, companyId: $company->id);
        }

        return back()->with('success', 'Empresa actualizada.');
    }

    public function destroy(Company $company, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $company);

        $inUse = Quote::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists()
            || Contract::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists();

        if ($inUse) {
            return back()->with('error', 'La empresa tiene cotizaciones o contratos. Desactívela en lugar de eliminarla.');
        }

        if ($this->context->currentCompanyId() === $company->id) {
            return back()->with('error', 'No puede eliminar la empresa activa. Cambie de empresa primero.');
        }

        $company->deleteLogo();
        $company->users()->detach();
        $company->delete();
        $audit->record('empresa.eliminada', $company, ['name' => $company->name], companyId: null);

        return redirect()->route('companies.index')->with('success', "Empresa {$company->name} eliminada.");
    }

    public function uploadLogo(Request $request, Company $company, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manage-companies');

        $request->validate(
            ['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']],
            ['logo.required' => 'Elija una imagen.', 'logo.image' => 'El archivo no es una imagen.', 'logo.mimes' => 'Use PNG, JPG o WebP.', 'logo.max' => 'El logo no puede pasar de 2 MB.'],
        );

        $company->storeLogo($request->file('logo'));
        $audit->record('empresa.logo', $company, [], companyId: $company->id);

        return back()->with('success', 'Logo actualizado.');
    }

    /**
     * Sirve el logo desde el disco privado a quien tiene acceso a la empresa.
     */
    public function logo(Company $company): StreamedResponse
    {
        $this->authorize('view', $company);

        abort_unless($company->logo_path && Storage::disk('local')->exists($company->logo_path), 404);

        return Storage::disk('local')->response($company->logo_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Agrega o actualiza la membresía de un usuario existente.
     */
    public function member(Request $request, Company $company, MembershipService $memberships, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manageMembers', $company);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::in(Permissions::ROLES)],
        ], ['user_id.required' => 'Elija un usuario.', 'role.in' => 'El rol elegido no es válido.']);

        $user = User::query()->findOrFail($data['user_id']);

        if ($company->users()->whereKey($user->id)->exists()) {
            $memberships->setRole($company, $user, $data['role']);
        } else {
            $company->users()->attach($user->id, [
                'role' => $data['role'],
                'is_default' => ! $user->companies()->exists(),
            ]);
            $audit->record('usuario.agregado', $user, ['role' => $data['role']], companyId: $company->id);
        }

        return back()->with('success', "{$user->name} ahora es miembro de {$company->name}.");
    }

    public function updateMember(Request $request, Company $company, User $user, MembershipService $memberships): RedirectResponse
    {
        $this->authorize('manageMembers', $company);

        $role = $request->validate(['role' => ['required', Rule::in(Permissions::ROLES)]], ['role.in' => 'El rol elegido no es válido.'])['role'];
        $memberships->setRole($company, $user, $role);

        return back()->with('success', 'Rol actualizado.');
    }

    public function removeMember(Company $company, User $user, MembershipService $memberships): RedirectResponse
    {
        $this->authorize('manageMembers', $company);

        $memberships->remove($company, $user);

        return back()->with('success', "{$user->name} ya no pertenece a {$company->name}.");
    }

    /**
     * GET /empresas/{company}/usuarios-disponibles?q=  →  usuarios que aún no son miembros.
     */
    public function availableUsers(Request $request, Company $company): JsonResponse
    {
        $this->authorize('manage-companies');

        $term = $request->string('q')->trim()->value();

        $users = User::query()
            ->select(['id', 'name', 'email'])
            ->whereDoesntHave('companies', fn (Builder $query) => $query->whereKey($company->id))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'description' => $user->email]);

        return response()->json(['data' => $users]);
    }

    /**
     * Datos de la empresa para la pantalla: el token solo como «configurado» y enmascarado.
     *
     * @return array<string, mixed>
     */
    private function payload(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'code' => $company->code,
            'tax_id' => $company->tax_id,
            'representative' => $company->representative,
            'contact_name' => $company->contact_name,
            'contact_email' => $company->contact_email,
            'contact_phone' => $company->contact_phone,
            'address' => $company->settings['address'] ?? null,
            'is_active' => $company->is_active,
            'logo_url' => $company->logoUrl(),
            'blessing_base_url' => $company->blessing_base_url,
            'blessing_price_list' => $company->blessing_price_list,
            'blessing_token_set' => $company->hasBlessingToken(),
            'blessing_token_masked' => $company->maskedBlessingToken(),
            'tax_rate' => $company->tax_rate !== null ? (float) $company->tax_rate : 15.0,
            'prices_include_tax' => (bool) $company->prices_include_tax,
            'created_at' => $company->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(CompanyRequest $request, ?Company $company = null): array
    {
        $data = $request->safe()->except(['blessing_token', 'clear_blessing_token', 'address', 'is_active', 'prices_include_tax', 'tax_rate']);

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        if ($request->has('prices_include_tax')) {
            $data['prices_include_tax'] = $request->boolean('prices_include_tax');
        }

        if ($request->filled('tax_rate')) {
            $data['tax_rate'] = (float) $request->input('tax_rate');
        }

        // Token vacío = conservar el guardado. Solo se borra a pedido.
        if ($request->boolean('clear_blessing_token')) {
            $data['blessing_token'] = null;
        } elseif (filled($request->input('blessing_token'))) {
            $data['blessing_token'] = trim((string) $request->input('blessing_token'));
        }

        if ($request->has('address')) {
            $settings = $company?->settings ?? [];
            $settings['address'] = $request->input('address');
            $data['settings'] = $settings;
        }

        return $data;
    }
}
