<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Crea (o actualiza) un usuario con permisos completos para el CRM.
 *
 * Uso típico:
 *   php artisan admin:create-user root@crm-b2g.test --name="Administrador B2G"
 *   php artisan admin:create-user root@crm-b2g.test --password="..." --force
 */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:create-user
                            {email : Correo electronico del administrador}
                            {--name= : Nombre visible del administrador}
                            {--password= : Contrasena; si se omite se solicita de forma segura}
                            {--role=admin : Rol asignado (admin concede todos los permisos)}
                            {--force : Actualiza el usuario si el correo ya existe}';

    protected $description = 'Crea un usuario administrador con todos los permisos del CRM.';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $role = (string) $this->option('role');

        if (! in_array($role, ['admin', 'operator'], true)) {
            $this->components->error('El rol debe ser admin u operator.');

            return self::FAILURE;
        }

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']]);
        if ($validator->fails()) {
            $this->components->error('El correo electronico no tiene un formato valido.');

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();
        if ($existing && ! $this->option('force')) {
            $this->components->error("El correo {$email} ya existe. Use --force para actualizarlo.");
            $this->components->twoColumnDetail('Usuario', $existing->name);
            $this->components->twoColumnDetail('Rol', $existing->role);

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: '');
        if ($password === '') {
            $password = (string) $this->secret('Contrasena');
            $confirmation = (string) $this->secret('Confirme la contrasena');
            if ($password !== $confirmation) {
                $this->components->error('Las contrasenas no coinciden.');

                return self::FAILURE;
            }
        }

        if (! $this->isStrongEnough($password)) {
            $this->components->error('La contrasena debe tener al menos 12 caracteres e incluir letras y numeros.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: ($existing?->name ?: 'Administrador B2G'));

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'role' => $role,
            'email_verified_at' => $existing?->email_verified_at ?? now(),
        ])->save();

        $this->components->info($existing ? 'Administrador actualizado.' : 'Administrador creado.');
        $this->components->twoColumnDetail('Correo', $user->email);
        $this->components->twoColumnDetail('Nombre', $user->name);
        $this->components->twoColumnDetail('Rol', $user->role);
        $this->components->twoColumnDetail('ID', (string) $user->id);

        if ($role === 'admin') {
            $this->components->info('El rol admin concede todos los permisos, incluidos ajustes, KPIs y eliminaciones.');
        }

        return self::SUCCESS;
    }

    private function isStrongEnough(string $password): bool
    {
        return strlen($password) >= 12
            && preg_match('/[A-Za-z]/', $password) === 1
            && preg_match('/\d/', $password) === 1;
    }
}
