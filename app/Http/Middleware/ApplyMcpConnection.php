<?php

namespace App\Http\Middleware;

use App\Models\McpConnection;
use App\Services\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica el alcance de la conexion MCP.
 *
 * El cliente MCP opera en nombre de un usuario, pero la conexion puede estar
 * limitada a un subconjunto de empresas. El permiso efectivo es la
 * interseccion entre las empresas del usuario y las de la conexion.
 */
class ApplyMcpConnection
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'error' => 'unauthorized',
                'error_description' => 'Se requiere autenticacion OAuth para usar el servidor MCP.',
            ], 401);
        }

        $connection = $this->resolveConnection($request, $user);

        if ($connection === null) {
            return response()->json([
                'error' => 'unauthorized',
                'error_description' => 'La conexion MCP no esta registrada o fue revocada.',
            ], 401);
        }

        $connection->forceFill(['last_used_at' => now()])->save();

        $context = app(CompanyContext::class);
        $allowed = $context->resolveAllowed($user);

        if ($connection->company_ids !== null && $connection->company_ids !== []) {
            $restricted = array_map('intval', $connection->company_ids);
            $allowed = array_values(array_intersect($allowed, $restricted));
        }

        $context->initialize($user, $allowed, $allowed[0] ?? null);

        $request->attributes->set('mcp_connection', $connection);
        $request->attributes->set('mcp_request_id', (string) \Illuminate\Support\Str::uuid());

        return $next($request);
    }

    protected function resolveConnection(Request $request, \App\Models\User $user): ?McpConnection
    {
        $token = $request->user()->token();
        $clientId = is_object($token) && method_exists($token, 'getAttribute')
            ? $token->getAttribute('client_id')
            : null;

        $connection = McpConnection::query()
            ->where('user_id', $user->id)
            ->when($clientId, fn ($query) => $query->where('client_id', (string) $clientId))
            ->latest('id')
            ->first();

        if ($connection === null || ! $connection->isActive()) {
            return null;
        }

        return $connection;
    }
}
