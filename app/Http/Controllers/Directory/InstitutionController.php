<?php

namespace App\Http\Controllers\Directory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Directory\InstitutionRequest;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Institution;
use App\Models\Quote;
use App\Models\Tender;
use App\Services\System\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de instituciones compradoras, compartido por todas las empresas del
 * grupo. Las licitaciones, cotizaciones y contratos que muestra la ficha son los
 * de la empresa activa.
 */
class InstitutionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Institution::class);

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), InstitutionRequest::STATUSES, true) ? $request->input('status') : 'all',
            'type' => $request->string('type')->trim()->value(),
            'potential' => in_array($request->input('potential'), InstitutionRequest::POTENTIALS, true) ? $request->input('potential') : '',
        ];

        $perPage = in_array((int) $request->input('per_page'), [25, 50, 100], true) ? (int) $request->input('per_page') : 25;

        $institutions = Institution::query()
            ->select(['id', 'name', 'acronym', 'type', 'potential', 'location', 'status', 'phone', 'email'])
            ->withCount('contacts')
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$search}%")->orWhere('acronym', 'like', "%{$search}%")))
            ->when($filters['status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['type'], fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['potential'], fn (Builder $query, string $potential) => $query->where('potential', $potential))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Institutions/Index', [
            'institutions' => $institutions,
            'filters' => $filters,
            'options' => $this->options(),
            'can' => [
                'create' => $request->user()->can('create', Institution::class),
                'update' => $request->user()->can('update', new Institution),
                'delete' => $request->user()->can('delete', new Institution),
            ],
        ]);
    }

    public function show(Request $request, Institution $institution): Response
    {
        $this->authorize('view', $institution);

        $institution->load(['contacts' => fn ($query) => $query->orderBy('name')->select(['id', 'institution_id', 'name', 'position', 'department', 'email', 'phone', 'whatsapp'])]);

        $tenders = Tender::query()->where('institution_id', $institution->id)->latest('id')->limit(50)
            ->get(['id', 'reference', 'title', 'status', 'presentation_deadline', 'estimated_amount'])
            ->map(fn (Tender $tender) => [
                'id' => $tender->id,
                'reference' => $tender->reference,
                'title' => $tender->title,
                'status' => $tender->status,
                'presentation_deadline' => $tender->presentation_deadline?->toIso8601String(),
                'estimated_amount' => (float) $tender->estimated_amount,
            ]);

        $quotes = Quote::query()->where('institution_id', $institution->id)->latest('quoted_at')->limit(50)
            ->get(['id', 'number', 'quoted_at', 'status', 'outcome', 'total'])
            ->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'number' => $quote->number,
                'quoted_at' => $quote->quoted_at?->toDateString(),
                'status' => $quote->status,
                'outcome' => $quote->outcome,
                'total' => (float) $quote->total,
            ]);

        $contracts = Contract::query()->where('institution_id', $institution->id)->latest('id')->limit(50)
            ->get(['id', 'number', 'name', 'status', 'amount', 'awarded_amount'])
            ->map(fn (Contract $contract) => [
                'id' => $contract->id,
                'number' => $contract->number,
                'name' => $contract->name,
                'status' => $contract->status,
                'amount' => (float) ($contract->awarded_amount ?? $contract->amount),
            ]);

        return Inertia::render('Institutions/Show', [
            'institution' => $institution->only(['id', 'name', 'acronym', 'type', 'contact_name', 'phone', 'email', 'potential', 'location', 'status', 'notes', 'source']),
            'contacts' => $institution->contacts,
            'tenders' => $tenders,
            'quotes' => $quotes,
            'contracts' => $contracts,
            'options' => $this->options(),
            'can' => [
                'update' => $request->user()->can('update', $institution),
                'delete' => $request->user()->can('delete', $institution),
                'create_contact' => $request->user()->can('create', Contact::class),
            ],
        ]);
    }

    /**
     * Crea una institución. Con `Accept: application/json` responde la institución
     * creada (o la ya existente con ese nombre) para la creación rápida desde otros módulos.
     */
    public function store(InstitutionRequest $request, AuditLogger $audit): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Institution::class);

        $institution = Institution::create($request->validated() + ['source' => $request->input('source', 'Manual')]);
        $audit->record('institucion.creada', $institution, ['name' => $institution->name]);

        if ($request->expectsJson()) {
            return response()->json(['data' => $this->option($institution)], 201);
        }

        return back()->with('success', "Institución {$institution->name} creada.");
    }

    public function update(InstitutionRequest $request, Institution $institution, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $institution);

        $institution->fill($request->validated());
        $changes = $institution->getDirty();
        $institution->save();

        if ($changes !== []) {
            $audit->record('institucion.actualizada', $institution, $changes);
        }

        return back()->with('success', 'Institución actualizada.');
    }

    public function destroy(Institution $institution, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $institution);

        // Se comprueba en todas las empresas: el catálogo es compartido.
        $inUse = $institution->quotes()->withoutGlobalScopes()->exists()
            || $institution->tenders()->withoutGlobalScopes()->exists()
            || $institution->clients()->withoutGlobalScopes()->exists()
            || $institution->opportunities()->withoutGlobalScopes()->exists();

        if ($inUse) {
            return back()->with('error', 'La institución tiene licitaciones, cotizaciones o clientes asociados. Márquela como inactiva.');
        }

        $institution->contacts()->delete();
        $institution->delete();
        $audit->record('institucion.eliminada', $institution, ['name' => $institution->name]);

        return redirect()->route('institutions.index')->with('success', "Institución {$institution->name} eliminada.");
    }

    /**
     * GET /instituciones/buscar?q=texto  →  { data: [{ id, name, acronym, description }] }
     * Para los `SearchSelect` de otros módulos. Solo instituciones activas, salvo `?todas=1`.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Institution::class);

        $term = $request->string('q')->trim()->value();

        $institutions = Institution::query()
            ->select(['id', 'name', 'acronym', 'type'])
            ->when(! $request->boolean('todas'), fn (Builder $query) => $query->where('status', 'Activa'))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$term}%")->orWhere('acronym', 'like', "%{$term}%")))
            ->when($request->filled('id'), fn (Builder $query) => $query->whereKey($request->integer('id')))
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', ["{$term}%"])
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json(['data' => $institutions->map(fn (Institution $institution) => $this->option($institution))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function option(Institution $institution): array
    {
        return [
            'id' => $institution->id,
            'name' => $institution->name,
            'acronym' => $institution->acronym,
            'description' => collect([$institution->acronym, $institution->type])->filter()->implode(' · '),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function options(): array
    {
        $types = Institution::query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type')->all();

        return [
            'types' => array_values(array_unique([...InstitutionRequest::TYPES, ...$types])),
            'potentials' => InstitutionRequest::POTENTIALS,
            'statuses' => InstitutionRequest::STATUSES,
        ];
    }
}
