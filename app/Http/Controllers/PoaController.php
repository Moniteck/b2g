<?php

namespace App\Http\Controllers;

use App\Models\{Institution, PoaEntry};
use Illuminate\Http\Request;
use Inertia\Inertia;

class PoaController extends Controller
{
    public function index(Request $request) { $this->authorize('viewAny', PoaEntry::class); $query = PoaEntry::with('institution:id,name')->latest(); foreach (['year','quarter','status'] as $field) if ($request->filled($field)) $query->where($field, $request->input($field)); return Inertia::render('Poa', ['records' => $query->paginate(20)->withQueryString(), 'institutions' => Institution::orderBy('name')->get(['id','name'])]); }
    public function store(Request $request) { $this->authorize('create', PoaEntry::class); PoaEntry::create($this->data($request)); return back(); }
    public function update(Request $request, PoaEntry $poaEntry) { $this->authorize('update', $poaEntry); $poaEntry->update($this->data($request)); return back(); }
    public function execute(PoaEntry $poaEntry) { $this->authorize('update', $poaEntry); $poaEntry->update(['executed_amount' => $poaEntry->budgeted_amount, 'status' => 'Ejecutada']); return back(); }
    public function destroy(PoaEntry $poaEntry) { $this->authorize('delete', $poaEntry); $poaEntry->delete(); return back(); }
    private function data(Request $request): array { return $request->validate(['institution_id' => 'nullable|exists:institutions,id','project' => 'required|string|max:255','year' => 'required|integer|min:2020|max:2100','partida' => 'nullable|string|max:100','quarter' => 'nullable|integer|between:1,4','probability' => 'required|integer|between:0,100','budgeted_amount' => 'required|numeric|min:0','executed_amount' => 'nullable|numeric|min:0|lte:budgeted_amount','estimated_date' => 'nullable|date','annual_close_alert' => 'boolean','status' => 'required|string|max:100','notes' => 'nullable|string|max:5000']); }
}
