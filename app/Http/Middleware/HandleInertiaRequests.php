<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

/**
 * Props compartidas con todas las páginas. Es el contrato con el frontend:
 *
 *   auth.user      { id, name, email, initials, is_root, company_role } | null
 *   auth.can       { create, update, delete, manage_users, use_ai, ... } (booleanos, empresa activa)
 *   companies      [{ id, name }]   empresas a las que el usuario puede cambiarse
 *   activeCompany  { id, name } | null
 *   flash          { success, error, warning }
 *   notifications  { unread, items }
 *   topbar         { alertsCount }
 *
 * Todo lo que consulta la base es perezoso: solo se evalúa si la respuesta lo necesita.
 */
class HandleInertiaRequests extends Middleware
{
    /** Segundos que se conserva el contador de alertas por usuario y empresa. */
    public const ALERTS_TTL = 60;

    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $context = app(CompanyContext::class);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $this->user($request->user(), $context),
                'can' => fn () => $this->abilities($request->user()),
            ],
            'companies' => fn () => $this->companies($request->user(), $context),
            'activeCompany' => fn () => $request->user() ? $context->currentCompany() : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'notifications' => fn () => $this->notifications($request->user(), $context),
            'topbar' => [
                'alertsCount' => fn () => $this->alertsCount($request->user(), $context),
            ],
        ];
    }

    /**
     * Clave de caché del contador de alertas. Quien cambie datos que afecten a
     * las alertas puede invalidarla con `Cache::forget(...)`.
     */
    public static function alertsCacheKey(int $userId, ?int $companyId): string
    {
        return "topbar.alerts.{$userId}.".($companyId ?? 0);
    }

    protected function user(?User $user, CompanyContext $context): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'initials' => $user->initials(),
            'is_root' => $user->isRoot(),
            'company_role' => $user->companyRole($context->currentCompanyId()),
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function abilities(?User $user): array
    {
        $abilities = [];

        foreach (Permissions::SHARED as $permission) {
            $abilities[$permission] = $user?->hasPermission($permission) ?? false;
        }

        return $abilities;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    protected function companies(?User $user, CompanyContext $context): array
    {
        if ($user === null) {
            return [];
        }

        return Company::query()
            ->whereIn('id', $context->allowedCompanyIds())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $company) => ['id' => $company->id, 'name' => $company->name])
            ->all();
    }

    /**
     * Campana de la cabecera.
     *
     * PUNTO DE EXTENSIÓN (Fase 6, recordatorios): sustituir el cuerpo por la
     * consulta de `ReminderDelivery` del canal «bandeja» del usuario en la
     * empresa activa, manteniendo la forma:
     *   unread: int
     *   items:  [{ id, title, body, url, created_at, read_at }]
     *
     * @return array{unread: int, items: array<int, array<string, mixed>>}
     */
    protected function notifications(?User $user, CompanyContext $context): array
    {
        return ['unread' => 0, 'items' => []];
    }

    protected function alertsCount(?User $user, CompanyContext $context): int
    {
        if ($user === null) {
            return 0;
        }

        return (int) Cache::remember(
            self::alertsCacheKey($user->id, $context->currentCompanyId()),
            self::ALERTS_TTL,
            fn () => count(app(AlertService::class)->active()),
        );
    }
}
