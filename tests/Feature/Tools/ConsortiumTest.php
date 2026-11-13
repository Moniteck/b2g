<?php

namespace Tests\Feature\Tools;

use App\Models\Company;
use App\Models\Consortium;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConsortiumTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Consorcio Vial',
            'status' => 'Activo',
            'members' => [
                ['name' => 'Empresa A', 'participation_percentage' => 60, 'role' => 'Líder'],
                ['name' => 'Empresa B', 'participation_percentage' => 40],
            ],
            ...$overrides,
        ];
    }

    public function test_operator_creates_and_syncs_members(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');

        $this->actingInCompany($operator, $company)->post('/consorcios', $this->payload())->assertSessionHasNoErrors();
        $consortium = Consortium::query()->withoutGlobalScopes()->sole();
        $this->assertSame(2, $consortium->members()->count());
        $first = $consortium->members()->where('name', 'Empresa A')->first();

        $this->actingInCompany($operator, $company)->put("/consorcios/{$consortium->id}", $this->payload(['members' => [
            ['id' => $first->id, 'name' => 'Empresa A', 'participation_percentage' => 50],
            ['name' => 'Empresa C', 'participation_percentage' => 50],
        ]]))->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['Empresa A', 'Empresa C'], $consortium->members()->pluck('name')->all());
        $this->assertSame($first->id, $consortium->members()->where('name', 'Empresa A')->value('id'));

        $this->actingInCompany($operator, $company)->get('/consorcios')
            ->assertInertia(fn (Assert $page) => $page->component('Consortia')->where('records.data.0.members_total', 100));
    }

    public function test_percentages_must_add_up_to_one_hundred(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');

        $this->actingInCompany($operator, $company)->post('/consorcios', $this->payload(['members' => [
            ['name' => 'A', 'participation_percentage' => 60],
            ['name' => 'B', 'participation_percentage' => 30],
        ]]))->assertSessionHasErrors(['members' => 'La participación de los miembros debe sumar 100 %; ahora suma 90 %.']);

        $this->actingInCompany($operator, $company)->post('/consorcios', $this->payload(['members' => [
            ['name' => 'A', 'participation_percentage' => 0],
        ], 'status' => 'Raro']))->assertSessionHasErrors(['members.0.participation_percentage', 'status']);

        $this->assertSame(0, Consortium::query()->withoutGlobalScopes()->count());
    }

    public function test_name_is_unique_per_company_only(): void
    {
        [$admin, $company] = $this->memberOf();
        $other = Company::factory()->create();
        $this->inCompany($other, fn () => Consortium::create(['name' => 'Consorcio Vial', 'status' => 'Activo']));

        $this->actingInCompany($admin, $company)->post('/consorcios', $this->payload())->assertSessionHasNoErrors();
        $this->actingInCompany($admin, $company)->post('/consorcios', $this->payload())->assertSessionHasErrors('name');
    }

    public function test_roles_and_isolation(): void
    {
        [$admin, $company] = $this->memberOf();
        [$viewer] = $this->memberOf($company, 'viewer');
        [$operator] = $this->memberOf($company, 'operator');
        $other = Company::factory()->create();
        $mine = $this->inCompany($company, fn () => Consortium::create(['name' => 'Propio', 'status' => 'Activo']));
        $foreign = $this->inCompany($other, fn () => Consortium::create(['name' => 'Ajeno', 'status' => 'Activo']));

        $this->actingInCompany($viewer, $company)->post('/consorcios', $this->payload())->assertForbidden();
        $this->actingInCompany($operator, $company)->delete("/consorcios/{$mine->id}")->assertForbidden();
        $this->actingInCompany($admin, $company)->put("/consorcios/{$foreign->id}", $this->payload())->assertNotFound();
        $this->actingInCompany($admin, $company)->get('/consorcios')->assertInertia(fn (Assert $page) => $page->has('records.data', 1));

        $this->actingInCompany($admin, $company)->delete("/consorcios/{$mine->id}")->assertSessionHas('success');
        $this->assertModelMissing($mine);
    }
}
