<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\ComplianceItemRequest;
use App\Http\Requests\Tools\ComplianceMatrixRequest;
use App\Http\Requests\Tools\ComplianceRenewRequest;
use App\Models\ComplianceItem;
use App\Models\ComplianceMatrix;
use App\Models\Document;
use App\Models\Tender;
use App\Services\System\AuditLogger;
use App\Support\Permissions;
use App\Support\Tools\CompanyLetterhead;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Matriz documental: por licitación, los requisitos que exige el pliego con su
 * estado (cumple, pendiente o vencido), el documento que los respalda y su
 * vencimiento. Las columnas existentes de `compliance_matrices` y
 * `compliance_items` las usa también el análisis del pliego.
 */
class ComplianceMatrixController extends Controller
{
    private const FILTERS = ['all', 'pending', 'expired', 'complete'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ComplianceMatrix::class);

        $search = $request->string('search')->trim()->value();
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'all';
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $today = today()->toDateString();

        $expired = fn (Builder $items) => $items->whereNotNull('renewal_due_at')->where('renewal_due_at', '<', $today);
        $notExpired = fn (Builder $items) => $items->where(fn (Builder $inner) => $inner->whereNull('renewal_due_at')->orWhere('renewal_due_at', '>=', $today));

        $base = ComplianceMatrix::query()
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('tender', fn (Builder $tender) => $tender->where('reference', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%"))));

        $records = (clone $base)
            ->with('tender:id,reference,title,presentation_deadline')
            ->withCount([
                'items',
                'items as complies_count' => fn (Builder $items) => $notExpired($items)->where('completed', true),
                'items as expired_count' => $expired,
            ])
            ->withMin(['items as next_due_at' => fn (Builder $items) => $items->where('renewal_due_at', '>=', $today)], 'renewal_due_at')
            ->when($filter === 'expired', fn (Builder $query) => $query->whereHas('items', $expired))
            ->when($filter === 'pending', fn (Builder $query) => $query->whereHas('items', fn (Builder $items) => $notExpired($items)->where('completed', false)))
            ->when($filter === 'complete', fn (Builder $query) => $query->has('items')
                ->whereDoesntHave('items', fn (Builder $items) => $items->where(fn (Builder $open) => $open->where('completed', false)->orWhere(fn (Builder $inner) => $expired($inner)))))
            ->latest('id')
            ->paginate($perPage)->withQueryString()
            ->through(fn (ComplianceMatrix $matrix) => $this->summary($matrix));

        return Inertia::render('ComplianceMatrices/Index', [
            'records' => $records,
            'filters' => ['search' => $search, 'filter' => $filter],
            'tenders' => Tender::query()->select(['id', 'reference', 'title'])->latest('id')->limit(300)->get()
                ->map(fn (Tender $tender) => ['id' => $tender->id, 'name' => $tender->reference, 'description' => $tender->title]),
            'can' => $this->abilities($request),
        ]);
    }

    public function show(Request $request, ComplianceMatrix $complianceMatrix): Response
    {
        $this->authorize('view', $complianceMatrix);

        $complianceMatrix->load(['tender:id,reference,title,presentation_deadline', 'items' => fn ($items) => $items->with('document:id,name,expires_at')->orderBy('id')]);

        return Inertia::render('ComplianceMatrices/Show', [
            'matrix' => [
                'id' => $complianceMatrix->id,
                'name' => $complianceMatrix->name,
                'tender' => $this->tender($complianceMatrix->tender),
                'summary' => $this->counts($complianceMatrix->items),
            ],
            'items' => $complianceMatrix->items->map(fn (ComplianceItem $item) => $this->item($item))->values(),
            'documents' => Document::query()->orderBy('name')->limit(500)->get(['id', 'name', 'category', 'expires_at'])
                ->map(fn (Document $document) => [
                    'id' => $document->id,
                    'name' => $document->name,
                    'description' => collect([$document->category, $document->expires_at ? 'Vence '.$document->expires_at->format('d/m/Y') : null])->filter()->implode(' · '),
                    'expires_at' => $document->expires_at?->toDateString(),
                ]),
            'can' => $this->abilities($request),
        ]);
    }

    public function store(ComplianceMatrixRequest $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', ComplianceMatrix::class);

        $data = $request->validated();

        $matrix = DB::transaction(function () use ($data) {
            $matrix = ComplianceMatrix::create(['tender_id' => $data['tender_id'], 'name' => $data['name']]);
            $matrix->items()->createMany(collect($data['items'])->map(fn (array $item) => $item + ['completed' => false])->all());

            return $matrix;
        });

        $audit->record('compliance_matrix.created', $matrix, ['items' => count($data['items'])]);

        return redirect()->route('compliance.show', $matrix)->with('success', "Matriz «{$matrix->name}» creada.");
    }

    public function update(ComplianceMatrixRequest $request, ComplianceMatrix $complianceMatrix, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $complianceMatrix);

        $complianceMatrix->update(['name' => $request->validated('name')]);
        $audit->record('compliance_matrix.updated', $complianceMatrix, $complianceMatrix->getChanges());

        return back()->with('success', 'Matriz actualizada.');
    }

    public function destroy(ComplianceMatrix $complianceMatrix, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $complianceMatrix);

        $complianceMatrix->delete();
        $audit->record('compliance_matrix.deleted', $complianceMatrix);

        return redirect()->route('compliance.index')->with('success', "Matriz «{$complianceMatrix->name}» eliminada.");
    }

    public function storeItem(ComplianceItemRequest $request, ComplianceMatrix $complianceMatrix, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $complianceMatrix);

        $item = $complianceMatrix->items()->create($request->validated());
        $audit->record('compliance_item.created', $complianceMatrix, ['item' => $item->id]);

        return back()->with('success', 'Requisito agregado.');
    }

    public function updateItem(ComplianceItemRequest $request, ComplianceMatrix $complianceMatrix, int $item, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $complianceMatrix);

        $row = $complianceMatrix->items()->findOrFail($item);
        $row->update($request->validated());
        $audit->record('compliance_item.updated', $complianceMatrix, ['item' => $row->id] + $row->getChanges());

        return back()->with('success', 'Requisito actualizado.');
    }

    /**
     * Marca un requisito como cumplido o pendiente.
     */
    public function toggle(Request $request, ComplianceMatrix $complianceMatrix, int $item, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $complianceMatrix);

        $completed = (bool) $request->validate(['completed' => ['required', 'boolean']])['completed'];
        $row = $complianceMatrix->items()->findOrFail($item);
        $row->update(['completed' => $completed]);
        $audit->record('compliance_item.toggled', $complianceMatrix, ['item' => $row->id, 'completed' => $completed]);

        return back()->with('success', $completed ? 'Requisito marcado como cumplido.' : 'Requisito marcado como pendiente.');
    }

    /**
     * Renueva un requisito: vincula el documento vigente y su nueva fecha de vencimiento.
     */
    public function renew(ComplianceRenewRequest $request, ComplianceMatrix $complianceMatrix, int $item, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $complianceMatrix);

        $data = $request->validated();

        DB::transaction(function () use ($complianceMatrix, $item, $data) {
            $row = $complianceMatrix->items()->lockForUpdate()->findOrFail($item);
            $row->update([
                'document_id' => $data['document_id'],
                'renewal_due_at' => $data['renewal_due_at'],
                'notes' => $data['notes'] ?? $row->notes,
                'completed' => true,
            ]);
        });

        $audit->record('compliance_item.renewed', $complianceMatrix, ['item' => $item, 'document_id' => $data['document_id']]);

        return back()->with('success', 'Requisito renovado.');
    }

    public function destroyItem(ComplianceMatrix $complianceMatrix, int $item, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $complianceMatrix);

        $complianceMatrix->items()->findOrFail($item)->delete();
        $audit->record('compliance_item.deleted', $complianceMatrix, ['item' => $item]);

        return back()->with('success', 'Requisito eliminado.');
    }

    public function pdf(ComplianceMatrix $complianceMatrix)
    {
        $this->authorize('view', $complianceMatrix);

        $complianceMatrix->load(['tender:id,reference,title', 'items' => fn ($items) => $items->with('document:id,name')->orderBy('id')]);
        $items = $complianceMatrix->items->map(fn (ComplianceItem $item) => $this->item($item))->values()->all();

        return Pdf::loadView('pdf.tools.compliance-matrix', [
            'title' => 'Matriz documental: '.$complianceMatrix->name,
            'subtitle' => $complianceMatrix->tender ? trim($complianceMatrix->tender->reference.' · '.$complianceMatrix->tender->title, ' ·') : null,
            'company' => CompanyLetterhead::current(),
            'generatedAt' => now(),
            'items' => $items,
            'summary' => $this->counts($complianceMatrix->items),
        ])->download('matriz-'.Str::slug($complianceMatrix->name).'.pdf');
    }

    /** @return array<string, bool> */
    private function abilities(Request $request): array
    {
        return [
            'create' => $request->user()->can('create', ComplianceMatrix::class),
            'update' => $request->user()->hasPermission(Permissions::UPDATE),
            'delete' => $request->user()->hasPermission(Permissions::DELETE),
        ];
    }

    /** @return array<string, mixed>|null */
    private function tender(?Tender $tender): ?array
    {
        return $tender ? [
            'id' => $tender->id,
            'reference' => $tender->reference,
            'title' => $tender->title,
            'presentation_deadline' => $tender->presentation_deadline?->toDateTimeString(),
        ] : null;
    }

    /** @return array<string, mixed> */
    private function summary(ComplianceMatrix $matrix): array
    {
        $total = (int) $matrix->items_count;
        $complies = (int) $matrix->complies_count;
        $expired = (int) $matrix->expired_count;

        return [
            'id' => $matrix->id,
            'name' => $matrix->name,
            'tender' => $this->tender($matrix->tender),
            'total' => $total,
            'complies' => $complies,
            'expired' => $expired,
            'pending' => max($total - $complies - $expired, 0),
            'progress' => $total > 0 ? (int) floor(($complies / $total) * 100) : 0,
            'next_due_at' => $matrix->next_due_at ? substr((string) $matrix->next_due_at, 0, 10) : null,
        ];
    }

    /**
     * @param  iterable<ComplianceItem>  $items
     * @return array<string, int>
     */
    private function counts(iterable $items): array
    {
        $statuses = collect($items)->map(fn (ComplianceItem $item) => $item->status());
        $total = $statuses->count();
        $complies = $statuses->filter(fn ($status) => $status === ComplianceItem::STATUS_COMPLIES)->count();

        return [
            'total' => $total,
            'complies' => $complies,
            'pending' => $statuses->filter(fn ($status) => $status === ComplianceItem::STATUS_PENDING)->count(),
            'expired' => $statuses->filter(fn ($status) => $status === ComplianceItem::STATUS_EXPIRED)->count(),
            'progress' => $total > 0 ? (int) floor(($complies / $total) * 100) : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function item(ComplianceItem $item): array
    {
        return [
            'id' => $item->id,
            'requirement' => $item->requirement,
            'document_id' => $item->document_id,
            'document' => $item->document?->name,
            'renewal_due_at' => $item->renewal_due_at?->toDateString(),
            'completed' => (bool) $item->completed,
            'status' => $item->status(),
            'notes' => $item->notes,
        ];
    }
}
