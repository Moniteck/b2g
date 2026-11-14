<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Support\McpSession;
use App\Models\Company;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Base de las pruebas del servidor MCP: conexión aprobada, sesión MCP abierta (lo que hace el
 * middleware) y llamadas con el cliente de pruebas de laravel/mcp.
 */
abstract class McpTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, Company>  $companies
     */
    protected function connect(User $user, array $companies, string $level = McpConnection::LEVEL_WRITE, array $attributes = []): McpConnection
    {
        return McpConnection::create($attributes + [
            'user_id' => $user->id,
            'name' => 'Claude',
            'client_id' => (string) fake()->uuid(),
            'client_name' => 'Claude',
            'company_ids' => array_map(fn (Company $company) => $company->id, $companies),
            'scopes' => McpConnection::scopesFor($level),
            'authorized_at' => now(),
            'expires_at' => now()->addDays(90),
        ]);
    }

    /**
     * Abre la sesión MCP como lo hace `ApplyMcpConnection`.
     */
    protected function inSession(User $user, McpConnection $connection): static
    {
        $this->actingAs($user);
        app('request')->attributes->remove(McpSession::ATTRIBUTE);
        $this->assertNotNull(McpSession::start(app('request'), $user, $connection));

        return $this;
    }

    /**
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    protected function tool(string $tool, array $arguments = []): TestResponse
    {
        return CrmServer::tool($tool, $arguments);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(TestResponse $response): array
    {
        return (fn () => $this->structuredContent() ?? [])->call($response);
    }

    /**
     * @return array<int, string>
     */
    protected function errorsOf(TestResponse $response): array
    {
        return (fn () => $this->errors())->call($response);
    }
}
