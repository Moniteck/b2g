<?php

namespace Tests\Feature\Tools;

use App\Models\Company;
use App\Models\GeneratedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => 'clave-de-prueba', 'services.openai.model' => 'modelo-de-prueba']);
    }

    private function payload(array $overrides = []): array
    {
        return ['type' => 'carta_presentacion', 'recipient' => 'Secretaría de Salud', 'context' => 'Somos distribuidores de llantas con 10 años de experiencia.', ...$overrides];
    }

    public function test_operator_generates_a_document_with_the_active_company_letterhead(): void
    {
        [$operator, $company] = $this->memberOf(Company::factory()->create(['name' => 'Alfa Comercial']), 'operator');
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => "Estimados señores:\n<script>x</script>"]]]])]);

        $this->actingInCompany($operator, $company)->postJson('/generador-documentos', $this->payload())
            ->assertCreated()->assertJsonPath('document.type_label', 'Carta de presentación');

        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], 'Alfa Comercial'));

        $document = GeneratedDocument::query()->withoutGlobalScopes()->sole();
        $this->assertSame($company->id, $document->company_id);

        $this->actingInCompany($operator, $company)->get('/generador-documentos')
            ->assertInertia(fn (Assert $page) => $page->component('Tools/DocumentGenerator')->has('records.data', 1)->where('can.delete', false));

        $this->actingInCompany($operator, $company)->get("/generador-documentos/{$document->id}/pdf")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_validation_and_ai_failure(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');

        $this->actingInCompany($operator, $company)->postJson('/generador-documentos', ['type' => 'garantia_bancaria', 'recipient' => '', 'context' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['type', 'recipient', 'context']);

        Http::fake(['api.openai.com/*' => Http::response([], 500)]);
        $this->actingInCompany($operator, $company)->postJson('/generador-documentos', $this->payload())
            ->assertStatus(422)->assertJsonPath('message', fn ($message) => is_string($message) && $message !== '');

        $this->assertSame(0, GeneratedDocument::query()->withoutGlobalScopes()->count());
    }

    public function test_roles_authorship_and_isolation(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        [$colleague] = $this->memberOf($company, 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');
        $document = $this->inCompany($company, fn () => GeneratedDocument::create(['user_id' => $operator->id, 'type' => 'carta_presentacion', 'title' => 'T', 'recipient' => 'R', 'content' => 'C']));
        $other = Company::factory()->create();
        $foreign = $this->inCompany($other, fn () => GeneratedDocument::create(['user_id' => $admin->id, 'type' => 'carta_presentacion', 'title' => 'T', 'recipient' => 'R', 'content' => 'C']));

        $this->actingInCompany($viewer, $company)->get('/generador-documentos')->assertForbidden();
        $this->actingInCompany($viewer, $company)->postJson('/generador-documentos', $this->payload())->assertForbidden();

        $this->actingInCompany($colleague, $company)->get("/generador-documentos/{$document->id}/pdf")->assertForbidden();
        $this->actingInCompany($colleague, $company)->get('/generador-documentos')->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
        $this->actingInCompany($operator, $company)->delete("/generador-documentos/{$document->id}")->assertForbidden();

        $this->actingInCompany($admin, $company)->get('/generador-documentos')->assertInertia(fn (Assert $page) => $page->has('records.data', 1));
        $this->actingInCompany($admin, $company)->get("/generador-documentos/{$foreign->id}/pdf")->assertNotFound();
        $this->actingInCompany($admin, $company)->delete("/generador-documentos/{$document->id}")->assertSessionHas('success');
        $this->assertModelMissing($document);
    }
}
