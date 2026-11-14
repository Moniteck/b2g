<?php

namespace Tests\Feature\Tools;

use App\Models\Company;
use App\Models\ComplianceMatrix;
use App\Models\Document;
use App\Models\Tender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ComplianceMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_builds_a_matrix_and_tracks_item_status(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        $tender = Tender::factory()->for($company)->create();
        $document = Document::factory()->for($company)->create();

        $this->actingInCompany($operator, $company)->post('/matriz-cumplimiento', [
            'tender_id' => $tender->id,
            'name' => 'Documentos legales',
            'items' => [
                ['requirement' => 'RTN numérico'],
                ['requirement' => 'Solvencia fiscal', 'renewal_due_at' => today()->subDay()->toDateString()],
                ['requirement' => 'Constancia ONCAE'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $matrix = ComplianceMatrix::query()->withoutGlobalScopes()->sole();
        [$rtn, $solvency, $oncae] = $matrix->items()->orderBy('id')->get()->all();

        $this->actingInCompany($operator, $company)->patch("/matriz-cumplimiento/{$matrix->id}/items/{$rtn->id}", ['completed' => true])->assertSessionHasNoErrors();
        $this->actingInCompany($operator, $company)->patch("/matriz-cumplimiento/{$matrix->id}/items/{$solvency->id}", ['completed' => true]);

        $this->actingInCompany($operator, $company)->get("/matriz-cumplimiento/{$matrix->id}")
            ->assertInertia(fn (Assert $page) => $page->component('ComplianceMatrices/Show')
                ->where('items.0.status', 'Cumple')
                ->where('items.1.status', 'Vencido')
                ->where('items.2.status', 'Pendiente')
                ->where('matrix.summary.progress', 33));

        $this->actingInCompany($operator, $company)->patch("/matriz-cumplimiento/{$matrix->id}/items/{$solvency->id}/renovar", [
            'document_id' => $document->id, 'renewal_due_at' => today()->addYear()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame('Cumple', $solvency->fresh()->status());

        $this->actingInCompany($operator, $company)->put("/matriz-cumplimiento/{$matrix->id}/items/{$oncae->id}", [
            'requirement' => 'Constancia ONCAE vigente', 'completed' => true,
        ])->assertSessionHasNoErrors();

        $this->actingInCompany($operator, $company)->get('/matriz-cumplimiento?filter=complete')
            ->assertInertia(fn (Assert $page) => $page->component('ComplianceMatrices')->has('records.data', 1)->where('records.data.0.progress', 100));

        $this->actingInCompany($operator, $company)->get("/matriz-cumplimiento/{$matrix->id}/pdf")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_validation(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        $foreignTender = Tender::factory()->for(Company::factory())->create();

        $this->actingInCompany($operator, $company)->post('/matriz-cumplimiento', ['tender_id' => $foreignTender->id, 'name' => '', 'items' => []])
            ->assertSessionHasErrors(['tender_id', 'name', 'items']);

        $tender = Tender::factory()->for($company)->create();
        $matrix = $this->inCompany($company, fn () => ComplianceMatrix::create(['tender_id' => $tender->id, 'name' => 'M']));
        $item = $matrix->items()->create(['requirement' => 'RTN']);

        $this->actingInCompany($operator, $company)->patch("/matriz-cumplimiento/{$matrix->id}/items/{$item->id}/renovar", [
            'document_id' => null, 'renewal_due_at' => today()->subDay()->toDateString(),
        ])->assertSessionHasErrors(['document_id', 'renewal_due_at']);
    }

    public function test_roles_and_isolation(): void
    {
        [$admin, $company] = $this->memberOf();
        [$viewer] = $this->memberOf($company, 'viewer');
        [$operator] = $this->memberOf($company, 'operator');
        $tender = Tender::factory()->for($company)->create();
        $matrix = $this->inCompany($company, fn () => ComplianceMatrix::create(['tender_id' => $tender->id, 'name' => 'M']));
        $item = $matrix->items()->create(['requirement' => 'RTN']);

        $other = Company::factory()->create();
        $otherTender = Tender::factory()->for($other)->create();
        $foreign = $this->inCompany($other, fn () => ComplianceMatrix::create(['tender_id' => $otherTender->id, 'name' => 'Ajena']));

        $this->actingInCompany($viewer, $company)->get("/matriz-cumplimiento/{$matrix->id}")->assertOk();
        $this->actingInCompany($viewer, $company)->patch("/matriz-cumplimiento/{$matrix->id}/items/{$item->id}", ['completed' => true])->assertForbidden();
        $this->actingInCompany($operator, $company)->delete("/matriz-cumplimiento/{$matrix->id}/items/{$item->id}")->assertForbidden();
        $this->actingInCompany($admin, $company)->get("/matriz-cumplimiento/{$foreign->id}")->assertNotFound();

        $this->actingInCompany($admin, $company)->delete("/matriz-cumplimiento/{$matrix->id}/items/{$item->id}")->assertSessionHas('success');
        $this->actingInCompany($admin, $company)->delete("/matriz-cumplimiento/{$matrix->id}")->assertRedirect('/matriz-cumplimiento');
        $this->assertModelMissing($matrix);
    }
}
