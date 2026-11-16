<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra las sesiones abiertas de un usuario (hallazgo M1).
 *
 * - Sesiones web: con el driver `database` se borran sus filas (salvo la sesión actual, si se
 *   indica). Con cualquier driver, además, `AuthenticateSession` (grupo `web`) expulsa las
 *   sesiones cuyo cifrado de contraseña guardado ya no coincide.
 * - Tokens OAuth (clientes MCP): se revocan los de acceso y sus tokens de refresco.
 *
 *   app(SessionTerminator::class)->endWebSessions($user, except: $request->session()->getId());
 *   app(SessionTerminator::class)->revokeApiTokens($user);
 */
class SessionTerminator
{
    public function endWebSessions(User $user, ?string $except = null): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $table = (string) config('session.table', 'sessions');
        $connection = DB::connection(config('session.connection'));

        if (! Schema::connection($connection->getName())->hasTable($table)) {
            return 0;
        }

        return $connection->table($table)
            ->where('user_id', $user->getKey())
            ->when($except !== null, fn ($query) => $query->where('id', '!=', $except))
            ->delete();
    }

    public function revokeApiTokens(User $user): int
    {
        $tokenIds = $user->tokens()->where('revoked', false)->pluck('id');

        if ($tokenIds->isEmpty()) {
            return 0;
        }

        $user->tokens()->whereIn('id', $tokenIds)->update(['revoked' => true]);

        if (Schema::hasTable('oauth_refresh_tokens')) {
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        }

        return $tokenIds->count();
    }
}
