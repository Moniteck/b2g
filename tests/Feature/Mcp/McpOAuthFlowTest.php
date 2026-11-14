<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

/**
 * Recorrido OAuth real de un cliente MCP: registro dinámico, consentimiento en español con
 * empresas y nivel, intercambio del código con PKCE, llamadas a /mcp/crm y revocación.
 */
class McpOAuthFlowTest extends McpTestCase
{
    private const REDIRECT = 'http://localhost:6274/oauth/callback';

    private Company $company;

    private Company $second;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        $this->second = Company::factory()->create(['name' => 'Empresa Segunda']);
        $this->second->users()->attach($this->admin->id, ['role' => 'operator', 'is_default' => false]);
    }

    private function register(): string
    {
        return $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]])
            ->assertCreated()->json('client_id');
    }

    /**
     * @return array{0: string, 1: string} verificador PKCE y token de autorización de la sesión
     */
    private function authorizeScreen(string $clientId): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->actingAs($this->admin)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'estado-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertOk()
            ->assertSee('Autorizar a Claude')
            ->assertSee('Empresas a las que podrá acceder')
            ->assertSee($this->second->name)
            ->assertSee('Solo lectura')
            ->assertSee('Lectura y escritura');

        return [$verifier, (string) session('authToken')];
    }

    private function token(string $clientId, string $verifier, string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('estado-123', $query['state']);

        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
            'code' => $query['code'],
        ])->assertOk()->json('access_token');
    }

    private function rpc(string $token, string $method, array $params = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp/crm', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    public function test_full_flow_creates_connection_and_applies_level_and_companies(): void
    {
        $clientId = $this->register();
        [$verifier, $authToken] = $this->authorizeScreen($clientId);

        $response = $this->post('/oauth/mcp/aprobar', ['auth_token' => $authToken, 'company_ids' => [$this->company->id], 'level' => 'read']);
        $response->assertRedirect();
        $this->assertStringStartsWith(self::REDIRECT, $response->headers->get('Location'));

        $connection = McpConnection::query()->sole();
        $this->assertSame($this->admin->id, $connection->user_id);
        $this->assertSame($clientId, $connection->client_id);
        $this->assertSame([$this->company->id], $connection->companyIds());
        $this->assertSame(['mcp:read'], $connection->scopes);
        $this->assertTrue($connection->expires_at->isFuture());

        $token = $this->token($clientId, $verifier, $response->headers->get('Location'));

        $tools = collect($this->rpc($token, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');
        $this->assertContains('list_tenders', $tools);
        $this->assertNotContains('create_tender', $tools);

        $companies = $this->rpc($token, 'tools/call', ['name' => 'list_companies', 'arguments' => []])->assertOk()->json('result.structuredContent');
        $this->assertSame([$this->company->id], array_column($companies['companies'], 'id'));
        $this->assertNotNull($connection->fresh()->last_used_at);
    }

    public function test_consent_validates_companies_and_level(): void
    {
        $foreign = Company::factory()->create();
        $clientId = $this->register();
        [, $authToken] = $this->authorizeScreen($clientId);

        $this->post('/oauth/mcp/aprobar', ['auth_token' => $authToken, 'company_ids' => [$foreign->id], 'level' => 'write'])
            ->assertSessionHasErrors('company_ids.0');
        $this->post('/oauth/mcp/aprobar', ['auth_token' => $authToken, 'company_ids' => [], 'level' => 'write'])
            ->assertSessionHasErrors('company_ids');
        $this->post('/oauth/mcp/aprobar', ['auth_token' => 'otro', 'company_ids' => [$this->company->id], 'level' => 'write'])
            ->assertStatus(419)->assertSee('La autorización venció');

        $this->assertDatabaseCount('mcp_connections', 0);
    }

    public function test_tokens_without_connection_or_revoked_are_rejected(): void
    {
        $clientId = $this->register();
        [$verifier, $authToken] = $this->authorizeScreen($clientId);
        $location = $this->post('/oauth/mcp/aprobar', ['auth_token' => $authToken, 'company_ids' => [$this->company->id], 'level' => 'write'])->headers->get('Location');
        $token = $this->token($clientId, $verifier, $location);

        $this->rpc($token, 'tools/list')->assertOk();

        McpConnection::query()->sole()->revoke($this->admin);

        $this->rpc($token, 'tools/list')->assertUnauthorized();
        $this->assertSame(0, Passport::token()->newQuery()->where('client_id', $clientId)->where('revoked', false)->count());
    }

    public function test_middleware_rejects_unknown_expired_and_former_members(): void
    {
        $client = Passport::client()->forceFill(['id' => (string) Str::orderedUuid(), 'name' => 'Claude', 'redirect_uris' => [self::REDIRECT], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false]);
        $client->save();

        Passport::actingAs($this->admin, ['mcp:use'], 'api', $client);
        $this->postJson('/mcp/crm', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized()->assertJsonPath('error_description', fn ($m) => str_contains($m, 'no está registrada'));

        $connection = $this->connect($this->admin, [$this->company], attributes: ['client_id' => (string) $client->getKey(), 'expires_at' => now()->subMinute()]);
        Passport::actingAs($this->admin, ['mcp:use'], 'api', $client);
        $this->postJson('/mcp/crm', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized()->assertJsonPath('error_description', fn ($m) => str_contains($m, 'venció'));

        $connection->forceFill(['expires_at' => now()->addDay()])->save();
        $this->company->users()->detach($this->admin->id);
        $this->admin->forgetMemberships();
        Passport::actingAs($this->admin, ['mcp:use'], 'api', $client);
        $this->postJson('/mcp/crm', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();

        Passport::actingAs($this->admin, [], 'api', $client);
        $this->postJson('/mcp/crm', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_last_used_is_written_at_most_once_per_minute(): void
    {
        $client = Passport::client()->forceFill(['id' => (string) Str::orderedUuid(), 'name' => 'Claude', 'redirect_uris' => [self::REDIRECT], 'grant_types' => ['authorization_code'], 'revoked' => false]);
        $client->save();
        $connection = $this->connect($this->admin, [$this->company], attributes: ['client_id' => (string) $client->getKey(), 'last_used_at' => now()->subSeconds(10)]);
        $before = $connection->last_used_at->toDateTimeString();

        Passport::actingAs($this->admin, ['mcp:use'], 'api', $client);
        $this->postJson('/mcp/crm', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
        $this->assertSame($before, $connection->fresh()->last_used_at->toDateTimeString());

        $this->travel(2)->minutes();
        Passport::actingAs($this->admin, ['mcp:use'], 'api', $client);
        $this->postJson('/mcp/crm', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
        $this->assertNotSame($before, $connection->fresh()->last_used_at->toDateTimeString());
    }

    public function test_registration_is_limited_per_ip_and_localhost_only_outside_production(): void
    {
        $this->assertContains('http://localhost', config('mcp.redirect_domains'));

        for ($i = 0; $i < (int) config('mcp.crm.registrations_per_hour'); $i++) {
            $this->postJson('/oauth/register', ['client_name' => "C{$i}", 'redirect_uris' => [self::REDIRECT]])->assertCreated();
        }

        $this->postJson('/oauth/register', ['client_name' => 'Uno más', 'redirect_uris' => [self::REDIRECT]])->assertStatus(429);
    }

    public function test_janitor_removes_orphan_clients_only(): void
    {
        $orphan = $this->register();
        $used = $this->register();
        $this->connect($this->admin, [$this->company], attributes: ['client_id' => $used]);

        $this->travel(25)->hours();
        $this->artisan('mcp:limpiar-clientes')->assertSuccessful();

        $this->assertNull(Passport::client()->newQuery()->find($orphan));
        $this->assertNotNull(Passport::client()->newQuery()->find($used));
    }
}
