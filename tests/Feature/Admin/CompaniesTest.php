<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompaniesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'secreto-blessing-123456789';

    public function test_only_root_creates_edits_and_deletes_companies(): void
    {
        [$admin, $company] = $this->memberOf();
        $root = User::factory()->root()->create();

        $this->actingAs($admin)->post('/empresas', ['name' => 'Nueva'])->assertForbidden();
        $this->actingAs($admin)->put("/empresas/{$company->id}", ['name' => 'Cambiada'])->assertForbidden();
        $this->actingAs($admin)->delete("/empresas/{$company->id}")->assertForbidden();
        $this->actingAs($admin)->get('/empresas')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Companies/Index')->where('can.manage', false)->has('companyRecords.data', 1));

        $this->actingAs($root)->post('/empresas', ['name' => 'Empresa Nueva', 'code' => 'EN', 'tax_id' => '08019999999999'])->assertRedirect();
        $created = Company::query()->where('code', 'EN')->firstOrFail();
        $this->actingAs($root)->post('/empresas', ['name' => 'Repetida', 'code' => 'EN'])->assertSessionHasErrors('code');

        $this->actingAs($root)->put("/empresas/{$created->id}", ['name' => 'Empresa Renombrada', 'tax_rate' => 12.5, 'prices_include_tax' => true, 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('companies', ['id' => $created->id, 'name' => 'Empresa Renombrada', 'tax_rate' => 12.5, 'prices_include_tax' => true, 'is_active' => false]);

        Quote::factory()->for($company)->create();
        $this->actingAs($root)->delete("/empresas/{$company->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('companies', ['id' => $company->id]);

        $this->actingAs($root)->delete("/empresas/{$created->id}")->assertRedirect('/empresas');
        $this->assertDatabaseMissing('companies', ['id' => $created->id]);
    }

    public function test_blessing_token_is_encrypted_masked_and_never_sent_to_the_browser(): void
    {
        $root = User::factory()->root()->create();
        $company = Company::factory()->create();

        $this->actingAs($root)->put("/empresas/{$company->id}", ['name' => $company->name, 'blessing_base_url' => 'https://api.blessing.test', 'blessing_token' => self::TOKEN])->assertSessionHasNoErrors();

        $raw = DB::table('companies')->where('id', $company->id)->value('blessing_token');
        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, $company->fresh()->blessing_token);
        $this->assertArrayNotHasKey('blessing_token', $company->fresh()->toArray());

        // Editar con el campo vacío no lo borra.
        $this->actingAs($root)->put("/empresas/{$company->id}", ['name' => 'Otro nombre', 'blessing_token' => ''])->assertSessionHasNoErrors();
        $this->assertSame(self::TOKEN, $company->fresh()->blessing_token);

        $response = $this->actingAs($root)->get("/empresas/{$company->id}");
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Companies/Show')
            ->where('company.blessing_token_set', true)
            ->where('company.blessing_token_masked', '••••6789')
            ->missing('company.blessing_token'));
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, $this->actingAs($root)->get('/empresas')->getContent());
        $this->assertStringNotContainsString('secreto-blessing', json_encode(DB::table('audit_logs')->pluck('changes')));

        $this->actingAs($root)->put("/empresas/{$company->id}", ['name' => 'Otro nombre', 'clear_blessing_token' => true])->assertSessionHasNoErrors();
        $this->assertNull($company->fresh()->blessing_token);
    }

    public function test_root_manages_members_and_the_last_admin_is_protected(): void
    {
        $root = User::factory()->root()->create();
        [$admin, $company] = $this->memberOf();
        $newcomer = User::factory()->create(['name' => 'Nora Nueva']);

        $this->actingAs($root)->getJson("/empresas/{$company->id}/usuarios-disponibles?q=nora")->assertOk()->assertJsonPath('data.0.id', $newcomer->id);
        $this->actingAs($admin)->getJson("/empresas/{$company->id}/usuarios-disponibles?q=nora")->assertForbidden();

        $this->actingAs($root)->post("/empresas/{$company->id}/miembros", ['user_id' => $newcomer->id, 'role' => 'operator'])->assertSessionHasNoErrors();
        $this->assertSame('operator', $newcomer->fresh()->companyRole($company->id));

        $this->actingAs($root)->put("/empresas/{$company->id}/miembros/{$newcomer->id}", ['role' => 'viewer'])->assertSessionHasNoErrors();
        $this->assertSame('viewer', $newcomer->fresh()->companyRole($company->id));

        $this->actingAs($root)->put("/empresas/{$company->id}/miembros/{$admin->id}", ['role' => 'viewer'])->assertSessionHasErrors('role');
        $this->actingAs($root)->delete("/empresas/{$company->id}/miembros/{$admin->id}")->assertSessionHasErrors('user');

        $this->actingAs($root)->delete("/empresas/{$company->id}/miembros/{$newcomer->id}")->assertSessionHasNoErrors();
        $this->assertNull($newcomer->fresh()->companyRole($company->id));
    }

    public function test_logo_upload_is_validated_and_served_only_to_members(): void
    {
        Storage::fake('local');
        $root = User::factory()->root()->create();
        [$admin, $company] = $this->memberOf();
        [$stranger] = $this->memberOf();

        $this->actingAs($root)->post("/empresas/{$company->id}/logo", ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
        $this->actingAs($root)->post("/empresas/{$company->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)])->assertSessionHasNoErrors();

        $path = $company->fresh()->logo_path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($admin)->get("/empresas/{$company->id}/logo")->assertOk();
        $this->actingAs($stranger)->get("/empresas/{$company->id}/logo")->assertForbidden();
    }
}
