<?php

namespace Tests\Feature\Finance;

use App\Models\Client;
use App\Models\CollectionInvoice;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Cobros y cartera: alta, edición, pago con interés (idempotente), notas,
 * permisos por rol, número único por empresa, aislamiento y exportes.
 */
class CollectionsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'number' => 'FAC-100', 'concept' => 'Suministro de equipo', 'amount' => 36500,
            'issued_at' => '2026-10-01', 'due_at' => '2026-10-31', 'interest_rate' => 12,
        ];
    }

    private function invoice(Company $company, array $attributes = []): CollectionInvoice
    {
        return CollectionInvoice::query()->create($attributes + [
            'company_id' => $company->id, 'number' => 'FAC-'.fake()->unique()->numberBetween(1000, 9999), 'concept' => 'Servicio',
            'amount' => 1000, 'issued_at' => today()->subDays(40)->toDateString(), 'due_at' => today()->addDays(5)->toDateString(), 'status' => 'Pendiente',
        ]);
    }

    public function test_operator_registers_and_edits_an_invoice_in_the_active_company(): void
    {
        [$operator, $company] = $this->memberOf(role: 'operator');
        $client = Client::factory()->for($company)->create();

        $this->actingAs($operator)->post('/cobros', $this->payload(['client_id' => $client->id, 'company_id' => 999]))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $invoice = CollectionInvoice::withoutGlobalScopes()->sole();
        $this->assertSame($company->id, $invoice->company_id);
        $this->assertSame('Pendiente', $invoice->status);

        $this->actingAs($operator)->put("/cobros/{$invoice->id}", $this->payload(['concept' => 'Suministro corregido']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('collection_invoices', ['id' => $invoice->id, 'concept' => 'Suministro corregido']);
    }

    public function test_listing_filters_and_stats(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        $this->invoice($company, ['number' => 'FAC-VENCIDA', 'due_at' => today()->subDays(3)->toDateString(), 'amount' => 700]);
        $this->invoice($company, ['number' => 'FAC-PENDIENTE', 'amount' => 300]);
        $this->invoice($company, ['number' => 'FAC-PAGADA', 'status' => 'Pagada', 'paid_at' => today()->toDateString(), 'amount' => 200]);

        $this->actingAs($viewer)->get('/cobros')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Finance/Collections')
            ->where('stats.receivable', 1000)
            ->where('stats.overdue', 700)
            ->where('stats.overdue_count', 1)
            ->where('stats.pending_count', 1)
            ->where('stats.collected_month', 200)
            ->where('can.create', false)
            ->where('can.export', true)
            ->has('records.data', 3));

        $this->actingAs($viewer)->get('/cobros?filter=overdue')->assertInertia(fn (Assert $page) => $page
            ->has('records.data', 1)
            ->where('records.data.0.number', 'FAC-VENCIDA')
            ->where('records.data.0.display_status', 'Vencida'));

        $this->actingAs($viewer)->get('/cobros?search=PAGADA')->assertInertia(fn (Assert $page) => $page
            ->has('records.data', 1)->where('records.data.0.number', 'FAC-PAGADA'));
    }

    public function test_validation_messages_and_number_unique_per_company(): void
    {
        [$operator, $company] = $this->memberOf(role: 'operator');
        $other = Company::factory()->create();
        $this->invoice($other, ['number' => 'FAC-100']);
        $foreignClient = Client::factory()->for($other)->create();

        $this->actingAs($operator)->post('/cobros', $this->payload(['due_at' => '2026-09-01', 'amount' => 0, 'client_id' => $foreignClient->id]))
            ->assertSessionHasErrors([
                'due_at' => 'El vencimiento no puede ser anterior a la emisión.',
                'amount' => 'El monto debe ser mayor que cero.',
                'client_id',
            ]);

        // El mismo número existe en otra empresa: aquí se permite.
        $this->actingAs($operator)->post('/cobros', $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($operator)->post('/cobros', $this->payload())
            ->assertSessionHasErrors(['number' => 'Ya existe una factura con ese número en la empresa.']);
    }

    public function test_payment_applies_interest_once_and_locks_the_invoice(): void
    {
        [$operator, $company] = $this->memberOf(role: 'operator');
        $invoice = $this->invoice($company, ['amount' => 36500, 'interest_rate' => 10, 'due_at' => today()->subDays(10)->toDateString()]);

        $this->actingAs($operator)->patch("/cobros/{$invoice->id}/pagar")->assertSessionHas('success');
        // 36,500 × 10 % × 10 / 365 = 100.
        $this->assertDatabaseHas('collection_invoices', ['id' => $invoice->id, 'status' => 'Pagada', 'interest_amount' => 100]);

        $this->actingAs($operator)->patch("/cobros/{$invoice->id}/pagar")->assertSessionHas('warning');
        $this->assertDatabaseHas('collection_invoices', ['id' => $invoice->id, 'interest_amount' => 100]);

        $this->actingAs($operator)->put("/cobros/{$invoice->id}", $this->payload())->assertSessionHas('error');
        $this->assertDatabaseMissing('collection_invoices', ['number' => 'FAC-100']);

        $future = $this->invoice($company);
        $this->actingAs($operator)->patch("/cobros/{$future->id}/pagar", ['paid_at' => today()->addDay()->toDateString()])
            ->assertSessionHasErrors(['paid_at' => 'La fecha de pago no puede ser futura.']);
    }

    public function test_roles_on_write_actions(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');
        $invoice = $this->invoice($company);

        $this->actingAs($viewer)->post('/cobros', $this->payload())->assertForbidden();
        $this->actingAs($viewer)->patch("/cobros/{$invoice->id}/pagar")->assertForbidden();
        $this->actingAs($viewer)->patch("/cobros/{$invoice->id}/nota", ['collection_note' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->get("/cobros/{$invoice->id}/whatsapp")->assertOk()->assertJsonPath('message', fn ($text) => str_contains($text, $invoice->number));
        $this->actingAs($operator)->delete("/cobros/{$invoice->id}")->assertForbidden();

        $this->actingAs($operator)->patch("/cobros/{$invoice->id}/nota", ['collection_note' => 'Pagan el viernes.'])->assertSessionHas('success');
        $this->assertDatabaseHas('collection_invoices', ['id' => $invoice->id, 'collection_note' => 'Pagan el viernes.']);

        $this->actingAs($admin)->delete("/cobros/{$invoice->id}")->assertSessionHas('success');
        $this->assertDatabaseCount('collection_invoices', 0);
    }

    public function test_invoices_of_another_company_are_out_of_reach(): void
    {
        [$admin, $company] = $this->memberOf();
        $foreign = $this->invoice(Company::factory()->create(), ['number' => 'FAC-AJENA']);
        $this->invoice($company, ['number' => 'FAC-PROPIA']);

        $this->actingAs($admin)->get('/cobros')->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.number', 'FAC-PROPIA'));
        $this->actingAs($admin)->put("/cobros/{$foreign->id}", $this->payload())->assertNotFound();
        $this->actingAs($admin)->patch("/cobros/{$foreign->id}/pagar")->assertNotFound();
        $this->actingAs($admin)->get("/cobros/{$foreign->id}/whatsapp")->assertNotFound();
        $this->actingAs($admin)->get("/cobros/{$foreign->id}/nota.pdf")->assertNotFound();
        $this->actingAs($admin)->delete("/cobros/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('collection_invoices', ['id' => $foreign->id, 'status' => 'Pendiente']);
    }

    public function test_exports_respect_filters_and_company(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        $this->invoice($company, ['number' => 'FAC-VENCIDA', 'due_at' => today()->subDays(3)->toDateString()]);
        $this->invoice($company, ['number' => 'FAC-AL-DIA']);
        $this->invoice(Company::factory()->create(), ['number' => 'FAC-AJENA', 'due_at' => today()->subDays(3)->toDateString()]);

        $xlsx = $this->actingAs($viewer)->get('/cobros/export/xlsx?filter=overdue');
        $xlsx->assertOk()->assertDownload();
        $values = collect(IOFactory::load($xlsx->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray())->flatten()->filter()->all();
        $this->assertContains('FAC-VENCIDA', $values);
        $this->assertNotContains('FAC-AL-DIA', $values);
        $this->assertNotContains('FAC-AJENA', $values);

        $this->actingAs($viewer)->get('/cobros/export/pdf?filter=overdue')->assertOk()->assertHeader('content-type', 'application/pdf');

        $own = CollectionInvoice::withoutGlobalScopes()->where('number', 'FAC-VENCIDA')->sole();
        $this->actingAs($viewer)->get("/cobros/{$own->id}/nota.pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
