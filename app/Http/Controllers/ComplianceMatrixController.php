<?php

namespace App\Http\Controllers;

use App\Models\ComplianceMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ComplianceMatrixController extends Controller
{
    public function index() { $this->authorize('viewAny', ComplianceMatrix::class); return Inertia::render('ComplianceMatrices', ['records' => ComplianceMatrix::with(['items.document', 'tender:id,reference,title'])->latest()->paginate(20)]); }
    public function store(Request $request): RedirectResponse { $this->authorize('create', ComplianceMatrix::class); $data = $request->validate(['tender_id' => 'required|exists:tenders,id', 'name' => 'required|string|max:255', 'items' => 'required|array|min:1', 'items.*.requirement' => 'required|string|max:255', 'items.*.document_id' => 'nullable|exists:documents,id', 'items.*.renewal_due_at' => 'nullable|date', 'items.*.notes' => 'nullable|string|max:2000']); DB::transaction(fn () => ComplianceMatrix::create(['tender_id' => $data['tender_id'], 'name' => $data['name']])->items()->createMany($data['items'])); return back(); }
    public function toggle(Request $request, ComplianceMatrix $complianceMatrix, int $item): RedirectResponse { $this->authorize('update', $complianceMatrix); $complianceMatrix->items()->findOrFail($item)->update($request->validate(['completed' => 'required|boolean'])); return back(); }
    public function renew(Request $request, ComplianceMatrix $complianceMatrix, int $item): RedirectResponse { $this->authorize('update', $complianceMatrix); $data = $request->validate(['document_id' => 'required|exists:documents,id', 'renewal_due_at' => 'nullable|date', 'notes' => 'nullable|string|max:2000']); DB::transaction(fn () => $complianceMatrix->items()->findOrFail($item)->update([...$data, 'completed' => true])); return back(); }
}
