<?php

namespace App\Mcp\Tools;

use App\Models\InventoryItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_inventory')]
#[Title('Buscar en el inventario')]
#[Description('Busca productos del inventario sincronizado desde Blessing. Devuelve el precio unitario sugerido con impuesto incluido, la tasa de impuesto cuando Blessing la informa, las existencias y la fecha de la ultima sincronizacion.')]
#[IsReadOnly]
class SearchInventoryTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Empresa. Omitirlo usa la empresa activa.'),
            'search' => $schema->string()->description('Texto para buscar por descripcion, sku o marca.'),
            'sku' => $schema->string()->description('Busqueda exacta por sku.'),
            'categoria' => $schema->string()->description('Filtra por categoria.'),
            'solo_activos' => $schema->boolean()->description('Si es true, solo productos activos.'),
            'page' => $schema->integer()->description('Pagina solicitada.'),
            'per_page' => $schema->integer()->description('Registros por pagina (maximo 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $query = InventoryItem::query()
            ->where('company_id', $companyId)
            ->orderBy('sku');

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('description', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        if ($sku = $request->get('sku')) {
            $query->where('sku', $sku);
        }

        if ($category = $request->get('categoria')) {
            $query->where('category', $category);
        }

        if ($request->boolean('solo_activos', true)) {
            $query->where('is_active', true);
        }

        $result = $this->paginate($query, $request);

        $result['data'] = collect($result['data'])->map(fn (InventoryItem $item) => [
            'id' => $item->id,
            'sku' => $item->sku,
            'descripcion' => $item->description,
            'categoria' => $item->category,
            'marca' => $item->brand,
            'unidad' => $item->unit,
            'existencia' => (float) ($item->stock_available ?? $item->stock),
            'precio_sugerido' => $item->salePrice(),
            'tasa_impuesto' => $item->tax_rate !== null ? (float) $item->tax_rate : null,
            'impuesto_incluido' => $item->tax_included,
            'costo' => (float) $item->cost,
            'sincronizado_en' => $item->synced_at?->toDateTimeString(),
        ])->all();

        $result['nota'] = 'Los precios sugeridos de Blessing ya incluyen impuesto; no debe adicionarse un ISV adicional al momento de cotizar.';

        return $this->ok($result);
    }
}
