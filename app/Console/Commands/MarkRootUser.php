<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Marca (o desmarca) a un usuario como raíz del grupo.
 *
 * La raíz opera sobre todas las empresas y es la única que las administra. No
 * existe pantalla para concederla: solo este comando.
 *
 *   php artisan crm:root persona@empresa.hn
 *   php artisan crm:root persona@empresa.hn --revoke
 */
class MarkRootUser extends Command
{
    protected $signature = 'crm:root
                            {email : Correo del usuario}
                            {--revoke : Quita la condición de raíz en lugar de concederla}';

    protected $description = 'Marca a un usuario como raíz del grupo (acceso a todas las empresas).';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No existe ningún usuario con el correo {$email}.");

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');

        if ($revoke && $user->is_root && User::query()->where('is_root', true)->count() === 1) {
            $this->components->error('No se puede quitar la raíz al único usuario raíz del sistema.');

            return self::FAILURE;
        }

        $user->forceFill(['is_root' => ! $revoke])->save();

        $this->components->info($revoke
            ? "{$user->name} ya no es raíz del grupo."
            : "{$user->name} ahora es raíz del grupo.");

        return self::SUCCESS;
    }
}
