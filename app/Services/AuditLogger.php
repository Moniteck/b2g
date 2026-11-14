<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registro de trazabilidad de todas las operaciones con efecto en el negocio.
 *
 * Nunca se registran contrasenas, tokens, imagenes de firma ni el contenido
 * integro de los documentos: solo metadatos y cambios de campos sensibles.
 */
class AuditLogger
{
    protected ?string $channel = 'web';

    protected ?string $requestId = null;

    public function forChannel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function withRequestId(?string $requestId): static
    {
        $this->requestId = $requestId;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(
        string $action,
        ?object $resource = null,
        array $changes = [],
        ?User $actor = null,
        ?int $companyId = null,
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();
        $actor ??= $request->user();

        $log = new AuditLog;
        $log->user_id = $actor?->id;
        $log->company_id = $companyId ?? ($resource?->company_id ?? null);
        $log->channel = $this->channel;
        $log->actor_name = $actor?->name;
        $log->action = $action;
        $log->resource_type = $resource !== null ? class_basename($resource) : null;
        $log->resource_id = $resource?->getKey();
        // Dentro de una petición MCP toda acción (también la de los servicios) queda con canal `mcp` y su conexión.
        if (($connection = $request->attributes->get('mcp_connection')) instanceof McpConnection) {
            [$log->channel, $log->mcp_connection_id] = ['mcp', $connection->getKey()];
        }
        $log->request_id = $this->requestId ?? $request->attributes->get('mcp_request_id') ?? $request->header('X-Request-Id') ?? (string) Str::uuid();
        $log->changes = $changes === [] ? null : $this->sanitize($changes);
        $log->ip = $request->ip();
        $log->user_agent = substr((string) $request->userAgent(), 0, 255);
        $log->created_at = now();

        try {
            $log->save();
        } catch (\Throwable $exception) {
            // La auditoria nunca debe impedir la operacion de negocio.
            Log::warning('No fue posible registrar la auditoria.', [
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }

        return $log;
    }

    /**
     * Oculta valores que nunca deben persistirse en la bitacora.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function sanitize(array $changes): array
    {
        $blocked = ['password', 'password_confirmation', 'token', 'api_key', 'secret', 'remember_token'];

        foreach ($changes as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $changes[$key] = '[oculto]';
            }

            if (is_array($value)) {
                $changes[$key] = $this->sanitize($value);
            }
        }

        return $changes;
    }
}
