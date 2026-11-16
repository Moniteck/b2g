<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\CompetitorRequest;
use App\Models\Competitor;
use App\Models\QuoteParticipant;
use App\Services\System\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inteligencia competitiva: catálogo de competidores compartido por el grupo.
 * El historial de participaciones de cada competidor sale de los participantes
 * registrados en las cotizaciones de la empresa ACTIVA (solo lectura).
 */
class CompetitorController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Competitor::class);

        $search = $request->string('search')->trim()->value();
        $category = $request->string('category')->trim()->value();
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;

        $records = Competitor::query()
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")->orWhere('tax_id', 'like', "%{$search}%")->orWhere('category', 'like', "%{$search}%")))
            ->when($category, fn (Builder $query) => $query->where('category', $category))
            ->orderByRaw('last_participation_at IS NULL')->orderByDesc('last_participation_at')->orderBy('name')
            ->paginate($perPage)->withQueryString()
            ->through(fn (Competitor $competitor) => $this->present($competitor));

        $totals = Competitor::query()->toBase()
            ->selectRaw('COUNT(*) as competitors, COALESCE(SUM(wins), 0) as wins, COALESCE(SUM(losses), 0) as losses')
            ->first();
        $decisions = (int) $totals->wins + (int) $totals->losses;

        return Inertia::render('Competitors/Index', [
            'records' => $records,
            'filters' => ['search' => $search, 'category' => $category],
            'categories' => Competitor::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'analytics' => [
                'competitors' => (int) $totals->competitors,
                'wins' => (int) $totals->wins,
                'losses' => (int) $totals->losses,
                'win_rate' => $decisions > 0 ? round(((int) $totals->wins / $decisions) * 100, 1) : null,
            ],
            'can' => [
                'create' => $request->user()->can('create', Competitor::class),
                'update' => $request->user()->can('create', Competitor::class),
                'delete' => $request->user()->can('delete', new Competitor),
            ],
        ]);
    }

    /**
     * Participaciones del competidor en cotizaciones de la empresa activa,
     * emparejadas por RTN o, si no hay RTN, por nombre exacto.
     */
    public function participations(Competitor $competitor): JsonResponse
    {
        $this->authorize('view', $competitor);

        $rows = QuoteParticipant::query()
            ->where('is_ours', false)
            ->where(fn (Builder $query) => $query
                ->when(filled($competitor->tax_id), fn (Builder $byTax) => $byTax->where('tax_id', $competitor->tax_id)->orWhere(fn (Builder $byName) => $byName->whereNull('tax_id')->whereRaw('LOWER(name) = ?', [mb_strtolower($competitor->name)])))
                ->when(blank($competitor->tax_id), fn (Builder $byName) => $byName->whereRaw('LOWER(name) = ?', [mb_strtolower($competitor->name)])))
            ->whereHas('quote')
            ->with(['quote' => fn ($quote) => $quote->select(['id', 'company_id', 'institution_id', 'number', 'quoted_at', 'status', 'outcome', 'winner_name'])->with('institution:id,name')])
            ->latest('id')
            ->limit(100)
            ->get();

        $participations = $rows->map(fn (QuoteParticipant $participant) => [
            'id' => $participant->id,
            'quote_number' => $participant->quote->number,
            'institution' => $participant->quote->institution?->name,
            'quoted_at' => $participant->quote->quoted_at?->toDateString(),
            'amount' => (float) $participant->amount,
            'currency' => $participant->currency,
            'position' => $participant->position ?: null,
            'won' => filled($participant->quote->winner_name) && mb_strtolower($participant->quote->winner_name) === mb_strtolower($participant->name),
        ]);

        return response()->json([
            'data' => $participations,
            'summary' => [
                'count' => $participations->count(),
                'won' => $participations->where('won', true)->count(),
                'average_amount' => $participations->where('currency', 'HNL')->avg('amount'),
            ],
        ]);
    }

    public function store(CompetitorRequest $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', Competitor::class);

        $competitor = Competitor::create($request->validated());
        $audit->record('competitor.created', $competitor);

        return back()->with('success', "Competidor {$competitor->name} registrado.");
    }

    public function update(CompetitorRequest $request, Competitor $competitor, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $competitor);

        $competitor->update($request->validated());
        $audit->record('competitor.updated', $competitor, $competitor->getChanges());

        return back()->with('success', "Competidor {$competitor->name} actualizado.");
    }

    public function destroy(Competitor $competitor, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $competitor);

        $competitor->delete();
        $audit->record('competitor.deleted', $competitor);

        return back()->with('success', "Competidor {$competitor->name} eliminado.");
    }

    /** @return array<string, mixed> */
    private function present(Competitor $competitor): array
    {
        $decisions = (int) $competitor->wins + (int) $competitor->losses;

        return [
            'id' => $competitor->id,
            'name' => $competitor->name,
            'tax_id' => $competitor->tax_id,
            'category' => $competitor->category,
            'wins' => (int) $competitor->wins,
            'losses' => (int) $competitor->losses,
            'win_rate' => $decisions > 0 ? round(((int) $competitor->wins / $decisions) * 100, 1) : null,
            'institutions' => $competitor->institutions ?? [],
            'average_award_price' => $competitor->average_award_price !== null ? (float) $competitor->average_award_price : null,
            'last_participation_at' => $competitor->last_participation_at?->toDateString(),
            'notes' => $competitor->notes,
        ];
    }
}
