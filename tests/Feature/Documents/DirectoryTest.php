<?php

namespace Tests\Feature\Documents;

use App\Models\Company;
use App\Models\Document;
use App\Services\Documents\DocumentService;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Directorio Institucional: listado con estado calculado, roles, aislamiento entre empresas,
 * versiones, renovación, descarga y paquete ZIP para oferta.
 */
class DirectoryTest extends DocumentsTestCase
{
    private function upload(array $data = [], ?string $file = null): Document
    {
        $this->actingInCompany($this->admin, $this->company);

        return $this->inCompany($this->company, fn () => app(DocumentService::class)->create(
            $data + ['kind' => 'empresa', 'category' => 'rtn', 'name' => 'RTN'],
            $this->pdf($file ?? 'rtn.pdf'),
            $this->admin,
        ));
    }

    public function test_documentos_redirects_to_directorio(): void
    {
        $this->actingInCompany($this->viewer, $this->company)->get('/documentos?status=vencido')
            ->assertRedirect('/directorio?status=vencido');
    }

    public function test_directory_lists_company_documents_with_computed_status_and_stats(): void
    {
        $this->upload(['name' => 'RTN vigente', 'expires_at' => now()->addYear()]);
        $this->upload(['name' => 'Solvencia por vencer', 'category' => 'solvencia_sar', 'expires_at' => now()->addDays(10)]);
        $this->upload(['name' => 'IHSS vencida', 'category' => 'ihss', 'expires_at' => now()->subDay()]);
        $this->upload(['name' => 'Escritura', 'category' => 'escritura']);
        $other = Company::factory()->create();
        Document::factory()->for($other)->create(['name' => 'Ajeno', 'kind' => 'empresa']);
        // Un adjunto de cotización no aparece en el directorio.
        Document::factory()->for($this->company)->create(['name' => 'Adjunto de proceso', 'kind' => 'proceso']);

        $this->actingInCompany($this->viewer, $this->company)->get('/directorio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Directory/Index')
                ->has('documents.data', 4)
                ->where('documents.data.0.status', 'Vencido')
                ->where('documents.data.1.status', 'Por vencer')
                ->where('documents.data.3.status', 'Sin vencimiento')
                ->where('stats', ['total' => 4, 'valid' => 1, 'expiring' => 1, 'expired' => 1, 'noExpiry' => 1])
                ->where('can', ['create' => false, 'update' => false, 'delete' => false])
                ->has('categories', 12)
                ->has('limits.pdfMb'));

        $this->actingInCompany($this->viewer, $this->company)->get('/directorio?status=por_vencer')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)->where('documents.data.0.name', 'Solvencia por vencer'));

        $this->actingInCompany($this->viewer, $this->company)->get('/directorio?search=escri&category=escritura')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));

        $this->assertSame('Vencido', Document::query()->where('name', 'IHSS vencida')->value('status'));
    }

    public function test_roles_viewer_reads_operator_writes_admin_deletes(): void
    {
        $document = $this->upload();

        $viewer = $this->actingInCompany($this->viewer, $this->company);
        $viewer->get("/documentos/{$document->id}/download")->assertOk();
        $viewer->get("/documentos/{$document->id}/vista")->assertOk();
        $viewer->getJson("/documentos/{$document->id}/versiones")->assertOk();
        $viewer->post('/documentos', ['file' => $this->pdf(), 'category' => 'rtn'])->assertForbidden();
        $viewer->put("/documentos/{$document->id}", ['name' => 'X', 'category' => 'rtn'])->assertForbidden();
        $viewer->post("/documentos/{$document->id}/replace", ['file' => $this->pdf()])->assertForbidden();
        $viewer->patch("/documentos/{$document->id}/renovar", ['issued_at' => now()->toDateString()])->assertForbidden();
        $viewer->delete("/documentos/{$document->id}")->assertForbidden();

        $operator = $this->actingInCompany($this->operator, $this->company);
        $operator->get('/directorio')->assertInertia(fn (Assert $page) => $page->where('can', ['create' => true, 'update' => true, 'delete' => false]));
        $operator->post("/documentos/{$document->id}/replace", ['file' => $this->pdf('nuevo.pdf')])->assertSessionHasNoErrors();
        $operator->put("/documentos/{$document->id}", ['name' => 'RTN de la empresa', 'category' => 'rtn', 'reference_number' => '0801'])->assertSessionHasNoErrors();
        $operator->delete("/documentos/{$document->id}")->assertForbidden();

        $files = $this->storedFiles();
        $this->assertCount(2, $files);

        $this->actingInCompany($this->admin, $this->company)->delete("/documentos/{$document->id}")->assertRedirect();
        $this->assertModelMissing($document);
        $this->assertSame([], $this->storedFiles());
        $this->assertDatabaseHas('audit_logs', ['action' => 'documento.eliminado', 'resource_id' => $document->id]);
    }

    public function test_documents_of_another_company_are_out_of_reach(): void
    {
        $document = $this->upload();
        [$intruder, $other] = $this->memberOf(role: 'admin');

        $client = $this->actingInCompany($intruder, $other);
        $client->get("/documentos/{$document->id}/download")->assertNotFound();
        $client->get("/documentos/{$document->id}/vista")->assertNotFound();
        $client->get("/documentos/{$document->id}/versiones")->assertNotFound();
        $client->put("/documentos/{$document->id}", ['name' => 'X', 'category' => 'rtn'])->assertNotFound();
        $client->delete("/documentos/{$document->id}")->assertNotFound();
        $client->get("/directorio/paquete?ids[]={$document->id}")->assertSessionHasErrors('ids.0');
        $client->get('/directorio')->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
        $client->post('/documentos', ['file' => $this->pdf(), 'category' => 'rtn', 'company_id' => $this->company->id])->assertSessionHasErrors('company_id');

        $this->assertModelExists($document);
    }

    public function test_download_streams_with_safe_headers_and_range(): void
    {
        $document = $this->upload([], 'Constancia ñandú.pdf');

        $response = $this->actingInCompany($this->viewer, $this->company)->get("/documentos/{$document->id}/download");
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''Constancia%20%C3%B1and%C3%BA.pdf", $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'documento.descargado', 'resource_id' => $document->id]);

        $preview = $this->actingInCompany($this->viewer, $this->company)->get("/documentos/{$document->id}/vista");
        $this->assertStringStartsWith('inline;', $preview->headers->get('Content-Disposition'));

        $partial = $this->actingInCompany($this->viewer, $this->company)->get("/documentos/{$document->id}/vista", ['Range' => 'bytes=0-7']);
        $partial->assertStatus(206);
        $this->assertSame('%PDF-1.4', $partial->streamedContent());
    }

    public function test_replace_renew_and_version_history(): void
    {
        $document = $this->upload(['issued_at' => now()->subYear(), 'expires_at' => now()->subDay()]);
        $operator = $this->actingInCompany($this->operator, $this->company);

        $operator->post("/documentos/{$document->id}/replace", ['file' => $this->pdf('segunda.pdf')])->assertSessionHasNoErrors();
        $operator->post("/documentos/{$document->id}/replace", ['file' => $this->truncatedPdf()])->assertSessionHasErrors('file');

        // Renovar sin archivo: solo cambian las fechas.
        $operator->patch("/documentos/{$document->id}/renovar", ['issued_at' => now()->toDateString(), 'expires_at' => now()->addYear()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('Vigente', $document->fresh()->status);
        $this->assertSame(2, $document->fresh()->version);

        // Renovar con archivo: versión nueva con su vigencia.
        $operator->post("/documentos/{$document->id}/renovar", ['_method' => 'patch', 'issued_at' => now()->toDateString(), 'expires_at' => now()->addDays(20)->toDateString(), 'file' => $this->pdf('renovada.pdf')])->assertSessionHasNoErrors();
        $fresh = $document->fresh();
        $this->assertSame(3, $fresh->version);
        $this->assertSame('Por vencer', $fresh->status);

        $operator->patch("/documentos/{$document->id}/renovar", ['issued_at' => now()->toDateString(), 'expires_at' => now()->subDays(3)->toDateString()])->assertSessionHasErrors('expires_at');

        $versions = $operator->getJson("/documentos/{$document->id}/versiones")->assertOk()->json('data');
        $this->assertSame([3, 2, 1], array_column($versions, 'version'));
        $this->assertTrue($versions[0]['current']);
        $this->assertSame('Renovación', $versions[0]['note']);
        $this->assertSame('renovada.pdf', $versions[0]['fileName']);

        $old = $this->actingInCompany($this->viewer, $this->company)->get($versions[2]['downloadUrl']);
        $old->assertOk();
        $this->assertStringContainsString('rtn.pdf', $old->headers->get('Content-Disposition'));
        $this->actingInCompany($this->viewer, $this->company)->get($versions[2]['previewUrl'])->assertOk();
        $this->assertCount(3, $this->storedFiles());
        $this->assertDatabaseHas('audit_logs', ['action' => 'documento.renovado', 'resource_id' => $document->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'documento.reemplazado', 'resource_id' => $document->id]);
    }

    public function test_version_of_another_document_is_not_reachable_through_the_url(): void
    {
        $first = $this->upload();
        $second = $this->upload(['name' => 'Otro'], 'otro.pdf');
        $foreignVersion = $second->currentVersion;

        $this->actingInCompany($this->viewer, $this->company)
            ->get("/documentos/{$first->id}/versiones/{$foreignVersion->id}/descargar")->assertNotFound();
    }

    public function test_offer_package_zips_only_valid_documents(): void
    {
        $rtn = $this->upload(['name' => 'RTN', 'expires_at' => now()->addYear()]);
        $solvency = $this->upload(['name' => 'Solvencia', 'category' => 'solvencia_sar', 'expires_at' => now()->addDays(5)], 'solvencia.pdf');
        $expired = $this->upload(['name' => 'IHSS', 'category' => 'ihss', 'expires_at' => now()->subDays(2)], 'ihss.pdf');

        $response = $this->actingInCompany($this->viewer, $this->company)
            ->get('/directorio/paquete?'.http_build_query(['ids' => [$rtn->id, $solvency->id, $expired->id]]));

        $response->assertOk()->assertHeader('X-Documents-Excluded', '1');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = $zip->getNameIndex($index);
        }
        $zip->close();
        sort($names);

        $this->assertSame(['RTN - RTN.pdf', 'Solvencia fiscal SAR - Solvencia.pdf'], $names);
        $this->assertDatabaseHas('audit_logs', ['action' => 'documento.paquete_descargado']);

        $this->actingInCompany($this->viewer, $this->company)
            ->get('/directorio/paquete?ids[]='.$expired->id)
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_status_is_saved_with_normal_eloquent_events(): void
    {
        $saved = [];
        Document::saved(function (Document $document) use (&$saved) {
            $saved[] = [$document->status, $document->expires_at?->toDateString()];
        });

        $document = $this->upload(['expires_at' => now()->addDays(3)]);
        $this->actingInCompany($this->operator, $this->company)
            ->put("/documentos/{$document->id}", ['name' => 'RTN', 'category' => 'rtn', 'expires_at' => now()->addYears(2)->toDateString()]);

        $this->assertSame(['Por vencer', now()->addDays(3)->toDateString()], $saved[0]);
        $this->assertSame(['Vigente', now()->addYears(2)->toDateString()], end($saved));
        Storage::disk('local')->assertExists($document->fresh()->path);
    }
}
