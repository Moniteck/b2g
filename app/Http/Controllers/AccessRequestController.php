<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\StoreAccessRequestRequest;
use App\Models\AccessRequest;
use App\Models\Company;
use App\Services\Auth\AccessRequestService;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Crear cuenta» de la pantalla de acceso: la persona envía una solicitud que queda pendiente
 * hasta que un administrador la aprueba (eligiendo empresa y rol) o la rechaza desde Usuarios.
 */
class AccessRequestController extends Controller
{
    /** Solicitudes por correo cada hora (además del límite por IP de la ruta). */
    private const MAX_PER_EMAIL = 3;

    public function __construct(private AccessRequestService $requests) {}

    /**
     * Pantalla de acceso con la cara indicada; la usa también `AuthController::create`.
     */
    public static function screen(string $mode): Response
    {
        return Inertia::render('Auth/Login', [
            'mode' => $mode,
            'companies' => fn () => app(AccessRequestService::class)->companyOptions(),
        ]);
    }

    public function create(): Response
    {
        return self::screen('register');
    }

    public function store(StoreAccessRequestRequest $request): RedirectResponse
    {
        // Robot: misma respuesta que una solicitud válida, sin guardar nada.
        if (filled($request->input('website'))) {
            return back();
        }

        $key = 'access-request:'.Str::transliterate(Str::lower(trim($request->validated('email'))));

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_EMAIL)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return back()->withErrors(['email' => "Ya recibimos varias solicitudes con este correo. Intenta de nuevo en {$minutes} minutos."]);
        }

        RateLimiter::hit($key, 3600);
        $this->requests->submit($request->validated(), $request->ip());

        return back();
    }

    public function approve(Request $request, AccessRequest $accessRequest, CompanyContext $context): RedirectResponse
    {
        $this->authorize('manage-users');
        $actor = $request->user();
        abort_unless($this->requests->visibleTo($actor, $accessRequest, (int) $context->currentCompanyId()), 404);

        $data = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'role' => ['required', Rule::in(Permissions::ROLES)],
        ], [
            'company_id.required' => 'Elija la empresa.',
            'company_id.integer' => 'La empresa elegida no es válida.',
            'company_id.exists' => 'La empresa elegida no es válida.',
            'role.required' => 'Elija un rol.',
            'role.in' => 'El rol elegido no es válido.',
        ]);

        // Un administrador solo aprueba hacia empresas que administra; la raíz, hacia cualquiera.
        if (! $actor->isRoot() && ! $actor->isCompanyAdmin((int) $data['company_id'])) {
            return back()->withErrors(['company_id' => 'Solo puede aprobar hacia empresas que administra.']);
        }

        $company = Company::query()->findOrFail($data['company_id']);
        $user = $this->requests->approve($accessRequest, $actor, $company, $data['role']);

        return back()->with('success', "Solicitud aprobada: {$user->name} ya puede entrar a {$company->name}.");
    }

    public function reject(Request $request, AccessRequest $accessRequest, CompanyContext $context): RedirectResponse
    {
        $this->authorize('manage-users');
        abort_unless($this->requests->visibleTo($request->user(), $accessRequest, (int) $context->currentCompanyId()), 404);

        $reason = $request->validate(
            ['reason' => ['nullable', 'string', 'max:500']],
            ['reason.max' => 'El motivo no puede pasar de 500 caracteres.'],
        )['reason'] ?? null;

        $this->requests->reject($accessRequest, $request->user(), $reason, $context->currentCompanyId());

        return back()->with('success', "Solicitud de {$accessRequest->name} rechazada.");
    }
}
