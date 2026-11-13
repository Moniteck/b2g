<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompetitorRequest;
use App\Models\Competitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompetitorController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Competitor::class);
        $query = Competitor::query()->latest('last_participation_at');
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('tax_id', 'like', "%{$search}%")->orWhere('category', 'like', "%{$search}%"));
        }
        if ($request->filled('category')) $query->where('category', $request->string('category'));

        $analytics = Competitor::selectRaw('COALESCE(SUM(wins), 0) as wins, COALESCE(SUM(losses), 0) as losses, COALESCE(AVG(average_award_price), 0) as average_award_price')->first();
        $decisions = (int) $analytics->wins + (int) $analytics->losses;

        return Inertia::render('Competitors', [
            'records' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('search', 'category'),
            'analytics' => [
                'competitors' => Competitor::count(),
                'wins' => (int) $analytics->wins,
                'losses' => (int) $analytics->losses,
                'win_rate' => $decisions ? round(((int) $analytics->wins / $decisions) * 100, 2) : 0,
                'average_award_price' => (float) $analytics->average_award_price,
            ],
        ]);
    }

    public function store(CompetitorRequest $request): RedirectResponse { $this->authorize('create', Competitor::class); Competitor::create($request->validated()); return back()->with('success', 'Competidor creado.'); }
    public function update(CompetitorRequest $request, Competitor $competitor): RedirectResponse { $this->authorize('update', $competitor); $competitor->update($request->validated()); return back()->with('success', 'Competidor actualizado.'); }
    public function destroy(Competitor $competitor): RedirectResponse { $this->authorize('delete', $competitor); $competitor->delete(); return back()->with('success', 'Competidor eliminado.'); }
}
