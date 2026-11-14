<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolError;
use App\Models\Quote;
use App\Models\User;
use App\Services\Business\AdjudicationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('register_quote_outcome')]
#[Title('Registrar Ganada o Perdida')]
#[Description('Registra el resultado comercial de una cotización: Ganada (crea o reutiliza el cliente y abre el contrato de post-adjudicación) o Perdida (exige quién ganó). NUNCA la uses por inferencia: solo cuando la persona diga explícitamente el resultado. ACCIÓN SENSIBLE en dos pasos: la primera llamada devuelve un resumen y un confirmation_code; muéstralo y, solo si la persona confirma, repite la llamada con los mismos argumentos y el código.')]
#[IsDestructive]
class RegisterQuoteOutcomeTool extends BaseTool
{
    protected ?array $record = ['quote_id', Quote::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Id de la cotización.')->required(),
            ...$this->companySchema($schema),
            'resultado' => $schema->string()->enum(['Ganada', 'Perdida'])->description('Resultado comercial.')->required(),
            'monto_adjudicado' => $schema->number()->description('Monto adjudicado (en Ganada, por defecto el total de la cotización; en Perdida, el del ganador).'),
            'fecha_adjudicacion' => $schema->string()->description('Fecha de adjudicación «AAAA-MM-DD» (por defecto hoy).'),
            'ganador' => $schema->string()->description('Adjudicatario. Obligatorio en Perdida.'),
            'motivo' => $schema->string()->description('Motivo o notas.'),
            ...$this->confirmationSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $quote = $this->find(Quote::query()->with('institution:id,name'), $request->get('quote_id'), 'la cotización');
        $this->authorize('adjudicate', $quote);

        $data = $this->validate($request->all(), [
            'resultado' => ['required', 'in:Ganada,Perdida'],
            'monto_adjudicado' => ['nullable', 'numeric', 'min:0'],
            'fecha_adjudicacion' => ['nullable', 'date'],
            'ganador' => ['nullable', 'string', 'max:255'],
            'motivo' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($data['resultado'] === 'Perdida' && blank($data['ganador'] ?? null)) {
            throw new ToolError('Para registrar una oferta Perdida indica quién ganó (ganador).');
        }

        if ($quote->outcome !== 'Pendiente') {
            throw new ToolError("La cotización {$quote->number} ya tiene resultado {$quote->outcome}. Para cambiarlo hay que revertirlo desde el CRM.");
        }

        $won = $data['resultado'] === 'Ganada';
        $summary = [
            'action' => $won ? 'Registrar como GANADA' : 'Registrar como PERDIDA',
            'quote' => $quote->number,
            'institution' => $quote->institution?->name,
            'awarded_amount' => (float) ($data['monto_adjudicado'] ?? ($won ? $quote->total : 0)),
            'awarded_at' => $data['fecha_adjudicacion'] ?? now()->toDateString(),
            'winner' => $data['ganador'] ?? null,
            'effect' => $won
                ? 'Se crea o reutiliza el cliente, se abre el contrato de post-adjudicación, la oportunidad pasa a Adjudicado y se cancelan los avisos de la oferta.'
                : 'La oportunidad pasa a No Adjudicado y el ganador queda registrado como participante.',
        ];

        return $this->confirmed($request, 'register_quote_outcome', $quote, $summary, function () use ($quote, $data, $won, $user) {
            $service = app(AdjudicationService::class);
            $updated = $won
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

            return $this->ok([
                'cotizacion_id' => $updated->id,
                'numero' => $updated->number,
                'resultado' => $updated->outcome,
                'cliente_id' => $updated->client_id,
                'monto_adjudicado' => $updated->awarded_amount !== null ? (float) $updated->awarded_amount : null,
            ], 'Resultado registrado.');
        });
    }
}
