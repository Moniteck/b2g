<?php

namespace App\Http\Controllers;

use App\Services\Auth\SessionTerminator;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mi cuenta: nombre, teléfono y contraseña del usuario que inició sesión.
 */
class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Admin/Account', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_root' => $user->isRoot(),
            ],
            'memberships' => $user->companies()->orderBy('name')->get(['companies.id', 'companies.name'])
                ->map(fn ($company) => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'role' => Permissions::ROLE_LABELS[$company->pivot->role] ?? $company->pivot->role,
                ]),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ], ['name.required' => 'Escriba su nombre.']);

        $user = $request->user();
        $user->fill($data);
        $changes = $user->getDirty();
        $user->save();

        if ($changes !== []) {
            $audit->record('cuenta.actualizada', $user, $changes);
        }

        return back()->with('success', 'Datos guardados.');
    }

    public function password(Request $request, AuditLogger $audit, SessionTerminator $sessions): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ], [
            'current_password.required' => 'Escriba su contraseña actual.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.required' => 'Escriba la contraseña nueva.',
            'password.confirmed' => 'Las dos contraseñas nuevas no coinciden.',
            'password.min' => 'La contraseña nueva debe tener al menos 10 caracteres.',
            'password.letters' => 'La contraseña nueva debe tener letras.',
            'password.numbers' => 'La contraseña nueva debe tener números.',
            'password.uncompromised' => 'Esta contraseña apareció en una filtración de datos. Elige otra.',
            'password.different' => 'La contraseña nueva debe ser distinta de la actual.',
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $request->input('password'), 'remember_token' => Str::random(60)])->save();

        // Las demás sesiones (otro equipo, una sesión robada) quedan cerradas: el cifrado nuevo
        // invalida su `password_hash` (AuthenticateSession) y, con sesiones en base de datos, se borran.
        Auth::logoutOtherDevices($request->input('password'));
        $request->session()->regenerate();
        $closed = $sessions->endWebSessions($user, $request->session()->getId());
        $audit->record('cuenta.contrasena_cambiada', $user, ['sesiones_cerradas' => $closed]);

        return back()->with('success', 'Contraseña cambiada.');
    }
}
