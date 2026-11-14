<?php

namespace App\Mcp\Tools;

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
#[Description('Cotizaciones de una empresa con número, institución, estado documental (Borrador, Emitida, Presentada, Sustituida, Anulada), resultado comercial (Pendiente, Ganada, Perdida), total y fechas.')]
#[IsReadOnly]
#[IsIdempotent]
class ListQuotesTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'search' => $schema->string()->description('Número, institución o tipo de compra.'),
            'estado' => $schema->string()->enum(Quote::STATUSES)->description('Estado documental.'),
            'resultado' => $schema->string()->enum(Quote::OUTCOMES)->description('Resultado comercial.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Quote::class);

        $data = $this->validate($request->all(), [
            'search' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:'.implode(',', Quote::STATUSES)],
            'resultado' => ['nullable', 'in:'.implode(',', Quote::OUTCOMES)],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $query = Quote::query()
            ->with('institution:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('number', 'like', "%{$search}%")
                ->orWhere('purchase_type', 'like', "%{$search}%")
                ->orWhereHas('institution', fn ($institution) => $institution->where('name', 'like', "%{$search}%"))))
            ->when(! empty($data['estado']), fn ($query) => $query->where('status', $data['estado']))
            ->when(! empty($data['resultado']), fn ($query) => $query->where('outcome', $data['resultado']))
            ->orderByDesc('quoted_at')
            ->orderByDesc('id');

        return $this->ok(['company_id' => $companyId, ...$this->paginate($query, $request, fn (Quote $quote) => [
            'id' => $quote->id,
            'numero' => $quote->number,
            'institucion' => $quote->institution?->name,
            'fecha' => $quote->quoted_at?->format('Y-m-d'),
            'tipo' => $quote->purchase_type,
            'estado' => $quote->status,
            'resultado' => $quote->outcome,
            'total' => (float) $quote->total,
            'impuesto' => (float) $quote->tax,
            'licitacion_id' => $quote->tender_id,
            'oportunidad_id' => $quote->opportunity_id,
            'adjudicado' => $quote->awarded_at?->format('Y-m-d'),
            'monto_adjudicado' => $quote->awarded_amount !== null ? (float) $quote->awarded_amount : null,
        ])]);
    }
}
