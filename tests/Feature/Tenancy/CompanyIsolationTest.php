<?php

namespace Tests\Feature\Tenancy;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un administrador de la empresa A no lista, ve, edita ni borra lo de la
 * empresa B, ni crea registros en ella o apuntando a sus datos.
 */
class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->userA, $this->companyA] = $this->memberOf();
        $this->companyB = Company::factory()->create();
    }

    public function test_quotes_of_another_company_are_out_of_reach(): void
    {
        $own = Quote::factory()->for($this->companyA)->create(['number' => 'COT-PROPIA-1']);
        $foreign = Quote::factory()->for($this->companyB)->create(['number' => 'COT-AJENA-1']);

        $this->actingAs($this->userA)->get('/cotizaciones')->assertOk()
            ->assertSee('COT-PROPIA-1')->assertDontSee('COT-AJENA-1');

        $this->actingAs($this->userA)->get("/cotizaciones/{$foreign->id}/documentos")->assertNotFound();
        $this->actingAs($this->userA)->put("/cotizaciones/{$foreign->id}", $this->quotePayload())->assertNotFound();
        $this->actingAs($this->userA)->patch("/cotizaciones/{$foreign->id}/estado", ['status' => 'Emitida'])->assertNotFound();
        $this->actingAs($this->userA)->patch("/cotizaciones/{$foreign->id}/resultado", ['outcome' => 'Ganada'])->assertNotFound();
        $this->actingAs($this->userA)->delete("/cotizaciones/{$foreign->id}")->assertNotFound();

        $this->assertDatabaseHas('quotes', ['id' => $foreign->id, 'status' => 'Borrador', 'deleted_at' => null]);
        $this->actingAs($this->userA)->get("/cotizaciones/{$own->id}/documentos")->assertOk();
    }

    public function test_quote_cannot_be_created_in_or_linked_to_another_company(): void
    {
        $foreignTender = Tender::factory()->for($this->companyB)->create();
        $foreignOpportunity = Opportunity::factory()->for($this->companyB)->create();

        $this->actingAs($this->userA)->post('/cotizaciones', $this->quotePayload(['company_id' => $this->companyB->id]))
            ->assertSessionHasErrors('company_id');
        $this->actingAs($this->userA)->post('/cotizaciones', $this->quotePayload(['tender_id' => $foreignTender->id]))
            ->assertSessionHasErrors('tender_id');
        $this->actingAs($this->userA)->post('/cotizaciones', $this->quotePayload(['opportunity_id' => $foreignOpportunity->id]))
            ->assertSessionHasErrors('opportunity_id');

        $this->assertDatabaseCount('quotes', 0);

        $this->actingAs($this->userA)->post('/cotizaciones', $this->quotePayload())->assertSessionHasNoErrors();

        $this->assertDatabaseHas('quotes', ['company_id' => $this->companyA->id]);
        $this->assertDatabaseMissing('quotes', ['company_id' => $this->companyB->id]);
    }

    public function test_quote_numbers_are_sequential_per_company(): void
    {
        [$userB] = $this->memberOf($this->companyB);

        $this->actingAs($this->userA)->post('/cotizaciones', $this->quotePayload())->assertSessionHasNoErrors();
        $this->actingAs($userB)->post('/cotizaciones', $this->quotePayload())->assertSessionHasNoErrors();

        $number = 'COT-'.now()->year.'-0001';

        $this->assertDatabaseHas('quotes', ['company_id' => $this->companyA->id, 'number' => $number]);
        $this->assertDatabaseHas('quotes', ['company_id' => $this->companyB->id, 'number' => $number]);
    }

    public function test_tenders_of_another_company_are_out_of_reach(): void
    {
        Tender::factory()->for($this->companyA)->create(['reference' => 'LPN-PROPIA-1']);
        $foreign = Tender::factory()->for($this->companyB)->create(['reference' => 'LPN-AJENA-1', 'title' => 'Original']);

        $this->actingAs($this->userA)->get('/licitaciones')->assertOk()
            ->assertSee('LPN-PROPIA-1')->assertDontSee('LPN-AJENA-1');

        $this->actingAs($this->userA)->put("/licitaciones/{$foreign->id}", $this->tenderPayload())->assertNotFound();
        $this->actingAs($this->userA)->delete("/licitaciones/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->userA)->post("/ia-licitaciones/{$foreign->id}/analizar")->assertNotFound();

        $this->assertDatabaseHas('tenders', ['id' => $foreign->id, 'title' => 'Original']);
    }

    public function test_tender_is_created_in_the_active_company_and_reference_is_unique_per_company(): void
    {
        Tender::factory()->for($this->companyB)->create(['reference' => 'LPN-007']);

        $this->actingAs($this->userA)->post('/licitaciones', $this->tenderPayload(['company_id' => $this->companyB->id]))
            ->assertSessionHasErrors('company_id');

        // El mismo expediente puede existir en otra empresa del grupo...
        $this->actingAs($this->userA)->post('/licitaciones', $this->tenderPayload(['reference' => 'LPN-007']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenders', ['company_id' => $this->companyA->id, 'reference' => 'LPN-007']);

        // ...pero no dos veces en la misma.
        $this->actingAs($this->userA)->post('/licitaciones', $this->tenderPayload(['reference' => 'LPN-007']))
            ->assertSessionHasErrors('reference');
    }

    public function test_opportunities_of_another_company_are_out_of_reach(): void
    {
        Opportunity::factory()->for($this->companyA)->create(['title' => 'Oportunidad propia']);
        $foreign = Opportunity::factory()->for($this->companyB)->create(['title' => 'Oportunidad ajena']);
        $foreignTender = Tender::factory()->for($this->companyB)->create();

        $this->actingAs($this->userA)->get('/pipeline')->assertOk()
            ->assertSee('Oportunidad propia')->assertDontSee('Oportunidad ajena');

        $this->actingAs($this->userA)->get("/pipeline/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->userA)->put("/pipeline/{$foreign->id}", $this->opportunityPayload())->assertNotFound();
        $this->actingAs($this->userA)->patch("/pipeline/{$foreign->id}/etapa", ['stage' => 'oportunidad'])->assertNotFound();
        $this->actingAs($this->userA)->delete("/pipeline/{$foreign->id}")->assertNotFound();

        $this->actingAs($this->userA)->post('/pipeline', $this->opportunityPayload(['company_id' => $this->companyB->id]))
            ->assertSessionHasErrors('company_id');
        $this->actingAs($this->userA)->post('/pipeline', $this->opportunityPayload(['tender_id' => $foreignTender->id]))
            ->assertSessionHasErrors('tender_id');

        $this->assertDatabaseHas('opportunities', ['id' => $foreign->id, 'stage' => 'prospecto', 'title' => 'Oportunidad ajena']);
        $this->assertDatabaseCount('opportunities', 2);
    }

    public function test_contracts_of_another_company_are_out_of_reach(): void
    {
        Contract::factory()->for($this->companyA)->create(['number' => 'CTR-PROPIO-1']);
        $foreign = Contract::factory()->for($this->companyB)->create(['number' => 'CTR-AJENO-1']);
        $foreignQuote = Quote::factory()->for($this->companyB)->create();
        $payload = ['number' => 'CTR-NUEVO-1', 'name' => 'Suministro', 'amount' => 1000];

        $this->actingAs($this->userA)->get('/post-adjudicacion')->assertOk()
            ->assertSee('CTR-PROPIO-1')->assertDontSee('CTR-AJENO-1');

        $this->actingAs($this->userA)->put("/post-adjudicacion/{$foreign->id}", $payload)->assertNotFound();
        $this->actingAs($this->userA)->post("/post-adjudicacion/{$foreign->id}/seguimientos", [])->assertNotFound();
        $this->actingAs($this->userA)->patch("/post-adjudicacion/{$foreign->id}/completar")->assertNotFound();
        $this->actingAs($this->userA)->delete("/post-adjudicacion/{$foreign->id}")->assertNotFound();

        $this->actingAs($this->userA)->post('/post-adjudicacion', $payload + ['company_id' => $this->companyB->id])
            ->assertSessionHasErrors('company_id');
        $this->actingAs($this->userA)->post('/post-adjudicacion', $payload + ['quote_id' => $foreignQuote->id])
            ->assertSessionHasErrors('quote_id');

        $this->assertDatabaseHas('contracts', ['id' => $foreign->id, 'number' => 'CTR-AJENO-1']);
        $this->assertDatabaseCount('contracts', 2);
    }

    public function test_documents_of_another_company_are_out_of_reach(): void
    {
        Storage::fake('local');

        Document::factory()->for($this->companyA)->create(['name' => 'RTN propio']);
        $foreign = Document::factory()->for($this->companyB)->create(['name' => 'RTN ajeno']);
        $file = fn () => UploadedFile::fake()->create('rtn.pdf', 20, 'application/pdf');

        $this->actingAs($this->userA)->get('/directorio')->assertOk()
            ->assertSee('RTN propio')->assertDontSee('RTN ajeno');

        $this->actingAs($this->userA)->get("/documentos/{$foreign->id}/download")->assertNotFound();
        $this->actingAs($this->userA)->get("/documentos/{$foreign->id}/vista")->assertNotFound();
        $this->actingAs($this->userA)->get("/documentos/{$foreign->id}/versiones")->assertNotFound();
        $this->actingAs($this->userA)->put("/documentos/{$foreign->id}", ['name' => 'Cambiado'])->assertNotFound();
        $this->actingAs($this->userA)->post("/documentos/{$foreign->id}/replace", ['file' => $file()])->assertNotFound();
        $this->actingAs($this->userA)->delete("/documentos/{$foreign->id}")->assertNotFound();

        $this->actingAs($this->userA)->post('/documentos', ['name' => 'Intruso', 'company_id' => $this->companyB->id, 'file' => $file()])
            ->assertSessionHasErrors('company_id');

        $this->assertDatabaseHas('documents', ['id' => $foreign->id, 'name' => 'RTN ajeno']);
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_a_document_of_another_company_cannot_be_attached_to_a_quote(): void
    {
        $quote = Quote::factory()->for($this->companyA)->create();
        $foreign = Document::factory()->for($this->companyB)->create();

        $this->actingAs($this->userA)->post("/cotizaciones/{$quote->id}/documentos", ['document_id' => $foreign->id])
            ->assertSessionHasErrors('document_id');

        $this->assertDatabaseCount('document_links', 0);
    }

    public function test_route_binding_resolves_after_the_company_context(): void
    {
        // Mismo usuario con acceso a las dos empresas: lo que decide es la empresa ACTIVA.
        $this->companyB->users()->attach($this->userA->id, ['role' => 'admin', 'is_default' => false]);
        $inB = Opportunity::factory()->for($this->companyB)->create();

        $this->actingInCompany($this->userA, $this->companyA)->get("/pipeline/{$inB->id}")->assertNotFound();
        $this->actingInCompany($this->userA, $this->companyB)->get("/pipeline/{$inB->id}")->assertOk();
    }

    public function test_records_get_the_active_company_when_none_is_given(): void
    {
        $this->actingAs($this->userA)->post('/pipeline', $this->opportunityPayload())->assertSessionHasNoErrors();
        $this->actingAs($this->userA)->post('/historial', [
            'process_number' => 'HIS-1', 'name' => 'Proceso', 'participated' => true, 'estimated_amount' => 10,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('opportunities', ['company_id' => $this->companyA->id]);
        $this->assertDatabaseHas('process_histories', ['process_number' => 'HIS-1', 'company_id' => $this->companyA->id]);
    }

    private function quotePayload(array $overrides = []): array
    {
        return $overrides + [
            'institution_id' => Institution::factory()->create()->id,
            'purchase_type' => 'Licitación Pública',
            'validity_days' => 30,
            'items' => [['description' => 'Resma de papel', 'quantity' => 10, 'unit' => 'Resma', 'unit_price' => 100]],
        ];
    }

    private function tenderPayload(array $overrides = []): array
    {
        return $overrides + [
            'reference' => 'LPN-NUEVA-1',
            'title' => 'Suministro de equipo',
            'status' => 'Activa',
            'estimated_amount' => 5000,
            'offer_submitted' => false,
        ];
    }

    private function opportunityPayload(array $overrides = []): array
    {
        return $overrides + ['title' => 'Nueva oportunidad', 'amount' => 1000, 'probability' => 50, 'stage' => 'prospecto'];
    }
}
