<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Quotes\LoseRequest;
use App\Http\Requests\Quotes\WinRequest;
use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\TargetsAdjudicable;
use App\Models\User;
use App\Services\Business\AdjudicationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('register_outcome')]
#[Title('Registrar Ganada o Perdida')]
#[Description('Registra el resultado comercial de una cotización o de una oportunidad (también sin cotización). Ganada: monto adjudicado y fecha; crea o reutiliza el cliente, abre el contrato en «Pendiente de contrato», mueve la oportunidad a Adjudicado y sustituye las demás cotizaciones vivas. Perdida: exige quién ganó (winner_name o winner_participant_id) y el valor de su oferta; la oportunidad pasa a No Adjudicado. Repetirla no duplica nada. NUNCA la uses por inferencia: solo cuando la persona diga explícitamente el resultado. ACCIÓN SENSIBLE en dos pasos: la primera llamada devuelve un resumen y un confirmation_code; muéstralo y, solo si la persona confirma, repite la llamada con los mismos argumentos y el código.')]
#[IsDestructive]
class RegisterOutcomeTool extends BaseTool
{
    use TargetsAdjudicable;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->subjectSchema($schema),
            ...$this->companySchema($schema),
            'outcome' => $schema->string()->enum(['Ganada', 'Perdida'])->description('Resultado.')->required(),
            'awarded_amount' => $schema->number()->description('Ganada: monto adjudicado real (puede ser menor que el cotizado).'),
            'awarded_at' => $schema->string()->description('Fecha de adjudicación «AAAA-MM-DD» (no futura; por defecto hoy).'),
            'quote_id_of_opportunity' => $schema->integer()->description('Solo si se indica opportunity_id y tiene varias cotizaciones vivas: cuál se ganó o perdió.'),
            'winner_name' => $schema->string()->description('Perdida: quién ganó.'),
            'winner_participant_id' => $schema->integer()->description('Perdida: id del participante que ganó (ver get_participants).'),
            'winner_tax_id' => $schema->string()->description('Perdida: RTN del ganador.'),
            'winner_amount' => $schema->number()->description('Perdida: valor de la oferta ganadora.'),
            'lost_reason' => $schema->string()->description('Perdida: motivo.'),
            ...$this->confirmationSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $subject = $this->subject($request);
        $this->authorize('adjudicate', $subject);

        $won = $request->get('outcome') === 'Ganada';
        if (! in_array($request->get('outcome'), ['Ganada', 'Perdida'], true)) {
            throw new ToolError('outcome debe ser Ganada o Perdida.');
        }

        $input = Arr::except($request->all(), ['quote_id', 'opportunity_id', 'empresa_id', 'confirmation_code', 'outcome']);
        $input['awarded_at'] ??= now()->toDateString();
        $input['quote_id'] = $input['quote_id_of_opportunity'] ?? null;
        $form = $won ? new WinRequest : new LoseRequest;
        $data = $this->validate($input, $form->rules(), $form->messages());

        $current = $this->outcomeOf($subject);
        if ($current !== 'Pendiente' && $current !== $request->get('outcome')) {
            throw new ToolError(ucfirst($this->subjectLabel($subject))." ya está registrada como {$current}. Para cambiarla hay que revertir el resultado (revert_outcome).");
        }

        $summary = [
            'action' => $won ? 'Registrar como GANADA' : 'Registrar como PERDIDA',
            'subject' => $this->subjectLabel($subject),
            'institution' => $subject->institution?->name,
            'awarded_at' => $data['awarded_at'] ?? null,
            ...($won
                ? ['awarded_amount' => (float) $data['awarded_amount']]
                : ['winner' => $data['winner_name'] ?? "participante #{$data['winner_participant_id']}", 'winner_amount' => (float) $data['winner_amount']]),
            'effect' => $won
                ? 'Se crea o reutiliza el cliente, se abre el contrato de post-adjudicación, la oportunidad pasa a Adjudicado y se cancelan los avisos de la oferta.'
                : 'La oportunidad pasa a No Adjudicado y el ganador queda registrado entre los participantes.',
        ];

        return $this->confirmed($request, 'register_outcome', $subject, $summary, function () use ($subject, $data, $won, $user) {
            $service = app(AdjudicationService::class);
            $result = $won ? $service->win($subject, $data, $user) : $service->lose($subject, $data, $user);

            return $this->ok(['result' => $result->toArray()], $result->alreadyApplied
                ? 'El resultado ya estaba registrado; no se duplicó nada.'
                : ($won ? 'Oferta registrada como ganada.'.($result->contract ? " Se abrió el contrato {$result->contract->number}." : '') : 'Oferta registrada como no adjudicada.'));
        });
    }
}
