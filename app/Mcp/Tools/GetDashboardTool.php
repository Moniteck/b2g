<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\DashboardMetricsService;
use App\Services\Notifications\DeadlineService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_dashboard')]
#[Title('Indicadores de la empresa')]
#[Description('Indicadores principales de la empresa (pipeline, cotizaciones, licitaciones activas) y los plazos de esta semana.')]
#[IsReadOnly]
#[IsIdempotent]
class GetDashboardTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return $this->companySchema($schema);
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('view-reports');

        return $this->ok([
            'company_id' => $companyId,
            'metrics' => app(DashboardMetricsService::class)->metrics(),
            'deadlines' => app(DeadlineService::class)->forCompany($companyId),
        ]);
    }
}
