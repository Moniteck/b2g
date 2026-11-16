<?php

namespace Tests\Feature\Pipeline;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Peticiones ligeras del Pipeline (rendimiento de las modales): detalle con participantes y sin
 * historial, historial perezoso por columnas padre, formulario de «Editar datos», cotizaciones
 * vivas para «Ganada» y detalle incluido en el enlace directo. Con permisos y aislamiento.
 */
class PipelineRequestsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $operator;

    private User $viewer;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        [$this->operator] = $this->memberOf($this->company, 'operator');
        [$this->viewer] = $this->memberOf($this->company, 'viewer');
        $this->institution = Institution::factory()->create(['name' => 'Secretaría de Salud']);
    }

    private function as(User $user): static
    {
        return $this->actingInCompany($user, $this->company);
    }

    private function opportunity(string $stage = 'oportunidad', ?Company $company = null): Opportunity
    {
        $company ??= $this->company;

        return $this->inCompany($company, fn () => Opportunity::factory()->for($company)->stage($stage)->create([
            'institution_id' => $this->institution->id,
            'amount' => 50000,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function quoteFor(Opportunity $opportunity, array $attributes = []): Quote
    {
        return $this->inCompany($this->company, fn () => Quote::factory()->for($this->company)->create($attributes + [
            'institution_id' => $this->institution->id,
            'opportunity_id' => $opportunity->id,
            'status' => 'Emitida',
            'total' => 46000,
        ]));
    }

    public function test_detail_carries_participants_and_leaves_history_for_its_own_request(): void
    {
        $opportunity = $this->opportunity();
        $this->as($this->operator)->putJson("/oportunidades/{$opportunity->id}/participantes", ['participants' => [
            ['name' => 'CAP Honduras', 'amount' => 48000, 'is_ours' => true],
            ['name' => 'Rival S.A.', 'amount' => 45000],
        ]])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('summary.ours_position', 2);

        $this->as($this->operator)->getJson("/pipeline/{$opportunity->id}")->assertOk()
            ->assertJsonMissingPath('history')
            ->assertJsonCount(2, 'participantsPayload.data')
            ->assertJsonPath('participantsPayload.summary.difference', 3000)
            ->assertJsonPath('participantsPayload.outcome', 'Pendiente')
            ->assertJsonPath('participantsPayload.can.edit', true)
            ->assertJsonPath('participantsPayload.reference_amount', 50000)
            ->assertJsonPath('data.participantsCount', 2);

        $this->as($this->viewer)->getJson("/pipeline/{$opportunity->id}")->assertJsonPath('participantsPayload.can.edit', false);
    }

    public function test_history_joins_opportunity_quotes_and_attachments_through_parent_columns(): void
    {
        $opportunity = $this->opportunity();
        $quote = $this->quoteFor($opportunity);
        $other = $this->opportunity();
        $audit = app(AuditLogger::class);

        $this->actingAs($this->admin);
        $this->travel(-3)->minutes();
        $audit->record('oportunidad.creada', $opportunity);
        $this->travel(1)->minutes();
        $audit->record('cotizacion.emitida', $quote);
        $this->travel(1)->minutes();
        $document = $this->inCompany($this->company, fn () => \App\Models\Document::factory()->for($this->company)->create());
        $log = $audit->record('documento.subido', $document, ['nombre' => 'Pliego', 'adjunto_a' => "Opportunity #{$opportunity->id}"]);
        $audit->record('documento.subido', $document, ['adjunto_a' => "Opportunity #{$other->id}"]);
        $this->travelBack();

        $this->assertSame(['Opportunity', $opportunity->id], [$log->parent_type, $log->parent_id]);

        $this->as($this->viewer)->getJson("/pipeline/{$opportunity->id}/historial")->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.action', 'documento.subido')
            ->assertJsonPath('data.1.action', 'cotizacion.emitida')
            ->assertJsonPath('data.2.action', 'oportunidad.creada');

        $foreign = $this->opportunity(company: Company::factory()->create());
        $this->as($this->admin)->getJson("/pipeline/{$foreign->id}/historial")->assertNotFound();
    }

    public function test_form_endpoint_returns_only_the_form_and_needs_update_permission(): void
    {
        $opportunity = $this->opportunity();

        $this->as($this->operator)->getJson("/pipeline/{$opportunity->id}/formulario")->assertOk()
            ->assertJsonPath('data.id', $opportunity->id)
            ->assertJsonPath('data.institution.name', 'Secretaría de Salud')
            ->assertJsonPath('form.amount', 50000)
            ->assertJsonMissingPath('participantsPayload')
            ->assertJsonMissingPath('quotes');

        $this->as($this->viewer)->getJson("/pipeline/{$opportunity->id}/formulario")->assertForbidden();
    }

    public function test_live_quotes_lists_open_quotes_without_outcome(): void
    {
        $opportunity = $this->opportunity('oferta_presentada');
        $open = $this->quoteFor($opportunity, ['status' => 'Presentada']);
        $this->quoteFor($opportunity, ['status' => 'Sustituida']);
        $this->quoteFor($opportunity, ['status' => 'Anulada']);
        $second = $this->quoteFor($opportunity, ['status' => 'Emitida']);

        $this->as($this->viewer)->getJson("/oportunidades/{$opportunity->id}/cotizaciones-vivas")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $open->id)
            ->assertJsonPath('data.1.total', 46000);

        $foreign = $this->opportunity(company: Company::factory()->create());
        $this->as($this->admin)->getJson("/oportunidades/{$foreign->id}/cotizaciones-vivas")->assertNotFound();
    }

    public function test_direct_link_ships_the_detail_and_partial_reloads_skip_it(): void
    {
        $opportunity = $this->opportunity();

        $this->as($this->viewer)->get("/pipeline/{$opportunity->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Pipeline/Index')
            ->where('focus', $opportunity->id)
            ->where('focusDetail.data.id', $opportunity->id)
            ->has('focusDetail.participantsPayload'));

        $this->as($this->viewer)->get('/pipeline')->assertInertia(fn (Assert $page) => $page->where('focusDetail', null));

        $this->as($this->viewer)->get("/pipeline/{$opportunity->id}")->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['summary'], fn (Assert $reload) => $reload->missing('focusDetail')->has('summary')));
    }

    public function test_stage_parameter_from_the_dashboard_focuses_a_valid_column(): void
    {
        $this->as($this->viewer)->get('/pipeline?etapa=oferta_presentada')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Pipeline/Index')->where('focusStage', 'oferta_presentada'));

        $this->as($this->viewer)->get('/pipeline?etapa=inventada')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('focusStage', null));

        $this->as($this->viewer)->get('/pipeline')->assertInertia(fn (Assert $page) => $page->where('focusStage', null));
    }

    public function test_attachment_logs_without_target_keep_parent_empty(): void
    {
        $this->actingAs($this->admin);
        $log = app(AuditLogger::class)->record('documento.subido', null, ['nombre' => 'Suelto'], companyId: $this->company->id);

        $this->assertNull($log->parent_type);
        $this->assertNull(AuditLog::query()->withoutGlobalScopes()->find($log->id)->parent_id);
    }
}
