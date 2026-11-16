<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\AccessRequestService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inicio y cierre de sesión.
 *
 * Seguridad:
 * - Misma cantidad de comparaciones de contraseña exista o no la cuenta (B1): con un correo
 *   inexistente se compara contra un cifrado de relleno, así el tiempo no delata qué correos existen.
 * - Los errores vuelven SIEMPRE a /login, nunca a la cabecera Referer (B2).
 * - Límite por correo e IP aquí, y en la ruta `throttle:login` (por IP y 20 por hora por correo, B4).
 * - Al entrar se va siempre al Dashboard (el sistema empieza ahí).
 * - Una cuenta nacida de una solicitud cuyo correo nadie confirmó no entra (M4).
 * - Al salir se borra el historial cifrado de Inertia (el botón Atrás no muestra datos).
 */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    private const ACCESS_MESSAGES = [
        'pending' => 'Tu solicitud de acceso está en revisión. Te avisaremos por correo cuando un administrador la apruebe.',
        'rejected' => 'Tu solicitud de acceso no fue aprobada. Si crees que es un error, escribe al administrador de tu empresa.',
        'unverified' => 'Confirma tu correo para continuar: te enviamos un enlace al solicitar acceso. Si venció, envía la solicitud de nuevo.',
    ];

    public function __construct(private AccessRequestService $requests) {}

    public function create(): Response
    {
        return AccessRequestController::screen('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->only('email', 'password', 'remember'), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->failed($validator->errors()->toArray());
        }

        $credentials = $validator->safe()->only(['email', 'password']);
        $key = $this->throttleKey($request, $credentials['email']);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($key);

            return $this->failed(['email' => "Demasiados intentos de acceso. Intente nuevamente en {$seconds} segundos."]);
        }

        // Sin cuenta, Auth::attempt no compara nada: se compara contra el relleno para igualar el costo.
        if (Auth::getProvider()->retrieveByCredentials(['email' => $credentials['email']]) === null) {
            Hash::check($credentials['password'], AccessRequestService::dummyHash());
        }

        $unverified = false;
        $authenticated = Auth::attemptWhen(
            $credentials,
            function (User $user) use (&$unverified) {
                return ! ($unverified = $this->requests->awaitsEmailVerification($user));
            },
            $request->boolean('remember'),
        );

        if (! $authenticated) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            if ($unverified) {
                return $this->failed(['email' => self::ACCESS_MESSAGES['unverified'], 'access' => 'unverified']);
            }

            // Quien aún no tiene cuenta pero escribió la contraseña de su solicitud ve en qué va;
            // sin esa contraseña la respuesta es la genérica y no revela si el correo existe.
            $status = $this->requests->loginStatus($credentials['email'], $credentials['password']);

            if ($status !== null) {
                return $this->failed(['email' => self::ACCESS_MESSAGES[$status], 'access' => $status]);
            }

            return $this->failed(['email' => 'Las credenciales no son válidas.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return redirect()->route('login');
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    private function failed(array $errors): RedirectResponse
    {
        return redirect()->route('login')->withErrors($errors)->onlyInput('email');
    }

    /**
     * Limita los intentos por correo e IP para impedir ataques de fuerza bruta
     * contra cuentas administrativas.
     */
    private function throttleKey(Request $request, string $email): string
    {
        return Str::transliterate(Str::lower($email).'|'.$request->ip());
    }
}
