<?php

namespace App\Http\Controllers;

use App\Http\Requests\Contracts\ContractDeliveryRequest;
use App\Http\Requests\Contracts\ContractFollowUpRequest;
use App\Http\Requests\Contracts\ContractRequest;
use App\Http\Requests\Contracts\ContractStatusRequest;
use App\Http\Requests\Contracts\HolidayRequest;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractFollowUp;
use App\Models\Holiday;
use App\Models\Institution;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Contracts\ContractService;
use App\Services\Contracts\ContractStatus;
use App\Services\Contracts\ContractTerm;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Post-adjudicación: contratos ganados, su plazo, documentos, entregas y seguimiento.
 */
class ContractController extends Controller
{
    public const SORTS = ['end_date', 'number', 'awarded_amount', 'created_at'];

    public function __construct(protected ContractService $contracts) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $this->authorize('viewAny', Contract::class);

        // Enlace de la campana y del calendario: `?contrato=ID` abre la ficha.
        if ($request->filled('contrato')) {
            $target = Contract::query()->find($request->integer('contrato'));
            if ($target !== null && $request->user()->can('view', $target)) {
                return redirect()->route('contracts.show', $target);
            }
        }

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'group' => array_key_exists((string) $request->input('group'), ContractStatus::GROUPS) ? $request->input('group') : 'todos',
            'status' => in_array($request->input('status'), ContractStatus::ALL, true) ? $request->input('status') : '',
            'manager_id' => $request->integer('manager_id') ?: null,
            'institution_id' => $request->integer('institution_id') ?: null,
            'end_from' => $this->dateOrNull($request->input('end_from')),
            'end_to' => $this->dateOrNull($request->input('end_to')),
            'sort' => in_array($request->input('sort'), self::SORTS, true) ? $request->input('sort') : 'end_date',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
        $perPage = in_array((int) $request->input('per_page'), [25, 50, 100], true) ? (int) $request->input('per_page') : 25;

        $contracts = $this->filtered($filters)
            ->select(['id', 'company_id', 'institution_id', 'manager_id', 'client_id', 'number', 'name', 'amount', 'awarded_amount', 'status', 'start_date', 'end_date', 'product_delivered', 'created_at'])
            ->with(['institution:id,name', 'manager:id,name'])
            ->withCount([
                'deliveries as deliveries_total' => fn (Builder $query) => $query->where('status', '!=', ContractDelivery::CANCELLED),
                'deliveries as deliveries_done' => fn (Builder $query) => $query->whereIn('status', ContractDelivery::DELIVERED_VALUES),
            ])
            ->when($filters['sort'] === 'end_date', fn (Builder $query) => $query->orderByRaw('end_date IS NULL'))
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Contract $contract) => $this->row($contract));

        return Inertia::render('Contracts/Index', [
            'contracts' => $contracts,
            'filters' => $filters,
            'stats' => $this->stats(),
            'groups' => $this->groupCounts(),
            'statuses' => ContractStatus::ALL,
            'tones' => ContractStatus::TONES,
            'officials' => $this->officials(),
            'institution' => $filters['institution_id'] ? Institution::query()->select(['id', 'name'])->find($filters['institution_id']) : null,
            'form' => $this->formOptions(),
            'can' => $this->screenAbilities($request->user()),
        ]);
    }

    public function show(Request $request, Contract $contract): Response
    {
        $this->authorize('view', $contract);

        $contract->load([
            'institution:id,name,acronym', 'client:id,name', 'contact:id,name,position,email,phone,institution_id',
            'manager:id,name,email', 'creator:id,name', 'quote:id,number,status,outcome', 'opportunity:id,title,stage', 'tender:id,reference,title',
            'deliveries' => fn ($query) => $query->with('evidence:id,name,mime_type,original_filename')->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id'),
            'followUps' => fn ($query) => $query->with('user:id,name')->latest('occurred_at')->latest('id'),
        ]);

        $user = $request->user();

        return Inertia::render('Contracts/Show', [
            'contract' => $this->detail($contract),
            'deliveries' => $contract->deliveries->map(fn (ContractDelivery $delivery) => $this->deliveryRow($delivery))->values(),
            'followUps' => $contract->followUps->map(fn (ContractFollowUp $followUp) => [
                'id' => $followUp->id,
                'type' => $followUp->type,
                'description' => $followUp->description,
                'occurred_at' => $followUp->occurred_at?->toIso8601String(),
                'next_action' => $followUp->next_action,
                'next_action_at' => $followUp->next_action_at?->toDateString(),
                'user' => $followUp->user?->name,
                'user_id' => $followUp->user_id,
            ])->values(),
            'history' => Inertia::defer(fn () => $this->history($contract)),
            'statuses' => ContractStatus::ALL,
            'tones' => ContractStatus::TONES,
            'transitions' => collect(ContractStatus::next($contract->status))
                ->filter(fn (string $to) => ! ContractStatus::requiresAdmin($contract->status, $to) || $user->can('cancel', $contract))
                ->values(),
            'officials' => $this->officials(),
            'form' => $this->formOptions(),
            'can' => [
                'update' => $user->can('update', $contract),
                'followUp' => $user->can('followUp', $contract),
                'close' => $user->can('close', $contract),
                'cancel' => $user->can('cancel', $contract),
                'delete' => $user->can('delete', $contract),
                'sign' => $user->hasPermission(Permissions::SIGN_DOCUMENTS, $contract->company_id),
                'manageHolidays' => $user->can('manageHolidays', Contract::class),
            ],
        ]);
    }

    public function store(ContractRequest $request): RedirectResponse
    {
        $contract = $this->contracts->save(null, $request->validated(), $request->user());

        return redirect()->route('contracts.show', $contract)->with('success', "Contrato {$contract->number} creado.");
    }

    public function update(ContractRequest $request, Contract $contract): RedirectResponse
    {
        $this->contracts->save($contract, $request->validated(), $request->user());

        return back()->with('success', 'Datos del contrato guardados.');
    }

    public function status(ContractStatusRequest $request, Contract $contract): RedirectResponse
    {
        $to = $request->validated('status');

        if (in_array($to, ContractStatus::ADMIN_TARGETS, true) || $contract->status === ContractStatus::CLOSED) {
            $this->authorize('cancel', $contract);
        }

        $updated = $this->contracts->changeStatus($contract, $to, $request->user(), $request->validated('reason'));

        return back()->with('success', "El contrato quedó en «{$updated->status}».");
    }

    /**
     * Cierre del expediente. Exige producto entregado; repetirlo no duplica nada.
     */
    public function complete(ContractStatusRequest $request, Contract $contract): RedirectResponse
    {
        $this->authorize('close', $contract);

        if ($contract->status === ContractStatus::CLOSED) {
            return back()->with('success', 'El contrato ya estaba cerrado.');
        }

        $this->contracts->close($contract, $request->user(), $request->validated('reason'));

        return back()->with('success', 'Contrato cerrado.');
    }

    public function destroy(Contract $contract, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $contract);

        DB::transaction(function () use ($contract, $audit) {
            $contract->deliveries()->get()->each->delete();
            $contract->followUps()->get()->each->delete();
            $contract->delete();
            $audit->record('contrato.eliminado', $contract, ['number' => $contract->number, 'name' => $contract->name]);
        });

        return redirect()->route('contracts.index')->with('success', "Contrato {$contract->number} eliminado.");
    }

    public function storeDelivery(ContractDeliveryRequest $request, Contract $contract): RedirectResponse
    {
        $this->contracts->saveDelivery($contract, null, $request->validated(), $request->user());

        return back()->with('success', 'Entrega registrada.');
    }

    public function updateDelivery(ContractDeliveryRequest $request, Contract $contract, ContractDelivery $delivery): RedirectResponse
    {
        $this->ensureChild($contract, $delivery);
        $this->contracts->saveDelivery($contract, $delivery, $request->validated(), $request->user());

        return back()->with('success', 'Entrega actualizada.');
    }

    public function deliver(ContractDeliveryRequest $request, Contract $contract, ContractDelivery $delivery): RedirectResponse
    {
        $this->ensureChild($contract, $delivery);
        $this->contracts->markDelivered($contract, $delivery, $request->validated('delivered_at'), $request->validated('notes'), $request->file('evidence'), $request->user());

        $message = 'Entrega marcada como entregada.';
        if (ContractService::allDelivered($contract) && ! in_array($contract->fresh()->status, [ContractStatus::DELIVERED, ...ContractStatus::FINAL], true)) {
            $message .= ' Todas las entregas están completas: puede pasar el contrato a «Producto entregado».';
        }

        return back()->with('success', $message);
    }

    public function destroyDelivery(Request $request, Contract $contract, ContractDelivery $delivery): RedirectResponse
    {
        $this->authorize('followUp', $contract);
        $this->ensureChild($contract, $delivery);
        $this->contracts->deleteDelivery($contract, $delivery, $request->user());

        return back()->with('success', 'Entrega eliminada.');
    }

    public function followUp(ContractFollowUpRequest $request, Contract $contract): RedirectResponse
    {
        $this->contracts->addFollowUp($contract, $request->validated(), $request->user());

        return back()->with('success', 'Seguimiento registrado.');
    }

    public function destroyFollowUp(Request $request, Contract $contract, ContractFollowUp $followUp): RedirectResponse
    {
        $this->authorize('followUp', $contract);
        abort_unless($followUp->contract_id === $contract->id, 404);
        abort_unless($followUp->user_id === $request->user()->id || $request->user()->can('delete', $contract), 403);

        $this->contracts->deleteFollowUp($contract, $followUp, $request->user());

        return back()->with('success', 'Seguimiento eliminado.');
    }

    /**
     * Vista previa de la fecha fin para el formulario.
     */
    public function term(Request $request, ContractTerm $term): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'business' => ['nullable', 'boolean'],
        ]);

        return response()->json(['end_date' => $term->endDate($data['start_date'], (int) $data['duration_days'], (bool) ($data['business'] ?? false), app(CompanyContext::class)->currentCompanyId())]);
    }

    /** Clientes de la empresa activa para el buscador del formulario. */
    public function clients(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        $term = $request->string('q')->trim()->value();

        return response()->json(['data' => Client::query()
            ->select(['id', 'name', 'tax_id', 'institution_id'])
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $builder) => $builder->where('name', 'like', "%{$term}%")->orWhere('tax_id', 'like', "%{$term}%")))
            ->when($request->filled('id'), fn (Builder $query) => $query->whereKey($request->integer('id')))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name, 'description' => $client->tax_id, 'institution_id' => $client->institution_id])]);
    }

    public function holidays(Request $request, ContractTerm $term): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        $companyId = app(CompanyContext::class)->currentCompanyId();
        $term->ensureSeeded($companyId);

        return response()->json([
            'data' => Holiday::query()->orderBy('holiday_date')->get(['id', 'holiday_date', 'name', 'is_pending_review'])
                ->map(fn (Holiday $holiday) => $this->holidayRow($holiday)),
            'can' => ['manage' => $request->user()->can('manageHolidays', Contract::class)],
        ]);
    }

    public function storeHoliday(HolidayRequest $request, AuditLogger $audit): JsonResponse
    {
        $holiday = Holiday::create($request->validated() + ['is_pending_review' => false, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $audit->record('feriado.creado', $holiday, ['fecha' => $holiday->holiday_date->toDateString(), 'nombre' => $holiday->name]);

        return response()->json(['message' => 'Feriado agregado.', 'data' => $this->holidayRow($holiday)], 201);
    }

    public function updateHoliday(HolidayRequest $request, Holiday $holiday, AuditLogger $audit): JsonResponse
    {
        $holiday->fill($request->validated() + ['is_pending_review' => false, 'updated_by' => $request->user()->id]);
        $changes = $holiday->getDirty();
        $holiday->save();
        $audit->record('feriado.actualizado', $holiday, $changes);

        return response()->json(['message' => 'Feriado actualizado.', 'data' => $this->holidayRow($holiday)]);
    }

    public function destroyHoliday(Request $request, Holiday $holiday, AuditLogger $audit): JsonResponse
    {
        $this->authorize('manageHolidays', Contract::class);

        $holiday->forceFill(['deleted_by' => $request->user()->id])->save();
        $holiday->delete();
        $audit->record('feriado.eliminado', $holiday, ['fecha' => $holiday->holiday_date->toDateString(), 'nombre' => $holiday->name]);

        return response()->json(['message' => 'Feriado eliminado.']);
    }

    // ---------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Contract>
     */
    private function filtered(array $filters): Builder
    {
        return Contract::query()
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(fn (Builder $builder) => $builder
                ->where('number', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhereHas('institution', fn (Builder $institution) => $institution->where('name', 'like', "%{$search}%"))))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(! $filters['status'] && $filters['group'] !== 'todos', fn (Builder $query) => $query->whereIn('status', ContractStatus::GROUPS[$filters['group']]))
            ->when($filters['manager_id'], fn (Builder $query, int $id) => $query->where('manager_id', $id))
            ->when($filters['institution_id'], fn (Builder $query, int $id) => $query->where('institution_id', $id))
            ->when($filters['end_from'], fn (Builder $query, string $date) => $query->whereDate('end_date', '>=', $date))
            ->when($filters['end_to'], fn (Builder $query, string $date) => $query->whereDate('end_date', '<=', $date));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'number' => $contract->number,
            'name' => $contract->name,
            'institution' => $contract->institution ? ['id' => $contract->institution->id, 'name' => $contract->institution->name] : null,
            'manager' => $contract->manager ? ['id' => $contract->manager->id, 'name' => $contract->manager->name] : null,
            'status' => $contract->status,
            'awarded_amount' => (float) ($contract->awarded_amount ?? $contract->amount),
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'product_delivered' => (bool) $contract->product_delivered,
            'deliveries_total' => (int) $contract->deliveries_total,
            'deliveries_done' => (int) $contract->deliveries_done,
            'is_open' => $contract->isOpen(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'company_id' => $contract->company_id,
            'number' => $contract->number,
            'name' => $contract->name,
            'status' => $contract->status,
            'is_open' => $contract->isOpen(),
            'amount' => (float) $contract->amount,
            'awarded_amount' => $contract->awarded_amount !== null ? (float) $contract->awarded_amount : null,
            'signed_at' => $contract->signed_at?->toDateString(),
            'duration_days' => $contract->duration_days,
            'duration_business_days' => (bool) $contract->duration_business_days,
            'start_trigger' => $contract->start_trigger ?: null,
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'is_end_date_manual' => (bool) $contract->is_end_date_manual,
            'product_delivered' => (bool) $contract->product_delivered,
            'closed_at' => $contract->closed_at?->toIso8601String(),
            'notes' => $contract->notes,
            'institution_id' => $contract->institution_id,
            'institution' => $contract->institution ? ['id' => $contract->institution->id, 'name' => $contract->institution->name] : null,
            'client_id' => $contract->client_id,
            'client' => $contract->client ? ['id' => $contract->client->id, 'name' => $contract->client->name] : null,
            'contact_id' => $contract->contact_id,
            'contact' => $contract->contact ? [
                'id' => $contract->contact->id, 'name' => $contract->contact->name, 'position' => $contract->contact->position,
                'email' => $contract->contact->email, 'phone' => $contract->contact->phone,
            ] : null,
            'manager_id' => $contract->manager_id,
            'manager' => $contract->manager ? ['id' => $contract->manager->id, 'name' => $contract->manager->name, 'email' => $contract->manager->email] : null,
            'quote' => $contract->quote ? ['id' => $contract->quote->id, 'number' => $contract->quote->number] : null,
            'opportunity' => $contract->opportunity ? ['id' => $contract->opportunity->id, 'title' => $contract->opportunity->title] : null,
            'tender' => $contract->tender ? ['id' => $contract->tender->id, 'reference' => $contract->tender->reference] : null,
            'quote_id' => $contract->quote_id,
            'opportunity_id' => $contract->opportunity_id,
            'tender_id' => $contract->tender_id,
            'created_by' => $contract->creator?->name,
            'created_at' => $contract->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryRow(ContractDelivery $delivery): array
    {
        $evidence = $delivery->evidence;

        return [
            'id' => $delivery->id,
            'description' => $delivery->description,
            'quantity' => (float) $delivery->quantity,
            'unit' => $delivery->unit,
            'due_date' => $delivery->due_date?->toDateString(),
            'delivered_at' => $delivery->delivered_at?->toDateString(),
            'status' => $delivery->isDelivered() ? ContractDelivery::DELIVERED : $delivery->status,
            'notes' => $delivery->notes,
            'evidence' => $evidence ? [
                'id' => $evidence->id,
                'name' => $evidence->name,
                'mime' => $evidence->mime_type,
                'fileName' => $evidence->original_filename,
                'previewUrl' => route('documents.preview', $evidence),
                'downloadUrl' => route('documents.download', $evidence),
            ] : null,
        ];
    }

    /**
     * Historial: auditoría del contrato y de los documentos que se le adjuntaron.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(Contract $contract): array
    {
        return AuditLog::query()
            ->where('company_id', $contract->company_id)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $own) => $own->where('resource_type', 'Contract')->where('resource_id', $contract->id))
                ->orWhere(fn (Builder $docs) => $docs->where('resource_type', 'Document')->where('changes->adjunto_a', "Contract #{$contract->id}")))
            ->latest('created_at')
            ->latest('id')
            ->limit(200)
            ->get(['id', 'action', 'actor_name', 'changes', 'created_at'])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor_name,
                'changes' => $log->changes,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, int|float>
     */
    private function stats(): array
    {
        $today = Carbon::today()->toDateString();
        $in30 = Carbon::today()->addDays(30)->toDateString();
        $open = ContractStatus::OPEN;
        $placeholders = implode(',', array_fill(0, count($open), '?'));

        $row = Contract::query()
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS in_progress', [ContractStatus::IN_PROGRESS, ContractStatus::PARTIAL])
            ->selectRaw("SUM(CASE WHEN status IN ({$placeholders}) AND end_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS expiring", [...$open, $today, $in30])
            ->selectRaw("SUM(CASE WHEN status IN ({$placeholders}) THEN COALESCE(awarded_amount, amount) ELSE 0 END) AS open_amount", $open)
            ->selectRaw("SUM(CASE WHEN status IN ({$placeholders}) THEN 1 ELSE 0 END) AS open_count", $open)
            ->first();

        $pendingDeliveries = ContractDelivery::query()
            ->whereIn('status', [ContractDelivery::PENDING, ContractDelivery::REJECTED])
            ->whereIn('contract_id', Contract::query()->select('id')->whereIn('status', $open))
            ->count();

        return [
            'in_progress' => (int) $row->in_progress,
            'expiring' => (int) $row->expiring,
            'pending_deliveries' => $pendingDeliveries,
            'open_amount' => (float) $row->open_amount,
            'open_count' => (int) $row->open_count,
        ];
    }

    /** @return array<string, int> */
    private function groupCounts(): array
    {
        $byStatus = Contract::query()->select('status', DB::raw('COUNT(*) AS total'))->groupBy('status')->pluck('total', 'status');
        $counts = ['todos' => (int) $byStatus->sum()];

        foreach (ContractStatus::GROUPS as $group => $statuses) {
            $counts[$group] = (int) collect($statuses)->sum(fn (string $status) => $byStatus[$status] ?? 0);
        }

        return $counts;
    }

    /**
     * Oficiales posibles: miembros de la empresa activa (no todos los usuarios del sistema).
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function officials(): array
    {
        $companyId = app(CompanyContext::class)->currentCompanyId();

        return User::query()
            ->whereExists(fn ($members) => $members->from('company_user')
                ->whereColumn('company_user.user_id', 'users.id')
                ->where('company_user.company_id', $companyId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'triggers' => Contract::START_TRIGGERS,
            'deliveryStatuses' => ContractDelivery::STATUSES,
            'followUpTypes' => ContractFollowUp::TYPES,
            'initialStatuses' => [ContractStatus::PENDING, ContractStatus::IN_PROGRESS, ContractStatus::SUSPENDED],
        ];
    }

    /** @return array<string, bool> */
    private function screenAbilities(User $user): array
    {
        $probe = new Contract(['company_id' => app(CompanyContext::class)->currentCompanyId()]);

        return [
            'create' => $user->can('create', Contract::class),
            'update' => $user->can('update', $probe),
            'delete' => $user->can('delete', $probe),
            'manageHolidays' => $user->can('manageHolidays', Contract::class),
        ];
    }

    /** @return array<string, mixed> */
    private function holidayRow(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'holiday_date' => $holiday->holiday_date->toDateString(),
            'name' => $holiday->name,
            'is_pending_review' => (bool) $holiday->is_pending_review,
        ];
    }

    private function ensureChild(Contract $contract, ContractDelivery $delivery): void
    {
        abort_unless($delivery->contract_id === $contract->id, 404);
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
