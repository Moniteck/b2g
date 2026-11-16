<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;

/**
 * Auditoría de la autenticación web (hallazgo B6): inicios de sesión exitosos y fallidos,
 * bloqueos por demasiados intentos y cierres de sesión. Nunca se guarda la contraseña.
 *
 * Registrado en AppServiceProvider con `Event::subscribe(AuditAuthenticationEvents::class)`.
 */
class AuditAuthenticationEvents
{
    public function __construct(private AuditLogger $audit) {}

    public function login(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        $this->audit->record('sesion.iniciada', $event->user, ['recordarme' => $event->remember], $event->user, $event->user->defaultCompanyId());
    }

    public function failed(Failed $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $user = $event->user instanceof User ? $event->user : null;

        $this->audit->record('sesion.fallida', $user, [
            'correo' => Str::limit(Str::lower((string) ($event->credentials['email'] ?? '')), 160, ''),
            'cuenta_existe' => $user !== null,
        ], $user, $user?->defaultCompanyId());
    }

    public function lockout(Lockout $event): void
    {
        $this->audit->record('sesion.bloqueada', null, [
            'correo' => Str::limit(Str::lower((string) $event->request->input('email', '')), 160, ''),
        ], null, null, $event->request);
    }

    public function logout(Logout $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        $this->audit->record('sesion.cerrada', $event->user, [], $event->user, $event->user->defaultCompanyId());
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            Logout::class => 'logout',
        ];
    }
}
