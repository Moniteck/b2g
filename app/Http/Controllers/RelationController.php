<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tools\RelationInteractionRequest;
use App\Http\Requests\Tools\RelationRequest;
use App\Models\InstitutionalRelation;
use App\Models\RelationInteraction;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM político: relaciones de la empresa activa con instituciones y sus
 * interacciones, con la próxima acción de cada una.
 */
class RelationController extends Controller
{
    private const FILTERS = ['all', 'upcoming', 'overdue', 'inactive'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InstitutionalRelation::class);

        $search = $request->string('search')->trim()->value();
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'all';
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $today = today()->toDateString();
        $week = today()->addDays(7)->toDateString();

        $base = InstitutionalRelation::query()
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereHas('institution', fn (Builder $institution) => $institution->where('name', 'like', "%{$search}%")->orWhere('acronym', 'like', "%{$search}%"))
                ->orWhereHas('contact', fn (Builder $contact) => $contact->where('name', 'like', "%{$search}%"))
                ->orWhere('poa_interest', 'like', "%{$search}%")));

        $counts = (clone $base)->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status <> 'Inactiva' AND next_contact_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as upcoming", [$today, $week])
            ->selectRaw("SUM(CASE WHEN status <> 'Inactiva' AND next_contact_at < ? THEN 1 ELSE 0 END) as overdue", [$today])
            ->selectRaw("SUM(CASE WHEN status = 'Inactiva' THEN 1 ELSE 0 END) as inactive")
            ->first();

        $records = $base
            ->with(['institution:id,name,acronym', 'contact:id,name,position', 'owner:id,name'])
            ->withCount('interactions')
            ->when($filter === 'upcoming', fn (Builder $query) => $query->where('status', '<>', 'Inactiva')->whereBetween('next_contact_at', [$today, $week]))
            ->when($filter === 'overdue', fn (Builder $query) => $query->where('status', '<>', 'Inactiva')->where('next_contact_at', '<', $today))
            ->when($filter === 'inactive', fn (Builder $query) => $query->where('status', 'Inactiva'))
            ->orderByRaw('next_contact_at IS NULL')->orderBy('next_contact_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString()
            ->through(fn (InstitutionalRelation $relation) => $this->present($relation));

        return Inertia::render('Relations/Index', [
            'records' => $records,
            'filters' => ['search' => $search, 'filter' => $filter],
            'counts' => [
                'total' => (int) $counts->total,
                'upcoming' => (int) $counts->upcoming,
                'overdue' => (int) $counts->overdue,
                'inactive' => (int) $counts->inactive,
            ],
            'options' => [
                'statuses' => RelationRequest::STATUSES,
                'influence' => RelationRequest::INFLUENCE,
                'types' => RelationRequest::TYPES,
                'channels' => RelationRequest::CHANNELS,
                'interaction_types' => RelationInteractionRequest::TYPES,
            ],
            'can' => [
                'create' => $request->user()->can('create', InstitutionalRelation::class),
                'update' => $request->user()->hasPermission(Permissions::UPDATE),
                'delete' => $request->user()->hasPermission(Permissions::DELETE),
            ],
        ]);
    }

    /**
     * Línea de tiempo de interacciones de una relación (más reciente arriba).
     */
    public function show(InstitutionalRelation $relation): JsonResponse
    {
        $this->authorize('view', $relation);

        $relation->load(['institution:id,name,acronym', 'contact:id,name,position', 'owner:id,name'])->loadCount('interactions');

        $interactions = $relation->interactions()->with('user:id,name')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get()
            ->map(fn (RelationInteraction $interaction) => [
                'id' => $interaction->id,
                'type' => $interaction->type,
                'description' => $interaction->description,
                'result' => $interaction->result,
                'occurred_at' => $interaction->occurred_at?->format('Y-m-d\TH:i:s'),
                'next_action_at' => $interaction->next_action_at?->toDateString(),
                'user' => $interaction->user?->name,
            ]);

        return response()->json(['relation' => $this->present($relation), 'interactions' => $interactions]);
    }

    public function store(RelationRequest $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', InstitutionalRelation::class);

        $relation = InstitutionalRelation::create($request->validated() + ['owner_id' => $request->user()->id]);
        $audit->record('relation.created', $relation);

        return back()->with('success', 'Relación registrada.');
    }

    public function update(RelationRequest $request, InstitutionalRelation $relation, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $relation);

        $relation->update($request->validated());
        $audit->record('relation.updated', $relation, $relation->getChanges());

        return back()->with('success', 'Relación actualizada.');
    }

    /**
     * Registra una interacción y actualiza el último contacto y la próxima acción.
     */
    public function interaction(RelationInteractionRequest $request, InstitutionalRelation $relation, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $relation);

        $data = $request->validated();

        DB::transaction(function () use ($relation, $data, $request) {
            $locked = InstitutionalRelation::query()->whereKey($relation->id)->lockForUpdate()->firstOrFail();
            $locked->interactions()->create($data + ['user_id' => $request->user()->id]);

            $occurred = substr((string) $data['occurred_at'], 0, 10);
            $locked->update([
                'last_contact_at' => $locked->last_contact_at === null || $locked->last_contact_at->toDateString() < $occurred ? $occurred : $locked->last_contact_at,
                'next_contact_at' => $data['next_action_at'] ?? $locked->next_contact_at,
            ]);
        });

        $audit->record('relation.interaction_logged', $relation, ['type' => $data['type']]);

        return back()->with('success', 'Interacción registrada.');
    }

    public function destroy(InstitutionalRelation $relation, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $relation);

        $relation->delete();
        $audit->record('relation.deleted', $relation);

        return back()->with('success', 'Relación eliminada.');
    }

    /** @return array<string, mixed> */
    private function present(InstitutionalRelation $relation): array
    {
        return [
            'id' => $relation->id,
            'institution_id' => $relation->institution_id,
            'institution' => $relation->institution ? ['id' => $relation->institution->id, 'name' => $relation->institution->name, 'acronym' => $relation->institution->acronym] : null,
            'contact_id' => $relation->contact_id,
            'contact' => $relation->contact ? ['id' => $relation->contact->id, 'name' => $relation->contact->name, 'position' => $relation->contact->position] : null,
            'owner' => $relation->owner?->name,
            'relationship_type' => $relation->relationship_type,
            'influence' => $relation->influence,
            'channel' => $relation->channel,
            'status' => $relation->status,
            'started_at' => $relation->started_at?->toDateString(),
            'last_contact_at' => $relation->last_contact_at?->toDateString(),
            'next_contact_at' => $relation->next_contact_at?->toDateString(),
            'poa_interest' => $relation->poa_interest,
            'notes' => $relation->notes,
            'interactions_count' => (int) ($relation->interactions_count ?? 0),
        ];
    }
}
