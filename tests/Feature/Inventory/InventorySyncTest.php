<?php

namespace Tests\Feature\Inventory;

use App\Jobs\SyncBlessingInventory;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryPriceHistory;
use App\Models\InventorySyncRun;
use App\Services\Blessing\BlessingClientFactory;
use App\Services\Blessing\BlessingException;
use App\Services\Blessing\FakeBlessingClient;
use App\Services\Blessing\HttpBlessingClient;
use App\Services\Blessing\InventorySyncService;
use App\Services\Blessing\NullBlessingClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InventorySyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeBlessingClient $fake;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeBlessingClient;
        $this->app->instance(FakeBlessingClient::class, $this->fake);
        $this->company = Company::factory()->create();
    }

    private function sync(?Company $company = null): InventorySyncRun
    {
        return app(InventorySyncService::class)->sync($company ?? $this->company);
    }

    /** @return Builder<InventoryItem> */
    private function items(?Company $company = null)
    {
        return InventoryItem::query()->withoutGlobalScopes()->where('company_id', ($company ?? $this->company)->id);
    }

    public function test_first_sync_creates_every_product_with_the_tax_rule_of_the_company(): void
    {
        $run = $this->sync();

        $this->assertSame(InventorySyncRun::COMPLETED, $run->status);
        $this->assertSame(40, $run->created_count);
        $this->assertSame(0, $run->updated_count);
        $this->assertSame(40, $run->items_processed);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('demo', $run->source);
        $this->assertGreaterThan(1, $this->fake->pagesRequested, 'recorre todas las páginas');

        $this->assertSame(40, $this->items()->where('is_active', true)->count());
        $this->assertSame(40, InventoryPriceHistory::query()->withoutGlobalScopes()->count());

        $item = $this->items()->where('sku', 'LUB-15W40-G')->first();
        $this->assertFalse($item->tax_included, 'supuesto vigente: el precio sugerido no trae ISV');
        $this->assertSame('15.00', $item->tax_rate);
        $this->assertTrue($item->is_demo);
        $this->assertSame(792.35, $item->pricing()->total);
    }

    public function test_second_sync_without_changes_changes_nothing(): void
    {
        $this->sync();
        $run = $this->sync();

        $this->assertSame(0, $run->created_count);
        $this->assertSame(0, $run->updated_count);
        $this->assertSame(0, $run->deactivated_count);
        $this->assertSame(40, InventoryPriceHistory::query()->withoutGlobalScopes()->count());
    }

    public function test_changes_new_products_and_removed_products(): void
    {
        $this->sync();

        $this->fake
            ->change('BL-01001', ['suggestedPrice' => 699.0, 'stockAvailable' => 5.0])
            ->change('BL-01002', ['description' => 'Aceite diésel 15W-40, cubeta 5 gal (nueva presentación)'])
            ->remove('BL-01003')
            ->put(FakeBlessingClient::product('BL-09999', ['sku' => 'NUE-0001']));

        $run = $this->sync();

        $this->assertSame(1, $run->created_count);
        $this->assertSame(2, $run->updated_count);
        $this->assertSame(1, $run->deactivated_count);

        $removed = $this->items()->where('blessing_id', 'BL-01003')->first();
        $this->assertFalse($removed->is_active);
        $this->assertNotNull($removed->deactivated_at);

        $this->assertSame('699.00', $this->items()->where('blessing_id', 'BL-01001')->value('suggested_price'));
        // Historial: 40 iniciales + cambio de precio + alta (la descripción no es un cambio de precio).
        $this->assertSame(42, InventoryPriceHistory::query()->withoutGlobalScopes()->count());

        // Si vuelve, se reactiva.
        $this->fake->put(FakeBlessingClient::product('BL-01003', ['sku' => 'LUB-15W40-T']));
        $this->sync();
        $this->assertTrue($this->items()->where('blessing_id', 'BL-01003')->first()->is_active);
    }

    public function test_product_reported_inactive_is_deactivated(): void
    {
        $this->sync();
        $this->fake->change('BL-01005', ['active' => false]);

        $run = $this->sync();

        $this->assertSame(1, $run->deactivated_count);
        $this->assertFalse($this->items()->where('blessing_id', 'BL-01005')->value('is_active'));
    }

    public function test_api_failure_midway_keeps_the_previous_inventory(): void
    {
        $this->sync();
        $this->fake->change('BL-01001', ['suggestedPrice' => 1.0])->remove('BL-01010')->failAfter(20, 'Tiempo de espera agotado.');

        $run = $this->sync();

        $this->assertSame(InventorySyncRun::FAILED, $run->status);
        $this->assertSame('Tiempo de espera agotado.', $run->error);
        $this->assertSame(40, $this->items()->where('is_active', true)->count());
        $this->assertSame('689.00', $this->items()->where('blessing_id', 'BL-01001')->value('suggested_price'));
    }

    public function test_failure_after_the_last_page_also_keeps_the_inventory(): void
    {
        $this->sync();
        $this->fake->remove('BL-01010')->failAfter(39);

        $this->assertSame(InventorySyncRun::FAILED, $this->sync()->status);
        $this->assertTrue($this->items()->where('blessing_id', 'BL-01010')->value('is_active'));
    }

    public function test_empty_catalog_does_not_wipe_the_inventory(): void
    {
        $this->sync();
        foreach ($this->fake->all() as $product) {
            $this->fake->remove($product->id);
        }

        $run = $this->sync();

        $this->assertSame(InventorySyncRun::FAILED, $run->status);
        $this->assertStringContainsString('catálogo vacío', $run->error);
        $this->assertSame(40, $this->items()->where('is_active', true)->count());
    }

    public function test_invalid_and_duplicated_products_are_reported_without_stopping_the_run(): void
    {
        $this->fake
            ->put(FakeBlessingClient::product('BL-X1', ['sku' => '']))
            ->put(FakeBlessingClient::product('BL-X2', ['sku' => 'LUB-15W40-G']))
            ->put(FakeBlessingClient::product('BL-X3', ['suggestedPrice' => -5.0]));

        $run = $this->sync();

        $this->assertSame(InventorySyncRun::PARTIAL, $run->status);
        $this->assertSame(40, $run->created_count);
        $this->assertSame(3, $run->error_count);
        $this->assertCount(3, $run->errors);
        $this->assertSame('BL-X1', $run->errors[0]['id']);
    }

    public function test_adopts_a_manual_product_with_the_same_sku(): void
    {
        $manual = InventoryItem::factory()->create([
            'company_id' => $this->company->id, 'blessing_id' => null, 'sku' => 'LUB-15W40-G', 'description' => 'Cargado a mano',
        ]);

        $run = $this->sync();

        $this->assertSame(39, $run->created_count);
        $this->assertSame(1, $run->updated_count);
        $this->assertSame('BL-01001', $manual->fresh()->blessing_id);
        $this->assertSame(40, $this->items()->count());
    }

    public function test_only_one_run_at_a_time_per_company(): void
    {
        $lock = Cache::lock('inventario:blessing:sync:'.$this->company->id, 60);
        $this->assertTrue($lock->get());

        try {
            $this->sync();
            $this->fail('Debió rechazar la corrida simultánea.');
        } catch (BlessingException $error) {
            $this->assertSame(409, $error->getCode());
        } finally {
            $lock->release();
        }

        // Otra empresa no se bloquea.
        $other = Company::factory()->create();
        $this->assertSame(InventorySyncRun::COMPLETED, $this->sync($other)->status);
        $this->assertSame(0, InventorySyncRun::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_a_running_run_in_the_database_blocks_and_a_stale_one_is_closed(): void
    {
        $running = InventorySyncRun::query()->forceCreate([
            'company_id' => $this->company->id, 'status' => InventorySyncRun::RUNNING, 'trigger' => 'manual', 'started_at' => now()->subMinute(),
        ]);

        $this->expectExceptionCode(409);

        try {
            $this->sync();
        } finally {
            $running->forceFill(['started_at' => now()->subHour()])->save();
            $run = $this->sync();
            $this->assertSame(InventorySyncRun::COMPLETED, $run->status);
            $this->assertSame(InventorySyncRun::FAILED, $running->fresh()->status);
        }
    }

    public function test_company_without_connection_cannot_sync(): void
    {
        $this->app->forgetInstance(FakeBlessingClient::class);
        $this->app->offsetUnset(FakeBlessingClient::class);

        $this->assertInstanceOf(NullBlessingClient::class, app(BlessingClientFactory::class)->forCompany($this->company));

        $this->expectException(BlessingException::class);
        $this->sync();
    }

    public function test_inverting_the_tax_rule_only_needs_the_company_setting(): void
    {
        $this->company->forceFill(['settings' => ['pricing' => ['prices_include_tax' => true]]])->save();

        $this->sync();

        $item = $this->items()->where('sku', 'LUB-15W40-G')->first();
        $this->assertTrue($item->tax_included);
        $this->assertSame(689.0, $item->pricing()->total);
        $this->assertSame(599.13, $item->pricing()->subtotal);
    }

    public function test_companies_are_isolated(): void
    {
        $other = Company::factory()->create();
        $foreign = InventoryItem::factory()->create(['company_id' => $other->id, 'blessing_id' => 'BL-01001', 'sku' => 'LUB-15W40-G']);

        $this->sync();

        $this->assertSame(40, $this->items()->count());
        $this->assertSame(1, $this->items($other)->count());
        $this->assertTrue($foreign->fresh()->is_active);
        $this->assertSame($other->id, $foreign->fresh()->company_id);
    }

    public function test_factory_picks_the_client_by_configuration(): void
    {
        $this->app->offsetUnset(FakeBlessingClient::class);
        $factory = app(BlessingClientFactory::class);

        $api = Company::factory()->create(['blessing_base_url' => 'https://blessing.test/api', 'blessing_token' => 'secreto']);
        $demo = Company::factory()->create(['settings' => ['blessing' => ['demo' => true]]]);

        $this->assertInstanceOf(HttpBlessingClient::class, $factory->forCompany($api));
        $this->assertInstanceOf(FakeBlessingClient::class, $factory->forCompany($demo));
        $this->assertInstanceOf(NullBlessingClient::class, $factory->forCompany($this->company));
        $this->assertSame('ninguno', $factory->modeOf($this->company));
    }

    public function test_demo_command_seeds_and_cleans_demo_inventory(): void
    {
        $this->app->offsetUnset(FakeBlessingClient::class);

        $this->artisan('blessing:demo', ['empresa' => (string) $this->company->id])->assertSuccessful();
        $this->assertSame(40, $this->items()->where('is_demo', true)->count());
        $this->assertTrue($this->company->fresh()->settings['blessing']['demo']);

        $this->artisan('blessing:demo', ['empresa' => (string) $this->company->id, '--limpiar' => true])->assertSuccessful();
        $this->assertSame(0, $this->items()->count());
        $this->assertFalse($this->company->fresh()->settings['blessing']['demo']);
    }

    public function test_sync_command_runs_now_or_queues_one_job_per_company(): void
    {
        $this->artisan('blessing:sync', ['empresa' => (string) $this->company->id])->assertSuccessful();
        $this->assertSame(40, $this->items()->count());
        $this->assertSame(InventorySyncRun::TRIGGER_COMMAND, InventorySyncRun::query()->withoutGlobalScopes()->value('trigger'));

        Queue::fake();
        $this->artisan('blessing:sync', ['--cola' => true])->assertSuccessful();
        Queue::assertPushed(SyncBlessingInventory::class, fn (SyncBlessingInventory $job) => $job->companyId === $this->company->id);

        $this->artisan('blessing:sync', ['empresa' => 'no-existe'])->assertFailed();
    }

    public function test_job_syncs_and_ignores_a_company_without_connection(): void
    {
        (new SyncBlessingInventory($this->company->id))->handle(app(InventorySyncService::class));
        $this->assertSame(InventorySyncRun::TRIGGER_SCHEDULED, InventorySyncRun::query()->withoutGlobalScopes()->value('trigger'));

        $this->app->offsetUnset(FakeBlessingClient::class);
        $lonely = Company::factory()->create();
        (new SyncBlessingInventory($lonely->id))->handle(app(InventorySyncService::class));
        $this->assertSame(0, InventorySyncRun::query()->withoutGlobalScopes()->where('company_id', $lonely->id)->count());
    }

    public function test_http_client_paginates_and_maps_products(): void
    {
        Http::fake([
            'blessing.test/api/products?price_list=MAYOREO&per_page=200' => Http::response([
                'data' => [['id' => 7, 'sku' => 'A-1', 'description' => 'Aceite', 'stock' => '12', 'suggested_price' => '100.50', 'tax_rate' => 15, 'active' => true]],
                'next_cursor' => 'abc',
            ]),
            'blessing.test/api/products?price_list=MAYOREO&per_page=200&cursor=abc' => Http::response([
                'data' => [['id' => 8, 'code' => 'B-2', 'name' => 'Grasa', 'existencia' => 3, 'precio' => 50, 'activo' => 'false']],
                'next_cursor' => null,
            ]),
        ]);

        $client = new HttpBlessingClient('https://blessing.test/api', 'secreto', 'MAYOREO', retries: 1);
        $products = iterator_to_array($client->products(), false);

        $this->assertCount(2, $products);
        $this->assertSame('7', $products[0]->id);
        $this->assertSame(100.5, $products[0]->suggestedPrice);
        $this->assertSame(12.0, $products[0]->stock);
        $this->assertSame('B-2', $products[1]->sku);
        $this->assertFalse($products[1]->active);
        $this->assertNull($products[1]->taxIncluded);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secreto'));
    }

    public function test_http_client_errors_are_readable_and_do_not_leak_the_token(): void
    {
        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        try {
            iterator_to_array((new HttpBlessingClient('https://blessing.test/api', 'secreto-xyz', retries: 1))->products());
            $this->fail('Debió fallar.');
        } catch (BlessingException $error) {
            $this->assertStringContainsString('rechazó el token', $error->getMessage());
            $this->assertStringNotContainsString('secreto-xyz', $error->getMessage());
        }

        Http::fake(['*' => Http::response('caído', 503)]);
        $this->expectException(BlessingException::class);
        iterator_to_array((new HttpBlessingClient('https://blessing.test/api', 'secreto', retries: 1))->products());
    }
}
