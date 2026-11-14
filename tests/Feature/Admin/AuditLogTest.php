<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function log(Company $company, array $attributes): AuditLog
    {
        return AuditLog::query()->withoutGlobalScopes()->forceCreate($attributes + [
            'company_id' => $company->id,
            'channel' => 'web',
            'action' => 'cliente.actualizado',
            'resource_type' => 'Client',
            'resource_id' => 1,
            'created_at' => '2026-11-10 10:00:00',
        ]);
    }

    public function test_filters_and_detail_payload(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');

        $this->log($company, ['user_id' => $operator->id, 'actor_name' => $operator->name, 'changes' => ['name' => 'Nuevo']]);
        $this->log($company, ['user_id' => $admin->id, 'actor_name' => $admin->name, 'channel' => 'mcp', 'action' => 'adjudicacion.ganada', 'resource_type' => 'Quote', 'created_at' => '2026-11-12 09:00:00']);
        $this->log($company, ['user_id' => $admin->id, 'actor_name' => $admin->name, 'action' => 'usuario.creado', 'resource_type' => 'User', 'created_at' => '2026-11-01 08:00:00']);

        $this->actingAs($admin)->get('/configuracion/auditoria')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AuditLog')
            ->has('logs.data', 3)
            ->where('logs.data.0.action', 'adjudicacion.ganada')
            ->has('options.actions', 3)
            ->has('logs.data.0', fn (Assert $row) => $row->hasAll(['id', 'created_at', 'actor_name', 'channel', 'action', 'resource_type', 'resource_id', 'changes', 'ip', 'user_agent', 'request_id', 'user_id'])));

        $this->actingAs($admin)->get('/configuracion/auditoria?channel=mcp')->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
        $this->actingAs($admin)->get("/configuracion/auditoria?user_id={$operator->id}")->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)->where('logs.data.0.changes.name', 'Nuevo'));
        $this->actingAs($admin)->get('/configuracion/auditoria?action=usuario.creado')->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
        $this->actingAs($admin)->get('/configuracion/auditoria?resource_type=Quote')->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
        $this->actingAs($admin)->get('/configuracion/auditoria?from=2026-11-05&to=2026-11-11')->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
        $this->actingAs($admin)->get('/configuracion/auditoria?from=no-es-fecha')->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.from', null));
    }

    public function test_only_admins_see_it_and_only_for_their_company(): void
    {
        [$admin, $company] = $this->memberOf();
        [$viewer] = $this->memberOf($company, 'viewer');
        [$operator] = $this->memberOf($company, 'operator');
        $other = Company::factory()->create();
        $this->log($company, ['action' => 'propio.registro']);
        $this->log($other, ['action' => 'ajeno.registro']);

        $this->actingAs($viewer)->get('/configuracion/auditoria')->assertForbidden();
        $this->actingAs($operator)->get('/configuracion/auditoria')->assertForbidden();

        $this->actingAs($admin)->get('/configuracion/auditoria')->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'propio.registro')
            ->where('options.actions', ['propio.registro']));
    }
}
