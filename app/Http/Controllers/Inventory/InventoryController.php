<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryPriceHistory;
use App\Models\InventorySyncRun;
use App\Services\Blessing\BlessingClientFactory;
use App\Services\Blessing\BlessingException;
use App\Services\Blessing\InventorySyncService;
use App\Services\Pricing\InventoryPricing;
use App\Services\Pricing\TaxRules;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inventario Blessing: listado con precio sugerido, ISV y total por cantidad,
 * detalle de producto, búsqueda para otros módulos y sincronización.
 */
class InventoryController extends Controller
{
    /** Horas tras las cuales el dato se considera desactualizado (la tarea corre cada hora). */
    public const STALE_AFTER_HOURS = 3;

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const STOCK_FILTERS = ['all', 'in', 'out', 'inactive'];

    public function __construct(
        private CompanyContext $context,
        private InventoryPricing $pricing,
        private BlessingClientFactory $factory,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InventoryItem::class);

        $user = $request->user();
        $canSeeCost = $user->can('viewCost', InventoryItem::class);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'category' => trim((string) $request->query('category', '')),
            'stock' => in_array($request->query('stock'), self::STOCK_FILTERS, true) ? $request->query('stock') : 'all',
            'per_page' => in_array((int) $request->query('per_page'), self::PER_PAGE_OPTIONS, true) ? (int) $request->query('per_page') : 25,
        ];

        $columns = ['id', 'company_id', 'blessing_id', 'sku', 'description', 'category', 'brand', 'unit', 'stock',
            'stock_available', 'cost', 'price', 'suggested_price', 'tax_rate', 'tax_included', 'currency',
            'is_active', 'is_demo', 'synced_at'];

        $items = InventoryItem::query()
            ->select($columns)
            ->when($filters['stock'] === 'inactive', fn ($q) => $q->where('is_active', false), fn ($q) => $q->where('is_active', true))
            ->when($filters['stock'] === 'in', fn ($q) => $q->where('stock_available', '>', 0))
            ->when($filters['stock'] === 'out', fn ($q) => $q->where(fn ($w) => $w->where('stock_available', '<=', 0)->orWhereNull('stock_available')))
            ->when($filters['category'] !== '', fn ($q) => $q->where('category', $filters['category']))
            ->search($filters['q'])
            ->orderBy('sku')
            ->paginate($filters['per_page'])
            ->withQueryString()
            ->through(fn (InventoryItem $item) => $this->row($item, $canSeeCost));

        $company = $this->company();

        return Inertia::render('Inventory/Index', [
            'items' => $items,
            'filters' => $filters,
            'categories' => InventoryItem::query()->where('is_active', true)->whereNotNull('category')
                ->distinct()->orderBy('category')->pluck('category'),
            'stats' => $this->stats(),
            'connection' => $this->connection($company),
            'tax' => TaxRules::forCompany($company)->toArray(),
            'can' => [
                'sync' => $user->can('sync', InventoryItem::class),
                'view_cost' => $canSeeCost,
                'price' => $user->can('price', InventoryItem::class),
            ],
            'runs' => Inertia::optional(fn () => $this->recentRuns()),
        ]);
    }

    /**
     * Detalle de un producto para la modal (JSON).
     */
    public function show(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        $this->authorize('view', $inventoryItem);

        $canSeeCost = $request->user()->can('viewCost', InventoryItem::class);

        $history = InventoryPriceHistory::query()
            ->where('inventory_item_id', $inventoryItem->id)
            ->latest('recorded_at')
            ->limit(20)
            ->get(['cost', 'suggested_price', 'tax_rate', 'tax_included', 'recorded_at'])
            ->map(fn (InventoryPriceHistory $entry) => array_filter([
                'suggested_price' => $entry->suggested_price !== null ? (float) $entry->suggested_price : null,
                'cost' => $canSeeCost && $entry->cost !== null ? (float) $entry->cost : null,
                'tax_rate' => $entry->tax_rate !== null ? (float) $entry->tax_rate : null,
                'tax_included' => $entry->tax_included,
                'recorded_at' => $entry->recorded_at?->format('Y-m-d H:i:s'),
            ], fn ($value) => $value !== null));

        $lastRun = InventorySyncRun::query()->whereIn('status', [InventorySyncRun::COMPLETED, InventorySyncRun::PARTIAL])
            ->latest('started_at')->first();

        return response()->json([
            'item' => $this->row($inventoryItem, $canSeeCost) + [
                'stock_total' => (float) $inventoryItem->stock,
                'deactivated_at' => $inventoryItem->deactivated_at?->format('Y-m-d H:i:s'),
                'updated_at' => $inventoryItem->updated_at?->format('Y-m-d H:i:s'),
            ],
            'history' => $history,
            'last_sync' => $lastRun?->summary(),
        ]);
    }

    /**
     * Búsqueda para `SearchSelect` de otros módulos (cotizaciones, calculadora):
     * GET /inventario/buscar?q=texto → { data: [ { id, sku, description, … } ] }.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $canSeeCost = $request->user()->can('viewCost', InventoryItem::class);
        $term = trim((string) $request->query('q', ''));

        $items = InventoryItem::query()
            ->select(['id', 'company_id', 'sku', 'description', 'brand', 'unit', 'stock', 'stock_available', 'cost', 'price', 'suggested_price', 'tax_rate', 'tax_included', 'currency'])
            ->where('is_active', true)
            ->search($term)
            ->orderByRaw('CASE WHEN sku = ? THEN 0 ELSE 1 END', [$term])
            ->orderBy('sku')
            ->limit((int) $request->query('limit', 20))
            ->get();

        return response()->json([
            'data' => $items->map(function (InventoryItem $item) use ($canSeeCost) {
                $line = $this->pricing->line($item, 1);

                return array_filter([
                    'id' => $item->id,
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'label' => "{$item->sku} · {$item->description}",
                    'brand' => $item->brand,
                    'unit' => $item->unit,
                    'stock' => $item->availableStock(),
                    'suggested_price' => $this->pricing->sourcePrice($item),
                    'unit_price' => $line->unitPrice,
                    'unit_total' => $line->unitTotal,
                    'tax_rate' => $this->pricing->taxRate($item),
                    'tax_included' => (bool) $item->tax_included,
                    'currency' => $item->currency ?: 'HNL',
                    'cost' => $canSeeCost ? (float) $item->cost : null,
                ], fn ($value) => $value !== null);
            })->values(),
        ]);
    }

    /**
     * «Sincronizar ahora»: corre en la petición para mostrar el resultado al instante.
     */
    public function sync(Request $request, InventorySyncService $service): JsonResponse
    {
        $this->authorize('sync', InventoryItem::class);

        $company = $this->company();

        if ($company === null) {
            return response()->json(['message' => 'Selecciona una empresa activa.'], 422);
        }

        try {
            $run = $service->sync($company, InventorySyncRun::TRIGGER_MANUAL, $request->user());
        } catch (BlessingException $error) {
            return response()->json(['message' => $error->getMessage()], $error->getCode() === 409 ? 409 : 422);
        }

        return response()->json(['run' => $run->summary()]);
    }

    /**
     * Historial de corridas (JSON para la modal).
     */
    public function runs(): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        return response()->json(['data' => $this->recentRuns()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(InventoryItem $item, bool $withCost): array
    {
        $line = $this->pricing->line($item, 1);

        return [
            'id' => $item->id,
            'blessing_id' => $item->blessing_id,
            'sku' => $item->sku,
            'description' => $item->description,
            'category' => $item->category,
            'brand' => $item->brand,
            'unit' => $item->unit,
            'stock' => $item->availableStock(),
            'suggested_price' => $this->pricing->sourcePrice($item),
            'unit_price' => $line->unitPrice,
            'unit_tax' => $line->unitTax,
            'unit_total' => $line->unitTotal,
            'tax_rate' => $this->pricing->taxRate($item),
            'tax_included' => (bool) $item->tax_included,
            'currency' => $item->currency ?: 'HNL',
            'is_active' => (bool) $item->is_active,
            'is_demo' => (bool) $item->is_demo,
            'synced_at' => $item->synced_at?->format('Y-m-d H:i:s'),
            'cost' => $withCost ? (float) $item->cost : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $counts = InventoryItem::query()
            ->where('is_active', true)
            ->selectRaw('COUNT(*) as active, SUM(CASE WHEN stock_available > 0 THEN 1 ELSE 0 END) as in_stock, SUM(CASE WHEN is_demo = 1 THEN 1 ELSE 0 END) as demo')
            ->first();

        return [
            'active' => (int) ($counts->active ?? 0),
            'in_stock' => (int) ($counts->in_stock ?? 0),
            'demo' => (int) ($counts->demo ?? 0),
            'total' => InventoryItem::query()->count(),
        ];
    }

    /**
     * Estado de la conexión y antigüedad del dato.
     *
     * @return array<string, mixed>
     */
    private function connection(?Company $company): array
    {
        $mode = $company ? $this->factory->modeOf($company) : 'ninguno';
        $lastRun = InventorySyncRun::query()->with('user:id,name')->latest('started_at')->latest('id')->first();
        $lastSuccess = InventorySyncRun::query()
            ->whereIn('status', [InventorySyncRun::COMPLETED, InventorySyncRun::PARTIAL])
            ->latest('finished_at')->value('finished_at')
            ?? InventoryItem::query()->max('synced_at');
        $lastSuccessAt = $lastSuccess ? Carbon::parse($lastSuccess) : null;

        return [
            'mode' => $mode,
            'configured' => $mode !== 'ninguno',
            'last_run' => $lastRun?->summary(),
            'last_success_at' => $lastSuccessAt?->format('Y-m-d H:i:s'),
            'age_hours' => $lastSuccessAt ? round($lastSuccessAt->diffInMinutes(now()) / 60, 1) : null,
            'stale' => $lastSuccessAt !== null && $lastSuccessAt->lt(now()->subHours(self::STALE_AFTER_HOURS)),
            'stale_after_hours' => self::STALE_AFTER_HOURS,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentRuns(): array
    {
        return InventorySyncRun::query()
            ->with('user:id,name')
            ->latest('started_at')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (InventorySyncRun $run) => $run->summary())
            ->all();
    }

    private function company(): ?Company
    {
        $id = $this->context->currentCompanyId();

        return $id ? Company::query()->find($id) : null;
    }
}
