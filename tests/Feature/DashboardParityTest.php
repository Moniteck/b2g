<?php

namespace Tests\Feature;

use App\Models\CollectionInvoice;
use App\Models\Document;
use App\Models\Quote;
use App\Models\Tender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_tenders_and_overdue_invoices_for_its_indicators(): void
    {
        $this->travelTo(now()->startOfDay());

        [$user, $company] = $this->memberOf();
        [, $other] = $this->memberOf();

        $this->inCompany($company, function () use ($company) {
            Tender::factory()->for($company)->create(['offer_submitted' => true, 'result' => 'Ganada']);
            Tender::factory()->for($company)->create(['offer_submitted' => true, 'result' => 'Perdida']);
            Quote::factory()->for($company)->won()->create();
            CollectionInvoice::create(['number' => 'FAC-1', 'concept' => 'Servicio', 'amount' => 300, 'issued_at' => today()->subDays(40), 'due_at' => today()->subDay(), 'status' => 'Pendiente']);
            CollectionInvoice::create(['number' => 'FAC-2', 'concept' => 'Pagada', 'amount' => 100, 'issued_at' => today()->subDays(40), 'due_at' => today()->subDay(), 'status' => 'Pagada']);
            Document::factory()->for($company)->expired()->create();
        });

        // Lo de otra empresa no cuenta en los indicadores.
        Tender::factory()->for($other)->create(['offer_submitted' => true, 'result' => 'Ganada']);
        Document::factory()->for($other)->expired()->create();

        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('dashboard.awardRate', 50)
            ->where('dashboard.wonTenders', 1)
            ->where('dashboard.submittedTenders', 2)
            ->where('dashboard.acceptedQuotes', 1)
            ->where('dashboard.overdueReceivables', 300)
            ->where('dashboard.expiredDocuments', 1)
            ->has('alerts', 2));
    }
}
