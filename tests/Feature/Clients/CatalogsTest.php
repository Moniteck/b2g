<?php

namespace Tests\Feature\Clients;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Institution;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Instituciones y contactos: catálogo compartido del grupo.
 */
class CatalogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_institutions_list_filters_and_page_contract(): void
    {
        [$viewer] = $this->memberOf(role: 'viewer');
        Institution::factory()->create(['name' => 'Secretaría de Salud', 'acronym' => 'SESAL', 'potential' => 'ALTO', 'type' => 'Gobierno central']);
        Institution::factory()->create(['name' => 'Alcaldía de La Ceiba', 'potential' => 'BAJO', 'type' => 'Municipalidad', 'status' => 'Inactiva']);

        $this->actingAs($viewer)->get('/instituciones')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Institutions/Index')
            ->has('institutions.data', 2)
            ->has('options.types')
            ->where('can.create', false));

        $this->actingAs($viewer)->get('/instituciones?potential=ALTO')->assertInertia(fn (Assert $page) => $page->has('institutions.data', 1)->where('institutions.data.0.acronym', 'SESAL'));
        $this->actingAs($viewer)->get('/instituciones?status=Inactiva')->assertInertia(fn (Assert $page) => $page->has('institutions.data', 1));
        $this->actingAs($viewer)->get('/instituciones?type=Municipalidad')->assertInertia(fn (Assert $page) => $page->has('institutions.data', 1));
        $this->actingAs($viewer)->get('/instituciones?search=sesal')->assertInertia(fn (Assert $page) => $page->has('institutions.data', 1));
    }

    public function test_operator_creates_and_edits_institutions_and_viewer_cannot(): void
    {
        [$operator, $company] = $this->memberOf(role: 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');

        $this->actingAs($operator)->post('/instituciones', ['name' => '  Instituto  Hondureño de Seguridad Social ', 'acronym' => 'IHSS', 'potential' => 'ALTO'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $institution = Institution::query()->where('acronym', 'IHSS')->firstOrFail();
        $this->assertSame('Instituto Hondureño de Seguridad Social', $institution->name);
        $this->assertSame('Activa', $institution->status);

        $this->actingAs($operator)->post('/instituciones', ['name' => 'Instituto Hondureño de Seguridad Social'])->assertSessionHasErrors('name');
        $this->actingAs($operator)->post('/instituciones', ['name' => 'Otra', 'potential' => 'ENORME'])->assertSessionHasErrors('potential');

        $this->actingAs($operator)->put("/instituciones/{$institution->id}", ['name' => $institution->name, 'status' => 'Inactiva', 'location' => 'Tegucigalpa'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('institutions', ['id' => $institution->id, 'status' => 'Inactiva', 'location' => 'Tegucigalpa']);

        $this->actingAs($viewer)->post('/instituciones', ['name' => 'Bloqueada'])->assertForbidden();
        $this->actingAs($viewer)->put("/instituciones/{$institution->id}", ['name' => 'X', 'status' => 'Activa'])->assertForbidden();
        $this->actingAs($operator)->delete("/instituciones/{$institution->id}")->assertForbidden();
    }

    public function test_quick_create_by_json_for_other_modules(): void
    {
        [$operator] = $this->memberOf(role: 'operator');

        $this->actingAs($operator)->postJson('/instituciones', ['name' => 'Hospital Escuela'])
            ->assertCreated()->assertJsonPath('data.name', 'Hospital Escuela')->assertJsonStructure(['data' => ['id', 'name', 'acronym', 'description']]);

        $this->actingAs($operator)->postJson('/instituciones', ['name' => 'Hospital Escuela'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_search_endpoints_for_search_selects(): void
    {
        [$viewer] = $this->memberOf(role: 'viewer');
        $sesal = Institution::factory()->create(['name' => 'Secretaría de Salud', 'acronym' => 'SESAL']);
        Institution::factory()->create(['name' => 'Salud Inactiva', 'status' => 'Inactiva']);
        $other = Institution::factory()->create(['name' => 'Secretaría de Educación']);
        Contact::factory()->for($sesal)->create(['name' => 'María Pérez', 'position' => 'Jefa de Compras']);
        Contact::factory()->for($other)->create(['name' => 'María Gómez']);

        $this->actingAs($viewer)->getJson('/instituciones/buscar?q=salud')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sesal->id)->assertJsonPath('data.0.description', 'SESAL · Gobierno central');
        $this->actingAs($viewer)->getJson('/instituciones/buscar?q=salud&todas=1')->assertJsonCount(2, 'data');

        $this->actingAs($viewer)->getJson('/contactos/buscar?q=maría')->assertJsonCount(2, 'data');
        $this->actingAs($viewer)->getJson("/contactos/buscar?q=maría&institution_id={$sesal->id}")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'María Pérez')->assertJsonPath('data.0.description', 'Jefa de Compras · Secretaría de Salud');

        $this->app['auth']->forgetGuards();
        $this->getJson('/instituciones/buscar?q=salud')->assertUnauthorized();
    }

    public function test_contacts_crud_with_roles(): void
    {
        [$operator, $company] = $this->memberOf(role: 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');
        $root = User::factory()->root()->create();
        $institution = Institution::factory()->create();

        $this->actingAs($operator)->post('/contactos', ['institution_id' => $institution->id, 'name' => 'Luis Mejía', 'email' => 'no-es-correo'])->assertSessionHasErrors('email');
        $this->actingAs($operator)->post('/contactos', ['name' => 'Sin institución'])->assertSessionHasErrors('institution_id');

        $this->actingAs($operator)->post('/contactos', ['institution_id' => $institution->id, 'name' => 'Luis Mejía', 'position' => 'Gerente', 'email' => 'luis@hn.gob'])->assertSessionHasNoErrors();
        $contact = Contact::query()->where('name', 'Luis Mejía')->firstOrFail();

        $this->actingAs($operator)->put("/contactos/{$contact->id}", ['institution_id' => $institution->id, 'name' => 'Luis Mejía', 'position' => 'Director'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'position' => 'Director']);

        $this->actingAs($operator)->get('/contactos')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Contacts/Index')->has('contacts.data', 1)->where('can.update', true)->where('can.delete', false));
        $this->actingAs($operator)->get("/contactos?institution_id={$institution->id}")->assertInertia(fn (Assert $page) => $page
            ->where('institution.id', $institution->id));

        $this->actingAs($viewer)->put("/contactos/{$contact->id}", ['institution_id' => $institution->id, 'name' => 'X'])->assertForbidden();
        $this->actingAs($operator)->delete("/contactos/{$contact->id}")->assertForbidden();
        $this->actingAs($root)->delete("/contactos/{$contact->id}")->assertRedirect();
        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
    }

    public function test_institution_detail_shows_only_the_active_company_records(): void
    {
        [$admin, $company] = $this->memberOf();
        $institution = Institution::factory()->create();
        Contact::factory()->for($institution)->count(2)->create();
        Tender::factory()->for($company)->create(['institution_id' => $institution->id, 'reference' => 'LPN-PROPIA']);
        Tender::factory()->for(Company::factory())->create(['institution_id' => $institution->id, 'reference' => 'LPN-AJENA']);
        Quote::factory()->for($company)->create(['institution_id' => $institution->id]);

        $this->actingAs($admin)->get("/instituciones/{$institution->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Institutions/Show')
            ->has('contacts', 2)
            ->has('tenders', 1)
            ->where('tenders.0.reference', 'LPN-PROPIA')
            ->has('quotes', 1));
    }

    public function test_institution_in_use_is_not_deleted(): void
    {
        $root = User::factory()->root()->create();
        $company = Company::factory()->create();
        $used = Institution::factory()->create();
        Quote::factory()->for($company)->create(['institution_id' => $used->id]);
        $free = Institution::factory()->create();
        Contact::factory()->for($free)->create();

        $this->actingAs($root)->delete("/instituciones/{$used->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('institutions', ['id' => $used->id]);

        $this->actingAs($root)->delete("/instituciones/{$free->id}")->assertRedirect('/instituciones');
        $this->assertDatabaseMissing('institutions', ['id' => $free->id]);
        $this->assertDatabaseCount('contacts', 0);
    }
}
