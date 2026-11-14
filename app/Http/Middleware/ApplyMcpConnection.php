<?php

namespace App\Http\Middleware;

use App\Mcp\Support\McpSession;
use App\Models\McpConnection;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica la conexión MCP de cada petición al servidor `/mcp/crm`.
 *
 * 1. El token debe ser un token OAuth de un cliente concreto con el scope `mcp:use`.
 * 2. La conexión se busca por usuario Y cliente del token, sin adivinar: si no existe, está
 *    revocada o venció, se invalidan los tokens de ese cliente (para que vuelva a pedir
 *    consentimiento) y se responde 401.
 * 3. El usuario debe seguir siendo miembro de alguna empresa de la conexión.
 * 4. Abre `McpSession` (empresas efectivas y nivel: `mcp:read` o `mcp:read` + `mcp:write`).
 * 5. `last_used_at` se escribe como mucho una vez por minuto.
 */
class ApplyMcpConnection
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->deny('Se requiere autenticación OAuth para usar el servidor MCP.');
        }

        $token = $user->token();
        $clientId = $token instanceof AccessToken ? (string) ($token->oauth_client_id ?? '') : '';

        if ($clientId === '' || ! $token->can(Registrar::OAUTH_SCOPE)) {
            return $this->deny('El token no pertenece a un cliente MCP autorizado.');
        }

        $connection = McpConnection::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->first();

        if ($connection === null || ! $connection->isActive()) {
            McpConnection::revokeTokens((int) $user->id, $clientId);

            return $this->deny(match (true) {
                $connection === null => 'La conexión MCP no está registrada. Vuelva a conectar el cliente y apruebe el acceso.',
                $connection->isRevoked() => 'La conexión MCP fue revocada. Vuelva a conectar el cliente y apruebe el acceso.',
                default => 'La conexión MCP venció. Vuelva a conectar el cliente y apruebe el acceso.',
            });
        }

        if (McpSession::start($request, $user, $connection) === null) {
            return response()->json([
                'error' => 'forbidden',
                'error_description' => 'Ya no pertenece a ninguna de las empresas de esta conexión MCP.',
            ], 403);
        }

        $this->touch($connection);

        return $next($request);
    }

    protected function touch(McpConnection $connection): void
    {
        $threshold = now()->subSeconds((int) config('mcp.crm.last_used_resolution_seconds', 60));

        if ($connection->last_used_at !== null && $connection->last_used_at->greaterThan($threshold)) {
            return;
        }

        McpConnection::query()->whereKey($connection->id)
            ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', $threshold))
            ->update(['last_used_at' => now()]);
    }

    protected function deny(string $message): JsonResponse
    {
        return response()->json(['error' => 'invalid_token', 'error_description' => $message], 401);
    }
}
