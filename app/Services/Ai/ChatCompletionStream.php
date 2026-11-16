<?php

namespace App\Services\Ai;

use App\Services\Tenancy\CompanyContext;
use Composer\CaBundle\CaBundle;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Chat Completions de OpenAI en streaming (SSE, `stream: true`).
 *
 * Mismas reglas que `OpenAIService`: modelo y clave de `config('services.openai')`,
 * `max_completion_tokens` sin temperatura, TLS verificado (CA de composer si PHP no tiene una)
 * y presupuesto diario de tokens por empresa (`AiTokenBudget`): se comprueba antes de llamar y
 * se registra el consumo que OpenAI informa en el último fragmento (`stream_options.include_usage`).
 *
 *   $stream->ensureReady();                                   // RuntimeException antes de abrir la respuesta
 *   $text = $stream->stream($messages, fn (string $delta) => print($delta));
 */
class ChatCompletionStream
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /** @var array<string, int> */
    private array $lastUsage = [];

    public function __construct(private readonly AiTokenBudget $budget) {}

    /**
     * Falla con un mensaje en español si no hay clave o la empresa agotó su presupuesto.
     *
     * @throws RuntimeException
     */
    public function ensureReady(): void
    {
        if (blank(config('services.openai.key'))) {
            throw new RuntimeException('La integración con OpenAI no está configurada (falta OPENAI_API_KEY).');
        }

        $this->budget->ensureAvailable($this->companyId());
    }

    /**
     * Envía la conversación y llama a `$onDelta` con cada trozo de texto que llega.
     * Devuelve el texto completo.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     *
     * @throws RuntimeException
     */
    public function stream(array $messages, callable $onDelta, int $maxCompletionTokens = 4000, int $timeout = 120): string
    {
        $this->ensureReady();
        $this->lastUsage = [];

        try {
            $response = $this->client($timeout)->post(self::ENDPOINT, [
                'stream' => true,
                'headers' => ['Authorization' => 'Bearer '.config('services.openai.key'), 'Accept' => 'text/event-stream'],
                'json' => [
                    'model' => (string) config('services.openai.model'),
                    'messages' => $messages,
                    'max_completion_tokens' => $maxCompletionTokens,
                    'stream' => true,
                    'stream_options' => ['include_usage' => true],
                ],
            ]);
        } catch (RequestException $exception) {
            $status = $exception->getResponse()?->getStatusCode() ?? 0;
            Log::warning('OpenAI (stream): respuesta con error.', ['status' => $status]);

            throw new RuntimeException(match (true) {
                $status === 401 => 'La clave de OpenAI no es válida.',
                $status === 429 => 'El servicio de IA está saturado o sin saldo. Intenta más tarde.',
                $status >= 500 => 'El servicio de IA no está disponible en este momento.',
                default => 'El servicio de IA no pudo procesar la solicitud.',
            });
        } catch (GuzzleException $exception) {
            Log::warning('OpenAI (stream): fallo de conexión.', ['error' => $exception->getMessage()]);

            throw new RuntimeException('No fue posible conectar con el servicio de IA. Intenta de nuevo en unos minutos.');
        }

        $body = $response->getBody();
        $buffer = '';
        $text = '';
        $finish = null;

        try {
            while (! $body->eof()) {
                $buffer .= $body->read(2048);

                while (($break = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $break));
                    $buffer = substr($buffer, $break + 1);

                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $data = trim(substr($line, 5));

                    if ($data === '[DONE]') {
                        break 2;
                    }

                    $chunk = json_decode($data, true);

                    if (! is_array($chunk)) {
                        continue;
                    }

                    if (is_array($chunk['usage'] ?? null)) {
                        $this->lastUsage = array_filter($chunk['usage'], 'is_int');
                    }

                    $finish = data_get($chunk, 'choices.0.finish_reason') ?? $finish;
                    $delta = data_get($chunk, 'choices.0.delta.content');

                    if (is_string($delta) && $delta !== '') {
                        $text .= $delta;
                        $onDelta($delta);
                    }
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('OpenAI (stream): se cortó la respuesta.', ['error' => $exception->getMessage()]);

            throw new RuntimeException('Se cortó la conexión con el servicio de IA. Inténtalo de nuevo.');
        } finally {
            $this->budget->record($this->companyId(), (int) ($this->lastUsage['total_tokens'] ?? 0));
        }

        if (blank($text)) {
            throw new RuntimeException($finish === 'length'
                ? 'La IA agotó el límite de respuesta antes de terminar.'
                : 'El servicio de IA devolvió una respuesta vacía.');
        }

        return $text;
    }

    /** @return array<string, int> */
    public function lastUsage(): array
    {
        return $this->lastUsage;
    }

    private function companyId(): ?int
    {
        return app(CompanyContext::class)->currentCompanyId();
    }

    /** Cliente HTTP (las pruebas lo sustituyen por uno con respuestas simuladas). */
    protected function client(int $timeout): Client
    {
        $verify = blank(ini_get('curl.cainfo')) && blank(ini_get('openssl.cafile')) ? CaBundle::getBundledCaBundlePath() : true;

        return new Client(['timeout' => $timeout, 'connect_timeout' => 15, 'read_timeout' => 60, 'verify' => $verify, 'http_errors' => true]);
    }
}
