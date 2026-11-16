<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\User;
use App\Services\Auth\SessionTerminator;
use App\Services\System\AuditLogger;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Membresías de usuarios en empresas: alta, cambio de rol y baja, con la regla
 * de que una empresa nunca se queda sin administrador. Lo usan Usuarios
 * (empresa activa) y Empresas (raíz).
 *
 *   app(MembershipService::class)->setRole($company, $user, 'operator');
 */
class MembershipService
{
    public function __construct(private AuditLogger $audit, private SessionTerminator $sessions) {}

    /**
     * Crea el usuario (con contraseña temporal) o reutiliza el existente con ese
     * correo, y lo agrega a la empresa con el rol indicado.
     *
     * @return array{user: User, password: string|null, created: bool}
     */
    public function invite(Company $company, string $name, string $email, string $role): array
    {
        return DB::transaction(function () use ($company, $name, $email, $role) {
            $email = Str::lower(trim($email));
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $password = null;
            $created = false;

            if ($user !== null && $company->users()->whereKey($user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'Ese usuario ya pertenece a la empresa.']);
            }

            if ($user === null) {
                $password = self::temporaryPassword();
                $user = User::create(['name' => trim($name), 'email' => $email, 'password' => $password, 'role' => $role]);
                $created = true;
            }

            $company->users()->attach($user->id, [
                'role' => $role,
                'is_default' => ! DB::table('company_user')->where('user_id', $user->id)->exists(),
            ]);
            $user->forgetMemberships();

            $this->audit->record($created ? 'usuario.creado' : 'usuario.agregado', $user, ['email' => $email, 'role' => $role], companyId: $company->id);

            return ['user' => $user, 'password' => $password, 'created' => $created];
        });
    }

    public function setRole(Company $company, User $user, string $role): void
    {
        DB::transaction(function () use ($company, $user, $role) {
            $current = $this->lockedRole($company, $user);

            if ($current === null) {
                abort(404);
            }

            if ($current === $role) {
                return;
            }

            if ($current === Permissions::ROLE_ADMIN && $this->adminCount($company) <= 1) {
                throw ValidationException::withMessages(['role' => 'No se puede quitar el rol de administrador al único administrador de la empresa.']);
            }

            $company->users()->updateExistingPivot($user->id, ['role' => $role]);
            $user->forgetMemberships();

            $this->audit->record('usuario.rol_cambiado', $user, ['role' => ['de' => $current, 'a' => $role]], companyId: $company->id);
        });
    }

    public function remove(Company $company, User $user): void
    {
        DB::transaction(function () use ($company, $user) {
            $current = $this->lockedRole($company, $user);

            if ($current === null) {
                abort(404);
            }

            if ($current === Permissions::ROLE_ADMIN && $this->adminCount($company) <= 1) {
                throw ValidationException::withMessages(['user' => 'No se puede quitar al único administrador de la empresa.']);
            }

            $company->users()->detach($user->id);
            $user->forgetMemberships();

            // Si era su empresa predeterminada, pasa a otra.
            if (! DB::table('company_user')->where('user_id', $user->id)->where('is_default', true)->exists()) {
                DB::table('company_user')->where('user_id', $user->id)->orderBy('company_id')->limit(1)->update(['is_default' => true]);
            }

            $this->audit->record('usuario.quitado', $user, ['role' => $current], companyId: $company->id);
        });
    }

    /**
     * Restablece la contraseña y devuelve la temporal (se muestra una sola vez).
     */
    public function resetPassword(User $user, ?int $companyId): string
    {
        $password = self::temporaryPassword();
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        // Quien tuviera la cuenta abierta (o sus tokens de clientes MCP) queda fuera.
        $closed = $this->sessions->endWebSessions($user);
        $revoked = $this->sessions->revokeApiTokens($user);

        $this->audit->record('usuario.contrasena_restablecida', $user, ['sesiones_cerradas' => $closed, 'tokens_revocados' => $revoked], companyId: $companyId);

        return $password;
    }

    /**
     * Un administrador de empresa solo restablece contraseñas de usuarios que no son
     * raíz y cuyas empresas administra todas: así no toma cuentas de otra empresa.
     */
    public function canResetPassword(User $actor, User $target): bool
    {
        if ($actor->isRoot()) {
            return true;
        }

        if ($target->isRoot() || $actor->is($target)) {
            return false;
        }

        $companies = array_keys($target->membershipRoles());

        return $companies !== [] && collect($companies)->every(fn (int $companyId) => $actor->isCompanyAdmin($companyId));
    }

    /**
     * Contraseña temporal legible: 14 caracteres con letras, números y símbolo.
     */
    public static function temporaryPassword(): string
    {
        return Str::password(14, symbols: false).'#'.random_int(10, 99);
    }

    private function lockedRole(Company $company, User $user): ?string
    {
        $role = DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->lockForUpdate()->value('role');

        return $role !== null ? (string) $role : null;
    }

    private function adminCount(Company $company): int
    {
        return DB::table('company_user')->where('company_id', $company->id)->where('role', Permissions::ROLE_ADMIN)->lockForUpdate()->count();
    }
}
