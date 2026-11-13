<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcessHistoryRequest;
use App\Models\ProcessHistory;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Historial de procesos de HonduCompras de la empresa activa: en cuáles se
 * participó, con qué monto, el resultado y su causa.
 */
class ProcessHistoryController extends Controller
{
    private const FILTERS = ['all', 'participated', 'awarded', 'not_awarded'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ProcessHistory::class);

        $search = $request->string('search')->trim()->value();
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'all';
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;

        $base = ProcessHistory::query()
            ->when($search, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('process_number', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhereHas('institution', fn ($institution) => $institution->where('name', 'like', "%{$search}%"))));

        $counts = (clone $base)->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN participated = 1 THEN 1 ELSE 0 END) as participated')
            ->selectRaw("SUM(CASE WHEN result = 'Adjudicado' THEN 1 ELSE 0 END) as awarded")
            ->selectRaw("SUM(CASE WHEN result = 'No adjudicado' THEN 1 ELSE 0 END) as not_awarded")
            ->first();

        $records = $base
            ->with('institution:id,name')
            ->when($filter === 'participated', fn ($query) => $query->where('participated', true))
            ->when($filter === 'awarded', fn ($query) => $query->where('result', 'Adjudicado'))
            ->when($filter === 'not_awarded', fn ($query) => $query->where('result', 'No adjudicado'))
            ->orderByDesc('closed_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString()
            ->through(fn (ProcessHistory $record) => $this->present($record));

        $participated = (int) $counts->participated;

        return Inertia::render('ProcessHistory', [
            'records' => $records,
            'filters' => ['search' => $search, 'filter' => $filter],
            'counts' => [
                'total' => (int) $counts->total,
                'participated' => $participated,
                'awarded' => (int) $counts->awarded,
                'not_awarded' => (int) $counts->not_awarded,
                'win_rate' => $participated > 0 ? round(((int) $counts->awarded / $participated) * 100, 1) : null,
            ],
            'results' => ProcessHistoryRequest::RESULTS,
            'can' => [
                'create' => $request->user()->can('create', ProcessHistory::class),
                'update' => $request->user()->hasPermission(Permissions::UPDATE),
                'delete' => $request->user()->hasPermission(Permissions::DELETE),
            ],
        ]);
    }

    public function store(ProcessHistoryRequest $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', ProcessHistory::class);

        $record = ProcessHistory::create($request->validated());
        $audit->record('process_history.created', $record);

        return back()->with('success', "Proceso {$record->process_number} registrado.");
    }

    public function update(ProcessHistoryRequest $request, ProcessHistory $processHistory, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $processHistory);

        $processHistory->update($request->validated());
        $audit->record('process_history.updated', $processHistory, $processHistory->getChanges());

        return back()->with('success', "Proceso {$processHistory->process_number} actualizado.");
    }

    public function destroy(ProcessHistory $processHistory, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $processHistory);

        $processHistory->delete();
        $audit->record('process_history.deleted', $processHistory);

        return back()->with('success', "Proceso {$processHistory->process_number} eliminado.");
    }

    /** @return array<string, mixed> */
    private function present(ProcessHistory $record): array
    {
        return [
            'id' => $record->id,
            'process_number' => $record->process_number,
            'name' => $record->name,
            'institution_id' => $record->institution_id,
            'institution' => $record->institution?->name,
            'modality' => $record->modality,
            'category' => $record->category,
            'participated' => (bool) $record->participated,
            'decision' => $record->decision,
            'estimated_amount' => $record->estimated_amount !== null ? (float) $record->estimated_amount : null,
            'offered_amount' => $record->offered_amount !== null ? (float) $record->offered_amount : null,
            'result' => $record->result,
            'cause' => $record->cause,
            'published_at' => $record->published_at?->toDateString(),
            'closed_at' => $record->closed_at?->toDateString(),
            'notes' => $record->notes,
        ];
    }
}
