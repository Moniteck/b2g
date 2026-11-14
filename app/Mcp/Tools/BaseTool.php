<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Confirmations;
use App\Mcp\Support\McpSession;
use App\Mcp\Support\ToolError;
use App\Mcp\Support\Untrusted;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\Blessing\BlessingException;
use App\Services\Documents\SignatureException;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Base de las herramientas MCP: capa delgada sobre los servicios de dominio.
 *
 * `handle()` hace lo común y llama a `run()` de cada herramienta:
 *  1. Exige la sesión MCP (`ApplyMcpConnection`) y el nivel: las herramientas sin `#[IsReadOnly]`
 *     son de escritura y exigen `mcp:write` (además se ocultan en conexiones de solo lectura).
 *  2. Resuelve la empresa: `empresa_id` (validado contra la conexión), la del registro indicado
 *     por `$record` o la predeterminada; y ejecuta `run()` dentro de `CompanyContext::runFor`,
 *     así el scope de empresa, `BelongsToActiveCompany` y los permisos por rol miran esa empresa.
 *  3. Convierte toda excepción en un error controlado en español (nunca un 500).
 *
 * Las autorizaciones se hacen con las MISMAS policies que la web (`$this->authorize()`).
 */
abstract class BaseTool extends Tool
{
    /**
     * Registro principal de la herramienta: [argumento, modelo]. Si llega sin `empresa_id`, la
     * empresa de ese registro (si es de la conexión) fija el contexto.
     *
     * @var array{0: string, 1: class-string<Model>}|null
     */
    protected ?array $record = null;

    /**
     * Lógica de la herramienta, ya dentro de la empresa resuelta.
     */
    abstract protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory;

    public function handle(Request $request): Response|ResponseFactory
    {
        $session = McpSession::current();

        if ($session === null) {
            return Response::error('La sesión MCP no está inicializada. Vuelva a conectar el cliente.');
        }

        try {
            if (! $session->canRead()) {
                throw new ToolError('Esta conexión no tiene permiso de lectura.');
            }

            if (! $this->isReadOnly() && ! $session->canWrite()) {
                throw new ToolError('Esta conexión es de solo lectura. Para crear o modificar datos, vuelva a conectar el asistente y elija «Lectura y escritura».');
            }

            $companyId = $this->resolveCompany($request, $session);

            return app(CompanyContext::class)->runFor($companyId, fn () => $this->run($request, $session->user, $companyId));
        } catch (Throwable $exception) {
            return $this->toError($exception, $session);
        }
    }

    /**
     * Oculta las herramientas de escritura en las conexiones de solo lectura.
     */
    public function shouldRegister(): bool
    {
        $session = McpSession::current();

        return $session === null || $this->isReadOnly() || $session->canWrite();
    }

    public function isReadOnly(): bool
    {
        return ($this->annotations()['readOnlyHint'] ?? false) === true;
    }

    /**
     * @return array{0: string, 1: class-string<Model>}|null
     */
    protected function recordFor(Request $request): ?array
    {
        return $this->record;
    }

    protected function session(): McpSession
    {
        return McpSession::current() ?? throw new ToolError('La sesión MCP no está inicializada.');
    }

    protected function resolveCompany(Request $request, McpSession $session): int
    {
        $requested = $request->get('empresa_id');

        if ($requested !== null && $requested !== '') {
            if (! is_numeric($requested) || ! $session->allowsCompany((int) $requested)) {
                throw new ToolError("La empresa {$requested} no está disponible en esta conexión. Use list_companies para ver las empresas permitidas.");
            }

            return (int) $requested;
        }

        if (($record = $this->recordFor($request)) !== null) {
            [$argument, $model] = $record;
            $id = $request->get($argument);

            if (is_numeric($id) && in_array(BelongsToCompany::class, class_uses_recursive($model), true)) {
                $companyId = $model::query()->withoutGlobalScope(CompanyScope::class)->whereKey((int) $id)->value('company_id');

                if ($companyId !== null && $session->allowsCompany((int) $companyId)) {
                    return (int) $companyId;
                }
            }
        }

        return $session->defaultCompanyId();
    }

    /**
     * Misma autorización que la web: policy del modelo o gate con guiones.
     */
    protected function authorize(string $ability, mixed $arguments = []): void
    {
        $response = Gate::forUser($this->session()->user)->inspect($ability, $arguments);

        if ($response->denied()) {
            throw new AuthorizationException($response->message() ?: 'Tu rol no permite esta acción en esta empresa.');
        }
    }

    /**
     * Valida los argumentos con reglas de Laravel (las mismas de los Form Request de la web).
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    protected function validate(array $data, array $rules, array $messages = [], array $attributes = []): array
    {
        return Validator::make($data, $rules, $messages, $attributes)->validate();
    }

    /**
     * Registro de la empresa resuelta; si no existe (o es de otra empresa), error controlado.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>|Builder<TModel>  $query
     * @return TModel
     */
    protected function find(string|Builder $query, mixed $id, string $label)
    {
        $builder = is_string($query) ? $query::query() : $query;

        if (! is_numeric($id)) {
            throw new ToolError("Indica el id de {$label}.");
        }

        return $builder->find((int) $id) ?? throw new ToolError("No se encontró {$label} {$id} en las empresas de esta conexión.");
    }

    /**
     * Acción sensible con confirmación en dos pasos (ver `Confirmations`).
     *
     * @param  array<string, mixed>  $summary
     * @param  callable(): (Response|ResponseFactory)  $action
     */
    protected function confirmed(Request $request, string $action, Model $resource, array $summary, callable $action_): Response|ResponseFactory
    {
        $result = app(Confirmations::class)->guard($this->session(), $action, $resource, $request->all(), $summary, $action_);

        return is_array($result) ? $this->ok($result) : $result;
    }

    /**
     * Esquema común del código de confirmación.
     *
     * @return array<string, mixed>
     */
    protected function confirmationSchema(JsonSchema $schema): array
    {
        return [
            Confirmations::ARGUMENT => $schema->string()->description('Código de un solo uso que devolvió la primera llamada. Omitirlo devuelve el resumen y el código; enviarlo (con los mismos argumentos) ejecuta la acción.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function companySchema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Empresa en la que se opera (ver list_companies). Omitirlo usa la del registro indicado o la empresa predeterminada de la conexión.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function pageSchema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Página (desde 1).'),
            'per_page' => $schema->integer()->description('Registros por página, máximo 50 (por defecto 20).'),
        ];
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  callable(Model): array<string, mixed>  $map
     * @return array{data: array<int, mixed>, meta: array<string, int>}
     */
    protected function paginate(Builder $query, Request $request, callable $map, int $defaultPerPage = 20, int $maxPerPage = 50): array
    {
        $perPage = min(max((int) $request->get('per_page', $defaultPerPage), 1), $maxPerPage);
        $page = max((int) $request->get('page', 1), 1);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => array_map($map, $paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ok(array $payload, string $message = ''): ResponseFactory
    {
        if ($message !== '') {
            $payload = ['message' => $message] + $payload;
        }

        if ($this->containsUntrusted($payload)) {
            $payload['_note'] = Untrusted::NOTE;
        }

        return Response::structured($payload);
    }

    protected function fail(string $message): Response
    {
        return Response::error($message);
    }

    /**
     * Texto de terceros delimitado (ver `Untrusted`).
     *
     * @param  array<string, mixed>  $values
     * @return array{untrusted: array<string, mixed>}
     */
    protected function untrusted(array $values, int $max = 2000): array
    {
        return Untrusted::of($values, $max);
    }

    protected function date(mixed $value): ?string
    {
        return $value?->format('Y-m-d');
    }

    protected function dateTime(mixed $value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }

    protected function toError(Throwable $exception, McpSession $session): Response
    {
        return match (true) {
            $exception instanceof ToolError => $this->fail($exception->getMessage()),
            $exception instanceof ValidationException => $this->fail('Datos no válidos. '.implode(' ', array_map(
                fn (string $field, array $messages) => "{$field}: ".implode(' ', $messages),
                array_keys($exception->errors()),
                $exception->errors(),
            ))),
            $exception instanceof AuthorizationException => $this->fail('Sin permiso: '.($exception->getMessage() ?: 'tu rol no permite esta acción en esta empresa.')),
            $exception instanceof ModelNotFoundException => $this->fail('No se encontró el registro indicado en las empresas de esta conexión.'),
            $exception instanceof SignatureException, $exception instanceof BlessingException => $this->fail($exception->getMessage()),
            $exception instanceof HttpExceptionInterface => $this->fail(match ($exception->getStatusCode()) {
                404 => 'No se encontró el registro indicado en las empresas de esta conexión.',
                403 => 'Sin permiso: '.($exception->getMessage() ?: 'tu rol no permite esta acción.'),
                default => $exception->getMessage() ?: 'La operación no se pudo completar.',
            }),
            default => $this->internal($exception, $session),
        };
    }

    private function internal(Throwable $exception, McpSession $session): Response
    {
        report($exception);

        return $this->fail("Ocurrió un error interno y no se completó la operación. Código de seguimiento: {$session->requestId}.");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function containsUntrusted(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if ($key === Untrusted::KEY || (is_array($value) && $this->containsUntrusted($value))) {
                return true;
            }
        }

        return false;
    }
}
