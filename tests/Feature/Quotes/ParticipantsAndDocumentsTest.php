<?php

namespace Tests\Feature\Quotes;

use App\Mail\QuoteMail;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;

/**
 * Participantes por diferencias, adjuntos en Presentada, copia del PDF para firmar y correo.
 */
class ParticipantsAndDocumentsTest extends QuotesTestCase
{
    public function test_participants_are_saved_by_differences_in_a_presented_quote(): void
    {
        $quote = $this->quote(['status' => 'Presentada']);
        $kept = QuoteParticipant::factory()->create(['quote_id' => $quote->id, 'opportunity_id' => $quote->opportunity_id, 'name' => 'Rival A', 'amount' => 12000]);
        $removed = QuoteParticipant::factory()->create(['quote_id' => $quote->id, 'opportunity_id' => $quote->opportunity_id, 'name' => 'Rival B', 'amount' => 13000]);

        $this->as($this->operator)->getJson("/cotizaciones/{$quote->id}/participantes")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('can.edit', true);

        $response = $this->as($this->operator)->putJson("/cotizaciones/{$quote->id}/participantes", ['participants' => [
            ['id' => $kept->id, 'name' => 'Rival A', 'amount' => 11800, 'notes' => 'Corrigió su oferta'],
            ['name' => 'CAP Honduras', 'amount' => 11500, 'is_ours' => true],
            ['name' => 'Rival C', 'tax_id' => '0801', 'amount' => 10900, 'currency' => 'HNL', 'includes_tax' => false],
        ]])->assertOk();

        $this->assertSame($kept->id, QuoteParticipant::query()->where('name', 'Rival A')->value('id'));
        $this->assertSame('11800.00', $kept->fresh()->amount);
        $this->assertNull(QuoteParticipant::query()->find($removed->id));
        $this->assertSame(3, QuoteParticipant::query()->count());
        $this->assertSame(['Rival C', 'CAP Honduras', 'Rival A'], QuoteParticipant::query()->orderBy('position')->pluck('name')->all());
        $response->assertJsonPath('summary.ours_position', 2)
            ->assertJsonPath('summary.lowest_name', 'Rival C')
            ->assertJsonPath('summary.difference', 600);
        $this->assertDatabaseHas('audit_logs', ['action' => 'oferta.participantes']);
    }

    public function test_rows_without_id_match_by_name_instead_of_duplicating(): void
    {
        $quote = $this->quote();
        $existing = QuoteParticipant::factory()->create(['quote_id' => $quote->id, 'opportunity_id' => $quote->opportunity_id, 'name' => 'Rival A', 'amount' => 1]);

        $this->as($this->operator)->putJson("/cotizaciones/{$quote->id}/participantes", ['participants' => [['name' => '  rival a ', 'amount' => 5]]])->assertOk();

        $this->assertSame($existing->id, QuoteParticipant::query()->sole()->id);
    }

    public function test_only_one_ours_and_winner_only_with_known_result(): void
    {
        $quote = $this->quote();

        $this->as($this->operator)->putJson("/cotizaciones/{$quote->id}/participantes", ['participants' => [
            ['name' => 'A', 'amount' => 1, 'is_ours' => true],
            ['name' => 'B', 'amount' => 2, 'is_ours' => true],
        ]])->assertStatus(422)->assertJsonValidationErrors('participants.1.is_ours');

        $this->as($this->operator)->putJson("/cotizaciones/{$quote->id}/participantes", ['participants' => [
            ['name' => 'A', 'amount' => 1, 'is_winner' => true],
        ]])->assertStatus(422)->assertJsonValidationErrors('participants.0.is_winner');

        $foreign = QuoteParticipant::factory()->create();
        $this->as($this->operator)->putJson("/cotizaciones/{$quote->id}/participantes", ['participants' => [
            ['id' => $foreign->id, 'name' => 'A', 'amount' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('participants.0.id');

        $this->assertSame(1, QuoteParticipant::query()->count());
    }

    public function test_opportunity_participants_are_shared_with_its_quote(): void
    {
        $quote = $this->quote();

        $this->as($this->operator)->putJson("/oportunidades/{$quote->opportunity_id}/participantes", ['participants' => [['name' => 'Rival', 'amount' => 100]]])->assertOk();

        $this->as($this->operator)->getJson("/cotizaciones/{$quote->id}/participantes")->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rival');
        $this->assertNull(QuoteParticipant::query()->sole()->quote_id);
        $this->assertSame($quote->opportunity_id, QuoteParticipant::query()->sole()->opportunity_id);
    }

    public function test_presented_quote_accepts_attachments_but_annulled_does_not(): void
    {
        $presented = $this->quote(['status' => 'Presentada']);
        $annulled = $this->quote(['status' => 'Anulada']);
        $pdf = fn () => UploadedFile::fake()->createWithContent('oferta.pdf', $this->minimalPdf());

        $this->as($this->operator)->postJson("/adjuntos/quote/{$presented->id}", ['file' => $pdf()])->assertCreated();
        $this->as($this->operator)->getJson("/adjuntos/quote/{$presented->id}")->assertJsonPath('can.edit', true);
        $this->as($this->operator)->postJson("/adjuntos/quote/{$annulled->id}", ['file' => $pdf()])->assertForbidden();
        $this->as($this->viewer)->postJson("/adjuntos/quote/{$presented->id}", ['file' => $pdf()])->assertForbidden();
    }

    public function test_generated_pdf_snapshot_is_stored_once_as_attachment(): void
    {
        $quote = $this->quote(['status' => 'Presentada']);

        $first = $this->as($this->operator)->postJson("/cotizaciones/{$quote->id}/pdf/documento")->assertCreated()->json('document');
        $second = $this->as($this->operator)->postJson("/cotizaciones/{$quote->id}/pdf/documento")->assertCreated()->json('document');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('Cotización '.$quote->number, $first['name']);
        $this->assertSame('application/pdf', Document::query()->sole()->mime_type);
        $this->assertDatabaseHas('document_links', ['linkable_type' => Quote::class, 'linkable_id' => $quote->id, 'context' => 'cotizacion_generada']);

        $this->as($this->viewer)->postJson("/cotizaciones/{$quote->id}/pdf/documento")->assertForbidden();
        $this->assertSame(1, DocumentLink::query()->count());
    }

    public function test_mail_validates_recipients_and_is_sent_with_the_pdf(): void
    {
        Mail::fake();
        $quote = $this->quote(['status' => 'Emitida']);

        $this->as($this->operator)->post("/cotizaciones/{$quote->id}/email", ['recipients' => ['no-es-correo']])->assertSessionHasErrors('recipients.0');
        $this->as($this->operator)->post("/cotizaciones/{$quote->id}/email", ['recipients' => array_map(fn ($i) => "c{$i}@salud.hn", range(1, 6))])->assertSessionHasErrors('recipients');
        $this->as($this->viewer)->post("/cotizaciones/{$quote->id}/email", ['recipients' => ['compras@salud.hn']])->assertForbidden();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();

        $this->as($this->operator)->post("/cotizaciones/{$quote->id}/email", [
            'recipients' => 'compras@salud.hn, jefe@salud.hn',
            'message' => 'Adjuntamos nuestra oferta.',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        Mail::assertQueued(QuoteMail::class, fn (QuoteMail $mail) => $mail->hasTo('compras@salud.hn') && $mail->hasTo('jefe@salud.hn') && $mail->body === 'Adjuntamos nuestra oferta.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'cotizacion.enviada']);
    }

    public function test_mail_route_is_rate_limited(): void
    {
        Mail::fake();
        $quote = $this->quote(['status' => 'Emitida']);

        foreach (range(1, 6) as $attempt) {
            $this->as($this->operator)->post("/cotizaciones/{$quote->id}/email", ['recipients' => ['compras@salud.hn']]);
        }

        // Límite superado: vuelve a la pantalla con el aviso en español (toast), no una página 429.
        $this->as($this->operator)->post("/cotizaciones/{$quote->id}/email", ['recipients' => ['compras@salud.hn']])
            ->assertRedirect()->assertSessionHas('error', fn (string $message) => str_contains($message, 'Espera'));
        $this->as($this->operator)->postJson("/cotizaciones/{$quote->id}/email", ['recipients' => ['compras@salud.hn']])
            ->assertStatus(429)->assertJsonPath('message', fn (string $message) => str_contains($message, 'Demasiadas solicitudes'));
    }

    public function test_viewer_reads_participants_but_cannot_change_them(): void
    {
        $quote = $this->quote();
        $opportunity = Opportunity::query()->find($quote->opportunity_id);

        $this->as($this->viewer)->getJson("/cotizaciones/{$quote->id}/participantes")->assertOk()->assertJsonPath('can.edit', false);
        $this->as($this->viewer)->putJson("/oportunidades/{$opportunity->id}/participantes", ['participants' => []])->assertForbidden();
    }

    private function minimalPdf(): string
    {
        $stream = 'BT /F1 24 Tf 72 700 Td (Oferta) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
