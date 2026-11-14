<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\InventoryItem;
use App\Rules\BelongsToActiveCompany;

/**
 * Líneas de cotización por MCP (contrato provisional en español, pendiente de la API final de
 * `QuoteService` del módulo de Cotizaciones). Los ids se validan contra la empresa.
 */
trait ValidatesQuoteItems
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function itemRules(bool $required): array
    {
        return [
            'items' => [$required ? 'required' : 'nullable', 'array', 'min:1', 'max:200'],
            'items.*.descripcion' => ['required', 'string', 'max:255'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.unidad' => ['nullable', 'string', 'max:50'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'items.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'items.*.margen' => ['nullable', 'numeric', 'min:0', 'max:95'],
            'items.*.tasa_impuesto' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.inventory_item_id' => ['nullable', 'integer', new BelongsToActiveCompany(InventoryItem::class)],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapItems(array $items): array
    {
        return array_map(fn (array $item) => [
            'description' => $item['descripcion'],
            'quantity' => $item['cantidad'],
            'unit' => $item['unidad'] ?? 'Unidad',
            'unit_price' => $item['precio_unitario'] ?? null,
            'unit_cost' => $item['costo_unitario'] ?? null,
            'margin' => $item['margen'] ?? 0,
            'tax_rate' => $item['tasa_impuesto'] ?? null,
            'inventory_item_id' => $item['inventory_item_id'] ?? null,
            'sku' => $item['sku'] ?? null,
        ], $items);
    }
}
