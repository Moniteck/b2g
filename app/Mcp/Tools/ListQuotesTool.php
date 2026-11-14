<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\DescribesQuoteFields;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_quotes')]
#[Title('Listar cotizaciones')]
#[Description('Cotizaciones de una empresa con número, institución, estado documental (Borrador, Emitida, Presentada, Sustituida, Anulada), resultado comercial (Pendiente, Ganada, Perdida) e importes. Filtros: búsqueda, estado, resultado, licitación u oportunidad.')]
#[IsReadOnly]
#[IsIdempotent]
class ListQuotesTool extends BaseTool
{
    use DescribesQuoteFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'search' => $schema->string()->description('Número, institución o tipo de compra.'),
            'status' => $schema->string()->enum(Quote::STATUSES)->description('Estado documental.'),
            'outcome' => $schema->string()->enum(Quote::OUTCOMES)->description('Resultado comercial.'),
            'tender_id' => $schema->integer()->description('Solo las de esta licitación.'),
            'opportunity_id' => $schema->integer()->description('Solo las de esta oportunidad.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Quote::class);

        $data = $this->validate($request->all(), [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', Quote::STATUSES)],
            'outcome' => ['nullable', 'in:'.implode(',', Quote::OUTCOMES)],
            'tender_id' => ['nullable', 'integer'],
            'opportunity_id' => ['nullable', 'integer'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $query = Quote::query()
            ->with('institution:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('number', 'like', "%{$search}%")
                ->orWhere('purchase_type', 'like', "%{$search}%")
                ->orWhereHas('institution', fn ($institution) => $institution->where('name', 'like', "%{$search}%"))))
            ->when(! empty($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(! empty($data['outcome']), fn ($query) => $query->where('outcome', $data['outcome']))
            ->when(! empty($data['tender_id']), fn ($query) => $query->where('tender_id', $data['tender_id']))
            ->when(! empty($data['opportunity_id']), fn ($query) => $query->where('opportunity_id', $data['opportunity_id']))
            ->orderByDesc('quoted_at')
            ->orderByDesc('id');

        return $this->ok(['company_id' => $companyId, ...$this->paginate($query, $request, fn (Quote $quote) => $this->quoteSummary($quote))]);
    }
}
