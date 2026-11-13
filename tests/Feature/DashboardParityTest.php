<?php

namespace Tests\Feature;

use App\Models\CollectionInvoice;
use App\Models\Document;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_tenders_and_overdue_invoices_for_its_indicators(): void
    {
        $this->travelTo(now()->startOfDay());
        Tender::create(['reference' => 'LIC-1', 'title' => 'Ganada', 'offer_submitted' => true, 'result' => 'Ganada']);
        Tender::create(['reference' => 'LIC-2', 'title' => 'Perdida', 'offer_submitted' => true, 'result' => 'Perdida']);
        Quote::create(['number' => 'COT-1', 'quoted_at' => today(), 'purchase_type' => 'Compra', 'status' => 'Aceptada']);
        CollectionInvoice::create(['number' => 'FAC-1', 'concept' => 'Servicio', 'amount' => 300, 'issued_at' => today()->subDays(40), 'due_at' => today()->subDay(), 'status' => 'Pendiente']);
        CollectionInvoice::create(['number' => 'FAC-2', 'concept' => 'Pagada', 'amount' => 100, 'issued_at' => today()->subDays(40), 'due_at' => today()->subDay(), 'status' => 'Pagada']);
        Document::create(['name' => 'Documento vencido', 'expires_at' => today()->subDay()]);

        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('dashboard.awardRate', 50)
            ->where('dashboard.wonTenders', 1)
            ->where('dashboard.submittedTenders', 2)
            ->where('dashboard.overdueReceivables', 300)
            ->where('dashboard.expiredDocuments', 1)
            ->has('alerts', 2));
    }
}
