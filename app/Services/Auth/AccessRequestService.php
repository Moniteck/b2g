<?php

namespace App\Services\Auth;

use App\Mail\AccessApprovedMail;
use App\Mail\AccessRequestedMail;
use App\Models\AccessRequest;
use App\Models\Company;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Services\Admin\MembershipService;
use App\Services\AuditLogger;
use App\Services\Notifications\NotificationFeed;
use App\Support\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Solicitudes de acceso: envío desde la pantalla de acceso, revisión en Usuarios y el estado que
 * ve quien intenta entrar con una cuenta aún no aprobada.
 *
 *   app(AccessRequestService::class)->submit($datos, $request->ip());
 *   app(AccessRequestService::class)->approve($solicitud, $admin, $empresa, 'operator');
 *
 * Las empresas viajan al navegador con un identificador opaco (`companyToken`), nunca con su id.
 */
class AccessRequestService
{
    public function __construct(
        private AuditLogger $audit,
        private MembershipService $memberships,
        private NotificationFeed $feed,
    ) {}

    /**
     * Empresas activas para el formulario público, por nombre.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function companyOptions(): array
    {
        return Company::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Company $company) => ['value' => self::companyToken($company->id), 'label' => $company->name])
            ->all();
    }

    public static function companyToken(int $companyId): string
    {
        return substr(hash_hmac('sha256', "access-request-company:{$companyId}", (string) config('app.key')), 0, 20);
    }

    public function companyFromToken(?string $token): ?Company
    {
        if ($token === null || $token === '') {
            return null;
        }

        return Company::query()->where('is_active', true)->get(['id', 'name'])
            ->first(fn (Company $company) => hash_equals(self::companyToken($company->id), $token));
    }

    /**
     * Registra la solicitud. Si el correo ya tiene cuenta o una solicitud pendiente no crea nada:
     * quien envía ve la misma respuesta, así el formulario no revela qué correos existen.
     *
     * @param  array{name: string, email: string, phone?: string|null, position: string, company?: string|null, company_name?: string|null, password: string}  $data
     */
    public function submit(array $data, ?string $ip): ?AccessRequest
    {
        $email = Str::lower(trim($data['email']));
        $company = $this->companyFromToken($data['company'] ?? null);

        $created = DB::transaction(function () use ($data, $email, $company, $ip) {
            if (User::query()->where('email', $email)->lockForUpdate()->exists()) {
                return null;
            }

            if (AccessRequest::query()->pending()->where('email', $email)->lockForUpdate()->exists()) {
                return null;
            }

            return AccessRequest::create([
                'name' => trim($data['name']),
                'email' => $email,
                'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
                'position' => trim($data['position']),
                'company_id' => $company?->id,
                'company_name' => $company === null && filled($data['company_name'] ?? null) ? trim($data['company_name']) : null,
                'password' => $data['password'],
                'status' => AccessRequest::PENDING,
                'ip_address' => $ip,
            ]);
        });

        if ($created === null) {
            return null;
        }

        $this->audit->record('acceso.solicitado', $created, [
            'email' => $created->email,
            'empresa' => $company?->name ?? $created->company_name,
        ], companyId: $company?->id);

        $this->notifyReviewers($created, $company);

        return $created;
    }

    /**
     * Estado de una solicitud para quien intenta entrar con su correo y la contraseña que eligió.
     * Solo responde si la contraseña coincide: a terceros no les revela nada.
     */
    public function loginStatus(string $email, string $password): ?string
    {
        $request = AccessRequest::query()->where('email', Str::lower(trim($email)))
            ->whereIn('status', [AccessRequest::PENDING, AccessRequest::REJECTED])
            ->latest('id')->first(['id', 'password', 'status']);

        // Sin solicitud se compara igual contra un cifrado de relleno: el tiempo de respuesta no
        // delata si el correo tiene una solicitud.
        $matches = Hash::check($password, $request?->password ?? self::dummyHash());

        if ($request === null || ! $matches) {
            return null;
        }

        return $request->status;
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('relleno-sin-uso');
    }

    /**
     * Solicitudes pendientes que revisa el actor en la empresa activa: las dirigidas a ella y,
     * si es raíz, también las que no eligieron empresa.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingFor(User $actor, Company $company): Collection
    {
        return AccessRequest::query()->pending()
            ->where(fn ($query) => $query->where('company_id', $company->id)
                ->when($actor->isRoot(), fn ($inner) => $inner->orWhereNull('company_id')))
            ->with('company:id,name')
            ->latest('id')
            ->limit(100)
            ->get(['id', 'name', 'email', 'phone', 'position', 'company_id', 'company_name', 'created_at'])
            ->map(fn (AccessRequest $request) => [
                'id' => $request->id,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'position' => $request->position,
                'company' => $request->company?->name,
                'company_name' => $request->company_name,
                'targeted' => $request->company_id !== null,
                'created_at' => $request->created_at?->toDateTimeString(),
            ]);
    }

    public function visibleTo(User $actor, AccessRequest $request, int $activeCompanyId): bool
    {
        if ($request->company_id === null) {
            return $actor->isRoot();
        }

        return $request->company_id === $activeCompanyId && $actor->isCompanyAdmin($activeCompanyId);
    }

    /**
     * Crea la cuenta con la contraseña elegida, la agrega a la empresa con el rol y avisa por correo.
     */
    public function approve(AccessRequest $request, User $actor, Company $company, string $role): User
    {
        $user = DB::transaction(function () use ($request, $actor, $company, $role) {
            $locked = AccessRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['request' => 'Esta solicitud ya fue atendida.']);
            }

            if (User::query()->where('email', $locked->email)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['request' => 'Ya existe una cuenta con ese correo. Agréguela con «Agregar usuario».']);
            }

            // La contraseña ya está cifrada: el cast `hashed` de User no la vuelve a cifrar.
            $user = User::create(['name' => $locked->name, 'email' => $locked->email, 'password' => $locked->password, 'role' => $role, 'phone' => $locked->phone]);
            $this->memberships->invite($company, $locked->name, $locked->email, $role);

            $locked->forceFill([
                'status' => AccessRequest::APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'user_id' => $user->id,
                'approved_company_id' => $company->id,
                'approved_role' => $role,
            ])->save();

            $this->audit->record('acceso.aprobado', $locked, ['email' => $locked->email, 'empresa' => $company->name, 'role' => $role], $actor, $company->id);

            return $user;
        });

        $this->mail(fn () => Mail::to($user->email)->queue(new AccessApprovedMail($user->name, $company->name, Permissions::ROLE_LABELS[$role] ?? $role)));

        return $user;
    }

    public function reject(AccessRequest $request, User $actor, ?string $reason, ?int $companyId): void
    {
        DB::transaction(function () use ($request, $actor, $reason, $companyId) {
            $locked = AccessRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['request' => 'Esta solicitud ya fue atendida.']);
            }

            $locked->forceFill([
                'status' => AccessRequest::REJECTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => filled($reason) ? trim($reason) : null,
            ])->save();

            $this->audit->record('acceso.rechazado', $locked, ['email' => $locked->email, 'motivo' => $locked->rejection_reason], $actor, $locked->company_id ?? $companyId);
        });
    }

    /**
     * Aviso en la campana (y por correo) a quienes pueden aprobar: los administradores de la empresa
     * elegida o, si no eligió ninguna (o no tiene administradores), la raíz en su empresa predeterminada.
     */
    private function notifyReviewers(AccessRequest $request, ?Company $company): void
    {
        $recipients = collect();

        if ($company !== null) {
            $recipients = User::query()
                ->join('company_user', 'company_user.user_id', '=', 'users.id')
                ->where('company_user.company_id', $company->id)
                ->where('company_user.role', Permissions::ROLE_ADMIN)
                ->get(['users.id', 'users.name', 'users.email'])
                ->map(fn (User $user) => [$user, $company->id]);
        }

        if ($recipients->isEmpty()) {
            $recipients = User::query()->where('is_root', true)->get(['id', 'name', 'email', 'is_root'])
                ->map(fn (User $user) => [$user, $user->defaultCompanyId()])
                ->filter(fn (array $pair) => $pair[1] !== null);
        }

        $where = $company?->name ?? ($request->company_name ? "dice ser de «{$request->company_name}»" : 'sin empresa indicada');
        $title = 'Nueva solicitud de acceso';
        $body = Str::limit("{$request->name} · {$request->position} · {$where}", 480);

        foreach ($recipients as [$user, $companyId]) {
            $key = "access-request:{$request->id}:{$user->id}";

            if (ReminderDelivery::query()->withoutGlobalScopes()->where('idempotency_key', $key)->exists()) {
                continue;
            }

            // `title`, `body` y `href` no son asignables en masa en el modelo compartido: forceFill.
            (new ReminderDelivery)->forceFill([
                'idempotency_key' => $key,
                'event_id' => null,
                'company_id' => $companyId,
                'user_id' => $user->id,
                'channel' => 'campana',
                'offset_key' => 'now',
                'title' => $title,
                'body' => $body,
                'href' => '/configuracion/usuarios#solicitudes',
                'recipient' => $user->email,
                'status' => 'Enviado',
                'scheduled_for' => now(),
                'sent_at' => now(),
                'attempts' => 1,
            ])->save();
            $this->feed->forget($user->id, $companyId);

            $this->mail(fn () => Mail::to($user->email)->queue(new AccessRequestedMail($request->name, $request->email, $request->position, $where)));
        }
    }

    /** El correo nunca impide la operación: si falla, queda en el registro. */
    private function mail(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $exception) {
            Log::warning('No se pudo encolar el correo de la solicitud de acceso.', ['error' => $exception->getMessage()]);
        }
    }
}
