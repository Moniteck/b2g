<?php

namespace App\Mcp\Tools;

use App\Services\DashboardMetricsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_dashboard')]
#[Title('Resumen del CRM')]
#[Description('Devuelve los indicadores principales de la empresa: valor ponderado del pipeline, cotizaciones presentadas y ganadas, licitaciones activas y por cerrar, cartera vencida y documentos por vencer.')]
#[IsReadOnly]
class GetDashboardTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Empresa. Omitirlo usa la empresa activa.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $context = $this->companyContext();

        $metrics = $context->runFor($companyId, fn () => app(DashboardMetricsService::class)->metrics());

        return $this->ok([
            'empresa_id' => $companyId,
            'metricas' => $metrics,
        ]);
    }
}
