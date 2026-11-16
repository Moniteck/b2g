<?php

namespace Tests\Feature\Tools;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Institution;
use App\Models\InstitutionalRelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_creates_a_relation_and_logs_interactions(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        $institution = Institution::factory()->create();
        $contact = Contact::factory()->create(['institution_id' => $institution->id]);

        $this->actingInCompany($operator, $company)->post('/relaciones-institucionales', [
            'institution_id' => $institution->id, 'contact_id' => $contact->id, 'status' => 'Activa', 'influence' => 'Alta',
        ])->assertSessionHasNoErrors();

        $relation = InstitutionalRelation::query()->withoutGlobalScopes()->sole();
        $this->assertSame($company->id, $relation->company_id);
        $this->assertSame($operator->id, $relation->owner_id);

        $next = today()->addDays(3)->toDateString();
        $this->actingInCompany($operator, $company)->post("/relaciones-institucionales/{$relation->id}/interacciones", [
            'type' => 'Reunión', 'description' => 'Presentación de catálogo', 'occurred_at' => today()->toDateString().'T09:30', 'next_action_at' => $next,
        ])->assertSessionHasNoErrors();

        $relation->refresh();
        $this->assertSame(today()->toDateString(), $relation->last_contact_at->toDateString());
        $this->assertSame($next, $relation->next_contact_at->toDateString());

        $this->actingInCompany($operator, $company)->getJson("/relaciones-institucionales/{$relation->id}")
            ->assertOk()->assertJsonCount(1, 'interactions')->assertJsonPath('interactions.0.user', $operator->name);

        $this->actingInCompany($operator, $company)->get('/relaciones-institucionales?filter=upcoming')
            ->assertInertia(fn (Assert $page) => $page->component('Relations/Index')->has('records.data', 1)->where('counts.upcoming', 1));
    }

    public function test_validation_rejects_a_contact_of_another_institution(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        $institution = Institution::factory()->create();
        $foreignContact = Contact::factory()->create();

        $this->actingInCompany($operator, $company)->post('/relaciones-institucionales', [
            'institution_id' => $institution->id, 'contact_id' => $foreignContact->id, 'status' => 'Rara',
        ])->assertSessionHasErrors(['contact_id', 'status']);
    }

    public function test_roles_and_company_isolation(): void
    {
        [$admin, $company] = $this->memberOf();
        [$viewer] = $this->memberOf($company, 'viewer');
        $other = Company::factory()->create();
        $institution = Institution::factory()->create();
        $relation = $this->inCompany($company, fn () => InstitutionalRelation::create(['institution_id' => $institution->id, 'status' => 'Activa']));
        $foreign = $this->inCompany($other, fn () => InstitutionalRelation::create(['institution_id' => $institution->id, 'status' => 'Activa']));

        $this->actingInCompany($viewer, $company)->post("/relaciones-institucionales/{$relation->id}/interacciones", [
            'type' => 'Llamada', 'description' => 'x', 'occurred_at' => now()->toDateTimeString(),
        ])->assertForbidden();
        $this->actingInCompany($viewer, $company)->delete("/relaciones-institucionales/{$relation->id}")->assertForbidden();

        $this->actingInCompany($admin, $company)->getJson("/relaciones-institucionales/{$foreign->id}")->assertNotFound();
        $this->actingInCompany($admin, $company)->delete("/relaciones-institucionales/{$foreign->id}")->assertNotFound();

        $this->actingInCompany($admin, $company)->delete("/relaciones-institucionales/{$relation->id}")->assertSessionHas('success');
        $this->assertModelMissing($relation);
    }
}
