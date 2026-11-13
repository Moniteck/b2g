<?php

namespace App\Http\Controllers;

use App\Http\Requests\OfferCalculationRequest;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Services\Pricing\OfferCalculator;
use App\Services\Pricing\TaxRules;
use App\Services\Tenancy\CompanyContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Calculadora de oferta (/calculadora-oferta), sobre el calculador único de precios.
 */
class OfferCalculatorController extends Controller
{
    public function __construct(private CompanyContext $context) {}

    public function index(Request $request): Response
    {
        $this->authorize('price', InventoryItem::class);

        $companyId = $this->context->currentCompanyId();
        $company = $companyId ? Company::query()->find($companyId) : null;

        return Inertia::render('OfferCalculator/Index', [
            'tax' => TaxRules::forCompany($company)->toArray(),
            'defaultScenarios' => OfferCalculationRequest::DEFAULT_SCENARIOS,
            'can' => [
                'view_cost' => $request->user()->can('viewCost', InventoryItem::class),
            ],
        ]);
    }

    public function calculate(OfferCalculationRequest $request, OfferCalculator $calculator): JsonResponse
    {
        $data = $request->validated();

        return response()->json($calculator->calculate($data['lines'], $data['currency'], $request->scenarios()));
    }

    public function pdf(OfferCalculationRequest $request, OfferCalculator $calculator): HttpResponse
    {
        $data = $request->validated();
        $result = $calculator->calculate($data['lines'], $data['currency'], $request->scenarios());
        $companyId = $this->context->currentCompanyId();

        return Pdf::loadView('pdf.offer-calculation', [
            'title' => $data['title'] ?? 'Cálculo de oferta',
            'company' => $companyId ? Company::query()->find($companyId) : null,
            'currency' => $data['currency'],
            'result' => $result,
            'generatedAt' => now(),
        ])->download('calculo-de-oferta.pdf');
    }
}
