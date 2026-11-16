<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\AccessRequest;
use App\Models\User;
use App\Services\Auth\SessionTerminator;
use App\Services\System\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Response;

/**
 * Recuperar la contraseña por correo (flujo estándar de Laravel con el broker `users`):
 *
 * 1. «¿Olvidaste tu contraseña?» → POST /olvide-contrasena: si el correo tiene cuenta se envía un
 *    enlace con token (vence en 60 minutos). La respuesta es la misma exista o no el correo.
 * 2. GET /restablecer-contrasena/{token}?email=… → escena del acceso con la tarjeta «Nueva contraseña».
 * 3. POST /restablecer-contrasena → cambia la contraseña, cierra todas las sesiones y revoca los
 *    tokens de los clientes MCP. Como el enlace demuestra que la persona controla el correo, una
 *    cuenta que esperaba confirmar el correo de su solicitud queda confirmada.
 *
 * Límite `throttle:password-reset` (por IP y por correo) y auditoría de solicitud y restablecimiento.
 */
class PasswordResetController extends Controller
{
    private const SENT_MESSAGE = 'Si el correo tiene una cuenta, te enviamos un enlace para crear una contraseña nueva. Revisa tu bandeja de entrada (y la de no deseados); el enlace vence en 60 minutos.';

    public function __construct(private AuditLogger $audit, private SessionTerminator $sessions) {}

    public function send(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->only('email'), ['email' => ['required', 'email', 'max:255']], [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'El correo no tiene un formato válido.',
        ]);

        if ($validator->fails()) {
            return redirect()->route('login')->withErrors($validator)->onlyInput('email');
        }

        $email = Str::lower(trim((string) $validator->validated()['email']));

        $status = Password::broker()->sendResetLink(['email' => $email], function (User $user, string $token) {
            $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

            try {
                Mail::to($user->email)->queue(new PasswordResetMail($user->name, $url, (int) config('auth.passwords.users.expire', 60)));
            } catch (\Throwable $exception) {
                Log::warning('No se pudo encolar el correo para restablecer la contraseña.', ['error' => $exception->getMessage()]);
            }
        });

        // El token se guarda cifrado (bcrypt): sin cuenta se cifra uno de relleno, así el tiempo de
        // respuesta no delata qué correos existen.
        if ($status === Password::INVALID_USER) {
            Hash::make(Str::random(40));
        }

        $this->audit->record('contrasena.recuperacion_solicitada', null, [
            'correo' => $email,
            'resultado' => match ($status) {
                Password::RESET_LINK_SENT => 'enlace_enviado',
                Password::RESET_THROTTLED => 'espera',
                default => 'sin_cuenta',
            },
        ], null);

        // Misma respuesta exista o no la cuenta (y aunque se haya pedido hace muy poco).
        return redirect()->route('login')->with('status', self::SENT_MESSAGE);
    }

    public function show(Request $request, string $token): Response
    {
        return AccessRequestController::screen('reset', [
            'reset' => ['token' => $token, 'email' => (string) $request->query('email', '')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->only('token', 'email', 'password', 'password_confirmation'), [
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], [
            'token.required' => 'El enlace no es válido. Pide uno nuevo.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'El correo no tiene un formato válido.',
            'password.required' => 'Escribe la contraseña nueva.',
            'password.confirmed' => 'Las dos contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'password.letters' => 'La contraseña debe incluir al menos una letra.',
            'password.numbers' => 'La contraseña debe incluir al menos un número.',
            'password.uncompromised' => 'Esta contraseña apareció en una filtración de datos. Elige otra.',
        ]);

        $back = fn () => redirect()->route('password.reset', ['token' => (string) $request->input('token'), 'email' => (string) $request->input('email')]);

        if ($validator->fails()) {
            return $back()->withErrors($validator)->onlyInput('email');
        }

        $data = $validator->validated();
        $data['email'] = Str::lower(trim($data['email']));

        $status = Password::broker()->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

            $closed = $this->sessions->endWebSessions($user);
            $revoked = $this->sessions->revokeApiTokens($user);

            // El enlace llegó a este correo: queda demostrado que la persona lo controla.
            AccessRequest::query()->where('user_id', $user->id)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);

            $this->audit->record('contrasena.restablecida_por_correo', $user, ['sesiones_cerradas' => $closed, 'tokens_revocados' => $revoked], $user, $user->defaultCompanyId());
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return $back()->withErrors(['email' => $status === Password::RESET_THROTTLED
                ? 'Espera un momento antes de volver a intentarlo.'
                : 'El enlace venció o no corresponde a este correo. Pide uno nuevo desde «¿Olvidaste tu contraseña?».'])->onlyInput('email');
        }

        return redirect()->route('login')->with('status', 'Listo: tu contraseña cambió. Ingresa con la nueva; las demás sesiones se cerraron.');
    }
}
