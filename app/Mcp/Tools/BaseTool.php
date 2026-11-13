<?php

namespace App\Mcp\Tools;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base de las herramientas MCP.
 *
 * Centraliza la resolucion del actor, el alcance empresarial, la paginacion,
 * el formato de errores y la auditoria, de modo que cada herramienta se
 * limite a la logica del negocio.
 */
abstract class BaseTool extends Tool
{
    /**
     * Usuario autenticado que invoca la herramienta.
     */
    protected function user(Request $request): User
    {
        $user = $request->user('api') ?? $request->user();

        abort_if($user === null, 401, 'Se requiere autenticacion para usar esta herramienta.');

        return $user;
    }

    protected function companyContext(): CompanyContext
    {
        return app(CompanyContext::class);
    }

    /**
     * Empresa sobre la que opera la herramienta.
     */
    protected function resolveCompanyId(Request $request, ?int $requested = null): int
    {
        $context = $this->companyContext();
        $allowed = $context->allowedCompanyIds();

        if ($requested !== null) {
            abort_if(! in_array($requested, $allowed, true), 403, 'La empresa solicitada no esta disponible para esta conexion.');

            return $requested;
        }

        $current = $context->currentCompanyId();

        abort_if($current === null || ! in_array($current, $allowed, true), 403, 'No hay ninguna empresa disponible para esta conexion.');

        return $current;
    }

    protected function connection(Request $request): ?McpConnection
    {
        $connection = request()->attributes->get('mcp_connection');

        return $connection instanceof McpConnection ? $connection : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function audit(Request $request, string $action, ?object $resource = null, array $changes = []): void
    {
        app(AuditLogger::class)
            ->forChannel('mcp')
            ->withRequestId(request()->attributes->get('mcp_request_id'))
            ->record($action, $resource, $changes, $this->user($request));
    }

    /**
     * Aplica paginacion de forma segura a la consulta.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array{data: array<int, mixed>, meta: array<string, mixed>}
     */
    protected function paginate(Builder $query, Request $request, int $defaultPerPage = 20, int $maxPerPage = 100): array
    {
        $perPage = min(max((int) $request->get('per_page', $defaultPerPage), 1), $maxPerPage);
        $page = max((int) $request->get('page', 1), 1);

        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get();

        return [
            'data' => $items->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max((int) ceil($total / $perPage), 1),
            ],
        ];
    }

    /**
     * Error controlado para el cliente MCP.
     */
    protected function fail(string $message): Response
    {
        return Response::error($message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ok(array $payload, string $message = ''): Response
    {
        if ($message !== '') {
            $payload = ['mensaje' => $message] + $payload;
        }

        return Response::structured($payload);
    }
}
