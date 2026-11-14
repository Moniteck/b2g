<?php

namespace App\Mcp\Tools;

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventorySyncRun;
use App\Models\User;
use App\Services\Blessing\InventorySyncService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('sync_inventory')]
#[Title('Sincronizar inventario con Blessing')]
#[Description('Trae ahora el inventario de Blessing de la empresa: inserta o actualiza productos, marca como inactivos los que ya no vienen y registra la corrida (altas, cambios, errores). Solo administradores de la empresa. Si ya hay una sincronización en curso, lo avisa.')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
class SyncInventoryTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return $this->companySchema($schema);
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('sync', InventoryItem::class);

        $run = app(InventorySyncService::class)->sync(Company::query()->findOrFail($companyId), InventorySyncRun::TRIGGER_MANUAL, $user);

        return $this->ok(['run' => $run->summary()], $run->succeeded() ? 'Sincronización terminada.' : 'La sincronización terminó con errores.');
    }
}
