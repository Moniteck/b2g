<?php

namespace Tests\Feature\Quotes;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Tender;
use App\Services\Tenders\TenderFlow;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * «Crear cotización» desde la ficha de una licitación: `/cotizaciones?nueva=1&tender_id=X`
 * abre el alta precargada con la licitación, su institución y la oportunidad enlazada.
 */
class NewQuoteFromTenderTest extends QuotesTestCase
{
    private function tender(?Company $company = null): Tender
    {
        $company ??= $this->company;

        return $this->inCompany($company, fn () => Tender::factory()->for($company)->create([
            'institution_id' => $this->institution->id,
            'reference' => 'LPN-FIN-001-2026',
        ]));
    }

    public function test_new_quote_link_brings_tender_institution_and_opportunity(): void
    {
        $tender = $this->tender();
        $opportunity = $this->inCompany($this->company, fn () => Opportunity::factory()->for($this->company)->stage('oportunidad')->create([
            'institution_id' => $this->institution->id,
            'tender_id' => $tender->id,
        ]));

        $this->as($this->operator)->get("/cotizaciones?nueva=1&tender_id={$tender->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('newQuote.tender_id', $tender->id)
                ->where('newQuote.tender_option.name', 'LPN-FIN-001-2026')
                ->where('newQuote.institution_id', $this->institution->id)
                ->where('newQuote.institution.name', $this->institution->name)
                ->where('newQuote.opportunity_id', $opportunity->id));
    }

    public function test_without_nueva_there_is_no_prefill(): void
    {
        $tender = $this->tender();

        $this->as($this->operator)->get("/cotizaciones?tender_id={$tender->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('newQuote', null));
    }

    public function test_tender_of_another_company_is_not_prefilled(): void
    {
        [, $other] = $this->memberOf(role: 'admin');
        $foreign = $this->tender($other);

        $this->as($this->operator)->get("/cotizaciones?nueva=1&tender_id={$foreign->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('newQuote', []));
    }

    public function test_tender_flow_links_to_the_prefilled_form(): void
    {
        $tender = $this->tender();

        $stage = ['key' => 'por_analizar', 'awaiting_result' => false];
        $flow = TenderFlow::for($tender, $stage, null, 0, null, 0, false, ['update' => true, 'createQuote' => true, 'useAi' => true], aiReady: false);

        $this->assertSame('Crear cotización', $flow['next']['label']);
        $this->assertSame('/cotizaciones?nueva=1&tender_id='.$tender->id, $flow['next']['href']);
    }
}
