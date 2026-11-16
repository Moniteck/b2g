<?php

namespace App\Services\Business;

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Scopes\CompanyScope;
use App\Models\Tender;
use App\Models\User;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PriceDocument;
use App\Services\Pricing\TaxRules;
use App\Services\System\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de la cotización: alta, edición, líneas con precio e ISV, numeración y estado documental.
 *
 * Todo el cálculo de precios pasa por PriceCalculator (el mismo que inventario, calculadora de
 * oferta y MCP). Cada línea guarda su precio en la base en que se capturó (`tax_included`) y su
 * subtotal sin ISV, su impuesto y su total. El documento suma las líneas ya redondeadas.
 * Si la empresa vende con precios que incluyen el ISV lo decide TaxRules::forCompany().
 */
class QuoteService
{
    public function __construct(
        protected AuditLogger $audit,
        protected PriceCalculator $calculator,
        protected AdjudicationService $adjudication,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $companyId, int $actorId, array $data): Quote
    {
        return DB::transaction(function () use ($companyId, $actorId, $data) {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->first();
            $rules = TaxRules::forCompany($company);
            $taxIncluded = array_key_exists('tax_included', $data) && $data['tax_included'] !== null
                ? (bool) $data['tax_included']
                : $rules->includesTax();

            $items = $this->normalizeItems($data['items'] ?? [], $taxIncluded, $rules);
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
                'number' => $this->nextNumber($companyId),
                'quoted_at' => filled($data['quoted_at'] ?? null) ? $data['quoted_at'] : now()->toDateString(),
                'purchase_type' => filled($data['purchase_type'] ?? null) ? $data['purchase_type'] : 'Cotización',
                'validity_days' => $data['validity_days'] ?? 30,
                'status' => 'Borrador',
                'outcome' => 'Pendiente',
                'tax_included' => $taxIncluded,
                'pricing_source' => $this->documentSource($items),
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'discount' => $totals['discount'],
                'total' => $totals['total'],
                'conditions' => $data['conditions'] ?? null,
                'notes' => $data['notes'] ?? null,
            ])->save();

            $this->storeItems($quote, $items);

            $this->audit->record('cotizacion.creada', $quote, [
                'numero' => $quote->number,
                'lineas' => count($items),
                'total' => $totals['total'],
            ], companyId: $companyId);

            return $quote->fresh(['items', 'institution', 'company']);
        });
    }

    /**
     * Edición de datos y líneas. Solo en Borrador o Emitida y sin resultado (lo valida la policy;
     * aquí se vuelve a comprobar bajo bloqueo por si cambió entre tanto).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Quote $quote, array $data): Quote
    {
        return DB::transaction(function () use ($quote, $data) {
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['status' => "La cotización {$locked->number} está {$locked->status} y ya no se puede editar."]);
            }

            $rules = TaxRules::forCompany(Company::query()->find($locked->company_id));
            $taxIncluded = array_key_exists('tax_included', $data) && $data['tax_included'] !== null
                ? (bool) $data['tax_included']
                : (bool) $locked->tax_included;

            $items = array_key_exists('items', $data) ? $this->normalizeItems($this->keepCosts($locked, (array) $data['items']), $taxIncluded, $rules) : null;

            foreach (['institution_id', 'contact_id', 'opportunity_id', 'tender_id', 'purchase_type', 'validity_days', 'quoted_at', 'conditions', 'notes'] as $field) {
                $required = in_array($field, ['quoted_at', 'purchase_type', 'validity_days'], true);
                if (array_key_exists($field, $data) && ! ($required && blank($data[$field]))) {
                    $locked->forceFill([$field => $data[$field]]);
                }
            }

            $locked->forceFill(['tax_included' => $taxIncluded]);

            if ($items !== null) {
                $totals = $this->computeTotals($items);
                $locked->forceFill([
                    'subtotal' => $totals['subtotal'],
                    'tax' => $totals['tax'],
                    'discount' => $totals['discount'],
                    'total' => $totals['total'],
                    'pricing_source' => $this->documentSource($items),
                ]);
            }

            $changed = array_keys($locked->getDirty());
            $locked->save();

            if ($items !== null) {
                $this->storeItems($locked, $items);
            }

            $this->audit->record('cotizacion.actualizada', $locked, [
                'numero' => $locked->number,
                'campos' => $changed,
                'total' => (float) $locked->total,
            ]);

            return $locked->fresh(['items', 'institution', 'company']);
        });
    }

    /**
     * Cambio del estado documental con sus reglas (Quote::TRANSITIONS).
     *
     * Presentada exige «nuestra oferta» entre los participantes (si no está, se crea con el total),
     * marca la licitación como presentada y lleva la oportunidad a Oferta Presentada (decisión 13).
     */
    public function changeStatus(Quote $quote, string $status, ?User $actor = null): Quote
    {
        return DB::transaction(function () use ($quote, $status, $actor) {
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from === $status) {
                return $locked;
            }

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['status' => "La cotización ya está {$locked->outcome}: revierta el resultado antes de cambiar su estado."]);
            }

            if (! in_array($status, Quote::TRANSITIONS[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "No se puede pasar de {$from} a {$status}."]);
            }

            $locked->forceFill(['status' => $status])->save();

            if ($status === 'Presentada') {
                $this->adjudication->ensureOurOffer($locked);
                $this->markPresented($locked);
            }

            $this->audit->record('cotizacion.estado', $locked, [
                'numero' => $locked->number,
                'de' => $from,
                'a' => $status,
            ], $actor);

            return $locked->fresh();
        });
    }

    public function delete(Quote $quote, ?User $actor = null): void
    {
        DB::transaction(function () use ($quote, $actor) {
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'Borrador' || ! $locked->isPending()) {
                throw ValidationException::withMessages(['status' => 'Solo se elimina una cotización en Borrador. Anúlela si ya se emitió.']);
            }

            $locked->delete();

            $this->audit->record('cotizacion.eliminada', $locked, ['numero' => $locked->number], $actor);
        });
    }

    /**
     * Normaliza las líneas y calcula cada una con la regla única.
     *
     * - Con producto del inventario y sin precio escrito, el precio sale de InventoryPricing
     *   (precio sugerido en su propia base de ISV).
     * - Sin precio, se calcula desde costo y margen sobre la venta (precio sin ISV).
     * - Una línea escrita a mano usa la base de la cotización (`$taxIncluded`).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function normalizeItems(array $items, bool $taxIncluded = false, ?TaxRules $rules = null): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'La cotización debe incluir al menos una línea.']);
        }

        $rules ??= TaxRules::defaults();
        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $quantity = max((float) ($item['quantity'] ?? 1), 0.01);
            $inventoryItem = null;

            if (! empty($item['inventory_item_id'])) {
                $inventoryItem = InventoryItem::query()->find((int) $item['inventory_item_id']);
            } elseif (! empty($item['sku'])) {
                $inventoryItem = InventoryItem::query()->where('sku', $item['sku'])->first();
            }

            $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== '' ? (float) $item['unit_price'] : null;
            $lineIncluded = isset($item['tax_included']) ? (bool) $item['tax_included'] : $taxIncluded;
            $taxRate = isset($item['tax_rate']) && $item['tax_rate'] !== '' ? (float) $item['tax_rate'] : null;
            $source = $item['pricing_source'] ?? 'manual';

            if ($unitPrice === null && $inventoryItem !== null) {
                $line = $inventoryItem->pricing($quantity);
                $unitPrice = $line->sourceUnitPrice;
                $lineIncluded = $line->taxIncluded;
                $taxRate ??= $line->nominalRate;
                $source = 'blessing';
            } elseif ($inventoryItem !== null && $source === 'manual') {
                $source = 'blessing';
            }

            if ($unitPrice === null) {
                $unitPrice = $this->priceFromCost((float) ($item['unit_cost'] ?? 0), (float) ($item['margin'] ?? 0));
                $lineIncluded = false;
                $source = 'calculadora';
            }

            $taxRate ??= $inventoryItem?->tax_rate !== null ? (float) $inventoryItem->tax_rate : $rules->rate;
            $discountPercent = (float) ($item['discount_percent'] ?? 0);

            $priced = $this->calculator->line(
                unitPrice: $unitPrice,
                quantity: $quantity,
                taxRate: $taxRate,
                taxIncluded: $lineIncluded,
                discountPercent: $discountPercent,
            );

            $normalized[] = [
                'position' => $index + 1,
                'description' => trim((string) ($item['description'] ?? $inventoryItem?->description ?? 'Sin descripción')),
                'quantity' => $quantity,
                'unit' => (string) ($item['unit'] ?? $inventoryItem?->unit ?? 'Unidad'),
                'unit_cost' => PriceCalculator::round((float) ($item['unit_cost'] ?? $inventoryItem?->cost ?? 0)),
                'margin' => (float) ($item['margin'] ?? 0),
                'unit_price' => $priced->sourceUnitPrice,
                'discount_percent' => $discountPercent,
                'tax_rate' => $taxRate,
                'tax_included' => $lineIncluded,
                'subtotal' => $priced->subtotal,
                'tax' => $priced->tax,
                'total' => $priced->total,
                'pricing_source' => $source,
                'inventory_item_id' => $inventoryItem?->id,
                'sku' => $item['sku'] ?? $inventoryItem?->sku,
            ];
        }

        return $normalized;
    }

    /**
     * Al editar, una línea existente que no trae `unit_cost` o `margin` conserva los suyos (el lector
     * no los recibe, y el formulario o MCP pueden omitirlos): sin esto se perdería un costo escrito a mano.
     * La línea se reconoce por su `id` y, si no lo trae, por producto de inventario, SKU o descripción.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function keepCosts(Quote $quote, array $items): array
    {
        $existing = $quote->items()->get(['id', 'description', 'inventory_item_id', 'sku', 'unit_cost', 'margin'])->keyBy('id');
        $missing = fn (array $item, string $key) => ! array_key_exists($key, $item) || $item[$key] === null || $item[$key] === '';

        $result = [];
        foreach (array_values($items) as $index => $item) {
            $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : null;
            $match = $id !== null ? $existing->get($id) : null;

            if ($match === null && ($missing($item, 'unit_cost') || $missing($item, 'margin'))) {
                $description = mb_strtolower(trim((string) ($item['description'] ?? '')));
                $match = $existing->first(fn (QuoteItem $line) => (! empty($item['inventory_item_id']) && (int) $line->inventory_item_id === (int) $item['inventory_item_id'])
                    || (! empty($item['sku']) && $line->sku === $item['sku'])
                    || ($description !== '' && mb_strtolower(trim((string) $line->description)) === $description));
            }

            if ($match !== null) {
                $existing->forget($match->id);
                if ($missing($item, 'unit_cost')) {
                    $item['unit_cost'] = (float) $match->unit_cost;
                }
                if ($missing($item, 'margin')) {
                    $item['margin'] = (float) $match->margin;
                }
            }

            $result[$index] = $item;
        }

        return $result;
    }

    /**
     * Totales del documento: suma exacta de las líneas (PriceCalculator::document()).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, tax: float, discount: float, total: float, taxes: array<int, array{rate: float, base: float, tax: float}>}
     */
    public function computeTotals(array $items): array
    {
        $document = $this->document($items);

        return [
            'subtotal' => $document->subtotal,
            'tax' => $document->tax,
            'discount' => $document->discount,
            'total' => $document->total,
            'taxes' => $document->taxes,
        ];
    }

    /**
     * @param  iterable<array<string, mixed>|QuoteItem>  $items
     */
    public function document(iterable $items): PriceDocument
    {
        $lines = [];

        foreach ($items as $item) {
            $lines[] = $item instanceof QuoteItem
                ? $item->priced()
                : $this->calculator->line(
                    unitPrice: (float) $item['unit_price'],
                    quantity: (float) $item['quantity'],
                    taxRate: (float) ($item['tax_rate'] ?? 0),
                    taxIncluded: (bool) ($item['tax_included'] ?? false),
                    discountPercent: (float) ($item['discount_percent'] ?? 0),
                );
        }

        return $this->calculator->document($lines, PriceCalculator::DEFAULT_CURRENCY);
    }

    protected function priceFromCost(float $cost, float $margin): float
    {
        return $this->calculator->priceFromMargin(max($cost, 0), min(max($margin, 0), 95));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function documentSource(array $items): string
    {
        $sources = array_unique(array_column($items, 'pricing_source'));

        return count($sources) === 1 ? (string) $sources[0] : 'mixto';
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
     * Presentar la cotización mueve su licitación y su oportunidad (decisión 13).
     */
    protected function markPresented(Quote $quote): void
    {
        if ($quote->tender_id !== null && ($tender = Tender::query()->find($quote->tender_id))) {
            $tender->forceFill([
                'offer_submitted' => true,
                'status' => in_array($tender->status, Tender::OPEN_STATUSES, true) ? 'Presentada' : $tender->status,
            ])->save();
        }

        if ($quote->opportunity_id !== null && ($opportunity = Opportunity::query()->find($quote->opportunity_id))) {
            if (in_array($opportunity->stage, ['prospecto', 'oportunidad'], true)) {
                $opportunity->forceFill(['stage' => 'oferta_presentada', 'quote_id' => $quote->id])->save();
            }
        }
    }

    /**
     * Siguiente número de la EMPRESA (`uq_quotes_company_id_number`): COT-AAAA-NNNN.
     *
     * Se llama dentro de la transacción de create(), que ya bloqueó la fila de la empresa: así dos
     * altas simultáneas se ordenan aunque la tabla de cotizaciones esté vacía.
     */
    protected function nextNumber(int $companyId): string
    {
        $prefix = 'COT-'.now()->year.'-';
        $numbers = fn () => Quote::withTrashed()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId);

        $sequence = (int) $numbers()
            ->where('number', 'like', $prefix.'%')
            ->pluck('number')
            ->map(fn ($number) => (int) substr((string) $number, strlen($prefix)))
            ->max() + 1;

        do {
            $candidate = $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $sequence++;
        } while ($numbers()->where('number', $candidate)->exists());

        return $candidate;
    }
}
