<?php

namespace App\Mcp\Support;

use App\Models\McpAuthorization;
use App\Models\McpConnection;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Limpieza de lo que deja el registro dinámico de clientes (cualquiera puede registrar uno):
 *
 * - Clientes OAuth públicos de registro dinámico (sin dueño, sin secreto, con
 *   `authorization_code`) de más de N horas que no tienen ninguna conexión activa ni tokens
 *   vigentes. Se borran con sus tokens, refrescos y códigos.
 * - Códigos de confirmación MCP vencidos o usados de hace más de un día.
 *
 * Nunca toca los clientes de acceso personal ni los creados a mano con dueño.
 */
class ClientJanitor
{
    /**
     * @return array{clients: int, confirmations: int}
     */
    public function prune(?int $olderThanHours = null): array
    {
        $hours = $olderThanHours ?? (int) config('mcp.crm.orphan_client_hours', 24);
        $clients = 0;

        Passport::client()->newQuery()
            ->whereNull('owner_id')
            ->whereNull('secret')
            ->where('grant_types', 'like', '%authorization_code%')
            ->where('created_at', '<', now()->subHours($hours))
            ->orderBy('created_at')
            ->chunk(200, function ($chunk) use (&$clients) {
                foreach ($chunk as $client) {
                    if ($this->inUse((string) $client->getKey())) {
                        continue;
                    }

                    DB::transaction(function () use ($client) {
                        $id = (string) $client->getKey();
                        $tokenIds = Passport::token()->newQuery()->where('client_id', $id)->pluck('id');

                        if ($tokenIds->isNotEmpty()) {
                            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->delete();
                        }

                        Passport::token()->newQuery()->where('client_id', $id)->delete();
                        Passport::authCode()->newQuery()->where('client_id', $id)->delete();
                        $client->delete();
                    });

                    $clients++;
                }
            });

        $confirmations = McpAuthorization::query()
            ->whereNotNull('code_hash')
            ->where(fn ($query) => $query->where('expires_at', '<', now()->subDay())->orWhere('consumed_at', '<', now()->subDay()))
            ->delete();

        return ['clients' => $clients, 'confirmations' => $confirmations];
    }

    protected function inUse(string $clientId): bool
    {
        if (McpConnection::query()->active()->where('client_id', $clientId)->exists()) {
            return true;
        }

        return Passport::token()->newQuery()
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->exists();
    }
}
