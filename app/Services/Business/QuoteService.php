<?php

namespace App\Services\Business;

use App\Models\InventoryItem;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Scopes\CompanyScope;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reglas economicas de la cotizacion.
 *
 * Los precios de referencia provienen de Blessing y ya incluyen impuesto, por
 * lo que el total de la linea es cantidad por precio unitario final. El
 * impuesto contenido se informa por separado para la trazabilidad, pero nunca
 * se vuelve a sumar al total.
 */
class QuoteService
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $companyId, int $actorId, array $data): Quote
    {
        return DB::transaction(function () use ($companyId, $actorId, $data) {
            $items = $this->normalizeItems($data['items'] ?? []);
            $totals = $this->computeTotals($items);

            $quote = new Quote;
            $quote->forceFill([
                'company_id' => $companyId,
                'institution_id' => $data['institution_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'opportunity_id' => $data['opportunity_id'] ?? null,
                'tender_id' => $data['tender_id'] ?? null,
                'created_by' => $actorId,
                'prepared_by' => $actorId,
                'number' => $data['number'] ?? $this->nextNumber($companyId),
                'quoted_at' => $data['quoted_at'] ?? now()->toDateString(),
                'purchase_type' => $data['purchase_type'] ?? 'Cotizacion',
                'validity_days' => $data['validity_days'] ?? 30,
                'status' => $data['status'] ?? 'Borrador',
                'outcome' => 'Pendiente',
                'tax_included' => true,
                'pricing_source' => $data['pricing_source'] ?? 'manual',
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'discount' => $totals['discount'],
                'total' => $totals['total'],
                'notes' => $data['notes'] ?? null,
            ])->save();

            $this->storeItems($quote, $items);

            $this->audit->record('cotizacion.creada', $quote, [
                'numero' => $quote->number,
                'total' => $totals['total'],
            ]);

            return $quote->fresh(['items', 'institution', 'company']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Quote $quote, array $data): Quote
    {
        return DB::transaction(function () use ($quote, $data) {
            $items = array_key_exists('items', $data)
                ? $this->normalizeItems($data['items'])
                : null;

            if ($items !== null) {
                $totals = $this->computeTotals($items);
                $quote->forceFill([
                    'subtotal' => $totals['subtotal'],
                    'tax' => $totals['tax'],
                    'discount' => $totals['discount'],
                    'total' => $totals['total'],
                ]);
            }

            foreach (['institution_id', 'contact_id', 'opportunity_id', 'tender_id', 'purchase_type', 'validity_days', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $quote->forceFill([$field => $data[$field]]);
                }
            }

            if (array_key_exists('status', $data) && $quote->isEditable()) {
                $quote->forceFill(['status' => $data['status']]);
            }

            $quote->save();

            if ($items !== null) {
                $this->storeItems($quote, $items);
            }

            $this->audit->record('cotizacion.actualizada', $quote, [
                'numero' => $quote->number,
                'total' => $quote->total,
            ]);

            return $quote->fresh(['items', 'institution', 'company']);
        });
    }

    /**
     * Normaliza las lineas y resuelve el precio desde el inventario cuando la
     * linea referencia un producto de Blessing.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw new RuntimeException('La cotizacion debe incluir al menos una linea.');
        }

        $normalized = [];

        foreach ($items as $item) {
            $quantity = max((float) ($item['quantity'] ?? 1), 0.01);
            $inventoryItem = null;

            if (! empty($item['inventory_item_id'])) {
                $inventoryItem = InventoryItem::query()->find($item['inventory_item_id']);
            } elseif (! empty($item['sku'])) {
                $inventoryItem = InventoryItem::query()->where('sku', $item['sku'])->first();
            }

            $unitPrice = $item['unit_price'] ?? null;
            $taxRate = $item['tax_rate'] ?? null;
            $pricingSource = $item['pricing_source'] ?? 'manual';

            if ($unitPrice === null && $inventoryItem) {
                $unitPrice = $inventoryItem->salePrice();
                $taxRate = $inventoryItem->tax_rate;
                $pricingSource = 'blessing';
            }

            if ($unitPrice === null) {
                $unitPrice = $this->priceFromCost(
                    (float) ($item['unit_cost'] ?? 0),
                    (float) ($item['margin'] ?? 0),
                );
                $pricingSource = 'manual';
            }

            $unitPrice = round((float) $unitPrice, 2);
            $total = round($quantity * $unitPrice, 2);

            $normalized[] = [
                'description' => (string) ($item['description'] ?? 'Sin descripcion'),
                'quantity' => $quantity,
                'unit' => (string) ($item['unit'] ?? $inventoryItem?->unit ?? 'Unidad'),
                'unit_cost' => round((float) ($item['unit_cost'] ?? $inventoryItem?->cost ?? 0), 2),
                'margin' => (float) ($item['margin'] ?? 0),
                'unit_price' => $unitPrice,
                'total' => $total,
                'tax_rate' => $taxRate,
                'pricing_source' => $pricingSource,
                'inventory_item_id' => $inventoryItem?->id,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, tax: float, discount: float, total: float}
     */
    public function computeTotals(array $items): array
    {
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['total'];
            $tax += $this->taxIncludedIn((float) $item['total'], $item['tax_rate'] ?? null);
        }

        $subtotal = round($subtotal, 2);
        $tax = round($tax, 2);

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => 0.0,
            'total' => $subtotal,
        ];
    }

    /**
     * Impuesto contenido en un precio final con tasa incluida.
     */
    protected function taxIncludedIn(float $total, mixed $taxRate): float
    {
        $rate = (float) ($taxRate ?? 0);

        if ($rate <= 0) {
            return 0.0;
        }

        $base = $total / (1 + ($rate / 100));

        return round($total - $base, 2);
    }

    protected function priceFromCost(float $cost, float $margin): float
    {
        if ($margin <= 0 || $margin >= 100) {
            return round($cost, 2);
        }

        return round($cost / (1 - ($margin / 100)), 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function storeItems(Quote $quote, array $items): void
    {
        $quote->items()->delete();

        foreach ($items as $item) {
            QuoteItem::create(['quote_id' => $quote->id] + $item);
        }
    }

    /**
     * Consecutivo seguro ante peticiones simultaneas.
     */
    /**
     * Siguiente número de la EMPRESA: la numeración es única por empresa
     * (índice `uq_quotes_company_id_number`), no global.
     */
    protected function nextNumber(?int $companyId): string
    {
        $year = now()->year;
        $prefix = "COT-{$year}-";
        $numbers = fn () => Quote::withTrashed()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId);

        $last = $numbers()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->lockForUpdate()
            ->value('number');

        $sequence = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        do {
            $candidate = $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $sequence++;
        } while ($numbers()->where('number', $candidate)->exists());

        return $candidate;
    }
}
