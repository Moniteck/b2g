<?php

namespace Tests\Feature\Calendar;

use App\Jobs\SendReminderEmail;
use App\Mail\ReminderMail;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractFollowUp;
use App\Models\Document;
use App\Models\Event;
use App\Models\Opportunity;
use App\Models\ReminderDelivery;
use App\Models\ReminderRule;
use App\Models\Tender;
use App\Services\Notifications\ReminderDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Motor de recordatorios: eventos derivados por observadores, plan de avisos, reprogramación,
 * cancelación, despacho sin duplicados y reintentos. Todo en hora de Honduras.
 */
class ReminderEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes 16 de noviembre de 2026, 10:00 a. m. en Tegucigalpa.
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'America/Tegucigalpa'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> offset => fecha y hora programada (de un canal y destinatario) */
    private function plan(Event $event, string $channel = 'campana', ?int $userId = null): array
    {
        return ReminderDelivery::query()->withoutGlobalScopes()
            ->where('event_id', $event->id)
            ->where('channel', $channel)
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->whereIn('status', ReminderDelivery::PENDING)
            ->orderBy('scheduled_for')
            ->get()
            ->unique('offset_key')
            ->mapWithKeys(fn (ReminderDelivery $delivery) => [$delivery->offset_key => $delivery->scheduled_for->format('Y-m-d H:i')])
            ->all();
    }

    private function eventFor(string $key): Event
    {
        return Event::query()->withoutGlobalScopes()->where('source_key', $key)->firstOrFail();
    }

    public function test_creating_a_tender_generates_its_events_and_the_default_reminders(): void
    {
        [$owner, $company] = $this->memberOf(null, 'operator');
        [$admin] = $this->memberOf($company, 'admin');

        $tender = Tender::factory()->for($company)->create([
            'owner_id' => $owner->id,
            'reference' => 'LPN-007-2026',
            'published_at' => '2026-11-10',
            'clarification_deadline' => '2026-11-27 17:00:00',
            'presentation_deadline' => '2026-12-04 10:00:00',
            'opening_at' => '2026-12-04 10:15:00',
        ]);

        $types = Event::query()->withoutGlobalScopes()->where('tender_id', $tender->id)->pluck('type')->sort()->values()->all();
        $this->assertSame(['Aclaraciones', 'Apertura', 'Licitacion', 'Presentacion'], $types);

        $clarification = $this->eventFor("tender:{$tender->id}:Aclaraciones");
        $this->assertSame([
            'd7' => '2026-11-20 08:00',
            'd3' => '2026-11-24 08:00',
            'd2' => '2026-11-25 08:00',
            'd1' => '2026-11-26 08:00',
            'd0' => '2026-11-27 08:00',
        ], $this->plan($clarification));

        $presentation = $this->eventFor("tender:{$tender->id}:Presentacion");
        $this->assertSame('2026-12-04 07:00', $this->plan($presentation)['d0']);

        $opening = $this->eventFor("tender:{$tender->id}:Apertura");
        $this->assertSame(['d1' => '2026-12-03 07:00', 'd0' => '2026-12-04 07:00'], $this->plan($opening));

        // La barra de inicio a fin: publicación → presentación.
        $start = $this->eventFor("tender:{$tender->id}:Licitacion");
        $this->assertSame('2026-12-04 10:00', $start->ends_at->format('Y-m-d H:i'));
        $this->assertSame([], $this->plan($start));

        // Destinatarios: responsable y administradores, por usuario y en los dos canales.
        $recipients = ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $clarification->id)->where('offset_key', 'd7')->get();
        $this->assertEqualsCanonicalizing(['campana', 'campana', 'correo', 'correo'], $recipients->pluck('channel')->all());
        $this->assertEqualsCanonicalizing([$owner->id, $admin->id], $recipients->pluck('user_id')->unique()->values()->all());
    }

    public function test_reminders_per_type_for_contracts_deliveries_follow_ups_and_documents(): void
    {
        [$manager, $company] = $this->memberOf(null, 'operator');

        $contract = Contract::factory()->for($company)->create([
            'manager_id' => $manager->id,
            'number' => 'CTR-001',
            'status' => 'En ejecucion',
            'start_date' => '2026-11-20',
            'end_date' => '2027-01-31',
        ]);

        $end = $this->eventFor("contract:{$contract->id}:FinContrato");
        $this->assertSame(['d30', 'd15', 'd7', 'd1'], array_keys($this->plan($end)));
        $this->assertSame('2027-01-01 07:00', $this->plan($end)['d30']);

        $delivery = ContractDelivery::factory()->create(['contract_id' => $contract->id, 'due_date' => '2026-12-10', 'description' => 'Lote 1']);
        $deliveryEvent = $this->eventFor("contract_delivery:{$delivery->id}:Entrega");
        $this->assertSame(['d7', 'd3', 'd1', 'd0'], array_keys($this->plan($deliveryEvent)));
        $this->assertSame($contract->id, $deliveryEvent->contract_id);

        $followUp = ContractFollowUp::factory()->create(['contract_id' => $contract->id, 'user_id' => $manager->id, 'next_action' => 'Llamar al administrador', 'next_action_at' => '2026-11-18']);
        $followUpEvent = $this->eventFor("contract_follow_up:{$followUp->id}:Seguimiento");
        $this->assertSame(['d0' => '2026-11-18 07:00'], $this->plan($followUpEvent));

        $document = Document::factory()->for($company)->create(['uploaded_by' => $manager->id, 'expires_at' => '2027-02-15']);
        $documentEvent = $this->eventFor("document:{$document->id}:Documento");
        $this->assertSame(['d30', 'd7', 'd1'], array_keys($this->plan($documentEvent)));

        // Entregada: el plazo se completa y deja de avisar.
        $delivery->update(['status' => 'Entregado', 'delivered_at' => now()]);
        $this->assertSame([], $this->plan($deliveryEvent->fresh()));
        $this->assertTrue($deliveryEvent->fresh()->completed);
    }

    public function test_a_deadline_loaded_with_less_than_a_day_of_margin_notifies_immediately(): void
    {
        [$owner, $company] = $this->memberOf();

        // Mañana a las 9:00: el aviso «1 día antes» (hoy 8:00) ya pasó, así que sale ya.
        $tender = Tender::factory()->for($company)->create([
            'owner_id' => $owner->id,
            'clarification_deadline' => '2026-11-17 09:00:00',
        ]);

        $plan = $this->plan($this->eventFor("tender:{$tender->id}:Aclaraciones"));
        $this->assertSame('2026-11-16 10:00', $plan['now']);
        $this->assertSame('2026-11-17 08:00', $plan['d0']);
        $this->assertArrayNotHasKey('d1', $plan);

        // Guardar otra vez el mismo registro no repite el aviso inmediato.
        $tender->update(['notes' => 'Revisado']);
        $this->assertSame(1, ReminderDelivery::query()->withoutGlobalScopes()->where('offset_key', 'now')->where('channel', 'campana')->where('user_id', $owner->id)->count());
    }

    public function test_same_day_reminder_moves_before_an_early_deadline(): void
    {
        [$owner, $company] = $this->memberOf();

        $tender = Tender::factory()->for($company)->create([
            'owner_id' => $owner->id,
            'presentation_deadline' => '2026-11-25 06:30:00',
            'opening_at' => '2026-11-25 06:45:00',
        ]);

        $plan = $this->plan($this->eventFor("tender:{$tender->id}:Presentacion"));
        $this->assertSame('2026-11-25 05:30', $plan['d0']);
    }

    public function test_moving_a_date_reschedules_pending_reminders_and_keeps_the_sent_ones(): void
    {
        [$owner, $company] = $this->memberOf();
        $tender = Tender::factory()->for($company)->create([
            'owner_id' => $owner->id,
            'clarification_deadline' => '2026-11-20 17:00:00',
        ]);
        $event = $this->eventFor("tender:{$tender->id}:Aclaraciones");

        // Hoy a las 10:00 ya salió el aviso inmediato/«3 días antes» del lunes... avanzamos al 17 a las 8:00.
        Carbon::setTestNow(Carbon::parse('2026-11-17 08:00:30'));
        Mail::fake();
        app(ReminderDispatcher::class)->dispatchDue();
        $sent = ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->where('status', 'Enviado')->get();
        $this->assertNotEmpty($sent);
        $this->assertTrue($sent->every(fn ($row) => $row->offset_key === 'd3'));

        $pendingIds = ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->where('status', 'Programado')->pluck('id')->sort()->values();

        $tender->update(['clarification_deadline' => '2026-11-27 17:00:00']);

        $event->refresh();
        $this->assertSame('2026-11-27 17:00', $event->starts_at->format('Y-m-d H:i'));
        $this->assertSame($event->id, $this->eventFor("tender:{$tender->id}:Aclaraciones")->id, 'El evento se actualiza en su sitio.');

        // Los enviados siguen intactos.
        foreach ($sent as $row) {
            $this->assertSame('Enviado', $row->fresh()->status);
            $this->assertSame('2026-11-20 17:00', $row->fresh()->due_at->format('Y-m-d H:i'));
        }

        // Los pendientes se reprogramaron en su fila (mismos ids) para la nueva fecha.
        $plan = $this->plan($event);
        $this->assertSame('2026-11-20 08:00', $plan['d7']);
        $this->assertSame('2026-11-26 08:00', $plan['d1']);
        $this->assertSame('2026-11-27 08:00', $plan['d0']);
        $rescheduled = ReminderDelivery::query()->withoutGlobalScopes()->whereIn('id', $pendingIds)->get();
        $this->assertTrue($rescheduled->every(fn ($row) => $row->status === 'Programado' && $row->due_at->format('Y-m-d') === '2026-11-27'));
        $this->assertSame(0, ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->where('status', 'Programado')->where('due_at', '2026-11-20 17:00:00')->count());
    }

    public function test_deleting_or_closing_the_source_cancels_pending_reminders_without_losing_history(): void
    {
        [$owner, $company] = $this->memberOf();
        $tender = Tender::factory()->for($company)->create(['owner_id' => $owner->id]);
        $event = $this->eventFor("tender:{$tender->id}:Presentacion");
        ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->limit(1)->update(['status' => 'Enviado', 'sent_at' => now()]);

        $tender->update(['status' => 'Cancelada']);

        $event->refresh();
        $this->assertNotNull($event->cancelled_at);
        $this->assertSame(0, ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->whereIn('status', ReminderDelivery::PENDING)->count());
        $this->assertSame(1, ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->where('status', 'Enviado')->count());

        // Reabrir la licitación reactiva sus plazos.
        $tender->update(['status' => 'Activa']);
        $this->assertNull($event->fresh()->cancelled_at);
        $this->assertNotEmpty($this->plan($event));

        $tender->delete();
        $this->assertNotNull($event->fresh()->cancelled_at);
        $this->assertSame(0, ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->whereIn('status', ReminderDelivery::PENDING)->count());
    }

    public function test_a_closed_contract_does_not_recreate_events_and_cancels_children(): void
    {
        [$manager, $company] = $this->memberOf();
        $contract = Contract::factory()->for($company)->create(['manager_id' => $manager->id, 'status' => 'En ejecucion', 'start_date' => '2026-11-20', 'end_date' => '2027-03-01']);
        $delivery = ContractDelivery::factory()->create(['contract_id' => $contract->id, 'due_date' => '2026-12-01']);

        $contract->update(['status' => 'Cerrado']);
        $contract->update(['notes' => 'Otra edición']);

        $events = Event::query()->withoutGlobalScopes()->where('contract_id', $contract->id)->get();
        $this->assertCount(3, $events, 'No se recrean eventos: siguen siendo los mismos tres.');
        $this->assertTrue($events->every(fn (Event $event) => $event->cancelled_at !== null));
        $this->assertSame(0, ReminderDelivery::query()->withoutGlobalScopes()->whereIn('event_id', $events->pluck('id'))->whereIn('status', ReminderDelivery::PENDING)->count());
        $this->assertNotNull($this->eventFor("contract_delivery:{$delivery->id}:Entrega")->cancelled_at);
    }

    public function test_an_opportunity_with_result_cancels_the_offer_reminders_and_reverting_restores_them(): void
    {
        [$owner, $company] = $this->memberOf();
        $tender = Tender::factory()->for($company)->create(['owner_id' => $owner->id]);
        $opportunity = Opportunity::factory()->for($company)->create(['tender_id' => $tender->id, 'owner_id' => $owner->id, 'stage' => 'oferta_presentada', 'expected_close_date' => '2026-12-15']);

        $opportunity->update(['stage' => 'no_adjudicado']);
        $presentation = $this->eventFor("tender:{$tender->id}:Presentacion");
        $this->assertNotNull($presentation->cancelled_at);
        $this->assertSame([], $this->plan($presentation));

        $opportunity->update(['stage' => 'oferta_presentada']);
        $this->assertNull($presentation->fresh()->cancelled_at);
        $this->assertNotEmpty($this->plan($presentation));
    }

    public function test_dispatching_twice_never_duplicates_and_emails_go_out_once(): void
    {
        Mail::fake();
        [$owner, $company] = $this->memberOf();
        $tender = Tender::factory()->for($company)->create(['owner_id' => $owner->id, 'reference' => 'LPN-777', 'clarification_deadline' => '2026-11-17 17:00:00']);

        $dispatcher = app(ReminderDispatcher::class);
        $first = $dispatcher->dispatchDue();
        $second = $dispatcher->dispatchDue();

        $this->assertSame(1, $first['campana']);
        $this->assertSame(1, $first['correo']);
        $this->assertSame(['campana' => 0, 'correo' => 0, 'cancelados' => 0], $second);
        Mail::assertSent(ReminderMail::class, 1);
        Mail::assertSent(ReminderMail::class, fn (ReminderMail $mail) => $mail->hasTo($owner->email)
            && $mail->subjectLine() === 'Mañana cierra el plazo de aclaraciones: LPN-777');

        $this->assertSame(2, ReminderDelivery::query()->withoutGlobalScopes()->where('status', 'Enviado')->count());
    }

    public function test_a_failing_email_is_retried_and_ends_as_failed(): void
    {
        [$owner, $company] = $this->memberOf();
        $tender = Tender::factory()->for($company)->create(['owner_id' => $owner->id, 'clarification_deadline' => '2026-11-17 17:00:00']);
        $delivery = ReminderDelivery::query()->withoutGlobalScopes()->where('channel', 'correo')->where('offset_key', 'now')->firstOrFail();
        $delivery->update(['status' => 'Encolado']);

        Mail::shouldReceive('to')->andThrow(new RuntimeException('cURL error 60'));

        $job = new SendReminderEmail($delivery->id);
        foreach ([1, 2, 3] as $attempt) {
            try {
                $job->handle();
                $this->fail('El envío debía fallar.');
            } catch (RuntimeException) {
                $this->assertSame($attempt, $delivery->fresh()->attempts);
            }
        }

        $delivery->refresh();
        $this->assertSame('Fallido', $delivery->status);
        $this->assertSame('cURL error 60', $delivery->error);
        $this->assertSame([60, 300, 900], $job->backoff);
    }

    public function test_company_rules_change_offsets_channels_and_extra_recipients(): void
    {
        [$owner, $company] = $this->memberOf();
        ReminderRule::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'event_type' => 'Aclaraciones', 'days_before' => [2], 'send_time' => '09:30',
            'channels' => ['correo'], 'recipients' => ['legal@example.hn'], 'is_active' => true,
        ]);

        $tender = Tender::factory()->for($company)->create(['owner_id' => $owner->id, 'clarification_deadline' => '2026-11-27 17:00:00']);
        $event = $this->eventFor("tender:{$tender->id}:Aclaraciones");

        $rows = ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->get();
        $this->assertSame(['correo'], $rows->pluck('channel')->unique()->values()->all());
        $this->assertSame(['d2'], $rows->pluck('offset_key')->unique()->values()->all());
        $this->assertSame('2026-11-25 09:30', $rows->first()->scheduled_for->format('Y-m-d H:i'));
        $this->assertEqualsCanonicalizing([$owner->email, 'legal@example.hn'], $rows->pluck('recipient')->all());
    }

    public function test_members_of_other_companies_never_receive_reminders(): void
    {
        [$owner, $company] = $this->memberOf();
        [$foreignAdmin] = $this->memberOf(Company::factory()->create(), 'admin');
        [$viewer] = $this->memberOf($company, 'viewer');

        // Un responsable que no es miembro de la empresa no recibe nada.
        $tender = Tender::factory()->for($company)->create(['owner_id' => $foreignAdmin->id]);
        $userIds = ReminderDelivery::query()->withoutGlobalScopes()->whereHas('event', fn ($q) => $q->withoutGlobalScopes()->where('tender_id', $tender->id))->pluck('user_id')->unique()->values()->all();

        $this->assertSame([$owner->id], $userIds);
        $this->assertNotContains($viewer->id, $userIds);
    }
}
