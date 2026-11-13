<?php

namespace App\Mcp\Tools;

use App\Models\Quote;
use App\Services\Business\AdjudicationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('set_quote_participants')]
#[Title('Registrar participantes de la oferta')]
#[Description('Registra los participantes de una oferta presentada con el monto que presento cada uno. Marque nuestra propia propuesta con `nuestra_oferta`. La lista reemplaza por completo la anterior, por lo que envie todos los participantes en una sola llamada.')]
class SetParticipantsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Identificador de la cotizacion.')->required(),
            'participantes' => $schema->array()->description('Participantes de la oferta.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $quote = Quote::query()->find((int) $request->get('quote_id'));

        if ($quote === null) {
            return $this->fail('La cotizacion indicada no existe.');
        }

        abort_unless($user->canAccessCompany($quote->company_id), 403, 'No tiene acceso a esta cotizacion.');

        $data = $request->validate([
            'participantes' => 'required|array|min:1|max:50',
            'participantes.*.nombre' => 'required|string|max:255',
            'participantes.*.rtn' => 'nullable|string|max:100',
            'participantes.*.monto' => 'required|numeric|min:0',
            'participantes.*.moneda' => 'nullable|string|max:3',
            'participantes.*.incluye_impuesto' => 'nullable|boolean',
            'participantes.*.nuestra_oferta' => 'nullable|boolean',
            'participantes.*.posicion' => 'nullable|integer|min:1|max:100',
            'participantes.*.notas' => 'nullable|string|max:2000',
        ]);

        $participants = array_map(fn (array $participant) => [
            'name' => $participant['nombre'],
            'tax_id' => $participant['rtn'] ?? null,
            'amount' => $participant['monto'],
            'currency' => $participant['moneda'] ?? 'HNL',
            'includes_tax' => $participant['incluye_impuesto'] ?? true,
            'is_ours' => $participant['nuestra_oferta'] ?? false,
            'position' => $participant['posicion'] ?? 0,
            'notes' => $participant['notas'] ?? null,
        ], $data['participantes']);

        $updated = app(AdjudicationService::class)->syncParticipants($quote, $participants);

        return $this->ok([
            'cotizacion_id' => $updated->id,
            'participantes' => $updated->participants->map(fn ($participant) => [
                'nombre' => $participant->name,
                'rtn' => $participant->tax_id,
                'monto' => (float) $participant->amount,
                'nuestra_oferta' => $participant->is_ours,
            ])->all(),
        ], 'Participantes registrados.');
    }
}
