<?php

namespace Tests\Feature\Performance;

use App\Http\Controllers\DiagnosticsController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Mail\DailyDigestMail;
use App\Mail\QuoteMail;
use App\Models\Company;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\ReminderRule;
use App\Models\Tender;
use App\Models\User;
use App\Services\Notifications\DailyDigestService;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Presupuesto de consultas por endpoint (DB::enableQueryLog): si una pantalla vuelve a tener N+1 o
 * consultas duplicadas, la prueba falla. Los topes llevan algo de holgura sobre lo medido.
 * También: campana como prop «once», diagnóstico en caché y correos en cola.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        $this->institution = Institution::factory()->create();
    }

    /**
     * Ejecuta la petición contando consultas (sin las de sesión, que en pruebas van en memoria).
     *
     * @return array{0: TestResponse, 1: int, 2: array<int, string>}
     */
    private function counted(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $request();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return [$response, count($queries), $queries];
    }

    private function opportunityWithEverything(): Opportunity
    {
        return $this->inCompany($this->company, function () {
            $opportunity = Opportunity::factory()->for($this->company)->stage('oferta_presentada')->create(['institution_id' => $this->institution->id, 'amount' => 50000]);
            Quote::factory()->for($this->company)->count(3)->create(['institution_id' => $this->institution->id, 'opportunity_id' => $opportunity->id, 'status' => 'Emitida', 'total' => 1000]);

            return $opportunity;
        });
    }

    private function as(): static
    {
        return $this->actingInCompany($this->admin, $this->company);
    }

    public function test_pipeline_modal_endpoints_stay_within_their_query_budget(): void
    {
        $opportunity = $this->opportunityWithEverything();
        $this->as()->putJson("/oportunidades/{$opportunity->id}/participantes", ['participants' => [
            ['name' => 'CAP Honduras', 'amount' => 48000, 'is_ours' => true],
            ['name' => 'Rival', 'amount' => 45000],
        ]])->assertOk();

        $budgets = [
            ['GET', "/pipeline/{$opportunity->id}", 14],
            ['GET', "/pipeline/{$opportunity->id}/historial", 6],
            ['GET', "/pipeline/{$opportunity->id}/formulario", 6],
            ['GET', "/oportunidades/{$opportunity->id}/participantes", 5],
            ['GET', "/oportunidades/{$opportunity->id}/cotizaciones-vivas", 4],
        ];

        foreach ($budgets as [$method, $url, $max]) {
            [$response, $count, $queries] = $this->counted(fn () => $this->as()->json($method, $url));
            $response->assertOk();
            $this->assertLessThanOrEqual($max, $count, "{$url}: {$count} consultas\n".implode("\n", $queries));
        }

        // Guardar: no vuelve a leer participantes ni el sujeto después de guardarlos.
        [$response, $count, $queries] = $this->counted(fn () => $this->as()->putJson("/oportunidades/{$opportunity->id}/participantes", ['participants' => [
            ['name' => 'CAP Honduras', 'amount' => 47000, 'is_ours' => true],
            ['name' => 'Rival', 'amount' => 45000],
        ]]));
        $response->assertOk()->assertJsonCount(2, 'data');
        $reads = collect($queries)->filter(fn ($sql) => str_starts_with($sql, 'select') && str_contains($sql, 'from "quote_participants"') && str_contains($sql, 'order by "position"'))->count();
        $this->assertSame(1, $reads, "Las filas guardadas se leen una sola vez:\n".implode("\n", $queries));
        $this->assertLessThanOrEqual(8, $count, "PUT participantes: {$count} consultas\n".implode("\n", $queries));
    }

    public function test_calendar_reads_all_reminder_rules_in_one_query(): void
    {
        foreach (['Presentación de oferta', 'Apertura de ofertas', 'Manual'] as $type) {
            ReminderRule::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'event_type' => $type, 'days_before' => [3, 1], 'send_time' => '08:00', 'channels' => ['campana'], 'is_active' => $type !== 'Manual']);
        }

        [$response, $count, $queries] = $this->counted(fn () => $this->as()->get('/calendario'));
        $response->assertOk();

        $ruleQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'reminder_rules'))->count();
        $this->assertSame(1, $ruleQueries, implode("\n", $queries));
        $this->assertLessThanOrEqual(14, $count, "Calendario: {$count} consultas\n".implode("\n", $queries));
        $response->assertInertia(fn ($page) => $page->where('rules', fn ($rules) => collect($rules)->firstWhere('event_type', 'Manual')['is_active'] === false));
    }

    public function test_dashboard_and_shared_props_stay_within_budget(): void
    {
        [$response, $count, $queries] = $this->counted(fn () => $this->as()->get('/'));
        $response->assertOk();
        $this->assertLessThanOrEqual(30, $count, "Dashboard: {$count} consultas\n".implode("\n", $queries));

        // Empresas: la empresa activa sale de la misma lista (una sola consulta a `companies`).
        $companyQueries = collect($queries)->filter(fn ($sql) => preg_match('/from "companies"/', $sql))->count();
        $this->assertLessThanOrEqual(1, $companyQueries, implode("\n", $queries));
        $response->assertInertia(fn ($page) => $page->where('activeCompany.id', $this->company->id)->has('companies', 1));
    }

    public function test_notifications_travel_once_and_again_only_when_they_change(): void
    {
        $first = $this->as()->get('/calendario', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())]);
        $first->assertOk();
        $page = $first->json();
        $this->assertArrayHasKey('notifications', $page['props']);
        $key = collect($page['onceProps'] ?? [])->keys()->first(fn ($key) => str_starts_with($key, 'notifications.'));
        $this->assertNotNull($key, 'La campana se declara como prop «once».');

        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) ($page['version'] ?? ''), 'X-Inertia-Except-Once-Props' => $key];
        $second = $this->as()->get('/calendario', $headers)->json();
        $this->assertArrayNotHasKey('notifications', $second['props'], 'Si el cliente ya la tiene, no viaja.');

        // Cambia el contenido (otro recuento): cambia la versión y vuelve a viajar.
        Cache::put(NotificationFeed::cacheKey($this->admin->id, $this->company->id), ['unread' => 3, 'items' => []], 60);
        $third = $this->as()->get('/calendario', $headers)->json();
        $this->assertSame(3, $third['props']['notifications']['unread']);
    }

    public function test_companies_travel_once_and_again_when_the_active_company_or_memberships_change(): void
    {
        $other = Company::factory()->create(['name' => 'Empresa B']);
        $this->admin->companies()->attach($other->id, ['role' => 'operator']);
        $version = (string) app(HandleInertiaRequests::class)->version(request());

        $first = $this->as()->get('/calendario', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->json();
        $this->assertCount(2, $first['props']['companies']);
        $this->assertSame($this->company->id, $first['props']['activeCompany']['id']);
        $keys = collect($first['onceProps'])->keys()->filter(fn ($key) => preg_match('/^(companies|activeCompany)\./', $key))->values();
        $this->assertCount(2, $keys);
        $this->assertNotNull($first['onceProps'][$keys[0]]['expiresAt'], 'Caduca para recoger cambios de nombre.');

        // Ya las tiene: ni viajan ni se consulta `companies`.
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Except-Once-Props' => $keys->implode(',')];
        [$response, , $queries] = $this->counted(fn () => $this->as()->get('/calendario', $headers));
        $second = $response->json();
        $this->assertArrayNotHasKey('companies', $second['props']);
        $this->assertArrayNotHasKey('activeCompany', $second['props']);
        $this->assertSame(0, collect($queries)->filter(fn ($sql) => str_contains($sql, 'from "companies"'))->count(), implode("\n", $queries));

        // Cambiar de empresa activa renueva `activeCompany`.
        $switched = $this->actingInCompany($this->admin, $other)->get('/calendario', $headers)->json();
        $this->assertSame($other->id, $switched['props']['activeCompany']['id']);

        // Una membresía nueva cambia la huella: `companies` vuelve a viajar.
        $third = Company::factory()->create(['name' => 'Empresa C']);
        $this->admin->companies()->attach($third->id, ['role' => 'viewer']);
        // (usuario recién leído, como en cada petición real: las membresías se memorizan en el modelo)
        $fresh = $this->actingInCompany(User::query()->findOrFail($this->admin->id), $this->company)->get('/calendario', $headers)->json();
        $this->assertCount(3, $fresh['props']['companies']);
    }

    public function test_diagnostics_report_is_cached_and_can_be_refreshed(): void
    {
        $runs = 0;
        Process::fake(['*' => function () use (&$runs) {
            $runs++;

            return Process::result('tesseract v5.3.0');
        }]);

        $this->as()->get('/configuracion/diagnostico')->assertOk();
        $this->assertTrue(Cache::has(DiagnosticsController::CACHE_KEY));
        $ran = $runs;
        $this->assertGreaterThan(0, $ran);

        $this->as()->get('/configuracion/diagnostico')->assertOk();
        $this->assertSame($ran, $runs, 'La segunda carga no vuelve a invocar binarios.');

        $this->as()->get('/configuracion/diagnostico', ['X-Diagnostics-Fresh' => '1'])->assertOk();
        $this->assertGreaterThan($ran, $runs, '«Volver a comprobar» rehace el informe.');
    }

    public function test_quote_mail_and_daily_digest_are_queued(): void
    {
        Mail::fake();
        $quote = $this->inCompany($this->company, fn () => Quote::factory()->for($this->company)->create(['institution_id' => $this->institution->id, 'status' => 'Emitida']));

        $this->as()->post("/cotizaciones/{$quote->id}/email", ['recipients' => ['compras@salud.hn']])->assertSessionHasNoErrors();
        Mail::assertQueued(QuoteMail::class, fn (QuoteMail $mail) => $mail->hasTo('compras@salud.hn'));
        Mail::assertNothingSent();

        Tender::factory()->for($this->company)->create(['owner_id' => $this->admin->id, 'clarification_deadline' => now()->addDay(), 'presentation_deadline' => now()->addDays(3), 'opening_at' => now()->addDays(3)]);
        app(DailyDigestService::class)->sendForCompany($this->company->id);
        Mail::assertQueued(DailyDigestMail::class, fn (DailyDigestMail $mail) => $mail->hasTo($this->admin->email));
        Mail::assertNothingSent();
        $this->assertTrue(Event::query()->withoutGlobalScopes()->exists());
    }
}
