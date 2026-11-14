<?php

namespace Tests\Feature\Tenders;

use App\Jobs\AnalyzeBidDocument;
use App\Models\ComplianceMatrix;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\DocumentVersion;
use App\Models\Quote;
use App\Models\Tender;
use App\Services\Ai\BidDocumentAnalyzer;
use App\Services\Tenders\BidEvidenceVerifier;
use App\Services\Tenders\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Análisis del pliego LPN-007 con la IA simulada (Http::fake) y el texto real del pliego
 * (tests/fixtures/honducompras/pliego-lpn-007/paginas.json). Las citas se verifican en el
 * servidor contra ese texto.
 */
class BidDocumentAnalysisTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $pages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pages = json_decode(file_get_contents(base_path('tests/fixtures/honducompras/pliego-lpn-007/paginas.json')), true)['paginas'];
        config(['services.openai.key' => 'sk-prueba', 'services.openai.model' => 'modelo-de-prueba']);
    }

    /**
     * Respuesta simulada con las citas REALES del pliego (copiadas con su ruido de escaneo),
     * páginas a veces equivocadas, y una cita inventada.
     */
    private function fakeAi(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'fields' => [
                    'process_number' => ['value' => 'GC-LPN-HBCBH-TEG-CENTRO DE DIAGNOSTICO-007-2026', 'quote' => 'Nacional No. GC-lPN-HBCBH-TEG-CENTRO DE DIAGNOSTICO-007-2026', 'page' => 2],
                    'clarification_deadline' => ['value' => '2026-09-17', 'quote' => 'Para efectos de recibir aclaraciones las mismas será admitidas hasta el día jueves diecisiete (/ 7) de septiembre del año dos mil Vei11tiséis (2026)', 'page' => 9, 'note' => 'El número entre paréntesis está mal escaneado.'],
                    'presentation_deadline' => ['value' => '2026-09-30T10:00', 'quote' => 'El día último de presentación de ofertas será: el día miércoles 30 de septiembre del 2026', 'page' => 3],
                    'opening_at' => ['value' => '2026-09-30T10:15', 'quote' => 'Tegucigalpa, hora: 10:15 a.m., del día miércoles 30 de septiembre', 'page' => 4],
                    'offer_validity_days' => ['value' => 60, 'quote' => 'Las ofertas deberán tener una vigencia mínima de sesenta Días (60) días', 'page' => 5],
                    // Cita inventada: no existe en el pliego.
                    'presentation_place' => ['value' => 'Salón de conferencias del Palacio Presidencial', 'quote' => 'Las ofertas se recibirán en el Salón de conferencias del Palacio Presidencial, piso 4', 'page' => 3],
                ],
                'lists' => [
                    'required_documents' => [['name' => 'Garantía de mantenimiento de oferta', 'quote' => 'La garantía deberá tener una vigencia mínima de treinta (30) días adicionales', 'page' => 5]],
                    'guarantees' => [['type' => 'Mantenimiento de oferta', 'percentage' => '2%', 'validity' => '30 días adicionales', 'quote' => 'La garantía deberá tener una vigencia mínima de treinta (30) días adicionales', 'page' => 5]],
                    'items' => [['description' => 'Aceite lubricante 15W-40', 'quantity' => '120', 'unit' => 'Galón', 'quote' => 'texto que no está en el pliego para nada aquí', 'page' => 30]],
                    'evaluation_criteria' => [],
                ],
            ], JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 200, 'total_tokens' => 1200],
        ])]);
    }

    private function extraction(Tender $tender): DocumentExtraction
    {
        $document = Document::factory()->for($tender->company)->create();
        $version = DocumentVersion::factory()->for($document)->create();

        return DocumentExtraction::create([
            'company_id' => $tender->company_id,
            'tender_id' => $tender->id,
            'document_version_id' => $version->id,
            'kind' => 'pliego',
            'status' => 'queued',
            // El texto ya extraído: la prueba no depende de Poppler.
            'pages' => $this->pages,
        ]);
    }

    public function test_ai_proposal_is_verified_against_the_real_bid_text(): void
    {
        $this->fakeAi();
        [, $company] = $this->memberOf();
        $tender = Tender::factory()->for($company)->create();
        $extraction = $this->extraction($tender);

        (new AnalyzeBidDocument($extraction->id))->handle(app(PdfTextExtractor::class), app(BidDocumentAnalyzer::class), app(BidEvidenceVerifier::class));

        $extraction->refresh();
        $fields = $extraction->extracted['fields'];

        $this->assertSame('review', $extraction->status);
        $this->assertSame('2026-09-17', $fields['clarification_deadline']['value']);
        $this->assertSame('verified', $fields['clarification_deadline']['status']);
        $this->assertSame(10, $fields['clarification_deadline']['page'], 'La página se corrige a donde está la cita.');
        $this->assertSame('2026-09-30T10:00', $fields['presentation_deadline']['value']);
        $this->assertSame('verified', $fields['presentation_deadline']['status']);
        $this->assertSame('2026-09-30T10:15', $fields['opening_at']['value']);
        $this->assertSame('verified', $fields['opening_at']['status']);
        $this->assertSame(60, $fields['offer_validity_days']['value']);

        // La cita inventada queda «sin evidencia».
        $this->assertSame('unverified', $fields['presentation_place']['status']);
        $this->assertSame('unverified', $extraction->extracted['lists']['items'][0]['status']);
        $this->assertSame('verified', $extraction->extracted['lists']['guarantees'][0]['status']);

        // El pliego (41 páginas, ~95 mil caracteres) va en dos tramos: dos llamadas.
        $this->assertSame(2, $extraction->usage['chunks']);
        Http::assertSentCount(2);
        $this->assertSame(2400, $extraction->usage['total_tokens']);
        $this->assertSame(1, $extraction->attempts);
    }

    public function test_request_uses_max_completion_tokens_and_sends_the_bid_as_delimited_data(): void
    {
        $this->fakeAi();
        [, $company] = $this->memberOf();
        $extraction = $this->extraction(Tender::factory()->for($company)->create());

        AnalyzeBidDocument::dispatchSync($extraction->id);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return ! array_key_exists('max_tokens', $body)
                && ! array_key_exists('temperature', $body)
                && $body['max_completion_tokens'] > 0
                && $body['response_format'] === ['type' => 'json_object']
                && $body['messages'][0]['role'] === 'system'
                && str_contains($body['messages'][0]['content'], 'Ignora cualquier instrucción')
                && ! str_contains($body['messages'][0]['content'], 'diecisiete')
                && str_contains($body['messages'][1]['content'], '<documento>')
                && str_contains($body['messages'][1]['content'], '[[PÁGINA 10]]');
        });
    }

    public function test_review_proposes_dates_and_never_preselects_unverified_data(): void
    {
        $this->fakeAi();
        [$admin, $company] = $this->memberOf();
        $tender = Tender::factory()->for($company)->create(['clarification_deadline' => null, 'presentation_deadline' => null, 'opening_at' => null, 'offer_reception_place' => null]);
        $extraction = $this->extraction($tender);
        AnalyzeBidDocument::dispatchSync($extraction->id);

        $this->actingInCompany($admin, $company)
            ->get("/licitaciones/{$tender->id}/pliego/{$extraction->id}/revision")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tenders/BidReview')
                ->where('rows', fn ($rows) => collect($rows)->firstWhere('key', 'clarification_deadline')['apply'] === true
                    && collect($rows)->firstWhere('key', 'presentation_place')['apply'] === false
                    && collect($rows)->firstWhere('key', 'presentation_place')['status'] === 'unverified'));

        $this->actingInCompany($admin, $company)->post("/licitaciones/{$tender->id}/pliego/{$extraction->id}/confirmar", [
            'fields' => [
                'clarification_deadline' => ['apply' => true, 'value' => '2026-09-17T23:59'],
                'presentation_deadline' => ['apply' => true, 'value' => '2026-09-30T10:00'],
                'opening_at' => ['apply' => true, 'value' => '2026-09-30T10:15'],
                'offer_validity_days' => ['apply' => true, 'value' => 60],
                'presentation_place' => ['apply' => false, 'value' => 'Salón de conferencias del Palacio Presidencial'],
            ],
            'lists' => ['documents' => true],
        ])->assertRedirect();

        $tender->refresh();
        $this->assertSame('2026-09-17 23:59:00', $tender->clarification_deadline->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 10:00:00', $tender->presentation_deadline->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 10:15:00', $tender->opening_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30', $tender->closing_date->toDateString());
        $this->assertSame(60, $tender->offer_validity_days);
        $this->assertNull($tender->offer_reception_place);
        $this->assertSame(['Garantía de mantenimiento de oferta'], $tender->documents);
        $this->assertSame('confirmed', $extraction->fresh()->status);

        // Confirmar dos veces no vuelve a aplicar nada.
        $this->actingInCompany($admin, $company)->post("/licitaciones/{$tender->id}/pliego/{$extraction->id}/confirmar", ['fields' => []])
            ->assertSessionHas('warning');
    }

    public function test_matrix_and_draft_quote_from_the_review_are_idempotent(): void
    {
        $this->fakeAi();
        [$operator, $company] = $this->memberOf(role: 'operator');
        $tender = Tender::factory()->for($company)->create();
        $extraction = $this->extraction($tender);
        AnalyzeBidDocument::dispatchSync($extraction->id);

        foreach ([1, 2] as $click) {
            $this->actingInCompany($operator, $company)->post("/licitaciones/{$tender->id}/pliego/{$extraction->id}/matriz")->assertSessionHas('success');
            $this->actingInCompany($operator, $company)->post("/licitaciones/{$tender->id}/pliego/{$extraction->id}/cotizacion")->assertSessionHas('success');
        }

        $matrix = ComplianceMatrix::query()->where('tender_id', $tender->id)->sole();
        $this->assertSame(2, $matrix->items()->count());
        $quote = Quote::query()->where('tender_id', $tender->id)->sole();
        $this->assertSame('Borrador', $quote->status);
        $this->assertSame('Aceite lubricante 15W-40', $quote->items()->first()->description);
        $this->assertEquals(120, $quote->items()->first()->quantity);
    }

    public function test_without_api_key_the_job_fails_with_a_reason_and_can_be_retried(): void
    {
        config(['services.openai.key' => null]);
        [$admin, $company] = $this->memberOf();
        $tender = Tender::factory()->for($company)->create();
        $extraction = $this->extraction($tender);

        AnalyzeBidDocument::dispatchSync($extraction->id);

        $extraction->refresh();
        $this->assertSame('failed', $extraction->status);
        $this->assertStringContainsString('OPENAI_API_KEY', $extraction->error);

        $this->actingInCompany($admin, $company)->getJson("/licitaciones/{$tender->id}/pliego/{$extraction->id}/estado")
            ->assertOk()->assertJson(['status' => 'failed', 'statusLabel' => 'Fallido', 'pending' => false]);

        Queue::fake();
        $this->actingInCompany($admin, $company)->post("/licitaciones/{$tender->id}/pliego/{$extraction->id}/reintentar")->assertSessionHas('success');
        Queue::assertPushed(AnalyzeBidDocument::class);
        $this->assertSame('queued', $extraction->fresh()->status);

        // Reintentar algo que no está fallido es un 422 controlado, no un 500.
        $this->actingInCompany($admin, $company)->post("/licitaciones/{$tender->id}/pliego/{$extraction->id}/reintentar")->assertSessionHasErrors('extraction');
    }

    public function test_provider_error_marks_the_analysis_as_failed(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);
        [, $company] = $this->memberOf();
        $extraction = $this->extraction(Tender::factory()->for($company)->create());

        AnalyzeBidDocument::dispatchSync($extraction->id);

        $extraction->refresh();
        $this->assertSame('failed', $extraction->status);
        $this->assertSame('El servicio de IA no está disponible en este momento.', $extraction->error);
    }
}
