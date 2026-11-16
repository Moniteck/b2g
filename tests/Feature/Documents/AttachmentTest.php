<?php

namespace Tests\Feature\Documents;

use App\Models\Contract;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\Quote;
use App\Services\Documents\DocumentService;
use Illuminate\Http\UploadedFile;

/**
 * Adjuntos genéricos por entidad: autorizados con la policy de la entidad y limitados a la
 * empresa activa.
 */
class AttachmentTest extends DocumentsTestCase
{
    public function test_quote_attachments_upload_list_and_remove(): void
    {
        $quote = Quote::factory()->for($this->company)->create(['status' => 'Borrador']);
        $client = $this->actingInCompany($this->operator, $this->company);

        $client->postJson("/adjuntos/quote/{$quote->id}", [
            'files' => [$this->pdf('ficha.pdf'), $this->pdf('catalogo.pdf')],
            'category' => 'Ficha técnica',
            'description' => 'Para la oferta',
        ])->assertCreated()->assertJsonCount(2, 'data');

        $list = $client->getJson("/adjuntos/quote/{$quote->id}")->assertOk()->assertJsonPath('can.edit', true)->json('data');
        $this->assertSame(['catalogo', 'ficha'], array_column($list, 'name'));
        $this->assertSame('Ficha técnica', $list[0]['category']);
        $this->assertSame('Para la oferta', $list[0]['description']);
        $this->assertSame($this->operator->name, $list[0]['uploadedBy']);
        $this->assertFalse($list[0]['signed']);
        $this->assertStringEndsWith("/documentos/{$list[0]['id']}/vista", $list[0]['previewUrl']);
        $this->assertSame('proceso', Document::query()->find($list[0]['id'])->kind);

        // Seguridad (B5): el operador no puede eliminar documentos, así que quitar el último enlace
        // solo lo desvincula; el documento y su archivo siguen en el Directorio.
        $client->deleteJson("/adjuntos/quote/{$quote->id}/{$list[0]['id']}")->assertOk()->assertJsonPath('deleted', false);
        $this->assertNotNull(Document::query()->withoutGlobalScopes()->find($list[0]['id']));
        $this->assertSame(1, DocumentLink::query()->count());
        $this->assertCount(2, $this->storedFiles());
        $this->assertDatabaseHas('audit_logs', ['action' => 'documento.adjunto_quitado']);

        // Quien sí puede eliminarlo (administrador) borra el documento huérfano con su archivo.
        $this->actingInCompany($this->admin, $this->company)
            ->deleteJson("/adjuntos/quote/{$quote->id}/{$list[1]['id']}")->assertOk()->assertJsonPath('deleted', true);
        $this->assertNull(Document::query()->withoutGlobalScopes()->find($list[1]['id']));
        $this->assertSame(0, DocumentLink::query()->count());
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_one_bad_file_rejects_the_whole_batch_with_error_under_that_file(): void
    {
        $contract = Contract::factory()->for($this->company)->create();

        $this->actingInCompany($this->admin, $this->company)
            ->postJson("/adjuntos/contract/{$contract->id}", ['files' => [$this->pdf('acta.pdf'), $this->truncatedPdf('roto.pdf')]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['files.1'])
            ->assertJsonMissingValidationErrors(['files.0']);

        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_contract_attachment_via_inertia_redirects_back_with_toast(): void
    {
        $contract = Contract::factory()->for($this->company)->create();

        $this->actingInCompany($this->admin, $this->company)
            ->from('/post-adjudicacion')
            ->post("/adjuntos/contract/{$contract->id}", ['file' => $this->pdf('contrato.pdf'), 'category' => 'Contrato'], ['X-Inertia' => 'true'])
            ->assertRedirect('/post-adjudicacion')
            ->assertSessionHas('success');

        $document = Document::query()->sole();
        $this->assertSame('contrato', $document->kind);
        $this->assertDatabaseHas('document_links', ['document_id' => $document->id, 'linkable_type' => Contract::class, 'linkable_id' => $contract->id, 'category' => 'Contrato', 'created_by' => $this->admin->id]);
    }

    public function test_viewer_lists_but_cannot_change_attachments(): void
    {
        $quote = Quote::factory()->for($this->company)->create(['status' => 'Borrador']);
        $this->actingInCompany($this->admin, $this->company);
        $document = $this->inCompany($this->company, fn () => app(DocumentService::class)->create(['name' => 'Adjunto'], $this->pdf(), $this->admin, $quote));

        $viewer = $this->actingInCompany($this->viewer, $this->company);
        $viewer->getJson("/adjuntos/quote/{$quote->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('can.edit', false);
        $viewer->postJson("/adjuntos/quote/{$quote->id}", ['file' => $this->pdf()])->assertForbidden();
        $viewer->deleteJson("/adjuntos/quote/{$quote->id}/{$document->id}")->assertForbidden();
        $viewer->get("/documentos/{$document->id}/vista")->assertOk();

        $this->assertModelExists($document);
    }

    public function test_entities_of_another_company_and_unknown_types_are_not_found(): void
    {
        [$intruder, $other] = $this->memberOf(role: 'admin');
        $quote = Quote::factory()->for($this->company)->create(['status' => 'Borrador']);
        $this->actingInCompany($this->admin, $this->company);
        $document = $this->inCompany($this->company, fn () => app(DocumentService::class)->create(['name' => 'Adjunto'], $this->pdf(), $this->admin, $quote));
        $ownQuote = Quote::factory()->for($other)->create(['status' => 'Borrador']);

        $client = $this->actingInCompany($intruder, $other);
        $client->getJson("/adjuntos/quote/{$quote->id}")->assertNotFound();
        $client->postJson("/adjuntos/quote/{$quote->id}", ['file' => $this->pdf()])->assertNotFound();
        $client->deleteJson("/adjuntos/quote/{$quote->id}/{$document->id}")->assertNotFound();
        // Un documento ajeno no se puede «quitar» de una entidad propia.
        $client->deleteJson("/adjuntos/quote/{$ownQuote->id}/{$document->id}")->assertNotFound();
        $client->getJson("/adjuntos/factura/{$ownQuote->id}")->assertNotFound();

        $this->assertModelExists($document);
    }

    public function test_attaching_requires_a_file_and_validates_type(): void
    {
        $quote = Quote::factory()->for($this->company)->create(['status' => 'Borrador']);
        $client = $this->actingInCompany($this->admin, $this->company);

        $client->postJson("/adjuntos/quote/{$quote->id}", [])->assertStatus(422)->assertJsonValidationErrors(['file', 'files']);
        $client->postJson("/adjuntos/quote/{$quote->id}", ['file' => UploadedFile::fake()->create('virus.exe', 10)])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    public function test_signed_current_version_is_flagged(): void
    {
        $contract = Contract::factory()->for($this->company)->create();
        $this->actingInCompany($this->admin, $this->company);
        $document = $this->inCompany($this->company, fn () => app(DocumentService::class)->create(['name' => 'Contrato'], $this->pdf(), $this->admin, $contract));
        app(DocumentService::class)->storeDerivedVersion($document, $this->pdfContents('Firmado'), 'contrato-firmado.pdf', $this->admin);

        $this->actingInCompany($this->viewer, $this->company)->getJson("/adjuntos/contract/{$contract->id}")
            ->assertJsonPath('data.0.signed', true)
            ->assertJsonPath('data.0.signedBy', $this->admin->name)
            ->assertJsonPath('data.0.version', 2);
    }
}
