<?php

namespace App\Mcp\Support;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Contexto de una petición MCP: quién opera, con qué conexión, en qué empresas y con qué nivel.
 *
 * Lo abre el middleware `ApplyMcpConnection` (y las pruebas, con `McpSession::start`). Las
 * empresas efectivas son la intersección entre las que eligió el usuario al aprobar la conexión
 * y aquellas de las que sigue siendo miembro hoy.
 */
final class McpSession
{
    public const ATTRIBUTE = 'mcp_session';

    /**
     * @param  array<int, int>  $companyIds
     */
    public function __construct(
        public readonly User $user,
        public readonly McpConnection $connection,
        public readonly array $companyIds,
        public readonly string $requestId,
    ) {}

    /**
     * Abre la sesión para la petición en curso. Devuelve null si el usuario ya no tiene
     * acceso a ninguna de las empresas de la conexión.
     */
    public static function start(Request $request, User $user, McpConnection $connection): ?self
    {
        $granted = $connection->companyIds();
        $companyIds = array_values(array_filter($user->accessibleCompanyIds(), fn (int $id) => in_array($id, $granted, true)));

        if ($companyIds === []) {
            return null;
        }

        $default = $user->defaultCompanyId();
        $session = new self($user, $connection, $companyIds, (string) Str::uuid());

        app(CompanyContext::class)->initialize($user, $companyIds, in_array($default, $companyIds, true) ? $default : $companyIds[0]);

        $request->attributes->set(self::ATTRIBUTE, $session);
        $request->attributes->set('mcp_connection', $connection);
        $request->attributes->set('mcp_request_id', $session->requestId);

        return $session;
    }

    public static function current(): ?self
    {
        $session = request()->attributes->get(self::ATTRIBUTE);

        return $session instanceof self ? $session : null;
    }

    public function canRead(): bool
    {
        return $this->connection->allowsScope(McpConnection::SCOPE_READ);
    }

    public function canWrite(): bool
    {
        return $this->connection->allowsScope(McpConnection::SCOPE_WRITE);
    }

    public function allowsCompany(?int $companyId): bool
    {
        return $companyId !== null && in_array($companyId, $this->companyIds, true);
    }

    public function defaultCompanyId(): int
    {
        $current = app(CompanyContext::class)->currentCompanyId();

        return $this->allowsCompany($current) ? (int) $current : $this->companyIds[0];
    }
}
