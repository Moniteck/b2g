<?php

namespace Tests\Feature\Tools;

use App\Models\LegalMessage;
use App\Services\Ai\AiTokenBudget;
use App\Services\Ai\ChatCompletionStream;
use App\Services\Tenancy\CompanyContext;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Chat del Agente Legal en streaming (`Accept: application/x-ndjson`): mismos permisos, mismo
 * límite de uso y mismo presupuesto de tokens que la respuesta completa.
 */
class LegalAgentStreamTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => 'clave-de-prueba', 'services.openai.model' => 'modelo-de-prueba']);
    }

    /** Sustituye el cliente de OpenAI por respuestas SSE simuladas. */
    private function fakeStream(Response ...$responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        $this->app->bind(ChatCompletionStream::class, fn ($app) => new class($app->make(AiTokenBudget::class), $stack) extends ChatCompletionStream
        {
            public function __construct(AiTokenBudget $budget, private HandlerStack $stack)
            {
                parent::__construct($budget);
            }

            protected function client(int $timeout): Client
            {
                return new Client(['handler' => $this->stack, 'http_errors' => true]);
            }
        });
    }

    /** @param  array<int, string>  $deltas */
    private function sse(array $deltas, int $tokens = 120): Response
    {
        $lines = array_map(fn (string $text) => 'data: '.json_encode(['choices' => [['delta' => ['content' => $text], 'finish_reason' => null]]]), $deltas);
        $lines[] = 'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]);
        $lines[] = 'data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => $tokens - 40, 'total_tokens' => $tokens]]);
        $lines[] = 'data: [DONE]';

        return new Response(200, ['Content-Type' => 'text/event-stream'], implode("\n\n", $lines)."\n\n");
    }

    /** @return array<int, array<string, mixed>> */
    private function events(string $body): array
    {
        return array_values(array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", $body))));
    }

    public function test_reply_streams_deltas_and_saves_the_conversation_at_the_end(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        $this->fakeStream($this->sse(['Según el ', '**Artículo 131**', ' tienes diez días.']));

        $response = $this->actingInCompany($operator, $company)
            ->withHeaders(['Accept' => 'application/json, application/x-ndjson'])
            ->post('/agente-legal/chat', ['message' => '¿Cuánto tiempo tengo para impugnar?']);

        $response->assertOk();
        $this->assertStringContainsString('application/x-ndjson', $response->headers->get('Content-Type'));
        $events = $this->events($response->streamedContent());

        $this->assertSame(['delta', 'delta', 'delta', 'done'], array_column($events, 'type'));
        $this->assertSame('Según el **Artículo 131** tienes diez días.', implode('', array_column(array_slice($events, 0, 3), 'text')));
        $this->assertSame('assistant', $events[3]['messages'][1]['role']);
        $this->assertSame('Según el **Artículo 131** tienes diez días.', $events[3]['messages'][1]['content']);
        $this->assertSame(2, LegalMessage::count());

        // Petición real a OpenAI: streaming con consumo, modelo configurado e instrucciones del sistema.
        $payload = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertTrue($payload['stream']);
        $this->assertTrue($payload['stream_options']['include_usage']);
        $this->assertSame('modelo-de-prueba', $payload['model']);
        $this->assertSame('system', $payload['messages'][0]['role']);
        $this->assertSame('¿Cuánto tiempo tengo para impugnar?', $payload['messages'][1]['content']);

        // El consumo informado se carga al presupuesto diario de la empresa.
        $this->assertSame(120, app(AiTokenBudget::class)->used($company->id));
    }

    public function test_stream_failure_emits_an_error_event_and_saves_nothing(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        $this->fakeStream(new Response(500, [], 'fallo'));

        $response = $this->actingInCompany($operator, $company)
            ->withHeaders(['Accept' => 'application/json, application/x-ndjson'])
            ->post('/agente-legal/chat', ['message' => '¿Qué garantías pide la ley?']);

        $events = $this->events($response->streamedContent());
        $this->assertSame('error', $events[0]['type']);
        $this->assertSame('El servicio de IA no está disponible en este momento.', $events[0]['message']);
        $this->assertSame(0, LegalMessage::count());
    }

    public function test_exhausted_budget_is_a_normal_json_error_before_streaming(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        config(['services.openai.daily_token_budget' => 100]);
        app(CompanyContext::class)->runFor($company->id, fn () => app(AiTokenBudget::class)->record($company->id, 100));
        $this->fakeStream($this->sse(['no debería llamarse']));

        $this->actingInCompany($operator, $company)
            ->withHeaders(['Accept' => 'application/json, application/x-ndjson'])
            ->postJson('/agente-legal/chat', ['message' => '¿Qué garantías pide la ley?'], ['Accept' => 'application/json, application/x-ndjson'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'presupuesto diario'));

        $this->assertSame([], $this->sent);
    }

    public function test_stream_keeps_the_rate_limit_and_the_permissions(): void
    {
        [$viewer, $company] = $this->memberOf(null, 'viewer');
        $this->actingInCompany($viewer, $company)
            ->withHeaders(['Accept' => 'application/json, application/x-ndjson'])
            ->post('/agente-legal/chat', ['message' => 'Hola'])
            ->assertForbidden();

        [$operator] = $this->memberOf($company, 'operator');
        config(['services.openai.per_minute' => 1]);
        RateLimiter::clear('usuario:'.$operator->id);
        $this->fakeStream($this->sse(['Primera respuesta.']), $this->sse(['Segunda.']));

        $this->actingInCompany($operator, $company)->withHeaders(['Accept' => 'application/json, application/x-ndjson'])
            ->post('/agente-legal/chat', ['message' => 'Primera consulta'])->assertOk()->streamedContent();

        $this->actingInCompany($operator, $company)->withHeaders(['Accept' => 'application/json, application/x-ndjson'])
            ->post('/agente-legal/chat', ['message' => 'Segunda consulta'])
            ->assertStatus(429);

        $this->assertCount(1, $this->sent);
    }
}
