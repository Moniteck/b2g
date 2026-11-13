<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\StoreUserRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\Admin\MembershipService;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usuarios y roles de la EMPRESA ACTIVA. El rol vive en `company_user.role`;
 * la condición de raíz solo se concede por consola (`php artisan crm:root`).
 * La contraseña temporal se entrega una sola vez, como dato de sesión flash.
 */
class AdminUserController extends Controller
{
    public function __construct(private CompanyContext $context, private MembershipService $memberships) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-users');

        $company = $this->company();
        $actor = $request->user();
        $search = $request->string('search')->trim()->value();
        $role = in_array($request->input('role'), Permissions::ROLES, true) ? $request->input('role') : 'all';

        $members = $company->users()
            ->select(['users.id', 'users.name', 'users.email', 'users.is_root', 'users.created_at'])
            ->when($search, fn (Builder $query, string $term) => $query->where(fn (Builder $builder) => $builder
                ->where('users.name', 'like', "%{$term}%")->orWhere('users.email', 'like', "%{$term}%")))
            ->when($role !== 'all', fn ($query) => $query->wherePivot('role', $role))
            ->when($actor->isRoot(), fn ($query) => $query->with('companies:companies.id,companies.name'))
            ->orderBy('users.name')
            ->paginate(25)
            ->withQueryString();

        $adminCount = $company->users()->wherePivot('role', Permissions::ROLE_ADMIN)->count();

        $members->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->pivot->role,
            'is_root' => (bool) $user->is_root,
            'is_self' => $user->is($actor),
            'last_admin' => $user->pivot->role === Permissions::ROLE_ADMIN && $adminCount <= 1,
            'can_reset_password' => $this->memberships->canResetPassword($actor, $user),
            'companies' => $actor->isRoot()
                ? $user->companies->map(fn (Company $item) => ['id' => $item->id, 'name' => $item->name, 'role' => $item->pivot->role])->values()
                : null,
        ]);

        $counts = $company->users()->toBase()->selectRaw('company_user.role AS role, COUNT(*) AS total')
            ->groupBy('company_user.role')->pluck('total', 'role');

        return Inertia::render('Admin/Users', [
            'users' => $members,
            'filters' => ['search' => $search, 'role' => $role],
            'counts' => ['all' => (int) $counts->sum(), ...collect(Permissions::ROLES)->mapWithKeys(fn ($key) => [$key => (int) ($counts[$key] ?? 0)])->all()],
            'roles' => Permissions::ROLE_LABELS,
            'company' => ['id' => $company->id, 'name' => $company->name],
            'credentials' => $request->session()->get('temporary_credentials'),
            'isRoot' => $actor->isRoot(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('manage-users');

        $company = $this->company();
        $result = $this->memberships->invite($company, $request->validated('name'), $request->validated('email'), $request->validated('role'));

        if (! $result['created']) {
            return back()->with('success', "{$result['user']->name} ya tenía cuenta: se agregó a {$company->name} con su contraseña actual.");
        }

        return back()
            ->with('temporary_credentials', ['name' => $result['user']->name, 'email' => $result['user']->email, 'password' => $result['password']])
            ->with('success', "Usuario {$result['user']->name} creado.");
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-users');

        $role = $request->validate(
            ['role' => ['required', Rule::in(Permissions::ROLES)]],
            ['role.required' => 'Elija un rol.', 'role.in' => 'El rol elegido no es válido.'],
        )['role'];

        $this->memberships->setRole($this->company(), $user, $role);

        return back()->with('success', "Rol de {$user->name}: ".Permissions::ROLE_LABELS[$role].'.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-users');

        if ($user->is($request->user())) {
            return back()->with('error', 'No puede quitarse a sí mismo de la empresa. Pídaselo a otro administrador.');
        }

        $company = $this->company();
        $this->memberships->remove($company, $user);

        return back()->with('success', "{$user->name} ya no pertenece a {$company->name}.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-users');

        $company = $this->company();
        abort_unless($company->users()->whereKey($user->id)->exists(), 404);

        if (! $this->memberships->canResetPassword($request->user(), $user)) {
            return back()->with('error', 'Solo la raíz puede restablecer esta contraseña: la cuenta también pertenece a otras empresas.');
        }

        $password = $this->memberships->resetPassword($user, $company->id);

        return back()
            ->with('temporary_credentials', ['name' => $user->name, 'email' => $user->email, 'password' => $password, 'reset' => true])
            ->with('success', "Contraseña de {$user->name} restablecida.");
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->context->currentCompanyId());
    }
}
