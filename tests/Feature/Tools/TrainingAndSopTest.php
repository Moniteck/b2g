<?php

namespace Tests\Feature\Tools;

use App\Models\TrainingProgress;
use App\Support\Tools\SopCatalog;
use App\Support\Tools\TrainingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrainingAndSopTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_is_saved_per_user_and_is_idempotent(): void
    {
        [$viewer, $company] = $this->memberOf(null, 'viewer');
        [$other] = $this->memberOf($company, 'viewer');
        $course = TrainingCatalog::all()[0];
        $lessons = count($course['lessons']);

        $this->actingInCompany($viewer, $company)->put('/capacitacion', ['tutorial_key' => $course['key'], 'completed_lessons' => 1])->assertSessionHasNoErrors();
        $this->actingInCompany($viewer, $company)->put('/capacitacion', ['tutorial_key' => $course['key'], 'completed_lessons' => 1]);

        $this->assertSame(1, TrainingProgress::count());
        $this->assertSame((int) round(100 / $lessons), (int) TrainingProgress::sole()->progress);

        $this->actingInCompany($viewer, $company)->put('/capacitacion', ['tutorial_key' => $course['key'], 'completed_lessons' => 99])->assertSessionHasErrors('completed_lessons');
        $this->actingInCompany($viewer, $company)->put('/capacitacion', ['tutorial_key' => 'inventado', 'completed_lessons' => 1])->assertSessionHasErrors('tutorial_key');

        $this->actingInCompany($viewer, $company)->get('/capacitacion')
            ->assertInertia(fn (Assert $page) => $page->component('Tools/Training')->where('courses.0.completed_lessons', 1));
        $this->actingInCompany($other, $company)->get('/capacitacion')
            ->assertInertia(fn (Assert $page) => $page->where('courses.0.completed_lessons', 0));
    }

    public function test_sops_are_read_marked_and_exported(): void
    {
        [$viewer, $company] = $this->memberOf(null, 'viewer');
        $slug = SopCatalog::all()[1]['slug'];

        $this->actingInCompany($viewer, $company)->put("/sops/{$slug}/lectura", ['read' => true])->assertSessionHasNoErrors();

        $this->actingInCompany($viewer, $company)->get("/sops?sop={$slug}")
            ->assertInertia(fn (Assert $page) => $page->component('Tools/Sops')->where('selected', $slug)->whereNot('sops.1.read_at', null)->where('sops.0.read_at', null));

        $this->actingInCompany($viewer, $company)->get("/sops/{$slug}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingInCompany($viewer, $company)->get('/sops/no-existe/pdf')->assertNotFound();
        $this->actingInCompany($viewer, $company)->put('/sops/no-existe/lectura', ['read' => true])->assertNotFound();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/capacitacion')->assertRedirect('/login');
        $this->get('/sops')->assertRedirect('/login');
    }
}
