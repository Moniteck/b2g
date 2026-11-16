<?php

namespace App\Services\Auth;

use App\Mail\AccessApprovedMail;
use App\Mail\AccessRequestedMail;
use App\Mail\AccessRequestVerifyMail;
use App\Models\AccessRequest;
use App\Models\Company;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Services\Admin\MembershipService;
use App\Services\Notifications\NotificationFeed;
use App\Services\System\AuditLogger;
use App\Support\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Solicitudes de acceso: envío desde la pantalla de acceso, confirmación del correo, revisión en
 * Usuarios y el estado que ve quien intenta entrar con una cuenta aún no aprobada.
 *
 * Seguridad (M4): quien envía la solicitud recibe un enlace firmado (24 horas) en el correo que
 * escribió; hasta confirmarlo, la solicitud no llega a los revisores. Una solicitud sin confirmar
 * caduca y otra con el mismo correo la reemplaza, así que nadie bloquea a la persona real. Si un
 * administrador aprobara una sin confirmar, la cuenta no entra hasta que el correo se confirme.
 *
 *   app(AccessRequestService::class)->submit($datos, $request->ip());
 *   app(AccessRequestService::class)->verify($solicitud, $hash);
 *   app(AccessRequestService::class)->approve($solicitud, $admin, $empresa, 'operator');
 *
 * Las empresas viajan al navegador con un identificador opaco (`companyToken`), nunca con su id.
 */
class AccessRequestService
{
    /** Horas de validez del enlace de confirmación del correo. */
    public const VERIFY_HOURS = 24;

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
     * Registra la solicitud y envía el enlace de confirmación al correo. Si el correo ya tiene
     * cuenta o una solicitud pendiente ya confirmada no crea nada: quien envía ve la misma
     * respuesta, así el formulario no revela qué correos existen. Una solicitud pendiente SIN
     * confirmar con el mismo correo se reemplaza (pudo enviarla alguien que no es su dueño).
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

            if (AccessRequest::query()->pending()->verified()->where('email', $email)->lockForUpdate()->exists()) {
                return null;
            }

            // Sin confirmar: la anterior con este correo se reemplaza y las caducadas se limpian.
            AccessRequest::query()->pending()->whereNull('email_verified_at')
                ->where(fn ($query) => $query->where('email', $email)->orWhere('created_at', '<', now()->subHours(self::VERIFY_HOURS)))
                ->delete();

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

        $this->sendVerification($created);

        return $created;
    }

    /** Enlace firmado de confirmación del correo, válido VERIFY_HOURS horas. */
    public function verificationUrl(AccessRequest $request): string
    {
        return URL::temporarySignedRoute('access-request.verify', now()->addHours(self::VERIFY_HOURS), [
            'accessRequest' => $request->getKey(),
            'hash' => $request->emailHash(),
        ]);
    }

    /**
     * Confirma el correo de la solicitud (el enlace firmado ya se validó en el controlador) y,
     * si sigue pendiente, la envía a los revisores. Idempotente.
     *
     * @return string «verified» (recién confirmado), «already» (ya lo estaba) o «invalid»
     */
    public function verify(AccessRequest $request, string $hash): string
    {
        [$status, $locked] = DB::transaction(function () use ($request, $hash) {
            $locked = AccessRequest::query()->whereKey($request->getKey())->lockForUpdate()->first();

            if ($locked === null || ! hash_equals($locked->emailHash(), $hash) || $locked->status === AccessRequest::REJECTED) {
                return ['invalid', null];
            }

            if ($locked->hasVerifiedEmail()) {
                return ['already', $locked];
            }

            $locked->forceFill(['email_verified_at' => now()])->save();
            $this->audit->record('acceso.correo_verificado', $locked, ['email' => $locked->email], companyId: $locked->company_id);

            return ['verified', $locked];
        });

        if ($status === 'verified' && $locked->isPending()) {
            $this->notifyReviewers($locked, $locked->company_id ? Company::query()->find($locked->company_id) : null);
        }

        return $status;
    }

    /**
     * ¿La cuenta nació de una solicitud aprobada cuyo correo nadie confirmó? Entonces no entra
     * hasta que se confirme (quien eligió la contraseña pudo no ser el dueño del correo).
     */
    public function awaitsEmailVerification(User $user): bool
    {
        return AccessRequest::query()
            ->where('user_id', $user->getKey())
            ->where('status', AccessRequest::APPROVED)
            ->whereNull('email_verified_at')
            ->exists();
    }

    private function sendVerification(AccessRequest $request): void
    {
        $url = $this->verificationUrl($request);
        $this->mail(fn () => Mail::to($request->email)->queue(new AccessRequestVerifyMail($request->name, $url, self::VERIFY_HOURS)));
    }

    /**
     * Estado de una solicitud para quien intenta entrar con su correo y la contraseña que eligió.
     * Solo responde si la contraseña coincide: a terceros no les revela nada.
     */
    public function loginStatus(string $email, string $password): ?string
    {
        $request = AccessRequest::query()->where('email', Str::lower(trim($email)))
            ->whereIn('status', [AccessRequest::PENDING, AccessRequest::REJECTED])
            ->latest('id')->first(['id', 'password', 'status', 'email_verified_at']);

        // Sin solicitud se compara igual contra un cifrado de relleno: el tiempo de respuesta no
        // delata si el correo tiene una solicitud.
        $matches = Hash::check($password, $request?->password ?? self::dummyHash());

        if ($request === null || ! $matches) {
            return null;
        }

        return $request->isPending() && ! $request->hasVerifiedEmail() ? 'unverified' : $request->status;
    }

    /** Cifrado de relleno: comparar contra él cuesta lo mismo que contra una contraseña real. */
    public static function dummyHash(): string
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
        return AccessRequest::query()->pending()->verified()
            ->where(fn ($query) => $query->where('company_id', $company->id)
                ->when($actor->isRoot(), fn ($inner) => $inner->orWhereNull('company_id')))
            ->with('company:id,name')
            ->latest('id')
            ->limit(100)
            ->get(['id', 'name', 'email', 'phone', 'position', 'company_id', 'company_name', 'email_verified_at', 'created_at'])
            ->map(fn (AccessRequest $request) => [
                'id' => $request->id,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'position' => $request->position,
                'company' => $request->company?->name,
                'company_name' => $request->company_name,
                'targeted' => $request->company_id !== null,
                'email_verified' => $request->hasVerifiedEmail(),
                'email_verified_at' => $request->email_verified_at?->toDateTimeString(),
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

        // Sin correo confirmado la cuenta no entra: se reenvía el enlace en lugar del aviso de aprobación.
        $request->refresh();
        $request->hasVerifiedEmail()
            ? $this->mail(fn () => Mail::to($user->email)->queue(new AccessApprovedMail($user->name, $company->name, Permissions::ROLE_LABELS[$role] ?? $role)))
            : $this->sendVerification($request);

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
