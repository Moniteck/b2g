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

#[Name('get_quote')]
#[Title('Detalle de cotización')]
#[Description('Detalle de una cotización: líneas con precios e ISV, estado documental, resultado comercial, participantes y datos de adjudicación.')]
#[IsReadOnly]
#[IsIdempotent]
class GetQuoteTool extends BaseTool
{
    protected ?array $record = ['quote_id', Quote::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Id de la cotización.')->required(),
            ...$this->companySchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $quote = $this->find(Quote::query()->with(['items', 'institution:id,name', 'contact:id,name', 'participants']), $request->get('quote_id'), 'la cotización');
        $this->authorize('view', $quote);

        return $this->ok([
            'cotizacion' => [
                'id' => $quote->id,
                'company_id' => $quote->company_id,
                'numero' => $quote->number,
                'institucion' => $quote->institution?->name,
                'contacto' => $quote->contact?->name,
                'fecha' => $quote->quoted_at?->format('Y-m-d'),
                'tipo' => $quote->purchase_type,
                'estado' => $quote->status,
                'resultado' => $quote->outcome,
                'validez_dias' => $quote->validity_days,
                'subtotal' => (float) $quote->subtotal,
                'impuesto' => (float) $quote->tax,
                'total' => (float) $quote->total,
                'licitacion_id' => $quote->tender_id,
                'oportunidad_id' => $quote->opportunity_id,
                'monto_adjudicado' => $quote->awarded_amount !== null ? (float) $quote->awarded_amount : null,
                'fecha_adjudicacion' => $quote->awarded_at?->format('Y-m-d'),
                'ganador' => $quote->winner_name,
                'lineas' => $quote->items->map(fn ($item) => [
                    'cantidad' => (float) $item->quantity,
                    'unidad' => $item->unit,
                    'precio_unitario' => (float) $item->unit_price,
                    'total' => (float) $item->total,
                    'tasa_impuesto' => $item->tax_rate !== null ? (float) $item->tax_rate : null,
                    ...$this->untrusted(['descripcion' => $item->description], 500),
                ])->all(),
                'participantes' => $quote->participants->map(fn ($participant) => [
                    'nombre' => $participant->name,
                    'rtn' => $participant->tax_id,
                    'monto' => (float) $participant->amount,
                    'moneda' => $participant->currency,
                    'nuestra_oferta' => (bool) $participant->is_ours,
                ])->all(),
                ...$this->untrusted(['notas' => $quote->notes]),
            ],
        ]);
    }
}
