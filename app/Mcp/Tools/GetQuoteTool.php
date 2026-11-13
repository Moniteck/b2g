<?php

namespace App\Mcp\Tools;

use App\Models\Quote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('get_quote')]
#[Title('Consultar cotizacion')]
#[Description('Obtiene el detalle de una cotizacion: lineas, precios unitarios, impuesto contenido, estado documental, resultado comercial, participantes y expedientes relacionados.')]
class GetQuoteTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Identificador de la cotizacion.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $quote = Quote::query()
            ->with(['items', 'institution:id,name', 'contact:id,name', 'company:id,name', 'participants'])
            ->find((int) $request->get('quote_id'));

        if ($quote === null) {
            return $this->fail('La cotizacion indicada no existe.');
        }

        $this->authorizeForUser($request, $quote);

        return $this->ok([
            'cotizacion' => [
                'id' => $quote->id,
                'numero' => $quote->number,
                'empresa' => $quote->company?->name,
                'institucion' => $quote->institution?->name,
                'contacto' => $quote->contact?->name,
                'fecha' => $quote->quoted_at?->toDateString(),
                'tipo' => $quote->purchase_type,
                'estado' => $quote->status,
                'resultado' => $quote->outcome,
                'validez_dias' => $quote->validity_days,
                'subtotal' => (float) $quote->subtotal,
                'impuesto_contenido' => (float) $quote->tax,
                'total' => (float) $quote->total,
                'notas' => $quote->notes,
                'monto_adjudicado' => $quote->awarded_amount !== null ? (float) $quote->awarded_amount : null,
                'fecha_adjudicacion' => $quote->awarded_at?->toDateString(),
                'ganador' => $quote->winner_name,
                'lineas' => $quote->items->map(fn ($item) => [
                    'descripcion' => $item->description,
                    'cantidad' => (float) $item->quantity,
                    'unidad' => $item->unit,
                    'costo_unitario' => (float) $item->unit_cost,
                    'precio_unitario' => (float) $item->unit_price,
                    'total' => (float) $item->total,
                    'tasa_impuesto' => $item->tax_rate !== null ? (float) $item->tax_rate : null,
                    'origen_precio' => $item->pricing_source,
                ])->all(),
                'participantes' => $quote->participants->map(fn ($participant) => [
                    'nombre' => $participant->name,
                    'rtn' => $participant->tax_id,
                    'monto' => (float) $participant->amount,
                    'moneda' => $participant->currency,
                    'incluye_impuesto' => $participant->includes_tax,
                    'nuestra_oferta' => $participant->is_ours,
                    'posicion' => $participant->position,
                ])->all(),
            ],
        ]);
    }

    protected function authorizeForUser(Request $request, Quote $quote): void
    {
        abort_unless(
            $this->user($request)->canAccessCompany($quote->company_id),
            403,
            'No tiene acceso a la empresa de esta cotizacion.',
        );
    }
}
