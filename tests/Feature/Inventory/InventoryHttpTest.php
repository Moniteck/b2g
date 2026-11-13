<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventorySyncRun;
use App\Services\Blessing\FakeBlessingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    private function item(array $attributes = [], ?Company $company = null): InventoryItem
    {
        return InventoryItem::factory()->create(['company_id' => ($company ?? $this->company)->id] + $attributes);
    }

    private function as(string $role): static
    {
        [$user] = $this->memberOf($this->company, $role);

        return $this->actingInCompany($user, $this->company);
    }

    /* ── Pantalla ─────────────────────────────────────────────────────────── */

    public function test_viewer_sees_the_inventory_without_cost_or_actions(): void
    {
        $this->item(['sku' => 'LUB-1', 'suggested_price' => 689, 'cost' => 520, 'tax_included' => false]);

        $this->as('viewer')->get('/inventario')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Inventory/Index')
                ->has('items.data', 1)
                ->where('items.data.0.sku', 'LUB-1')
                ->where('items.data.0.suggested_price', 689)
                ->where('items.data.0.unit_total', 792.35)
                ->where('items.data.0.cost', null)
                ->where('can.sync', false)
                ->where('can.view_cost', false)
                ->where('can.price', false)
                ->where('connection.configured', false)
                ->where('tax.prices_include_tax', false)
                ->where('stats.active', 1));
    }

    public function test_operator_sees_cost_and_can_price_but_not_sync(): void
    {
        $this->item(['cost' => 520]);

        $this->as('operator')->get('/inventario')
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.cost', 520)
                ->where('can.view_cost', true)
                ->where('can.price', true)
                ->where('can.sync', false));

        $this->as('operator')->postJson('/inventario/sincronizar')->assertForbidden();
    }

    public function test_filters_search_category_and_stock_on_the_server(): void
    {
        $this->item(['sku' => 'GRA-EP2', 'description' => 'Grasa EP-2', 'brand' => 'Grasol', 'category' => 'Grasas', 'stock_available' => 10]);
        $this->item(['sku' => 'FIL-1', 'description' => 'Filtro de aceite', 'brand' => 'TecnoFilt', 'category' => 'Filtros', 'stock_available' => 0]);
        $this->item(['sku' => 'OLD-1', 'description' => 'Grasa antigua', 'category' => 'Grasas'])->forceFill(['is_active' => false])->save();

        $viewer = $this->as('viewer');

        $viewer->get('/inventario?q=grasol')->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.sku', 'GRA-EP2'));
        $viewer->get('/inventario?q=grasa')->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
        $viewer->get('/inventario?category=Filtros')->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.sku', 'FIL-1'));
        $viewer->get('/inventario?stock=in')->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.sku', 'GRA-EP2'));
        $viewer->get('/inventario?stock=out')->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.sku', 'FIL-1'));
        $viewer->get('/inventario?stock=inactive')->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.sku', 'OLD-1'));
        $viewer->get('/inventario?q=%25')->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
        $viewer->get('/inventario?per_page=9999')->assertInertia(fn (Assert $page) => $page->where('filters.per_page', 25));
    }

    public function test_inventory_is_isolated_by_company(): void
    {
        $other = Company::factory()->create();
        $foreign = $this->item(['sku' => 'AJENO-1', 'description' => 'Producto ajeno'], $other);
        $this->item(['sku' => 'PROPIO-1']);

        $viewer = $this->as('viewer');

        $viewer->get('/inventario')->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.sku', 'PROPIO-1'));
        $viewer->getJson('/inventario/buscar?q=AJENO')->assertOk()->assertJsonCount(0, 'data');
        $this->assertContains($viewer->getJson("/inventario/{$foreign->id}")->status(), [403, 404]);
    }

    public function test_stale_data_and_failed_runs_are_reported(): void
    {
        $this->item(['synced_at' => now()->subHours(6)]);
        InventorySyncRun::query()->forceCreate(['company_id' => $this->company->id, 'status' => 'Completada', 'trigger' => 'programada', 'started_at' => now()->subHours(6), 'finished_at' => now()->subHours(6)]);
        InventorySyncRun::query()->forceCreate(['company_id' => $this->company->id, 'status' => 'Fallida', 'trigger' => 'programada', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'error' => 'Blessing no respondió.']);

        $this->as('viewer')->get('/inventario')->assertInertia(fn (Assert $page) => $page
            ->where('connection.stale', true)
            ->where('connection.last_run.status', 'Fallida')
            ->where('connection.last_run.error', 'Blessing no respondió.'));
    }

    /* ── Detalle, búsqueda e historial ────────────────────────────────────── */

    public function test_detail_returns_the_product_and_its_price_history(): void
    {
        $this->app->instance(FakeBlessingClient::class, new FakeBlessingClient);
        [$admin] = $this->memberOf($this->company, 'admin');
        $this->actingInCompany($admin, $this->company)->postJson('/inventario/sincronizar')->assertOk();
        $item = InventoryItem::query()->withoutGlobalScopes()->where('sku', 'LUB-15W40-G')->first();

        $this->as('viewer')->getJson("/inventario/{$item->id}")
            ->assertOk()
            ->assertJsonPath('item.sku', 'LUB-15W40-G')
            ->assertJsonPath('item.cost', null)
            ->assertJsonPath('history.0.suggested_price', 689)
            ->assertJsonMissingPath('history.0.cost')
            ->assertJsonPath('last_sync.status', 'Completada');
    }

    public function test_search_endpoint_for_other_modules(): void
    {
        $this->item(['sku' => 'LUB-15W40-G', 'description' => 'Aceite 15W-40', 'brand' => 'Lubrimax', 'suggested_price' => 689, 'tax_rate' => 15, 'tax_included' => false, 'stock_available' => 30, 'cost' => 520]);
        $this->item(['sku' => 'LUB-OFF', 'description' => 'Aceite descontinuado'])->forceFill(['is_active' => false])->save();

        $this->as('viewer')->getJson('/inventario/buscar?q=aceite')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'LUB-15W40-G')
            ->assertJsonPath('data.0.suggested_price', 689)
            ->assertJsonPath('data.0.tax_rate', 15)
            ->assertJsonPath('data.0.tax_included', false)
            ->assertJsonPath('data.0.stock', 30)
            ->assertJsonPath('data.0.unit_total', 792.35)
            ->assertJsonMissingPath('data.0.cost');

        $this->as('operator')->getJson('/inventario/buscar?q=lubrimax')->assertJsonPath('data.0.cost', 520);
        $this->as('viewer')->getJson('/inventario/buscar?limit=500')->assertUnprocessable();
    }

    public function test_guests_cannot_reach_the_inventory(): void
    {
        $this->get('/inventario')->assertRedirect();
        $this->getJson('/inventario/buscar?q=a')->assertUnauthorized();
    }

    /* ── Sincronización desde la pantalla ─────────────────────────────────── */

    public function test_admin_syncs_now_and_gets_the_result(): void
    {
        $this->app->instance(FakeBlessingClient::class, new FakeBlessingClient);

        $this->as('admin')->postJson('/inventario/sincronizar')
            ->assertOk()
            ->assertJsonPath('run.status', 'Completada')
            ->assertJsonPath('run.created', 40)
            ->assertJsonPath('run.trigger', 'Manual');

        $this->as('admin')->getJson('/inventario/sincronizaciones')->assertOk()->assertJsonCount(1, 'data');
        $this->as('viewer')->postJson('/inventario/sincronizar')->assertForbidden();
    }

    public function test_sync_without_connection_explains_it(): void
    {
        $this->as('admin')->postJson('/inventario/sincronizar')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La conexión con Blessing está pendiente de configurar para esta empresa.');
    }

    public function test_sync_while_another_run_is_in_progress_returns_409(): void
    {
        $this->app->instance(FakeBlessingClient::class, new FakeBlessingClient);
        InventorySyncRun::query()->forceCreate(['company_id' => $this->company->id, 'status' => InventorySyncRun::RUNNING, 'trigger' => 'programada', 'started_at' => now()]);

        $this->as('admin')->postJson('/inventario/sincronizar')->assertStatus(409);
    }

    /* ── Calculadora de oferta ────────────────────────────────────────────── */

    private function offer(array $overrides = []): array
    {
        return array_replace_recursive([
            'currency' => 'HNL',
            'scenarios' => [10, 20],
            'lines' => [
                ['description' => 'Aceite 15W-40', 'quantity' => 10, 'cost' => 520, 'margin' => 20, 'tax_rate' => 15],
            ],
        ], $overrides);
    }

    public function test_calculator_page_is_for_operators_and_admins(): void
    {
        $this->as('viewer')->get('/calculadora-oferta')->assertForbidden();
        $this->as('viewer')->postJson('/calculadora-oferta/calcular', $this->offer())->assertForbidden();

        $this->as('operator')->get('/calculadora-oferta')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('OfferCalculator/Index')->where('tax.rate', 15));
    }

    public function test_calculator_keeps_cost_margin_and_tax_rate(): void
    {
        $this->as('operator')->postJson('/calculadora-oferta/calcular', $this->offer())
            ->assertOk()
            ->assertJsonPath('lines.0.unit_price', 650)
            ->assertJsonPath('lines.0.cost', 520)
            ->assertJsonPath('lines.0.margin', 20)
            ->assertJsonPath('totals.subtotal', 6500)
            ->assertJsonPath('totals.tax', 975)
            ->assertJsonPath('totals.total', 7475)
            ->assertJsonPath('totals.profit', 1300)
            ->assertJsonPath('scenarios.0.margin', 10)
            ->assertJsonPath('scenarios.1.total', 7475);
    }

    public function test_calculator_validation_messages(): void
    {
        $this->as('operator')->postJson('/calculadora-oferta/calcular', $this->offer(['lines' => [['margin' => 100, 'quantity' => 0]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.margin', 'lines.0.quantity']);

        $this->as('operator')->postJson('/calculadora-oferta/calcular', ['lines' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);
    }

    public function test_calculator_rejects_products_of_another_company(): void
    {
        $foreign = $this->item([], Company::factory()->create());

        $this->as('operator')->postJson('/calculadora-oferta/calcular', $this->offer(['lines' => [['inventory_item_id' => $foreign->id]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.inventory_item_id']);
    }

    public function test_calculator_exports_a_pdf(): void
    {
        $response = $this->as('operator')->post('/calculadora-oferta/pdf', $this->offer(['title' => 'LPN-007']));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
