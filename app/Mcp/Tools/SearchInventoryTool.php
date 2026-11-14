<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_inventory')]
#[Title('Buscar en el inventario')]
#[Description('Busca productos del inventario sincronizado de Blessing por SKU, descripción o marca. Por cada producto devuelve existencia y `pricing` para la cantidad pedida, con el MISMO calculador que cotizaciones: unit_price (sin ISV), unit_tax, unit_total, subtotal, tax (ISV), total, tax_rate y si el precio de origen ya incluía el impuesto. Use esos importes tal cual: no vuelva a sumar ISV.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchInventoryTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'search' => $schema->string()->description('Texto en SKU, descripción o marca.'),
            'sku' => $schema->string()->description('SKU exacto.'),
            'category' => $schema->string()->description('Categoría.'),
            'quantity' => $schema->number()->description('Cantidad para calcular subtotal, ISV y total (por defecto 1).'),
            'in_stock' => $schema->boolean()->description('Solo con existencia.'),
            'include_inactive' => $schema->boolean()->description('Incluir productos que ya no vienen de Blessing.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', InventoryItem::class);

        $data = $this->validate($request->all(), [
            'search' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'in_stock' => ['nullable', 'boolean'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $quantity = (float) ($data['quantity'] ?? 1);
        $withCost = $user->can('viewCost', InventoryItem::class);
        $term = trim((string) ($data['search'] ?? ''));

        $query = InventoryItem::query()
            ->when(! ($data['include_inactive'] ?? false), fn ($query) => $query->active())
            ->search($term)
            ->when(filled($data['sku'] ?? null), fn ($query) => $query->where('sku', $data['sku']))
            ->when(filled($data['category'] ?? null), fn ($query) => $query->where('category', $data['category']))
            ->when($data['in_stock'] ?? false, fn ($query) => $query->where(fn ($inner) => $inner->where('stock_available', '>', 0)->orWhere(fn ($x) => $x->whereNull('stock_available')->where('stock', '>', 0))))
            ->orderByRaw('CASE WHEN sku = ? THEN 0 ELSE 1 END', [$term])
            ->orderBy('sku');

        $lastSync = InventoryItem::query()->max('synced_at');

        return $this->ok([
            'company_id' => $companyId,
            'quantity' => $quantity,
            'last_synced_at' => $lastSync ? (string) $lastSync : null,
            ...$this->paginate($query, $request, fn (InventoryItem $item) => Present::inventoryItem($item, $quantity, $withCost)),
        ]);
    }
}
