<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Notifications\NotificationFeed;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Inertia\OnceProp;

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
 *
 * Coste fijo por petición:
 *  - Lo que leen varias props se memoriza por petición: la lista de empresas da también
 *    `activeCompany` (sin otra consulta) y la campana y el contador de alertas salen de una sola
 *    lectura de caché.
 *  - `notifications` es una prop «once» versionada por su contenido: el cliente la conserva entre
 *    visitas y el servidor solo la reenvía cuando cambia (aviso nuevo, leído) o cambia la empresa.
 *    `router.reload({ only: ['notifications'] })` la trae siempre.
 *  - `companies` y `activeCompany` también son «once»: se renuevan al cambiar de empresa activa o de
 *    membresías (huella de los ids permitidos) y caducan a los 10 minutos (cambios de nombre).
 */
class HandleInertiaRequests extends Middleware
{
    /** Segundos que se conserva el contador de alertas por usuario y empresa. */
    public const ALERTS_TTL = 60;

    /** Segundos que el cliente conserva `companies` y `activeCompany` sin volver a pedirlas. */
    public const COMPANIES_TTL = 600;

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
            'companies' => $this->companiesProp($request, $context),
            'activeCompany' => $this->activeCompanyProp($request, $context),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'notifications' => $this->notificationsProp($request, $context),
            'topbar' => [
                'alertsCount' => fn () => $this->alertsCount($request, $context),
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
    protected function companies(Request $request, CompanyContext $context): array
    {
        if ($request->user() === null) {
            return [];
        }

        return $this->memo($request, 'companies', fn () => Company::query()
            ->whereIn('id', $context->allowedCompanyIds())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $company) => ['id' => $company->id, 'name' => $company->name])
            ->all());
    }

    /**
     * Empresa activa sacada de la lista de empresas permitidas (la misma consulta). Solo si no está
     * en ella se consulta su nombre.
     *
     * @return array{id: int, name: string}|null
     */
    protected function activeCompany(Request $request, CompanyContext $context): ?array
    {
        $current = $context->currentCompanyId();

        if ($current === null) {
            return null;
        }

        foreach ($this->companies($request, $context) as $company) {
            if ($company['id'] === $current) {
                return $company;
            }
        }

        return $context->currentCompany();
    }

    /**
     * Campana de la cabecera: avisos del canal «campana» del usuario en la empresa activa
     * (`NotificationFeed`, cacheado unos segundos e invalidado al leer o al recibir un aviso).
     *   unread: int
     *   items:  [{ id, title, body, href, read_at, created_at, severity }]
     *
     * @return array{unread: int, items: array<int, array<string, mixed>>}
     */
    protected function notifications(Request $request, CompanyContext $context): array
    {
        $user = $request->user();

        if ($user === null) {
            return ['unread' => 0, 'items' => []];
        }

        return $this->memo($request, 'notifications', function () use ($request, $user, $context) {
            $cached = $this->cachedShared($request, $context)[NotificationFeed::cacheKey($user->id, $context->currentCompanyId())] ?? null;

            return is_array($cached) ? $cached : app(NotificationFeed::class)->forUser($user->id, $context->currentCompanyId());
        });
    }

    /**
     * La campana como prop «once» con versión: la clave (usuario, empresa y huella del contenido) se
     * calcula al resolver la respuesta, no aquí, porque `share()` corre antes de que se resuelva la
     * empresa activa y antes del controlador (que puede marcar avisos como leídos). Si el cliente ya
     * tiene esa versión, la prop no viaja. En una recarga parcial que no la pide no se lee nada.
     */
    protected function notificationsProp(Request $request, CompanyContext $context): OnceProp
    {
        $feed = fn () => $this->notifications($request, $context);
        $key = function () use ($request, $context, $feed): ?string {
            $user = $request->user();

            return $user === null ? null
                : "notifications.{$user->id}.".($context->currentCompanyId() ?? 0).'.'.substr(md5((string) json_encode($feed())), 0, 12);
        };

        return $this->versionedOnce($feed, $key);
    }

    /**
     * Empresas permitidas como prop «once»: casi nunca cambian. La clave lleva el usuario y la huella
     * de los ids permitidos (cambia al crear o quitar membresías y al crear empresas, para la raíz);
     * caduca a los 10 minutos para recoger cambios de nombre. Una carga completa (F5) siempre la trae.
     */
    protected function companiesProp(Request $request, CompanyContext $context): OnceProp
    {
        return $this->versionedOnce(fn () => $this->companies($request, $context), fn () => $request->user() === null ? null
            : "companies.{$request->user()->id}.".$this->allowedFingerprint($context), self::COMPANIES_TTL);
    }

    /** Empresa activa: igual que `companies`, con la empresa activa en la clave (cambiar de empresa la renueva). */
    protected function activeCompanyProp(Request $request, CompanyContext $context): OnceProp
    {
        return $this->versionedOnce(fn () => $request->user() ? $this->activeCompany($request, $context) : null, fn () => $request->user() === null ? null
            : "activeCompany.{$request->user()->id}.".($context->currentCompanyId() ?? 0).'.'.$this->allowedFingerprint($context), self::COMPANIES_TTL);
    }

    protected function allowedFingerprint(CompanyContext $context): string
    {
        $ids = $context->allowedCompanyIds();
        sort($ids);

        return substr(md5(implode(',', $ids)), 0, 10);
    }

    /**
     * Prop «once» cuya clave se calcula al resolver la respuesta (no en `share()`, que corre antes de
     * resolver la empresa activa y antes del controlador). Si el cliente ya tiene esa clave, no viaja.
     */
    protected function versionedOnce(callable $value, \Closure $key, ?int $ttl = null): OnceProp
    {
        $prop = new class($value, $key) extends OnceProp
        {
            public function __construct(callable $value, private \Closure $keyResolver)
            {
                parent::__construct($value);
            }

            public function getKey(): ?string
            {
                return $this->key ??= ($this->keyResolver)();
            }
        };

        return $ttl === null ? $prop : $prop->until($ttl);
    }

    protected function alertsCount(Request $request, CompanyContext $context): int
    {
        $user = $request->user();

        if ($user === null) {
            return 0;
        }

        $key = self::alertsCacheKey($user->id, $context->currentCompanyId());
        $cached = $this->cachedShared($request, $context)[$key] ?? null;

        if ($cached !== null) {
            return (int) $cached;
        }

        // `AlertService` guarda las alertas por empresa; esta clave solo evita releerlas en cada petición.
        return (int) Cache::remember($key, self::ALERTS_TTL, fn () => app(AlertService::class)->count($context->currentCompanyId()));
    }

    /**
     * Campana y contador de alertas en una sola lectura de caché (`many`: con el almacén `database`
     * es una consulta en vez de dos). Lo que falte se calcula y se guarda como antes.
     *
     * @return array<string, mixed>
     */
    protected function cachedShared(Request $request, CompanyContext $context): array
    {
        return $this->memo($request, 'cache', function () use ($request, $context) {
            $user = $request->user();
            $companyId = $context->currentCompanyId();

            if ($user === null || $companyId === null) {
                return [];
            }

            return Cache::many([NotificationFeed::cacheKey($user->id, $companyId), self::alertsCacheKey($user->id, $companyId)]);
        });
    }


    /** Memoria por petición (en los atributos de la petición, no en el middleware). */
    protected function memo(Request $request, string $key, callable $resolve): mixed
    {
        $memo = $request->attributes->get('inertia.shared', []);

        if (! array_key_exists($key, $memo)) {
            $memo[$key] = $resolve();
            $request->attributes->set('inertia.shared', $memo);
        }

        return $memo[$key];
    }
}
