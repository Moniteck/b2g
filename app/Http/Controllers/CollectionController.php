<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FinanceScreen;
use App\Http\Requests\Finance\CollectionInvoiceRequest;
use App\Models\Client;
use App\Models\CollectionInvoice;
use App\Models\Institution;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceExport;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cobros y cartera: facturas por cobrar de la empresa activa, su pago con
 * interés moratorio, notas de gestión, recordatorio por WhatsApp y exportes.
 */
class CollectionController extends Controller
{
    use FinanceScreen;

    /** Filtros del segmentado. */
    private const FILTERS = ['all', 'pending', 'overdue', 'paid'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CollectionInvoice::class);

        $filters = $this->filters($request);

        return Inertia::render('Collections/Index', [
            'records' => $this->filtered($filters)
                ->with(['client:id,name', 'institution:id,name'])
                ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [CollectionInvoice::STATUS_PAID])
                ->orderBy('due_at')->orderByDesc('id')
                ->paginate($this->perPage($request))->withQueryString()
                ->through(fn (CollectionInvoice $invoice) => $this->present($invoice)),
            'filters' => $filters,
            'stats' => $this->stats(),
            'clients' => Client::query()->orderBy('name')->limit(500)->get(['id', 'name']),
            'institutions' => Institution::query()->orderBy('name')->get(['id', 'name']),
            'can' => $this->abilities($request->user(), CollectionInvoice::class),
        ]);
    }

    public function store(CollectionInvoiceRequest $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', CollectionInvoice::class);

        $invoice = CollectionInvoice::query()->create($request->payload() + ['status' => CollectionInvoice::STATUS_PENDING]);
        $audit->record('collection.created', $invoice, ['number' => $invoice->number, 'amount' => $invoice->amount]);

        return back()->with('success', "Factura {$invoice->number} registrada.");
    }

    public function update(CollectionInvoiceRequest $request, CollectionInvoice $collectionInvoice, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $collectionInvoice);

        if ($collectionInvoice->isPaid()) {
            return back()->with('error', 'Una factura pagada ya no se puede editar.');
        }

        $collectionInvoice->update($request->payload());
        $audit->record('collection.updated', $collectionInvoice, $collectionInvoice->getChanges());

        return back()->with('success', "Factura {$collectionInvoice->number} actualizada.");
    }

    public function pay(Request $request, CollectionInvoice $collectionInvoice, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $collectionInvoice);

        $data = $request->validate(
            ['paid_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']],
            ['paid_at.before_or_equal' => 'La fecha de pago no puede ser futura.'],
            ['paid_at' => 'fecha de pago'],
        );

        $paid = DB::transaction(function () use ($collectionInvoice, $data) {
            $invoice = CollectionInvoice::query()->lockForUpdate()->findOrFail($collectionInvoice->id);

            if ($invoice->isPaid()) {
                return null;
            }

            $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at'])->startOfDay() : today();
            if ($paidAt->lt($invoice->issued_at)) {
                $paidAt = $invoice->issued_at->copy();
            }

            $invoice->update([
                'status' => CollectionInvoice::STATUS_PAID,
                'paid_at' => $paidAt,
                'payment_recorded_at' => now(),
                'interest_amount' => $invoice->interestFor($paidAt),
            ]);

            return $invoice;
        });

        if ($paid === null) {
            return back()->with('warning', 'La factura ya estaba registrada como pagada.');
        }

        $audit->record('collection.paid', $paid, ['paid_at' => $paid->paid_at->toDateString(), 'interest_amount' => $paid->interest_amount]);

        return back()->with('success', "Pago de la factura {$paid->number} registrado.");
    }

    public function note(Request $request, CollectionInvoice $collectionInvoice, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $collectionInvoice);

        $data = $request->validate(
            ['collection_note' => ['nullable', 'string', 'max:5000']],
            [],
            ['collection_note' => 'nota de cobro'],
        );

        $collectionInvoice->update(['collection_note' => $data['collection_note'] ?? null]);
        $audit->record('collection.note', $collectionInvoice);

        return back()->with('success', 'Nota de cobro guardada.');
    }

    public function whatsapp(CollectionInvoice $collectionInvoice): JsonResponse
    {
        $this->authorize('view', $collectionInvoice);

        return response()->json(['message' => $this->reminderText($collectionInvoice)]);
    }

    public function destroy(CollectionInvoice $collectionInvoice, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $collectionInvoice);

        $collectionInvoice->delete();
        $audit->record('collection.deleted', $collectionInvoice, ['number' => $collectionInvoice->number]);

        return back()->with('success', "Factura {$collectionInvoice->number} eliminada.");
    }

    public function pdf(Request $request, FinanceExport $export)
    {
        $this->authorize('viewAny', CollectionInvoice::class);

        $filters = $this->filters($request);
        $invoices = $this->exportRows($filters);

        return $export->pdf('pdf.collections', [
            'invoices' => $invoices,
            'filterLabel' => $this->filterLabel($filters),
            'total' => $invoices->sum(fn (CollectionInvoice $invoice) => (float) $invoice->amount),
        ], 'Cobros y cartera', 'cobros', landscape: true);
    }

    public function xlsx(Request $request, FinanceExport $export)
    {
        $this->authorize('viewAny', CollectionInvoice::class);

        $filters = $this->filters($request);
        $invoices = $this->exportRows($filters);

        return $export->xlsx(
            'Cobros y cartera',
            ['Factura', 'Concepto', 'Cliente', 'Institución', 'Emisión', 'Vencimiento', 'Pago', 'Monto (L)', 'Interés (L)', 'Estado'],
            $invoices->map(fn (CollectionInvoice $row) => [
                $row->number, $row->concept, $row->client?->name, $row->institution?->name,
                $row->issued_at?->format('d/m/Y'), $row->due_at?->format('d/m/Y'), $row->paid_at?->format('d/m/Y'),
                (float) $row->amount, (float) $row->interest_amount, $row->displayStatus(),
            ]),
            'cobros',
            moneyColumns: ['H', 'I'],
            subtitle: ['Filtro: '.$this->filterLabel($filters)],
            totals: ['Total', '', '', '', '', '', '', (float) $invoices->sum('amount'), (float) $invoices->sum('interest_amount'), ''],
        );
    }

    public function notePdf(CollectionInvoice $collectionInvoice, FinanceExport $export)
    {
        $this->authorize('view', $collectionInvoice);

        return $export->pdf('pdf.collection-note', [
            'invoice' => $collectionInvoice->load(['client:id,name,tax_id', 'institution:id,name']),
        ], "Nota de cobro {$collectionInvoice->number}", "nota-cobro-{$collectionInvoice->number}");
    }

    /**
     * @return array{filter: string, search: string}
     */
    private function filters(Request $request): array
    {
        $filter = (string) $request->input('filter', 'all');

        return [
            'filter' => in_array($filter, self::FILTERS, true) ? $filter : 'all',
            'search' => mb_substr(trim((string) $request->input('search', '')), 0, 100),
        ];
    }

    /**
     * @param  array{filter: string, search: string}  $filters
     * @return Builder<CollectionInvoice>
     */
    private function filtered(array $filters): Builder
    {
        $query = CollectionInvoice::query();

        match ($filters['filter']) {
            'pending' => $query->unpaid()->whereDate('due_at', '>=', today()),
            'overdue' => $query->overdue(),
            'paid' => $query->where('status', CollectionInvoice::STATUS_PAID),
            default => null,
        };

        if ($filters['search'] !== '') {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn (Builder $inner) => $inner
                ->where('number', 'like', $term)
                ->orWhere('concept', 'like', $term)
                ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $term))
                ->orWhereHas('institution', fn (Builder $institution) => $institution->where('name', 'like', $term)));
        }

        return $query;
    }

    /**
     * @param  array{filter: string, search: string}  $filters
     */
    private function exportRows(array $filters)
    {
        return $this->filtered($filters)
            ->with(['client:id,name', 'institution:id,name'])
            ->orderBy('due_at')->orderBy('id')
            ->limit(5000)
            ->get();
    }

    /**
     * Cifras de la cartera en una sola consulta.
     *
     * @return array<string, float|int>
     */
    private function stats(): array
    {
        $today = today()->toDateString();
        $monthStart = today()->startOfMonth()->toDateString();
        $paid = CollectionInvoice::STATUS_PAID;

        $row = CollectionInvoice::query()->toBase()->selectRaw('
            COUNT(*) as total_count,
            SUM(CASE WHEN status <> ? THEN 1 ELSE 0 END) as unpaid_count,
            COALESCE(SUM(CASE WHEN status <> ? THEN amount ELSE 0 END), 0) as receivable,
            SUM(CASE WHEN status <> ? AND due_at < ? THEN 1 ELSE 0 END) as overdue_count,
            COALESCE(SUM(CASE WHEN status <> ? AND due_at < ? THEN amount ELSE 0 END), 0) as overdue,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid_count,
            COALESCE(SUM(CASE WHEN status = ? AND paid_at >= ? THEN amount ELSE 0 END), 0) as collected_month
        ', [$paid, $paid, $paid, $today, $paid, $today, $paid, $paid, $monthStart])->first();

        $unpaid = (int) ($row->unpaid_count ?? 0);
        $overdue = (int) ($row->overdue_count ?? 0);

        return [
            'total_count' => (int) ($row->total_count ?? 0),
            'receivable' => (float) ($row->receivable ?? 0),
            'unpaid_count' => $unpaid,
            'overdue' => (float) ($row->overdue ?? 0),
            'overdue_count' => $overdue,
            'pending_count' => $unpaid - $overdue,
            'paid_count' => (int) ($row->paid_count ?? 0),
            'collected_month' => (float) ($row->collected_month ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CollectionInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'concept' => $invoice->concept,
            'client_id' => $invoice->client_id,
            'client' => $invoice->client?->name,
            'institution_id' => $invoice->institution_id,
            'institution' => $invoice->institution?->name,
            'amount' => (float) $invoice->amount,
            'interest_rate' => (float) $invoice->interest_rate,
            'interest_amount' => (float) $invoice->interest_amount,
            'issued_at' => $invoice->issued_at?->toDateString(),
            'due_at' => $invoice->due_at?->toDateString(),
            'paid_at' => $invoice->paid_at?->toDateString(),
            'status' => $invoice->status,
            'display_status' => $invoice->displayStatus(),
            'is_paid' => $invoice->isPaid(),
            'collection_note' => $invoice->collection_note,
            'notes' => $invoice->notes,
        ];
    }

    private function reminderText(CollectionInvoice $invoice): string
    {
        $amount = 'L '.number_format((float) $invoice->amount, 2);
        $due = $invoice->due_at->format('d/m/Y');
        $verb = $invoice->isOverdue() ? 'venció el' : 'vence el';

        return "Estimado cliente: le recordamos que la factura {$invoice->number} ({$invoice->concept}) por {$amount} {$verb} {$due}. Quedamos atentos a su pago.";
    }

    /**
     * @param  array{filter: string, search: string}  $filters
     */
    private function filterLabel(array $filters): string
    {
        $label = ['all' => 'Todas', 'pending' => 'Pendientes', 'overdue' => 'Vencidas', 'paid' => 'Pagadas'][$filters['filter']];

        return $filters['search'] !== '' ? "{$label} · búsqueda «{$filters['search']}»" : $label;
    }
}
