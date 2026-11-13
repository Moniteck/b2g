<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Crea un usuario miembro de una empresa con el rol indicado.
     *
     *   [$user, $company] = $this->memberOf();                    // admin de una empresa nueva
     *   [$viewer] = $this->memberOf($company, 'viewer');          // otro miembro de la misma
     *
     * @return array{0: User, 1: Company}
     */
    protected function memberOf(?Company $company = null, string $role = 'admin', array $attributes = []): array
    {
        $company ??= Company::factory()->create();
        $user = User::factory()->create($attributes);
        $company->users()->attach($user->id, ['role' => $role, 'is_default' => true]);

        return [$user, $company];
    }

    /**
     * Autentica al usuario con una empresa activa concreta (la de la sesión).
     */
    protected function actingInCompany(User $user, Company $company): static
    {
        return $this->actingAs($user)->withSession([CompanyContext::SESSION_KEY => $company->id]);
    }

    /**
     * Ejecuta código de preparación «dentro» de una empresa, como si fuera su
     * empresa activa, y restaura el contexto sin restricción de las pruebas.
     */
    protected function inCompany(Company $company, callable $callback): mixed
    {
        return app(CompanyContext::class)->runFor($company->id, $callback);
    }
}
