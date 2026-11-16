<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\ConsortiumRequest;
use App\Models\Company;
use App\Models\Consortium;
use App\Models\ConsortiumMember;
use App\Models\Tender;
use App\Services\System\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consorcios de la empresa activa: empresas que se asocian para una oferta
 * conjunta. La participación de los miembros suma exactamente 100 %.
 */
class ConsortiumController extends Controller
{
    public function index(Request $request, CompanyContext $context): Response
    {
        $this->authorize('viewAny', Consortium::class);

        $search = $request->string('search')->trim()->value();
        $status = in_array($request->query('status'), ConsortiumRequest::STATUSES, true) ? $request->query('status') : 'all';
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;

        $base = Consortium::query()
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('members', fn (Builder $member) => $member->where('name', 'like', "%{$search}%"))
                ->orWhereHas('tender', fn (Builder $tender) => $tender->where('reference', 'like', "%{$search}%"))));

        $counts = (clone $base)->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $records = $base
            ->with(['members' => fn ($members) => $members->orderByDesc('participation_percentage'), 'tender:id,reference,title', 'institution:id,name', 'leaderCompany:id,name'])
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->latest('id')
            ->paginate($perPage)->withQueryString()
            ->through(fn (Consortium $consortium) => $this->present($consortium));

        return Inertia::render('Consortia/Index', [
            'records' => $records,
            'filters' => ['search' => $search, 'status' => $status],
            'counts' => ['all' => (int) $counts->sum(), ...collect(ConsortiumRequest::STATUSES)->mapWithKeys(fn ($value) => [$value => (int) ($counts[$value] ?? 0)])->all()],
            'statuses' => ConsortiumRequest::STATUSES,
            'tenders' => Tender::query()->select(['id', 'reference', 'title'])->latest('id')->limit(300)->get()
                ->map(fn (Tender $tender) => ['id' => $tender->id, 'name' => $tender->reference, 'description' => $tender->title]),
            'companies' => Company::query()->whereIn('id', $context->allowedCompanyIds())->orderBy('name')->get(['id', 'name']),
            'can' => [
                'create' => $request->user()->can('create', Consortium::class),
                'update' => $request->user()->hasPermission(Permissions::UPDATE),
                'delete' => $request->user()->hasPermission(Permissions::DELETE),
            ],
        ]);
    }

    public function store(ConsortiumRequest $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', Consortium::class);

        $data = $request->validated();

        $consortium = DB::transaction(function () use ($data) {
            $consortium = Consortium::create($this->attributes($data));
            $this->syncMembers($consortium, $data['members'] ?? []);

            return $consortium;
        });

        $audit->record('consortium.created', $consortium, ['members' => count($data['members'] ?? [])]);

        return back()->with('success', "Consorcio {$consortium->name} creado.");
    }

    public function update(ConsortiumRequest $request, Consortium $consortium, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $consortium);

        $data = $request->validated();

        DB::transaction(function () use ($consortium, $data) {
            $locked = Consortium::query()->whereKey($consortium->id)->lockForUpdate()->firstOrFail();
            $locked->update($this->attributes($data));
            $this->syncMembers($locked, $data['members'] ?? []);
        });

        $audit->record('consortium.updated', $consortium, ['members' => count($data['members'] ?? [])]);

        return back()->with('success', "Consorcio {$consortium->name} actualizado.");
    }

    public function destroy(Consortium $consortium, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $consortium);

        $consortium->delete();
        $audit->record('consortium.deleted', $consortium);

        return back()->with('success', "Consorcio {$consortium->name} eliminado.");
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $attributes = collect($data)->except('members')->all();
        $attributes['amount'] = $attributes['amount'] ?? 0;

        return $attributes;
    }

    /**
     * Guarda la lista de miembros por diferencias: actualiza los que traen id,
     * crea los nuevos y quita los que ya no vienen.
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    private function syncMembers(Consortium $consortium, array $members): void
    {
        $keep = collect($members)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $consortium->members()->whereNotIn('id', $keep)->delete();

        foreach ($members as $member) {
            $attributes = collect($member)->only(['name', 'company_id', 'tax_id', 'role', 'email', 'phone', 'participation_percentage'])->all();
            $existing = filled($member['id'] ?? null) ? $consortium->members()->whereKey($member['id'])->first() : null;

            $existing ? $existing->update($attributes) : $consortium->members()->create($attributes);
        }
    }

    /** @return array<string, mixed> */
    private function present(Consortium $consortium): array
    {
        $total = round((float) $consortium->members->sum('participation_percentage'), 2);

        return [
            'id' => $consortium->id,
            'name' => $consortium->name,
            'status' => $consortium->status,
            'tender_id' => $consortium->tender_id,
            'tender' => $consortium->tender ? ['id' => $consortium->tender->id, 'name' => $consortium->tender->reference, 'description' => $consortium->tender->title] : null,
            'institution_id' => $consortium->institution_id,
            'institution' => $consortium->institution ? ['id' => $consortium->institution->id, 'name' => $consortium->institution->name] : null,
            'leader_company_id' => $consortium->leader_company_id,
            'leader_company' => $consortium->leaderCompany?->name,
            'amount' => $consortium->amount !== null ? (float) $consortium->amount : null,
            'formed_at' => $consortium->formed_at?->toDateString(),
            'notes' => $consortium->notes,
            'members_total' => $total,
            'members' => $consortium->members->map(fn (ConsortiumMember $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'company_id' => $member->company_id,
                'tax_id' => $member->tax_id,
                'role' => $member->role,
                'email' => $member->email,
                'phone' => $member->phone,
                'participation_percentage' => (float) $member->participation_percentage,
            ])->values(),
        ];
    }
}
