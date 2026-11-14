<?php

namespace Tests\Feature\Quotes;

use App\Models\Company;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base de las pruebas de cotizaciones: una empresa con sus tres roles y una institución.
 */
abstract class QuotesTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected User $operator;

    protected User $viewer;

    protected Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        $this->company->forceFill(['name' => 'CAP Honduras', 'tax_rate' => 15, 'prices_include_tax' => false])->save();
        [$this->operator] = $this->memberOf($this->company, 'operator');
        [$this->viewer] = $this->memberOf($this->company, 'viewer');
        $this->institution = Institution::factory()->create(['name' => 'Secretaría de Salud']);
    }

    protected function as(User $user): static
    {
        return $this->actingInCompany($user, $this->company);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'institution_id' => $this->institution->id,
            'quoted_at' => '2026-11-13',
            'validity_days' => 30,
            'purchase_type' => 'Licitación Pública',
            'tax_included' => false,
            'conditions' => 'Entrega en 15 días hábiles.',
            'items' => [
                ['description' => 'Resma de papel carta', 'quantity' => 10, 'unit' => 'Resma', 'unit_price' => 100, 'tax_rate' => 15],
                ['description' => 'Tóner', 'quantity' => 2, 'unit' => 'Unidad', 'unit_price' => 1000, 'tax_rate' => 15, 'discount_percent' => 10],
            ],
        ];
    }

    /**
     * Cotización emitida o presentada lista para adjudicar, con su oportunidad.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function quote(array $attributes = [], bool $withOpportunity = true): Quote
    {
        return $this->inCompany($this->company, function () use ($attributes, $withOpportunity) {
            $opportunity = $withOpportunity
                ? Opportunity::factory()->for($this->company)->stage('oferta_presentada')->create(['institution_id' => $this->institution->id, 'amount' => 11500])
                : null;

            return Quote::factory()->for($this->company)->create($attributes + [
                'institution_id' => $this->institution->id,
                'opportunity_id' => $opportunity?->id,
                'status' => 'Presentada',
                'subtotal' => 10000,
                'tax' => 1500,
                'total' => 11500,
            ]);
        });
    }
}
