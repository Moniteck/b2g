<?php

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_matches_the_page_contract_and_only_shows_the_active_company(): void
    {
        [$admin, $company] = $this->memberOf();
        $other = Company::factory()->create();
        Client::factory()->for($company)->create(['name' => 'Secretaría de Salud', 'accumulated_amount' => 1500, 'last_purchase_at' => '2026-10-01']);
        Client::factory()->for($company)->create(['name' => 'ENEE', 'accumulated_amount' => 500, 'status' => 'Inactivo']);
        Client::factory()->for($other)->create(['name' => 'Cliente ajeno']);

        $this->actingAs($admin)->get('/clientes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Index')
            ->has('clients.data', 2)
            ->has('clients.data.0', fn (Assert $row) => $row
                ->hasAll(['id', 'name', 'tax_id', 'sector', 'contact_name', 'phone', 'email', 'status', 'accumulated_amount', 'last_purchase_at', 'institution']))
            ->where('stats.total', 2)
            ->where('stats.active', 1)
            ->where('stats.amount', 2000)
            ->where('can.update', true)
            ->where('can.delete', true));

        $this->actingAs($admin)->get('/clientes?status=Inactivo')->assertInertia(fn (Assert $page) => $page
            ->has('clients.data', 1)->where('clients.data.0.name', 'ENEE'));

        $this->actingAs($admin)->get('/clientes?search=salud')->assertInertia(fn (Assert $page) => $page
            ->has('clients.data', 1)->where('clients.data.0.name', 'Secretaría de Salud'));
    }

    public function test_clients_cannot_be_created_by_hand(): void
    {
        [$admin] = $this->memberOf();

        $this->actingAs($admin)->post('/clientes', ['name' => 'Manual'])->assertMethodNotAllowed();
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_operator_edits_contact_data_and_duplicate_tax_id_is_a_validation_error(): void
    {
        [$operator, $company] = $this->memberOf(role: 'operator');
        $client = Client::factory()->for($company)->create(['tax_id' => '08019000000001']);
        Client::factory()->for($company)->create(['tax_id' => '08019000000002']);

        $payload = ['name' => 'Alcaldía de Tegucigalpa', 'tax_id' => '08019000000099', 'contact_name' => 'Ana López', 'phone' => '2222-0000', 'email' => 'ana@amdc.hn', 'status' => 'Activo'];

        $this->actingAs($operator)->put("/clientes/{$client->id}", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'Alcaldía de Tegucigalpa', 'contact_name' => 'Ana López']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cliente.actualizado', 'resource_id' => $client->id, 'company_id' => $company->id]);

        $this->actingAs($operator)->put("/clientes/{$client->id}", ['tax_id' => '08019000000002'] + $payload)->assertSessionHasErrors('tax_id');

        // Por JSON el duplicado es un 422 con mensaje, no un 500.
        $this->actingAs($operator)->putJson("/clientes/{$client->id}", ['tax_id' => '08019000000002'] + $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['tax_id' => 'Ya hay otro cliente con ese RTN en la empresa.']);
    }

    public function test_same_tax_id_is_allowed_in_another_company(): void
    {
        [$admin, $company] = $this->memberOf();
        $client = Client::factory()->for($company)->create();
        Client::factory()->for(Company::factory())->create(['tax_id' => '08019000000077']);

        $this->actingAs($admin)->put("/clientes/{$client->id}", ['name' => $client->name, 'tax_id' => '08019000000077', 'status' => 'Activo'])
            ->assertSessionHasNoErrors();
    }

    public function test_viewer_reads_but_cannot_write(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        $client = Client::factory()->for($company)->create(['name' => 'Original']);

        $this->actingAs($viewer)->get('/clientes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.update', false)->where('can.delete', false));
        $this->actingAs($viewer)->get("/clientes/{$client->id}")->assertOk();
        $this->actingAs($viewer)->put("/clientes/{$client->id}", ['name' => 'Cambiado', 'status' => 'Activo'])->assertForbidden();
        $this->actingAs($viewer)->delete("/clientes/{$client->id}")->assertForbidden();
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'Original', 'deleted_at' => null]);
    }

    public function test_only_admin_deletes_and_only_without_contracts(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        $free = Client::factory()->for($company)->create();
        $withContract = Client::factory()->for($company)->create();
        Contract::factory()->for($company)->create(['client_id' => $withContract->id]);

        $this->actingAs($operator)->delete("/clientes/{$free->id}")->assertForbidden();

        $this->actingAs($admin)->delete("/clientes/{$withContract->id}")->assertRedirect()->assertSessionHas('error');
        $this->assertNotSoftDeleted('clients', ['id' => $withContract->id]);

        $this->actingAs($admin)->delete("/clientes/{$free->id}")->assertRedirect('/clientes');
        $this->assertSoftDeleted('clients', ['id' => $free->id]);
    }

    public function test_contracts_linked_only_through_the_quote_also_block_deletion(): void
    {
        [$admin, $company] = $this->memberOf();
        $client = Client::factory()->for($company)->create();
        $quote = Quote::factory()->for($company)->create(['client_id' => $client->id, 'outcome' => 'Ganada', 'awarded_amount' => 900]);
        Contract::factory()->for($company)->create(['quote_id' => $quote->id, 'client_id' => null]);

        $this->assertSame(1, $client->contractsQuery()->count());

        $this->actingAs($admin)->get("/clientes/{$client->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Show')
            ->has('quotes', 1)
            ->has('contracts', 1)
            ->where('can.delete', false)
            ->where('can.delete_blocked', true));

        $this->actingAs($admin)->delete("/clientes/{$client->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_clients_of_another_company_are_out_of_reach(): void
    {
        [$admin] = $this->memberOf();
        $foreign = Client::factory()->for(Company::factory())->create();

        $this->actingAs($admin)->get("/clientes/{$foreign->id}")->assertNotFound();
        $this->actingAs($admin)->put("/clientes/{$foreign->id}", ['name' => 'X', 'status' => 'Activo'])->assertNotFound();
        $this->actingAs($admin)->delete("/clientes/{$foreign->id}")->assertNotFound();
    }

    public function test_csv_export_has_accented_headers_and_respects_filters(): void
    {
        [$admin, $company] = $this->memberOf();
        Client::factory()->for($company)->create(['name' => 'Cliente Activo', 'accumulated_amount' => 1234.5]);
        Client::factory()->for($company)->create(['name' => 'Cliente Dormido', 'status' => 'Inactivo']);
        Client::factory()->for(Company::factory())->create(['name' => 'Cliente Ajeno']);

        $response = $this->actingAs($admin)->get('/clientes/export/csv?status=Activo');
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Institución', $csv);
        $this->assertStringContainsString('Teléfono', $csv);
        $this->assertStringContainsString('Último ganado', $csv);
        $this->assertStringContainsString('Cliente Activo', $csv);
        $this->assertStringContainsString('1234.50', $csv);
        $this->assertStringNotContainsString('Cliente Dormido', $csv);
        $this->assertStringNotContainsString('Cliente Ajeno', $csv);
    }
}
