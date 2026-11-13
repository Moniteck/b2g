<?php

namespace Tests\Feature\Contracts;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractFollowUp;
use App\Models\Document;
use App\Models\Event;
use App\Models\Holiday;
use App\Models\Institution;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Services\Contracts\ContractStatus;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Documents\DocumentsTestCase;

/**
 * Post-adjudicación: contrato con todos sus datos, plazo en días hábiles con feriados, fecha fin
 * manual, estados y transiciones, entregas, seguimientos con calendario, documentos, número único
 * por empresa, aislamiento y roles.
 */
class ContractsTest extends DocumentsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'America/Tegucigalpa'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contract(array $attributes = []): Contract
    {
        return Contract::factory()->for($this->company)->create($attributes + ['manager_id' => $this->operator->id]);
    }

    private function as(User $user): static
    {
        return $this->actingInCompany($user, $this->company);
    }

    public function test_create_with_all_fields_and_open_the_record(): void
    {
        $institution = Institution::factory()->create(['name' => 'Secretaría de Salud']);
        $contact = Contact::factory()->create(['institution_id' => $institution->id, 'name' => 'Ana Martínez']);

        $response = $this->as($this->operator)->post('/post-adjudicacion', [
            'number' => 'CTR-2026-010',
            'name' => 'Suministro de medicamentos',
            'status' => 'En ejecución',
            'institution_id' => $institution->id,
            'contact_id' => $contact->id,
            'manager_id' => $this->operator->id,
            'awarded_amount' => 1250000.50,
            'signed_at' => '2026-11-16',
            'start_trigger' => 'Firma del contrato',
            'duration_days' => 30,
            'duration_business_days' => false,
            'product_delivered' => false,
            'notes' => 'Entrega en almacén central',
        ]);

        $contract = Contract::query()->where('number', 'CTR-2026-010')->sole();
        $response->assertRedirect("/post-adjudicacion/{$contract->id}")->assertSessionHas('success');

        $this->assertSame('En ejecución', $contract->status);
        $this->assertSame('1250000.50', $contract->amount, 'El monto del contrato es el adjudicado si no se indica.');
        $this->assertSame('2026-11-16', $contract->start_date->toDateString(), 'Con hito «Firma del contrato» el inicio es la firma.');
        $this->assertSame('2026-12-16', $contract->end_date->toDateString());
        $this->assertSame($this->operator->id, $contract->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contrato.creado', 'resource_type' => 'Contract', 'resource_id' => $contract->id]);

        $this->as($this->viewer)->get("/post-adjudicacion/{$contract->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Contracts/Show')
                ->where('contract.number', 'CTR-2026-010')
                ->where('contract.contact.name', 'Ana Martínez')
                ->where('contract.manager.id', $this->operator->id)
                ->where('contract.awarded_amount', 1250000.5)
                ->where('can.update', false)
                ->where('can.close', false));
    }

    public function test_the_contact_must_belong_to_the_institution(): void
    {
        $institution = Institution::factory()->create();
        $other = Contact::factory()->create(['institution_id' => Institution::factory()->create()->id]);

        $this->as($this->operator)->post('/post-adjudicacion', ['number' => 'C-1', 'name' => 'X', 'awarded_amount' => 10, 'institution_id' => $institution->id, 'contact_id' => $other->id])
            ->assertSessionHasErrors('contact_id');
    }

    public function test_the_accented_default_status_is_accepted_and_missing_amount_gives_a_message(): void
    {
        $this->as($this->operator)->post('/post-adjudicacion', ['number' => 'C-1', 'name' => 'X', 'status' => 'En ejecución', 'awarded_amount' => 5])->assertSessionHasNoErrors();
        $this->as($this->operator)->post('/post-adjudicacion', ['number' => 'C-2', 'name' => 'X'])
            ->assertSessionHasErrors(['amount' => 'Indique el monto adjudicado o el monto del contrato.']);
    }

    public function test_business_day_term_skips_weekends_and_company_holidays(): void
    {
        // Miércoles 30 de septiembre + 5 hábiles: 1, 2, 5, 6 de octubre y, saltando la Semana Morazánica (7 a 9), el 12.
        $this->as($this->operator)->post('/post-adjudicacion', [
            'number' => 'C-HAB', 'name' => 'Hábiles', 'awarded_amount' => 100,
            'start_date' => '2026-09-30', 'duration_days' => 5, 'duration_business_days' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-12', Contract::query()->where('number', 'C-HAB')->sole()->end_date->toDateString());
        $this->assertTrue(Holiday::query()->where('holiday_date', '2026-10-07')->exists(), 'Los feriados nacionales se siembran en la empresa.');

        // Un feriado propio de la empresa también cuenta.
        $this->as($this->admin)->postJson('/post-adjudicacion/feriados', ['holiday_date' => '2026-10-12', 'name' => 'Feriado local'])->assertCreated();
        $this->as($this->operator)->getJson('/post-adjudicacion/plazo?start_date=2026-09-30&duration_days=5&business=1')
            ->assertOk()->assertJson(['end_date' => '2026-10-13']);
    }

    public function test_manual_end_date_is_never_overwritten_by_the_term(): void
    {
        $contract = $this->contract(['start_date' => '2026-11-16', 'duration_days' => 30, 'end_date' => '2026-12-16']);
        $payload = ['number' => $contract->number, 'name' => $contract->name, 'awarded_amount' => 100, 'start_date' => '2026-11-16', 'duration_days' => 30];

        $this->as($this->operator)->put("/post-adjudicacion/{$contract->id}", $payload + ['is_end_date_manual' => true, 'end_date' => '2027-01-31'])->assertSessionHasNoErrors();
        $this->assertSame('2027-01-31', $contract->fresh()->end_date->toDateString());

        // Cambiar el plazo después no pisa la fecha fijada.
        $this->as($this->operator)->put("/post-adjudicacion/{$contract->id}", ['duration_days' => 60, 'is_end_date_manual' => true, 'end_date' => '2027-01-31'] + $payload)->assertSessionHasNoErrors();
        $this->assertSame('2027-01-31', $contract->fresh()->end_date->toDateString());

        // Sin la marca manual vuelve a calcularse: 16 nov + 60.
        $this->as($this->operator)->put("/post-adjudicacion/{$contract->id}", ['duration_days' => 60, 'is_end_date_manual' => false, 'end_date' => '2027-01-31'] + $payload)->assertSessionHasNoErrors();
        $this->assertSame('2027-01-15', $contract->fresh()->end_date->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'contrato.actualizado', 'resource_id' => $contract->id]);
    }

    public function test_number_is_unique_per_company(): void
    {
        $this->contract(['number' => 'CTR-REPETIDO']);
        Contract::factory()->for(Company::factory()->create())->create(['number' => 'CTR-OTRA']);

        $this->as($this->operator)->post('/post-adjudicacion', ['number' => 'CTR-REPETIDO', 'name' => 'X', 'awarded_amount' => 1])
            ->assertSessionHasErrors(['number' => 'Ya existe un contrato con ese número en la empresa.']);
        $this->as($this->operator)->post('/post-adjudicacion', ['number' => 'CTR-OTRA', 'name' => 'X', 'awarded_amount' => 1])->assertSessionHasNoErrors();
    }

    public function test_status_transitions_follow_the_rules(): void
    {
        $contract = $this->contract(['status' => ContractStatus::PENDING]);

        // No se salta de Pendiente a Producto entregado.
        $this->as($this->operator)->patch("/post-adjudicacion/{$contract->id}/estado", ['status' => 'Producto entregado'])->assertSessionHasErrors('status');

        $this->as($this->operator)->patch("/post-adjudicacion/{$contract->id}/estado", ['status' => 'En ejecución'])->assertSessionHasNoErrors();
        $this->as($this->operator)->patch("/post-adjudicacion/{$contract->id}/estado", ['status' => 'Suspendido'])->assertSessionHasErrors(['reason' => 'Indique el motivo.']);
        $this->as($this->operator)->patch("/post-adjudicacion/{$contract->id}/estado", ['status' => 'Suspendido', 'reason' => 'Falta de pago'])->assertSessionHasNoErrors();
        $this->as($this->operator)->patch("/post-adjudicacion/{$contract->id}/estado", ['status' => 'Entrega parcial'])->assertSessionHasNoErrors();

        // Cancelar es del administrador.
        $this->as($this->operator)->patch("/post-adjudicacion/{$contract->id}/estado", ['status' => 'Cancelado', 'reason' => 'X'])->assertForbidden();
        $this->as($this->operator)->patch("/post-adjudicacion/{$contract->id}/estado", ['status' => 'Producto entregado'])->assertSessionHasNoErrors();

        $contract->refresh();
        $this->assertSame('Producto entregado', $contract->status);
        $this->assertTrue($contract->product_delivered);
        $this->assertSame(4, AuditLog::query()->where('action', 'contrato.estado')->where('resource_id', $contract->id)->count());

        $this->as($this->admin)->get("/post-adjudicacion/{$contract->id}")
            ->assertInertia(fn (Assert $page) => $page->where('transitions', ['Cerrado', 'Entrega parcial', 'En ejecución', 'Cancelado']));
        $this->as($this->operator)->get("/post-adjudicacion/{$contract->id}")
            ->assertInertia(fn (Assert $page) => $page->where('transitions', ['Entrega parcial', 'En ejecución']));
    }

    public function test_closing_requires_the_product_delivered_and_is_idempotent(): void
    {
        $contract = $this->contract(['status' => ContractStatus::IN_PROGRESS, 'end_date' => '2027-01-31']);

        $this->as($this->operator)->patch("/post-adjudicacion/{$contract->id}/completar")->assertForbidden();
        $this->as($this->admin)->patch("/post-adjudicacion/{$contract->id}/completar")->assertSessionHasErrors('status');
        $this->assertSame('En ejecución', $contract->fresh()->status);

        $contract->update(['product_delivered' => true]);
        $this->as($this->admin)->patch("/post-adjudicacion/{$contract->id}/completar")->assertSessionHasNoErrors()->assertSessionHas('success', 'Contrato cerrado.');
        $this->as($this->admin)->patch("/post-adjudicacion/{$contract->id}/completar")->assertSessionHas('success', 'El contrato ya estaba cerrado.');

        $contract->refresh();
        $this->assertSame('Cerrado', $contract->status);
        $this->assertNotNull($contract->closed_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'contrato.cerrado')->count());

        // Cerrado: el fin de contrato deja de avisar.
        $end = Event::query()->withoutGlobalScopes()->where('source_key', "contract:{$contract->id}:FinContrato")->sole();
        $this->assertNotNull($end->cancelled_at);
    }

    public function test_deliveries_without_real_date_no_longer_fail_and_do_not_touch_the_contract_status(): void
    {
        $contract = $this->contract(['status' => ContractStatus::IN_PROGRESS]);

        // Antes: «Undefined array key delivered_at» (500) y el estado de la entrega en el contrato.
        $this->as($this->operator)->post("/post-adjudicacion/{$contract->id}/entregas", ['description' => 'Lote 1', 'quantity' => 10, 'unit' => 'Cajas', 'due_date' => '2026-12-10'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->as($this->operator)->post("/post-adjudicacion/{$contract->id}/entregas", ['description' => 'Lote 2', 'quantity' => 1, 'status' => 'Cualquier cosa'])
            ->assertSessionHasErrors('status');

        $delivery = ContractDelivery::query()->where('description', 'Lote 1')->sole();
        $this->assertSame('Pendiente', $delivery->status);
        $this->assertNull($delivery->delivered_at);
        $this->assertSame('En ejecución', $contract->fresh()->status);

        // Su fecha comprometida va al calendario con recordatorios al oficial.
        $event = Event::query()->withoutGlobalScopes()->where('source_key', "contract_delivery:{$delivery->id}:Entrega")->sole();
        $this->assertSame('2026-12-10', $event->starts_at->toDateString());
        $this->assertTrue(ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->where('user_id', $this->operator->id)->exists());

        $this->as($this->operator)->put("/post-adjudicacion/{$contract->id}/entregas/{$delivery->id}", ['description' => 'Lote 1 corregido', 'quantity' => 12, 'due_date' => '2026-12-11', 'status' => 'Pendiente'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-12-11', $event->fresh()->starts_at->toDateString());
    }

    public function test_marking_all_deliveries_delivered_with_evidence_suggests_product_delivered(): void
    {
        $contract = $this->contract(['status' => ContractStatus::IN_PROGRESS]);
        $delivery = ContractDelivery::factory()->create(['contract_id' => $contract->id, 'due_date' => '2026-12-01']);

        $this->as($this->operator)->post("/post-adjudicacion/{$contract->id}/entregas/{$delivery->id}/entregada", [
            'delivered_at' => '2026-11-16',
            'evidence' => $this->pdf('acta-recepcion.pdf'),
        ])->assertSessionHasNoErrors()->assertSessionHas('success', fn (string $message) => str_contains($message, 'Producto entregado'));

        $delivery->refresh();
        $this->assertSame('Entregada', $delivery->status);
        $this->assertSame('2026-11-16', $delivery->delivered_at->toDateString());
        $document = Document::query()->findOrFail($delivery->evidence_document_id);
        $this->assertDatabaseHas('document_links', ['document_id' => $document->id, 'linkable_type' => Contract::class, 'linkable_id' => $contract->id, 'category' => 'Acta de recepción']);

        // El estado del contrato no cambia solo: se propone.
        $this->assertSame('En ejecución', $contract->fresh()->status);
        $event = Event::query()->withoutGlobalScopes()->where('source_key', "contract_delivery:{$delivery->id}:Entrega")->sole();
        $this->assertTrue($event->completed);

        // Fecha real futura: rechazada.
        $other = ContractDelivery::factory()->create(['contract_id' => $contract->id]);
        $this->as($this->operator)->post("/post-adjudicacion/{$contract->id}/entregas/{$other->id}/entregada", ['delivered_at' => '2027-05-01'])->assertSessionHasErrors('delivered_at');
    }

    public function test_follow_up_with_next_action_creates_a_calendar_event(): void
    {
        $contract = $this->contract(['status' => ContractStatus::IN_PROGRESS]);

        $this->as($this->operator)->post("/post-adjudicacion/{$contract->id}/seguimientos", [
            'type' => 'Llamada', 'description' => 'Se confirmó la recepción del lote 1', 'occurred_at' => '2026-11-16',
            'next_action' => 'Enviar factura', 'next_action_at' => '2026-11-20',
        ])->assertSessionHasNoErrors();

        $followUp = ContractFollowUp::query()->sole();
        $event = Event::query()->withoutGlobalScopes()->where('source_key', "contract_follow_up:{$followUp->id}:Seguimiento")->sole();
        $this->assertSame('2026-11-20', $event->starts_at->toDateString());
        $this->assertStringContainsString('Enviar factura', $event->title);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contrato.seguimiento.creado', 'resource_id' => $contract->id]);

        $this->as($this->operator)->post("/post-adjudicacion/{$contract->id}/seguimientos", ['description' => 'X', 'next_action' => 'Llamar'])
            ->assertSessionHasErrors('next_action_at');
    }

    public function test_the_official_receives_the_end_of_contract_reminder(): void
    {
        [$official] = $this->memberOf($this->company, 'operator');

        $this->as($this->admin)->post('/post-adjudicacion', [
            'number' => 'CTR-AVISO', 'name' => 'Aviso', 'awarded_amount' => 100, 'status' => 'En ejecución',
            'manager_id' => $official->id, 'start_date' => '2026-11-20', 'duration_days' => 60,
        ])->assertSessionHasNoErrors();

        $contract = Contract::query()->where('number', 'CTR-AVISO')->sole();
        $end = Event::query()->withoutGlobalScopes()->where('source_key', "contract:{$contract->id}:FinContrato")->sole();
        $this->assertSame($official->id, $end->owner_id);
        $this->assertSame('2027-01-19', $end->starts_at->toDateString());

        $toOfficial = ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $end->id)->where('user_id', $official->id)->pluck('offset_key')->unique()->sort()->values()->all();
        $this->assertSame(['d1', 'd15', 'd30', 'd7'], $toOfficial);
    }

    public function test_contract_pdf_is_attached_with_its_category(): void
    {
        $contract = $this->contract();

        $this->as($this->operator)->postJson("/adjuntos/contract/{$contract->id}", ['file' => $this->pdf('contrato-firmado.pdf'), 'category' => 'Contrato'])->assertCreated();
        $this->as($this->viewer)->getJson("/adjuntos/contract/{$contract->id}")->assertOk()->assertJsonPath('data.0.category', 'Contrato')->assertJsonPath('can.edit', false);
        $this->as($this->viewer)->postJson("/adjuntos/contract/{$contract->id}", ['file' => $this->pdf()])->assertForbidden();

        $this->as($this->operator)->get("/post-adjudicacion/{$contract->id}")
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('history.0.action', 'documento.subido')));
    }

    public function test_roles(): void
    {
        $contract = $this->contract();
        $delivery = ContractDelivery::factory()->create(['contract_id' => $contract->id]);

        $this->as($this->viewer)->get('/post-adjudicacion')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.create', false)->where('can.manageHolidays', false));
        $this->as($this->viewer)->post("/post-adjudicacion/{$contract->id}/entregas", ['description' => 'X', 'quantity' => 1])->assertForbidden();
        $this->as($this->viewer)->post("/post-adjudicacion/{$contract->id}/seguimientos", ['description' => 'X'])->assertForbidden();
        $this->as($this->viewer)->put("/post-adjudicacion/{$contract->id}", ['number' => 'X', 'name' => 'X', 'amount' => 1])->assertForbidden();
        $this->as($this->viewer)->patch("/post-adjudicacion/{$contract->id}/estado", ['status' => 'En ejecución'])->assertForbidden();
        $this->as($this->viewer)->delete("/post-adjudicacion/{$contract->id}/entregas/{$delivery->id}")->assertForbidden();

        $this->as($this->operator)->delete("/post-adjudicacion/{$contract->id}")->assertForbidden();
        $this->as($this->operator)->postJson('/post-adjudicacion/feriados', ['holiday_date' => '2026-12-24', 'name' => 'Nochebuena'])->assertForbidden();
        $this->as($this->operator)->getJson('/post-adjudicacion/feriados')->assertOk()->assertJsonPath('can.manage', false);

        $this->as($this->admin)->delete("/post-adjudicacion/{$contract->id}")->assertRedirect('/post-adjudicacion');
        $this->assertDatabaseMissing('contracts', ['id' => $contract->id]);
        $this->assertDatabaseMissing('contract_deliveries', ['id' => $delivery->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contrato.eliminado']);
    }

    public function test_isolation_between_companies(): void
    {
        [$foreignAdmin, $foreignCompany] = $this->memberOf();
        $foreign = Contract::factory()->for($foreignCompany)->create(['number' => 'CTR-AJENO']);
        $foreignDelivery = ContractDelivery::factory()->create(['contract_id' => $foreign->id]);
        $foreignHoliday = $this->inCompany($foreignCompany, fn () => Holiday::create(['company_id' => $foreignCompany->id, 'holiday_date' => '2026-12-31', 'name' => 'Ajeno']));
        $own = $this->contract();

        $this->as($this->admin)->get("/post-adjudicacion/{$foreign->id}")->assertNotFound();
        $this->as($this->admin)->post("/post-adjudicacion/{$foreign->id}/entregas/{$foreignDelivery->id}/entregada", ['delivered_at' => '2026-11-16'])->assertNotFound();
        $this->as($this->admin)->post("/post-adjudicacion/{$own->id}/entregas/{$foreignDelivery->id}/entregada", ['delivered_at' => '2026-11-16'])->assertNotFound();
        $this->as($this->admin)->postJson("/post-adjudicacion/feriados/{$foreignHoliday->id}", ['_method' => 'DELETE'])->assertNotFound();
        $this->as($this->admin)->put("/post-adjudicacion/{$own->id}", ['number' => $own->number, 'name' => 'X', 'amount' => 1, 'manager_id' => $foreignAdmin->id])->assertSessionHasErrors('manager_id');

        // Los oficiales posibles son solo miembros de la empresa activa.
        $this->as($this->admin)->get('/post-adjudicacion')->assertInertia(fn (Assert $page) => $page
            ->where('officials', fn ($officials) => collect($officials)->pluck('id')->sort()->values()->all() === collect([$this->admin->id, $this->operator->id, $this->viewer->id])->sort()->values()->all())
            ->has('contracts.data', 1));

        $this->assertSame('CTR-AJENO', $foreign->fresh()->number);
        $this->assertSame('Pendiente', $foreignDelivery->fresh()->status);
    }

    public function test_contrato_parameter_opens_the_record_and_list_shows_stats(): void
    {
        $contract = $this->contract(['status' => ContractStatus::IN_PROGRESS, 'awarded_amount' => 500, 'end_date' => '2026-12-01']);
        $this->contract(['status' => ContractStatus::CLOSED, 'awarded_amount' => 900, 'end_date' => '2026-11-20']);
        ContractDelivery::factory()->create(['contract_id' => $contract->id]);
        ContractDelivery::factory()->delivered()->create(['contract_id' => $contract->id]);

        $this->as($this->viewer)->get("/post-adjudicacion?contrato={$contract->id}")->assertRedirect("/post-adjudicacion/{$contract->id}");

        $this->as($this->viewer)->get('/post-adjudicacion?group=activos')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Contracts/Index')
            ->has('contracts.data', 1)
            ->where('contracts.data.0.deliveries_total', 2)
            ->where('contracts.data.0.deliveries_done', 1)
            ->where('stats.in_progress', 1)
            ->where('stats.expiring', 1)
            ->where('stats.pending_deliveries', 1)
            ->where('stats.open_amount', 500)
            ->where('groups.todos', 2)
            ->where('groups.finalizados', 1));
    }

    public function test_holidays_are_managed_by_the_admin(): void
    {
        $list = $this->as($this->admin)->getJson('/post-adjudicacion/feriados')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(22, count($list));
        $this->assertTrue(collect($list)->firstWhere('holiday_date', '2027-10-06')['is_pending_review']);

        $created = $this->as($this->admin)->postJson('/post-adjudicacion/feriados', ['holiday_date' => '2026-12-24', 'name' => 'Nochebuena'])->assertCreated()->json('data');
        $this->as($this->admin)->postJson('/post-adjudicacion/feriados', ['holiday_date' => '2026-12-24', 'name' => 'Otra'])->assertJsonValidationErrors('holiday_date');
        $this->as($this->admin)->postJson("/post-adjudicacion/feriados/{$created['id']}", ['_method' => 'PUT', 'holiday_date' => '2026-12-24', 'name' => 'Nochebuena (medio día)'])->assertOk();
        $this->as($this->admin)->postJson("/post-adjudicacion/feriados/{$created['id']}", ['_method' => 'DELETE'])->assertOk();

        $this->assertSoftDeleted('holidays', ['id' => $created['id']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'feriado.eliminado']);
    }
}
