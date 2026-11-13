<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcessHistoryRequest;
use App\Models\Institution;
use App\Models\ProcessHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcessHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ProcessHistory::class);
        $q = ProcessHistory::with('institution:id,name')->latest('closed_at');
        if ($s = $request->string('search')->trim()->value()) {
            $q->where(fn ($x) => $x->where('process_number', 'like', "%$s%")->orWhere('name', 'like', "%$s%"));
        } if ($request->filled('result')) {
            $q->where('result', $request->string('result'));
        } $stats = ['total' => (clone $q)->count(), 'participated' => (clone $q)->where('participated', true)->count(), 'awarded' => (clone $q)->where('result', 'Adjudicado')->count()];

        return Inertia::render('ProcessHistory', ['records' => $q->paginate(20)->withQueryString(), 'institutions' => Institution::orderBy('name')->get(['id', 'name']), 'filters' => $request->only('search', 'result'), 'statistics' => $stats]);
    }

    public function store(ProcessHistoryRequest $request): RedirectResponse
    {
        $this->authorize('create', ProcessHistory::class);
        ProcessHistory::create($request->validated());

        return back()->with('success', 'Proceso registrado.');
    }

    public function destroy(ProcessHistory $processHistory): RedirectResponse
    {
        $this->authorize('delete', $processHistory);
        $processHistory->delete();

        return back()->with('success', 'Proceso eliminado.');
    }
}
