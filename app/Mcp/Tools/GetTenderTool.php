<?php

namespace App\Mcp\Tools;

use App\Models\Tender;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('get_tender')]
#[Title('Consultar licitacion')]
#[Description('Obtiene el detalle completo de una licitacion: fechas criticas, requisitos, condiciones de entrega y pago, contacto, monto estimado y cotizaciones vinculadas.')]
class GetTenderTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'tender_id' => $schema->integer()->description('Identificador de la licitacion.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $tender = Tender::query()
            ->with(['institution:id,name', 'quotes:id,number,status,outcome,total,company_id'])
            ->find((int) $request->get('tender_id'));

        if ($tender === null) {
            return $this->fail('La licitacion indicada no existe.');
        }

        abort_unless($this->user($request)->canAccessCompany($tender->company_id), 403, 'No tiene acceso a esta licitacion.');

        return $this->ok([
            'licitacion' => [
                'id' => $tender->id,
                'referencia' => $tender->reference,
                'numero_proceso' => $tender->process_name,
                'titulo' => $tender->title,
                'institucion' => $tender->institution?->name,
                'estado' => $tender->status,
                'resultado' => $tender->result,
                'tipo' => $tender->purchase_type,
                'monto_estimado' => (float) $tender->estimated_amount,
                'fechas' => $tender->deadlines(),
                'requisitos' => $tender->requirements,
                'condiciones_entrega' => $tender->delivery_terms,
                'condiciones_pago' => $tender->payment_terms,
                'contacto' => [
                    'nombre' => $tender->contact_name,
                    'telefono' => $tender->contact_phone,
                    'correo' => $tender->contact_email,
                ],
                'observaciones' => $tender->observations,
                'cotizaciones' => $tender->quotes->map(fn ($quote) => [
                    'id' => $quote->id,
                    'numero' => $quote->number,
                    'estado' => $quote->status,
                    'resultado' => $quote->outcome,
                    'total' => (float) $quote->total,
                ])->all(),
            ],
        ]);
    }
}
