<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Tender;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use App\Services\Notifications\CalendarSyncService;
use App\Services\ProcessExportService;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OpportunityController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Opportunity::class);

        $query = $this->filtered($request)->with(['institution:id,name', 'owner:id,name', 'quote:id,number,outcome,total']);

        $opportunities = $query->paginate(30)->through(fn (Opportunity $item) => [
            'id' => (string) $item->id,
            'institutionId' => $item->institution_id,
            'titulo' => $item->title,
            'institucion' => $item->institution?->name ?? '',
            'monto' => (float) $item->amount,
            'probabilidad' => $item->probability,
            'etapa' => $item->stage,
            'etapaNombre' => $item->stageLabel(),
            'cierre' => $item->expected_close_date?->toDateString(),
            'responsable' => $item->owner?->name ?? 'Sin asignar',
            'siguienteAccion' => $item->next_action,
            'ganador' => $item->winner_name,
            'montoAdjudicado' => $item->awarded_amount !== null ? (float) $item->awarded_amount : null,
            'cotizacionId' => $item->quote_id,
            'cotizacionNumero' => $item->quote?->number,
        ]);

        return Inertia::render('Pipeline', [
            'opportunities' => $opportunities,
            'institutions' => Institution::orderBy('name')->get(['id', 'name']),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'stages' => Opportunity::STAGES,
            'filters' => $request->only('search', 'stage'),
        ]);
    }

    public function show(Opportunity $opportunity)
    {
        $this->authorize('view', $opportunity);

        return response()->json($opportunity->load(['institution:id,name', 'contact:id,name', 'owner:id,name', 'quote:id,number,status,outcome,total']));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Opportunity::class);

        $data = $request->validate([
            'company_id' => ['nullable', 'integer', new AllowedCompany],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'tender_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)],
            'title' => ['required', 'string', 'max:255'],
            'opportunity_type' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0'],
            'probability' => ['required', 'integer', 'between:0,100'],
            'stage' => ['required', 'in:'.implode(',', array_keys(Opportunity::STAGES))],
            'next_action' => ['nullable', 'string', 'max:255'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['company_id'] = $data['company_id'] ?? app(CompanyContext::class)->currentCompanyId();
        $data['owner_id'] = $request->user()->id;

        $opportunity = Opportunity::create($data);

        app(CalendarSyncService::class)->syncForOpportunity($opportunity);

        return back()->with('success', 'Oportunidad creada correctamente.');
    }

    public function update(Request $request, Opportunity $opportunity): RedirectResponse
    {
        $this->authorize('update', $opportunity);

        $data = $request->validate([
            'company_id' => ['nullable', 'integer', new AllowedCompany],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'tender_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)],
            'title' => ['required', 'string', 'max:255'],
            'opportunity_type' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0'],
            'probability' => ['required', 'integer', 'between:0,100'],
            'stage' => ['required', 'in:'.implode(',', array_keys(Opportunity::STAGES))],
            'next_action' => ['nullable', 'string', 'max:255'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $opportunity->fill($data)->save();

        app(CalendarSyncService::class)->syncForOpportunity($opportunity);

        return back()->with('success', 'Oportunidad actualizada.');
    }

    /**
     * Cambio de etapa en el tablero.
     */
    public function stage(Request $request, Opportunity $opportunity, CalendarSyncService $calendar): RedirectResponse
    {
        $this->authorize('update', $opportunity);

        $data = $request->validate([
            'stage' => ['required', 'in:'.implode(',', array_keys(Opportunity::STAGES))],
            'winner_name' => ['nullable', 'string', 'max:255'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0'],
            'lost_reason' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($data['stage'] === 'no_adjudicado' && blank($data['winner_name'] ?? null)) {
            return back()->withErrors(['winner_name' => 'Indique quién ganó la oferta.']);
        }

        $opportunity->fill([
            'stage' => $data['stage'],
            'winner_name' => $data['winner_name'] ?? null,
            'awarded_amount' => $data['awarded_amount'] ?? null,
            'lost_reason' => $data['lost_reason'] ?? null,
            'awarded_at' => in_array($data['stage'], ['adjudicado', 'no_adjudicado'], true) ? now()->toDateString() : null,
        ])->save();

        $calendar->syncForOpportunity($opportunity);

        return back()->with('success', 'Etapa actualizada.');
    }

    public function destroy(Opportunity $opportunity): RedirectResponse
    {
        $this->authorize('delete', $opportunity);

        $opportunity->delete();

        return back()->with('success', 'Oportunidad eliminada.');
    }

    public function pdf(Request $request, ProcessExportService $exports)
    {
        $this->authorize('viewAny', Opportunity::class);

        $records = $this->filtered($request)->with('institution:id,name')->get()->map(fn (Opportunity $opportunity) => (object) [
            'reference' => 'OP-'.$opportunity->id,
            'title' => $opportunity->title,
            'institution' => $opportunity->institution,
            'estimated_amount' => $opportunity->amount,
            'status' => $opportunity->stageLabel(),
            'closing_date' => $opportunity->expected_close_date,
        ]);

        return $exports->pdf('Pipeline de oportunidades', $records);
    }

    public function xlsx(Request $request, ProcessExportService $exports)
    {
        $this->authorize('viewAny', Opportunity::class);

        $records = $this->filtered($request)->with('institution:id,name')->get()->map(fn (Opportunity $opportunity) => (object) [
            'reference' => 'OP-'.$opportunity->id,
            'title' => $opportunity->title,
            'institution' => $opportunity->institution,
            'purchase_type' => $opportunity->opportunity_type,
            'estimated_amount' => $opportunity->amount,
            'published_at' => null,
            'closing_date' => $opportunity->expected_close_date,
            'status' => $opportunity->stageLabel(),
            'result' => $opportunity->winner_name,
        ]);

        return $exports->spreadsheet($records, 'pipeline.xlsx');
    }

    private function filtered(Request $request)
    {
        $query = Opportunity::query()->orderBy('expected_close_date')->orderBy('id');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhereHas('institution', fn ($i) => $i->where('name', 'like', "%{$search}%")));
        }

        if ($request->filled('stage')) {
            $query->where('stage', $request->string('stage'));
        }

        return $query;
    }
}
