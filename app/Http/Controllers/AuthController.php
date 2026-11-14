<?php

namespace App\Http\Controllers;

use App\Services\Auth\AccessRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Response;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    private const ACCESS_MESSAGES = [
        'pending' => 'Tu solicitud de acceso está en revisión. Te avisaremos por correo cuando un administrador la apruebe.',
        'rejected' => 'Tu solicitud de acceso no fue aprobada. Si crees que es un error, escribe al administrador de tu empresa.',
    ];

    public function create(): Response
    {
        return AccessRequestController::screen('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = $this->throttleKey($request, $credentials['email']);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withErrors(['email' => "Demasiados intentos de acceso. Intente nuevamente en {$seconds} segundos."])
                ->onlyInput('email');
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            // Quien aún no tiene cuenta pero escribió la contraseña de su solicitud ve en qué va;
            // sin esa contraseña la respuesta es la genérica y no revela si el correo existe.
            $status = app(AccessRequestService::class)->loginStatus($credentials['email'], $credentials['password']);

            if ($status !== null) {
                return back()
                    ->withErrors(['email' => self::ACCESS_MESSAGES[$status], 'access' => $status])
                    ->onlyInput('email');
            }

            return back()
                ->withErrors(['email' => 'Las credenciales no son válidas.'])
                ->onlyInput('email');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
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
