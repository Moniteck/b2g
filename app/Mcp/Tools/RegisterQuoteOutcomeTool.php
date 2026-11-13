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

#[Name('register_quote_outcome')]
#[Title('Registrar resultado de la oferta')]
#[Description('Registra el resultado comercial de una cotizacion presentada: Ganada o Perdida. Al marcar Ganada, el sistema actualiza la oportunidad, crea o reutiliza el cliente y abre el expediente post-adjudicacion; al marcar Perdida, exige ganador y monto adjudicado. Use esta herramienta solo cuando el usuario haya confirmado el resultado; no la ejecute por inferencia.')]
class RegisterQuoteOutcomeTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Identificador de la cotizacion presentada.')->required(),
            'resultado' => $schema->string()->enum(['Ganada', 'Perdida'])->description('Resultado comercial de la oferta.')->required(),
            'monto_adjudicado' => $schema->number()->description('Monto adjudicado. En Ganada, si se omite se usa el total de la cotizacion. En Perdida es el monto awarded al ganador.'),
            'fecha_adjudicacion' => $schema->string()->description('Fecha de adjudicacion en formato YYYY-MM-DD. Por defecto la fecha actual.'),
            'ganador' => $schema->string()->description('Nombre del adjudicatario. Obligatorio en Perdida.'),
            'motivo' => $schema->string()->description('Motivo o notas del resultado.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $quote = Quote::query()->with(['opportunity', 'tender', 'institution'])->find((int) $request->get('quote_id'));

        if ($quote === null) {
            return $this->fail('La cotizacion indicada no existe.');
        }

        abort_unless($user->canAccessCompany($quote->company_id), 403, 'No tiene acceso a esta cotizacion.');

        if (! $user->can('adjudicate', $quote)) {
            return $this->fail('No tiene permisos para registrar el resultado de una oferta.');
        }

        $data = $request->validate([
            'resultado' => 'required|in:Ganada,Perdida',
            'monto_adjudicado' => 'nullable|numeric|min:0',
            'fecha_adjudicacion' => 'nullable|date',
            'ganador' => 'nullable|string|max:255',
            'motivo' => 'nullable|string|max:5000',
        ]);

        if ($data['resultado'] === 'Perdida' && blank($data['ganador'] ?? null)) {
            return $this->fail('Al registrar una oferta perdida debe indicar quien gano la oferta.');
        }

        $service = app(AdjudicationService::class);

        $updated = $data['resultado'] === 'Ganada'
            ? $service->markWon($quote, [
                'awarded_amount' => $data['monto_adjudicado'] ?? $quote->total,
                'awarded_at' => $data['fecha_adjudicacion'] ?? now()->toDateString(),
                'winner_name' => $data['ganador'] ?? null,
            ], $user->id)
            : $service->markLost($quote, [
                'awarded_amount' => $data['monto_adjudicado'] ?? null,
                'awarded_at' => $data['fecha_adjudicacion'] ?? now()->toDateString(),
                'winner_name' => $data['ganador'] ?? null,
                'lost_reason' => $data['motivo'] ?? null,
            ]);

        $payload = [
            'cotizacion_id' => $updated->id,
            'numero' => $updated->number,
            'resultado' => $updated->outcome,
            'monto_adjudicado' => $updated->awarded_amount !== null ? (float) $updated->awarded_amount : null,
            'fecha_adjudicacion' => $updated->awarded_at?->toDateString(),
        ];

        if ($updated->outcome === 'Ganada') {
            $payload['cliente_id'] = $updated->client_id;
            $payload['institucion'] = $updated->institution?->name;
            $payload['siguiente_paso'] = 'Se abrio el expediente post-adjudicacion. Revise el contrato, las condiciones de inicio y los responsables.';
        }

        return $this->ok($payload, 'Resultado registrado correctamente.');
    }
}
