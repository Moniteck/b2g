<?php

namespace App\Mcp\Tools;

use App\Models\InventoryItem;
use App\Models\InventorySyncRun;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_inventory_sync_status')]
#[Title('Estado de la sincronización de inventario')]
#[Description('Última sincronización del inventario de Blessing (estado, altas, cambios, inactivados, errores), la última exitosa, la antigüedad del dato en horas y las últimas corridas.')]
#[IsReadOnly]
#[IsIdempotent]
class GetInventorySyncStatusTool extends BaseTool
{
    private const STALE_AFTER_HOURS = 24;

    public function schema(JsonSchema $schema): array
    {
        return $this->companySchema($schema);
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', InventoryItem::class);

        $runs = InventorySyncRun::query()->with('user:id,name')->latest('started_at')->latest('id')->limit(5)->get();
        $lastSuccess = InventorySyncRun::query()->whereIn('status', [InventorySyncRun::COMPLETED, InventorySyncRun::PARTIAL])->latest('finished_at')->value('finished_at')
            ?? InventoryItem::query()->max('synced_at');
        $lastSuccessAt = $lastSuccess ? Carbon::parse($lastSuccess) : null;
        $summary = fn (InventorySyncRun $run) => collect($run->summary())->except('errors')->all() + ['errors' => array_slice((array) ($run->errors ?? []), 0, 10)];

        return $this->ok([
            'company_id' => $companyId,
            'last_run' => $runs->first() ? $summary($runs->first()) : null,
            'last_success_at' => $lastSuccessAt?->format('Y-m-d H:i:s'),
            'age_hours' => $lastSuccessAt ? round($lastSuccessAt->diffInMinutes(now()) / 60, 1) : null,
            'stale' => $lastSuccessAt === null || $lastSuccessAt->lt(now()->subHours(self::STALE_AFTER_HOURS)),
            'active_items' => InventoryItem::query()->active()->count(),
            'recent_runs' => $runs->map($summary)->all(),
        ]);
    }
}
