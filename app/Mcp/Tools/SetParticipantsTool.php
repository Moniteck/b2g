<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Quotes\ParticipantsRequest;
use App\Mcp\Support\Present;
use App\Mcp\Tools\Concerns\TargetsAdjudicable;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('set_participants')]
#[Title('Guardar participantes de la oferta')]
#[Description('Guarda la lista COMPLETA de participantes de una cotización u oportunidad, por diferencias: las filas con `id` se actualizan, las nuevas se crean (una sin id cuyo nombre ya existe se actualiza, no se duplica) y las que no vienen se quitan. Una sola fila is_ours (nuestra oferta) y, conocido el resultado, una sola is_winner. Campos: id?, name, tax_id?, amount, currency? (HNL o USD), includes_tax?, is_ours?, is_winner?, notes?.')]
#[IsDestructive(false)]
#[IsIdempotent]
class SetParticipantsTool extends BaseTool
{
    use TargetsAdjudicable;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->subjectSchema($schema),
            ...$this->companySchema($schema),
            'participants' => $schema->array()->description('Lista completa de participantes.')->required(),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $subject = $this->subject($request);
        $this->authorize($subject instanceof Quote ? 'manageParticipants' : 'update', $subject);

        $form = new ParticipantsRequest;
        $data = $this->validate(['participants' => $request->get('participants')], $form->rules(), [], $form->attributes());

        app(AdjudicationService::class)->saveParticipants($subject, $data['participants'], $user);

        return $this->ok(Present::participants($subject->fresh(), $this->outcomeOf($subject->fresh())), 'Participantes guardados.');
    }
}
