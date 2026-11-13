<?php

namespace App\Http\Controllers;

use App\Models\CollectionInvoice;
use App\Models\ManualCashIncome;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CashFlowController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ManualCashIncome::class);
        $incomes = ManualCashIncome::query()->latest('received_at')->paginate(20)->withQueryString();
        $pending = CollectionInvoice::where('status', 'Pendiente')->orderBy('due_at')->get(['id', 'number', 'amount', 'due_at']);

        return Inertia::render('CashFlow', ['records' => $incomes, 'pendingInvoices' => $pending, 'totals' => ['manual' => (float) ManualCashIncome::sum('amount'), 'pending' => (float) $pending->sum('amount'), 'received' => (float) CollectionInvoice::where('status', 'Pagada')->sum('amount')]]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', ManualCashIncome::class);
        ManualCashIncome::create($request->validate(['concept' => 'required|string|max:255', 'amount' => 'required|numeric|gt:0', 'received_at' => 'required|date', 'notes' => 'nullable|string|max:5000']) + ['user_id' => $request->user()->id]);

        return back();
    }

    public function destroy(ManualCashIncome $cashIncome)
    {
        $this->authorize('delete', $cashIncome);
        $cashIncome->delete();

        return back();
    }
}
