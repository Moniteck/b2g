<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_tenders')]
#[Title('Listar licitaciones')]
#[Description('Licitaciones de una empresa con sus fechas críticas (cierre de aclaraciones, recepción y apertura de ofertas), ordenadas por la recepción más próxima. Filtros: búsqueda por expediente, objeto o institución; estado; ventana (`open` abiertas, `week` reciben ofertas en 7 días, `clarifications` cierran aclaraciones en 7 días).')]
#[IsReadOnly]
#[IsIdempotent]
class ListTendersTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'search' => $schema->string()->description('Texto en expediente, objeto o institución.'),
            'status' => $schema->string()->enum(Tender::STATUSES)->description('Estado.'),
            'window' => $schema->string()->enum(['all', 'open', 'week', 'clarifications'])->description('Ventana de plazos (por defecto all).'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Tender::class);

        $data = $this->validate($request->all(), [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', Tender::STATUSES)],
            'window' => ['nullable', 'in:all,open,week,clarifications'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $window = $data['window'] ?? 'all';
        $now = now();
        $week = $now->copy()->addDays(7);

        $query = Tender::query()
            ->select(['id', 'company_id', 'institution_id', 'reference', 'title', 'purchase_type', 'status', 'estimated_amount', 'clarification_deadline', 'presentation_deadline', 'opening_at'])
            ->with('institution:id,name')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $x) => $x
                ->where('reference', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhereHas('institution', fn (Builder $i) => $i->where('name', 'like', "%{$search}%")->orWhere('acronym', 'like', "%{$search}%"))))
            ->when(! empty($data['status']), fn (Builder $q) => $q->where('status', $data['status']))
            ->when($window === 'open', fn (Builder $q) => $q->whereIn('status', Tender::OPEN_STATUSES)
                ->where(fn (Builder $x) => $x->whereNull('presentation_deadline')->orWhere('presentation_deadline', '>=', $now)))
            ->when($window === 'week', fn (Builder $q) => $q->whereIn('status', Tender::OPEN_STATUSES)->whereBetween('presentation_deadline', [$now, $week]))
            ->when($window === 'clarifications', fn (Builder $q) => $q->whereIn('status', Tender::OPEN_STATUSES)->whereBetween('clarification_deadline', [$now, $week]))
            ->orderByRaw('CASE WHEN presentation_deadline IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN presentation_deadline < ? THEN 1 ELSE 0 END', [$now])
            ->orderBy('presentation_deadline')
            ->orderByDesc('id');

        return $this->ok(['company_id' => $companyId, ...$this->paginate($query, $request, fn (Tender $tender) => Present::tenderRow($tender))]);
    }
}
