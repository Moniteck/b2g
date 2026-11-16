<?php

namespace App\Services\Blessing;

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryPriceHistory;
use App\Models\InventorySyncRun;
use App\Models\User;
use App\Services\Pricing\TaxRules;
use App\Services\System\AuditLogger;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincroniza el inventario de una empresa con Blessing.
 *
 * Reglas:
 *  - Identidad por (empresa, id de Blessing). Un producto sin id de Blessing con
 *    el mismo SKU (cargado a mano antes) se adopta.
 *  - Se descarga el catálogo COMPLETO antes de tocar la base: si la API falla a
 *    mitad, la corrida queda «Fallida» y el inventario anterior no cambia.
 *  - Un catálogo vacío con productos activos se trata como fallo (protege de
 *    una respuesta defectuosa que daría de baja todo el inventario).
 *  - Lo que ya no viene (y vino antes de Blessing) se marca inactivo, no se borra.
 *  - Un producto inválido no detiene la corrida: se anota en `errors` y la
 *    corrida queda «Parcial».
 *  - Una sola corrida a la vez por empresa (bloqueo de caché + corrida «En curso»).
 *  - El impuesto de cada producto lo decide TaxRules de la empresa.
 */
class InventorySyncService
{
    /** Segundos que dura el bloqueo; una corrida «En curso» más vieja se da por interrumpida. */
    public const LOCK_SECONDS = 900;

    public const MAX_LOGGED_ERRORS = 50;

    /** Campos cuyo cambio se guarda en el historial de precios. */
    private const PRICE_FIELDS = ['cost', 'suggested_price', 'tax_rate', 'tax_included'];

    /** Campos que no cuentan como «cambio» del producto. */
    private const IGNORED_FIELDS = ['synced_at', 'raw'];

    public function __construct(
        private BlessingClientFactory $factory,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws BlessingException si la empresa no tiene conexión o ya hay una corrida en curso.
     */
    public function sync(Company $company, string $trigger = InventorySyncRun::TRIGGER_MANUAL, ?User $user = null, ?BlessingClient $client = null): InventorySyncRun
    {
        $client ??= $this->factory->forCompany($company);

        if (! $client->isConfigured()) {
            throw BlessingException::notConfigured();
        }

        $lock = Cache::lock($this->lockKey($company), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw BlessingException::alreadyRunning();
        }

        try {
            return $this->runLocked($company, $trigger, $user, $client);
        } finally {
            $this->release($lock);
        }
    }

    public function isRunning(Company $company): bool
    {
        return $this->runs($company)
            ->where('status', InventorySyncRun::RUNNING)
            ->where('started_at', '>=', now()->subSeconds(self::LOCK_SECONDS))
            ->exists();
    }

    private function runLocked(Company $company, string $trigger, ?User $user, BlessingClient $client): InventorySyncRun
    {
        if ($this->isRunning($company)) {
            throw BlessingException::alreadyRunning();
        }

        // Corridas que quedaron «En curso» por un proceso caído.
        $this->runs($company)->where('status', InventorySyncRun::RUNNING)->update([
            'status' => InventorySyncRun::FAILED,
            'finished_at' => now(),
            'error' => 'La corrida se interrumpió antes de terminar.',
        ]);

        $run = new InventorySyncRun;
        $run->forceFill([
            'company_id' => $company->id,
            'user_id' => $user?->id,
            'status' => InventorySyncRun::RUNNING,
            'trigger' => $trigger,
            'source' => $client->source(),
            'started_at' => now(),
        ])->save();

        try {
            $products = $this->download($client);
        } catch (BlessingException $error) {
            return $this->fail($run, $error->getMessage());
        } catch (Throwable $error) {
            Log::error('Fallo inesperado al leer el catálogo de Blessing.', ['company_id' => $company->id, 'error' => $error->getMessage()]);

            return $this->fail($run, 'Fallo inesperado al leer el catálogo de Blessing. Se conserva el inventario anterior.');
        }

        if ($products === [] && $this->items($company)->where('is_active', true)->exists()) {
            return $this->fail($run, 'Blessing devolvió un catálogo vacío; se conserva el inventario anterior por seguridad.');
        }

        try {
            $counts = DB::transaction(fn () => $this->apply($company, $products, $client->source() === 'demo'));
        } catch (Throwable $error) {
            Log::error('Fallo al guardar el inventario de Blessing.', ['company_id' => $company->id, 'error' => $error->getMessage()]);

            return $this->fail($run, 'No fue posible guardar el inventario; se conserva el anterior.');
        }

        $run->forceFill([
            'status' => $counts['errors'] === [] ? InventorySyncRun::COMPLETED : InventorySyncRun::PARTIAL,
            'finished_at' => now(),
            'items_processed' => count($products),
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'deactivated_count' => $counts['deactivated'],
            'error_count' => count($counts['errors']),
            'errors' => $counts['errors'] === [] ? null : array_slice($counts['errors'], 0, self::MAX_LOGGED_ERRORS),
            'error' => $counts['errors'] === [] ? null : 'Algunos productos no se pudieron guardar.',
        ])->save();

        $this->audit->record('inventario.sincronizado', $run, [
            'estado' => $run->status,
            'altas' => $run->created_count,
            'cambios' => $run->updated_count,
            'bajas' => $run->deactivated_count,
            'errores' => $run->error_count,
        ], $user, $company->id);

        return $run->fresh('user');
    }

    /**
     * Descarga todo el catálogo a memoria antes de escribir nada.
     *
     * @return array<int, BlessingProduct>
     */
    private function download(BlessingClient $client): array
    {
        $products = [];

        foreach ($client->products() as $product) {
            if (! $product instanceof BlessingProduct) {
                throw new BlessingException('El conector devolvió un producto con formato inesperado.');
            }

            $products[] = $product;
        }

        return $products;
    }

    /**
     * @param  array<int, BlessingProduct>  $products
     * @return array{created: int, updated: int, deactivated: int, errors: array<int, array<string, string>>}
     */
    private function apply(Company $company, array $products, bool $demo): array
    {
        $rules = TaxRules::forCompany($company);
        $now = now();
        $counts = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'errors' => []];

        /** @var Collection<int, InventoryItem> $existing */
        $existing = $this->items($company)->get();
        $byId = $existing->filter(fn (InventoryItem $item) => $item->blessing_id !== null)->keyBy('blessing_id');
        $bySku = $existing->keyBy(fn (InventoryItem $item) => mb_strtolower($item->sku));

        $feedIds = [];
        foreach ($products as $product) {
            $feedIds[$product->id] = true;
        }

        $seenIds = [];
        $seenSkus = [];
        $untouched = [];

        foreach ($products as $product) {
            $error = function (string $message) use (&$counts, $product): void {
                $counts['errors'][] = ['id' => $product->id, 'sku' => $product->sku, 'message' => $message];
            };

            if ($problems = $product->problems()) {
                $error('Producto inválido: '.implode(', ', $problems).'.');

                continue;
            }

            if (isset($seenIds[$product->id])) {
                $error('El id de Blessing viene repetido en el catálogo.');

                continue;
            }

            $skuKey = mb_strtolower($product->sku);

            if (isset($seenSkus[$skuKey])) {
                $error("El SKU {$product->sku} viene repetido en el catálogo con otro producto.");

                continue;
            }

            $seenIds[$product->id] = true;
            $seenSkus[$skuKey] = true;

            $item = $byId->get($product->id);
            $holder = $bySku->get($skuKey);

            if ($holder !== null && $holder->isNot($item)) {
                $holderStillInFeed = $holder->blessing_id !== null && isset($feedIds[$holder->blessing_id]);

                if ($item === null && ! $holderStillInFeed) {
                    // Producto cargado antes sin id de Blessing, o al que Blessing le cambió el id: se adopta.
                    $item = $holder;
                } else {
                    $error("El SKU {$product->sku} ya pertenece a otro producto del inventario.");

                    continue;
                }
            }

            $isNew = $item === null;
            $item ??= new InventoryItem;
            $wasActive = $isNew ? false : (bool) $item->is_active;

            $item->forceFill([
                'company_id' => $company->id,
                'blessing_id' => $product->id,
                'sku' => $product->sku,
                'description' => mb_substr($product->description, 0, 255),
                'category' => $product->category !== null ? mb_substr($product->category, 0, 255) : null,
                'brand' => $product->brand !== null ? mb_substr($product->brand, 0, 255) : null,
                'unit' => $product->unit ?: 'Unidad',
                'stock' => $product->stock ?? 0,
                'stock_available' => $product->stockAvailable ?? $product->stock,
                'cost' => $product->cost ?? 0,
                'suggested_price' => $product->suggestedPrice,
                'tax_rate' => $rules->rateFor($product->taxRate),
                'tax_included' => $rules->includesTax($product->taxIncluded),
                'currency' => $product->currency,
                'is_active' => $product->active,
                'is_demo' => $demo,
                'deactivated_at' => $product->active ? null : ($item->deactivated_at ?? $now),
            ]);

            $changed = $isNew || array_diff(array_keys($item->getDirty()), self::IGNORED_FIELDS) !== [];
            $priceChanged = $isNew || $item->isDirty(self::PRICE_FIELDS);

            if (! $changed) {
                $untouched[] = $item->id;

                continue;
            }

            $item->forceFill(['synced_at' => $now, 'raw' => $product->raw ?: null]);

            try {
                $item->save();
            } catch (QueryException $exception) {
                $error('No se pudo guardar el producto en la base de datos.');
                Log::warning('Producto de Blessing no guardado.', ['company_id' => $company->id, 'blessing_id' => $product->id, 'error' => $exception->getMessage()]);

                continue;
            }

            $bySku->put($skuKey, $item);
            $byId->put($product->id, $item);

            if ($isNew) {
                $counts['created']++;
            } else {
                $counts['updated']++;
            }

            if ($wasActive && ! $product->active) {
                $counts['deactivated']++;
            }

            if ($priceChanged) {
                InventoryPriceHistory::query()->create([
                    'company_id' => $company->id,
                    'inventory_item_id' => $item->id,
                    'cost' => $item->cost,
                    'suggested_price' => $item->suggested_price,
                    'tax_rate' => $item->tax_rate,
                    'tax_included' => $item->tax_included,
                    'recorded_at' => $now,
                ]);
            }
        }

        foreach (array_chunk($untouched, 500) as $ids) {
            $this->items($company)->whereKey($ids)->update(['synced_at' => $now]);
        }

        // Bajas: lo que vino antes de Blessing y ya no viene.
        $gone = $existing
            ->filter(fn (InventoryItem $item) => $item->is_active && $item->blessing_id !== null && ! isset($feedIds[$item->blessing_id]))
            ->pluck('id')
            ->all();

        foreach (array_chunk($gone, 500) as $ids) {
            $counts['deactivated'] += $this->items($company)->whereKey($ids)->update([
                'is_active' => false,
                'deactivated_at' => $now,
                'synced_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $counts;
    }

    private function fail(InventorySyncRun $run, string $message): InventorySyncRun
    {
        $run->forceFill([
            'status' => InventorySyncRun::FAILED,
            'finished_at' => now(),
            'error' => $message,
        ])->save();

        $this->audit->record('inventario.sincronizacion_fallida', $run, ['error' => $message], null, $run->company_id);

        return $run->fresh('user');
    }

    /**
     * @return Builder<InventoryItem>
     */
    private function items(Company $company)
    {
        return InventoryItem::query()->withoutGlobalScopes()->where('company_id', $company->id);
    }

    /**
     * @return Builder<InventorySyncRun>
     */
    private function runs(Company $company)
    {
        return InventorySyncRun::query()->withoutGlobalScopes()->where('company_id', $company->id);
    }

    private function lockKey(Company $company): string
    {
        return "inventario:blessing:sync:{$company->id}";
    }

    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // El bloqueo vence solo.
        }
    }
}
