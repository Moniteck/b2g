<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteStatusRequest;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\Institution;
use App\Models\InventoryItem;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use App\Services\Business\AdjudicationService;
use App\Services\Business\QuoteService;
use App\Services\Documents\DocumentService;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Quote::class);

        $query = $this->filtered($request)
            ->with(['company:id,name', 'institution:id,name', 'contact:id,name', 'items', 'participants'])
            ->withCount('documents');

        $quotes = $query->paginate(20)->through(fn (Quote $quote) => [
            'id' => (string) $quote->id,
            'numero' => $quote->number,
            'fecha' => $quote->quoted_at?->toDateString(),
            'empresa' => $quote->company?->name,
            'institucion' => $quote->institution?->name ?? '',
            'contacto' => $quote->contact?->name ?? '',
            'items' => $quote->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'descripcion' => $item->description,
                'cantidad' => (float) $item->quantity,
                'unidad' => $item->unit,
                'precioUnitario' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ]),
            'subtotal' => (float) $quote->subtotal,
            'impuesto' => (float) $quote->tax,
            'total' => (float) $quote->total,
            'validezDias' => $quote->validity_days,
            'estado' => $quote->status,
            'resultado' => $quote->outcome,
            'tipoCompra' => $quote->purchase_type,
            'notas' => $quote->notes,
            'participantes' => $quote->participants->count(),
            'documentos' => $quote->documents_count,
            'montoAdjudicado' => $quote->awarded_amount !== null ? (float) $quote->awarded_amount : null,
            'ganador' => $quote->winner_name,
        ]);

        return Inertia::render('Quotes', [
            'quotes' => $quotes,
            'companies' => Company::orderBy('name')->get(['id', 'name', 'tax_id', 'representative']),
            'institutions' => Institution::orderBy('name')->get(['id', 'name']),
            'contacts' => Contact::orderBy('name')->get(['id', 'institution_id', 'name']),
            'statuses' => Quote::STATUSES,
            'outcomes' => Quote::OUTCOMES,
            'filters' => $request->only('search', 'status', 'type', 'outcome'),
        ]);
    }

    public function store(Request $request, QuoteService $quotes): RedirectResponse
    {
        $this->authorize('create', Quote::class);

        $data = $this->validated($request);
        $companyId = (int) ($data['company_id'] ?? $this->currentCompanyId());

        $quote = $quotes->create($companyId, (int) $request->user()->id, $data);

        return back()->with('success', "Cotización {$quote->number} creada correctamente.");
    }

    public function update(Request $request, Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $this->authorize('update', $quote);

        $quotes->update($quote, $this->validated($request));

        return back()->with('success', 'Cotización actualizada correctamente.');
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        $this->authorize('delete', $quote);

        $quote->delete();

        return back()->with('success', 'Cotización eliminada.');
    }

    /**
     * Cambio del estado documental. No altera el resultado comercial.
     */
    public function status(QuoteStatusRequest $request, Quote $quote): RedirectResponse
    {
        $this->authorize('update', $quote);

        $quote->update($request->validated());

        return back()->with('success', 'Estado actualizado.');
    }

    /**
     * Registro del resultado comercial: Ganada o Perdida.
     */
    public function outcome(Request $request, Quote $quote, AdjudicationService $adjudication): RedirectResponse
    {
        $this->authorize('adjudicate', $quote);

        $data = $request->validate([
            'outcome' => ['required', 'in:Ganada,Perdida'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0'],
            'awarded_at' => ['nullable', 'date'],
            'winner_name' => ['nullable', 'string', 'max:255'],
            'lost_reason' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($data['outcome'] === 'Perdida' && blank($data['winner_name'] ?? null)) {
            return back()->withErrors(['winner_name' => 'Indique quién ganó la oferta.']);
        }

        if ($data['outcome'] === 'Ganada') {
            $adjudication->markWon($quote, $data, (int) $request->user()->id);

            return back()->with('success', 'Oferta registrada como ganada. El cliente y el expediente post-adjudicación se crearon automáticamente.');
        }

        $adjudication->markLost($quote, $data);

        return back()->with('success', 'Oferta registrada como no adjudicada.');
    }

    /**
     * Participantes de la oferta presentada.
     */
    public function participants(Request $request, Quote $quote, AdjudicationService $adjudication): RedirectResponse
    {
        $this->authorize('update', $quote);

        $data = $request->validate([
            'participants' => ['required', 'array', 'min:1', 'max:50'],
            'participants.*.name' => ['required', 'string', 'max:255'],
            'participants.*.tax_id' => ['nullable', 'string', 'max:100'],
            'participants.*.amount' => ['required', 'numeric', 'min:0'],
            'participants.*.currency' => ['nullable', 'string', 'max:3'],
            'participants.*.includes_tax' => ['nullable', 'boolean'],
            'participants.*.is_ours' => ['nullable', 'boolean'],
            'participants.*.position' => ['nullable', 'integer', 'min:1', 'max:100'],
            'participants.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $adjudication->syncParticipants($quote, $data['participants']);

        return back()->with('success', 'Participantes registrados.');
    }

    /**
     * Documentos adjuntos a la cotización.
     */
    public function attachDocument(Request $request, Quote $quote, DocumentService $documents): RedirectResponse
    {
        $this->authorize('update', $quote);

        $data = $request->validate([
            'document_id' => ['required', 'integer', new BelongsToActiveCompany(Document::class)],
            'context' => ['nullable', 'string', 'max:100'],
        ]);

        $document = Document::query()->findOrFail($data['document_id']);

        abort_unless(
            $request->user()->canAccessCompany($document->company_id) && $document->company_id === $quote->company_id,
            403,
            'El documento no pertenece a la misma empresa de la cotización.',
        );

        $documents->link($document, $quote, null, $data['context'] ?? 'cotizacion');

        return back()->with('success', 'Documento adjuntado a la cotización.');
    }

    public function detachDocument(Quote $quote, int $document): RedirectResponse
    {
        $this->authorize('update', $quote);

        DocumentLink::query()
            ->where('linkable_type', Quote::class)
            ->where('linkable_id', $quote->id)
            ->where('document_id', $document)
            ->delete();

        return back()->with('success', 'Documento desvinculado.');
    }

    /**
     * Documentos vinculados a la cotización.
     */
    public function documents(Quote $quote): JsonResponse
    {
        $this->authorize('view', $quote);

        $documents = Document::query()
            ->whereHas('links', fn ($query) => $query
                ->where('linkable_type', Quote::class)
                ->where('linkable_id', $quote->id))
            ->get();

        return response()->json([
            'data' => $documents->map(fn ($document) => [
                'id' => $document->id,
                'nombre' => $document->name,
                'categoria' => $document->category,
                'version' => $document->version,
                'firmado' => $document->signed_at !== null,
                'vencimiento' => $document->expires_at?->toDateString(),
                'url' => route('documents.download', $document),
            ])->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', new AllowedCompany('create')],
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('institution_id', $request->input('institution_id')))],
            'opportunity_id' => ['nullable', 'integer', new BelongsToActiveCompany(Opportunity::class)],
            'tender_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)],
            'purchase_type' => ['required', 'string', 'max:100'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'status' => ['nullable', 'in:'.implode(',', Quote::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.margin' => ['nullable', 'numeric', 'min:0', 'max:95'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.inventory_item_id' => ['nullable', 'integer', new BelongsToActiveCompany(InventoryItem::class)],
        ]);

        return $data;
    }

    private function currentCompanyId(): ?int
    {
        return app(CompanyContext::class)->currentCompanyId();
    }

    public function filtered(Request $request)
    {
        $query = Quote::query()->latest('quoted_at')->latest('id');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$search}%")
                ->orWhere('purchase_type', 'like', "%{$search}%")
                ->orWhereHas('institution', fn ($i) => $i->where('name', 'like', "%{$search}%")));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('outcome')) {
            $query->where('outcome', $request->string('outcome'));
        }

        if ($request->filled('type')) {
            $query->where('purchase_type', $request->string('type'));
        }

        return $query;
    }
}
