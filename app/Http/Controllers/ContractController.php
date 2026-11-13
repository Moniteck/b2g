<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\Document;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use App\Services\Notifications\CalendarSyncService;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Expedientes post-adjudicacion: contrato, responsables, condiciones de
 * inicio, entregas y seguimiento.
 */
class ContractController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contract::class);

        $query = Contract::query()
            ->with(['purchaseOrders', 'deliveryCertificates', 'deliveries', 'manager:id,name', 'institution:id,name'])
            ->latest('id');

        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($builder) => $builder->where('number', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }

        return Inertia::render('Contracts', [
            'records' => $query->paginate(20)->withQueryString()->through(fn (Contract $contract) => [
                'id' => (string) $contract->id,
                'numero' => $contract->number,
                'nombre' => $contract->name,
                'institucion' => $contract->institution?->name,
                'estado' => $contract->status,
                'monto' => (float) $contract->amount,
                'montoAdjudicado' => $contract->awarded_amount !== null ? (float) $contract->awarded_amount : null,
                'avanceFisico' => $contract->physical_progress,
                'avanceFinanciero' => $contract->financial_progress,
                'fechaFirma' => $contract->signed_at?->toDateString(),
                'inicio' => $contract->start_date?->toDateString(),
                'fin' => $contract->end_date?->toDateString(),
                'duracionDias' => $contract->duration_days,
                'diasHabiles' => $contract->duration_business_days,
                'disparadorInicio' => $contract->start_trigger,
                'productoEntregado' => $contract->product_delivered,
                'responsable' => $contract->manager?->name,
                'oficialEncargado' => $contract->official_in_charge,
                'entregas' => $contract->deliveries->map(fn (ContractDelivery $delivery) => [
                    'id' => (string) $delivery->id,
                    'descripcion' => $delivery->description,
                    'cantidad' => (float) $delivery->quantity,
                    'unidad' => $delivery->unit,
                    'vencimiento' => $delivery->due_date?->toDateString(),
                    'fechaEntrega' => $delivery->delivered_at?->toDateTimeString(),
                    'estado' => $delivery->status,
                ]),
                'ordenes' => $contract->purchaseOrders->count(),
                'actas' => $contract->deliveryCertificates->count(),
            ]),
            'statuses' => Contract::STATUSES,
            'institutions' => Institution::orderBy('name')->get(['id', 'name']),
            'contacts' => Contact::orderBy('name')->get(['id', 'name', 'institution_id']),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('search', 'status'),
        ]);
    }

    public function store(Request $request, CalendarSyncService $calendar): RedirectResponse
    {
        $this->authorize('create', Contract::class);

        $data = $this->validated($request);
        $data['company_id'] = $data['company_id'] ?? app(CompanyContext::class)->currentCompanyId();
        $data['status'] = $data['status'] ?? 'Pendiente de contrato';

        $contract = Contract::create($data);

        $this->recalculateDates($contract);
        $calendar->syncForContract($contract);

        return back()->with('success', 'Contrato creado correctamente.');
    }

    public function update(Request $request, Contract $contract, CalendarSyncService $calendar): RedirectResponse
    {
        $this->authorize('update', $contract);

        $contract->fill($this->validated($request))->save();

        $this->recalculateDates($contract);
        $calendar->syncForContract($contract);

        return back()->with('success', 'Contrato actualizado.');
    }

    /**
     * Registra una entrega de producto y su evidencia.
     */
    public function delivery(Request $request, Contract $contract, CalendarSyncService $calendar): RedirectResponse
    {
        $this->authorize('followUp', $contract);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'due_date' => ['nullable', 'date'],
            'delivered_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:100'],
            'evidence_document_id' => ['nullable', 'integer', new BelongsToActiveCompany(Document::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $delivery = $contract->deliveries()->create($data);

        $contract->forceFill([
            'status' => $data['status'] ?? ($data['delivered_at'] ? 'Entrega parcial' : $contract->status),
        ])->save();

        $calendar->syncForContract($contract);

        return back()->with('success', 'Entrega registrada.');
    }

    /**
     * Seguimiento del contrato con responsable y proxima accion.
     */
    public function followUp(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('followUp', $contract);

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date'],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_at' => ['nullable', 'date'],
        ]);

        $contract->followUps()->create([
            'user_id' => $request->user()->id,
            'type' => $data['type'] ?? 'Seguimiento',
            'description' => $data['description'],
            'occurred_at' => $data['occurred_at'] ?? now(),
            'next_action' => $data['next_action'] ?? null,
            'next_action_at' => $data['next_action_at'] ?? null,
        ]);

        return back()->with('success', 'Seguimiento registrado.');
    }

    /**
     * Cierre del expediente. Exige entrega del producto.
     */
    public function complete(Request $request, Contract $contract, CalendarSyncService $calendar): RedirectResponse
    {
        $this->authorize('close', $contract);

        if (! $contract->product_delivered) {
            return back()->withErrors(['estado' => 'Marque la entrega del producto antes de cerrar el contrato.']);
        }

        $contract->forceFill([
            'status' => 'Cerrado',
            'physical_progress' => 100,
            'completed_at' => now()->toDateString(),
            'closed_at' => now(),
        ])->save();

        $calendar->syncForContract($contract);

        return back()->with('success', 'Contrato cerrado.');
    }

    public function order(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', $contract);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'issued_at' => ['required', 'date'],
            'status' => ['required', 'string', 'max:100'],
        ]);

        $contract->purchaseOrders()->create($data);

        return back()->with('success', 'Orden registrada.');
    }

    public function certificate(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', $contract);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'issued_at' => ['required', 'date'],
            'status' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(fn () => $contract->deliveryCertificates()->create($data));

        return back()->with('success', 'Acta registrada.');
    }

    public function destroy(Contract $contract): RedirectResponse
    {
        $this->authorize('delete', $contract);

        $contract->delete();

        return back()->with('success', 'Contrato eliminado.');
    }

    /**
     * Recalcula el vencimiento del contrato a partir de la duracion pactada y
     * de la fecha de inicio.
     */
    private function recalculateDates(Contract $contract): void
    {
        if ($contract->start_date === null || $contract->duration_days === null) {
            return;
        }

        $end = $contract->calculateEndDate();

        if ($end !== null) {
            $contract->forceFill(['end_date' => $end])->save();
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'company_id' => ['nullable', 'integer', new AllowedCompany],
            'tender_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)],
            'quote_id' => ['nullable', 'integer', new BelongsToActiveCompany(Quote::class)],
            'opportunity_id' => ['nullable', 'integer', new BelongsToActiveCompany(Opportunity::class)],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'manager_id' => ['nullable', 'integer', new BelongsToActiveCompany(User::class)],
            'number' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0'],
            'physical_progress' => ['nullable', 'numeric', 'between:0,100'],
            'financial_progress' => ['nullable', 'numeric', 'between:0,100'],
            'retention_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(Contract::STATUSES))],
            'signed_at' => ['nullable', 'date'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'duration_business_days' => ['nullable', 'boolean'],
            'start_trigger' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'product_delivered' => ['nullable', 'boolean'],
            'official_in_charge' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
