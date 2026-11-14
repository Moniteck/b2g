<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Quotes\RevertRequest;
use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\TargetsAdjudicable;
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

#[Name('revert_outcome')]
#[Title('Revertir resultado')]
#[Description('Revierte un resultado Ganada o Perdida (solo administrador de la empresa, con motivo): la oferta vuelve a Presentada, el contrato creado pasa a Cancelado (no se borra) y el cliente se conserva con el monto descontado. No se puede si el contrato ya tiene actividad (documentos, entregas o seguimientos). ACCIÓN SENSIBLE en dos pasos: la primera llamada devuelve un resumen y un confirmation_code; muéstralo y, solo si la persona confirma, repite la llamada con los mismos argumentos y el código.')]
#[IsDestructive]
class RevertOutcomeTool extends BaseTool
{
    use TargetsAdjudicable;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->subjectSchema($schema),
            ...$this->companySchema($schema),
            'reason' => $schema->string()->description('Motivo de la reversión (mín. 5 caracteres).')->required(),
            ...$this->confirmationSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $subject = $this->subject($request);
        $this->authorize('revertOutcome', $subject);

        $form = new RevertRequest;
        $data = $this->validate(['reason' => $request->get('reason')], $form->rules(), $form->messages());

        $service = app(AdjudicationService::class);
        if (($blocker = $service->revertBlocker($subject)) !== null) {
            throw new ToolError($blocker);
        }

        $summary = [
            'action' => 'Revertir resultado',
            'subject' => $this->subjectLabel($subject),
            'current_outcome' => $this->outcomeOf($subject),
            'effect' => 'La oferta vuelve a Presentada; el contrato creado pasa a Cancelado y el cliente se conserva con el monto descontado.',
            ...$this->untrusted(['reason' => $data['reason']], 500),
        ];

        return $this->confirmed($request, 'revert_outcome', $subject, $summary, function () use ($service, $subject, $data, $user) {
            $result = $service->revert($subject, $data['reason'], $user);

            return $this->ok(['result' => $result->toArray()], 'Resultado revertido: la oferta vuelve a Presentada.'.($result->contract ? " El contrato {$result->contract->number} quedó Cancelado." : ''));
        });
    }
}
