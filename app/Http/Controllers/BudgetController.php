<?php

namespace App\Http\Controllers;

use App\Models\{BudgetMovement, RevolvingBudget};
use App\Services\RevolvingBudgetService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Inertia\Inertia;

class BudgetController extends Controller
{
    public function index(Request $request, RevolvingBudgetService $service) { $this->authorize('viewAny', BudgetMovement::class); $budget = $service->budget(); return Inertia::render('RevolvingBudget', ['budget' => $budget, 'records' => $budget->movements()->latest('moved_at')->paginate(20)->withQueryString()]); }
    public function initialize(Request $request, RevolvingBudgetService $service) { $this->authorize('create', BudgetMovement::class); $data = $request->validate(['initial_amount' => 'required|numeric|min:0']); $budget = $service->budget(); $budget->update(['initial_amount' => $data['initial_amount']]); $service->recalculate($budget); return back(); }
    public function store(Request $request, RevolvingBudgetService $service) { $this->authorize('create', BudgetMovement::class); $data = $request->validate(['moved_at' => 'required|date', 'type' => 'required|in:Ingreso,Egreso', 'concept' => 'required|string|max:255', 'amount' => 'required|numeric|gt:0', 'reference' => 'nullable|string|max:255|unique:budget_movements,reference']); $service->budget()->movements()->create($data + ['user_id' => $request->user()->id, 'synchronized' => false]); $service->recalculate($service->budget()); return back(); }
    public function destroy(BudgetMovement $budgetMovement, RevolvingBudgetService $service) { $this->authorize('delete', $budgetMovement); abort_if($budgetMovement->synchronized, 422, 'Los movimientos sincronizados se actualizan desde su origen.'); $budget = $budgetMovement->revolvingBudget; $budgetMovement->delete(); $service->recalculate($budget); return back(); }
    public function sync(RevolvingBudgetService $service) { $this->authorize('update', BudgetMovement::class); $service->sync(); return back(); }
    public function pdf(RevolvingBudgetService $service) { $this->authorize('viewAny', BudgetMovement::class); $budget = $service->budget(); return Pdf::loadView('pdf.budget', ['budget' => $budget, 'movements' => $budget->movements()->latest('moved_at')->get()])->download('presupuesto-revolvente.pdf'); }
    public function xlsx(RevolvingBudgetService $service) { $this->authorize('viewAny', BudgetMovement::class); $sheet = (new Spreadsheet())->getActiveSheet(); $sheet->fromArray(['Fecha','Tipo','Concepto','Referencia','Monto','Saldo']); foreach ($service->budget()->movements()->orderBy('moved_at')->get() as $i => $row) $sheet->fromArray([$row->moved_at->toDateString(),$row->type,$row->concept,$row->reference,$row->amount,$row->balance_after], null, 'A'.($i + 2)); $path = tempnam(sys_get_temp_dir(), 'budget').'.xlsx'; (new Xlsx($sheet->getParent()))->save($path); return response()->download($path, 'presupuesto-revolvente.xlsx')->deleteFileAfterSend(true); }
}
