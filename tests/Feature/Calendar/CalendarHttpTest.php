<?php

namespace Tests\Feature\Calendar;

use App\Mail\DailyDigestMail;
use App\Models\Company;
use App\Models\Event;
use App\Models\ReminderDelivery;
use App\Models\ReminderRule;
use App\Models\Tender;
use App\Services\Notifications\DailyDigestService;
use App\Services\Notifications\ReminderDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pantallas y rutas del módulo: calendario por rango, eventos manuales, atendido, reglas,
 * campana, «Mis recordatorios», resumen diario, dashboard, roles y aislamiento entre empresas.
 */
class CalendarHttpTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_viewer_sees_the_calendar_and_loads_events_by_visible_range(): void
    {
        [$admin, $company] = $this->memberOf();
        [$viewer] = $this->memberOf($company, 'viewer');

        Tender::factory()->for($company)->create([
            'owner_id' => $admin->id, 'reference' => 'LPN-RANGO',
            'published_at' => '2026-10-20', 'clarification_deadline' => '2026-11-25 17:00:00',
            'presentation_deadline' => '2026-12-10 10:00:00', 'opening_at' => '2026-12-10 10:30:00',
        ]);

        $this->actingInCompany($viewer, $company)->get('/calendario')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Calendar/Index')
                ->where('can.create', false)
                ->where('can.manage_rules', false)
                ->where('rules', null)
                ->has('types', count(Event::TYPES)));

        $response = $this->actingInCompany($viewer, $company)->getJson('/calendario/eventos?from=2026-11-01&to=2026-11-30')->assertOk();
        $types = collect($response->json('events'))->pluck('type')->sort()->values()->all();
        // La barra de la licitación empieza en octubre pero cruza noviembre; presentación y apertura caen en diciembre.
        $this->assertSame(['Aclaraciones', 'Licitacion'], $types);

        $this->actingInCompany($viewer, $company)->getJson('/calendario/eventos?from=2026-11-01&to=2026-11-30&types[]=Aclaraciones')
            ->assertOk()->assertJsonCount(1, 'events');

        $this->actingInCompany($viewer, $company)->getJson('/calendario/eventos?from=2026-01-01&to=2026-12-31')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_operator_creates_edits_and_attends_a_manual_event_viewer_cannot(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');

        $payload = [
            'title' => 'Visita a la ENEE', 'type' => 'Manual', 'starts_at' => '2026-11-20T09:00', 'ends_at' => '2026-11-20T11:00',
            'all_day' => false, 'description' => 'Llevar muestras', 'custom_reminders' => true, 'reminders' => [2, 0], 'recipients' => [$viewer->id],
        ];

        $this->actingInCompany($viewer, $company)->post('/calendario', $payload)->assertForbidden();
        $this->actingInCompany($operator, $company)->post('/calendario', [...$payload, 'ends_at' => '2026-11-19T09:00'])
            ->assertSessionHasErrors('ends_at');

        $this->actingInCompany($operator, $company)->from('/calendario')->post('/calendario', $payload)
            ->assertRedirect('/calendario')->assertSessionHas('success');

        $event = Event::query()->withoutGlobalScopes()->where('title', 'Visita a la ENEE')->firstOrFail();
        $this->assertSame($company->id, $event->company_id);
        $this->assertSame([2, 0], $event->reminder_offsets);
        $offsets = ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->pluck('offset_key')->unique()->sort()->values()->all();
        $this->assertSame(['d0', 'd2'], $offsets);
        // Destinatarios: creador, administrador y el elegido.
        $users = ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->pluck('user_id')->unique()->sort()->values()->all();
        $this->assertEqualsCanonicalizing([$admin->id, $operator->id, $viewer->id], $users);

        $this->actingInCompany($operator, $company)->put("/calendario/{$event->id}", [...$payload, 'starts_at' => '2026-11-24T09:00', 'ends_at' => null])
            ->assertSessionHas('success');
        $this->assertSame('2026-11-24 09:00', $event->fresh()->starts_at->format('Y-m-d H:i'));

        $detail = $this->actingInCompany($operator, $company)->getJson("/calendario/{$event->id}")->assertOk();
        $detail->assertJsonPath('can.attend', true)->assertJsonPath('can.update', true)->assertJsonPath('can.delete', true);
        $this->assertNotEmpty($detail->json('deliveries.scheduled'));

        $this->actingInCompany($viewer, $company)->post("/calendario/{$event->id}/atendido")->assertForbidden();
        $this->actingInCompany($operator, $company)->post("/calendario/{$event->id}/atendido")->assertSessionHas('success');
        $this->actingInCompany($operator, $company)->post("/calendario/{$event->id}/atendido")->assertSessionHas('success', 'Este plazo ya estaba atendido.');

        $event->refresh();
        $this->assertSame($operator->id, $event->attended_by);
        $this->assertNotNull($event->attended_at);
        $this->assertSame(0, ReminderDelivery::query()->withoutGlobalScopes()->where('event_id', $event->id)->whereIn('status', ReminderDelivery::PENDING)->count());

        // Atendido: aunque llegue la hora, nadie recibe avisos.
        Carbon::setTestNow(Carbon::parse('2026-11-24 08:00:00'));
        $this->assertSame(['campana' => 0, 'correo' => 0, 'cancelados' => 0], app(ReminderDispatcher::class)->dispatchDue());

        $this->actingInCompany($viewer, $company)->delete("/calendario/{$event->id}")->assertForbidden();
        $this->actingInCompany($operator, $company)->delete("/calendario/{$event->id}")->assertSessionHas('success');
        $this->assertNull(Event::query()->withoutGlobalScopes()->find($event->id));
    }

    public function test_derived_events_cannot_be_edited_or_deleted_by_hand(): void
    {
        [$admin, $company] = $this->memberOf();
        $tender = Tender::factory()->for($company)->create(['owner_id' => $admin->id]);
        $event = Event::query()->withoutGlobalScopes()->where('source_key', "tender:{$tender->id}:Presentacion")->firstOrFail();

        $this->actingInCompany($admin, $company)->delete("/calendario/{$event->id}")->assertSessionHasErrors('event');
        $this->actingInCompany($admin, $company)->put("/calendario/{$event->id}", ['title' => 'x', 'type' => 'Manual', 'starts_at' => '2026-11-20T09:00'])
            ->assertSessionHasErrors('event');
        $this->actingInCompany($admin, $company)->getJson("/calendario/{$event->id}")
            ->assertJsonPath('can.update', false)->assertJsonPath('can.attend', true)
            ->assertJsonPath('event.href', "/licitaciones?licitacion={$tender->id}");
    }

    public function test_another_company_events_are_invisible(): void
    {
        [$admin, $company] = $this->memberOf();
        [$otherAdmin, $other] = $this->memberOf(Company::factory()->create());
        Tender::factory()->for($other)->create(['owner_id' => $otherAdmin->id, 'clarification_deadline' => '2026-11-20 10:00:00']);
        $foreign = Event::query()->withoutGlobalScopes()->where('company_id', $other->id)->firstOrFail();

        $this->actingInCompany($admin, $company)->getJson('/calendario/eventos?from=2026-11-01&to=2026-11-30')->assertJsonCount(0, 'events');
        $this->actingInCompany($admin, $company)->getJson("/calendario/{$foreign->id}")->assertNotFound();
        $this->actingInCompany($admin, $company)->post("/calendario/{$foreign->id}/atendido")->assertNotFound();
    }

    public function test_only_admins_manage_reminder_rules(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        $payload = ['rules' => [[
            'event_type' => 'Aclaraciones', 'is_active' => true, 'days_before' => [5, 1, 0], 'send_time' => '09:00',
            'channels' => ['campana'], 'recipients' => 'legal@example.hn, compras@example.hn',
        ]]];

        $this->actingInCompany($operator, $company)->put('/calendario/reglas', $payload)->assertForbidden();
        $this->actingInCompany($admin, $company)->put('/calendario/reglas', ['rules' => [[...$payload['rules'][0], 'send_time' => '25:00']]])
            ->assertSessionHasErrors('rules.0.send_time');
        $this->actingInCompany($admin, $company)->put('/calendario/reglas', $payload)->assertSessionHas('success');

        $rule = ReminderRule::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('event_type', 'Aclaraciones')->firstOrFail();
        $this->assertSame([5, 1, 0], $rule->days_before);
        $this->assertSame(['legal@example.hn', 'compras@example.hn'], $rule->recipients);

        $this->actingInCompany($admin, $company)->get('/calendario')
            ->assertInertia(fn (Assert $page) => $page->where('can.manage_rules', true)->has('rules', count(Event::TYPES)));
    }

    public function test_bell_lists_unread_reminders_and_marks_them_read(): void
    {
        Mail::fake();
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        Tender::factory()->for($company)->create(['owner_id' => $operator->id, 'reference' => 'LPN-BELL', 'clarification_deadline' => '2026-11-17 12:00:00']);
        app(ReminderDispatcher::class)->dispatchDue();

        $this->actingInCompany($operator, $company)->get('/calendario')
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.unread', 1)
                ->where('notifications.items.0.title', 'Mañana cierra el plazo de aclaraciones')
                ->where('notifications.items.0.severity', 'warning')
                ->has('notifications.items.0.href')
                ->where('notifications.items.0.read_at', null));

        $mine = ReminderDelivery::query()->withoutGlobalScopes()->where('user_id', $operator->id)->where('channel', 'campana')->where('status', 'Enviado')->firstOrFail();
        $adminOwn = ReminderDelivery::query()->withoutGlobalScopes()->where('user_id', $admin->id)->where('channel', 'campana')->where('status', 'Enviado')->firstOrFail();

        $this->actingInCompany($operator, $company)->postJson("/notificaciones/{$adminOwn->id}/leer")->assertNotFound();
        $this->actingInCompany($operator, $company)->postJson("/notificaciones/{$mine->id}/leer")->assertNoContent();
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($adminOwn->fresh()->read_at);

        $this->actingInCompany($operator, $company)->get('/calendario')->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 0));

        $this->actingInCompany($admin, $company)->from('/calendario')->post('/notificaciones/leer-todas')->assertRedirect('/calendario');
        $this->assertNotNull($adminOwn->fresh()->read_at);

        // «Mis recordatorios» con filtros.
        $this->actingInCompany($operator, $company)->get('/notificaciones?estado=leidos&tipo=Aclaraciones')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Notifications/Index')->has('reminders.data', 1)->where('unread', 0));
        $this->actingInCompany($operator, $company)->get('/notificaciones?estado=no-leidos')
            ->assertInertia(fn (Assert $page) => $page->has('reminders.data', 0));
    }

    public function test_notification_templates_are_admin_only(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');

        $this->actingInCompany($operator, $company)->get('/notificaciones/plantillas')->assertForbidden();
        $this->actingInCompany($admin, $company)->get('/notificaciones/plantillas')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Notifications/Templates'));
        $this->actingInCompany($admin, $company)->post('/notificaciones/plantillas', ['name' => 'Aviso', 'channel' => 'correo', 'body' => 'Hola', 'active' => true])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('notification_templates', ['company_id' => $company->id, 'name' => 'Aviso']);
    }

    public function test_daily_digest_goes_to_responsible_users_and_admins_once(): void
    {
        Mail::fake();
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        [$idle] = $this->memberOf($company, 'operator');
        [$foreign, $other] = $this->memberOf(Company::factory()->create());

        Tender::factory()->for($company)->create(['owner_id' => $operator->id, 'clarification_deadline' => '2026-11-16 17:00:00', 'presentation_deadline' => '2026-11-20 10:00:00', 'opening_at' => '2026-11-20 10:30:00']);
        Tender::factory()->for($company)->create(['owner_id' => $admin->id, 'clarification_deadline' => '2026-11-19 17:00:00', 'presentation_deadline' => '2026-12-20 10:00:00', 'opening_at' => '2026-12-20 11:00:00']);
        Tender::factory()->for($other)->create(['owner_id' => $foreign->id, 'clarification_deadline' => '2026-11-30 17:00:00', 'presentation_deadline' => '2026-12-20 10:00:00', 'opening_at' => '2026-12-20 11:00:00']);

        $service = app(DailyDigestService::class);
        $this->assertSame(2, $service->sendForCompany($company->id));
        $this->assertSame(0, $service->sendForCompany($company->id), 'No se repite el mismo día.');
        $this->assertSame(0, $service->sendForCompany($other->id), 'Sin plazos en 7 días no se envía nada.');

        Mail::assertSent(DailyDigestMail::class, 2);
        Mail::assertSent(DailyDigestMail::class, fn (DailyDigestMail $mail) => $mail->hasTo($admin->email) && $mail->total === 4);
        Mail::assertSent(DailyDigestMail::class, fn (DailyDigestMail $mail) => $mail->hasTo($operator->email) && $mail->total === 3
            && str_starts_with($mail->subjectLine(), 'Hoy tienes 1 plazo'));
        Mail::assertNotSent(DailyDigestMail::class, fn (DailyDigestMail $mail) => $mail->hasTo($idle->email) || $mail->hasTo($foreign->email));
    }

    public function test_dashboard_lists_this_week_deadlines_of_the_active_company(): void
    {
        [$admin, $company] = $this->memberOf();
        [$otherAdmin, $other] = $this->memberOf(Company::factory()->create());
        $tender = Tender::factory()->for($company)->create(['owner_id' => $admin->id, 'clarification_deadline' => '2026-11-18 17:00:00']);
        Tender::factory()->for($other)->create(['owner_id' => $otherAdmin->id, 'clarification_deadline' => '2026-11-18 17:00:00']);

        $this->actingInCompany($admin, $company)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('deadlines', 1)
                ->where('deadlines.0.kind', 'tender')
                ->where('deadlines.0.href', "/licitaciones?licitacion={$tender->id}")
                ->where('deadlines.0.due_at', '2026-11-18 17:00:00'));
    }
}
