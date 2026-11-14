<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Mcp\Tools\Concerns\TargetsAdjudicable;
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

#[Name('get_participants')]
#[Title('Participantes de la oferta')]
#[Description('Participantes del proceso de una cotización u oportunidad, del más barato al más caro, con nuestra oferta y el ganador marcados, y el resumen: posición de nuestra oferta, la más baja y la diferencia contra ella.')]
#[IsReadOnly]
#[IsIdempotent]
class GetParticipantsTool extends BaseTool
{
    use TargetsAdjudicable;

    public function schema(JsonSchema $schema): array
    {
        return [...$this->subjectSchema($schema), ...$this->companySchema($schema)];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $subject = $this->subject($request);
        $this->authorize('view', $subject);

        return $this->ok(Present::participants($subject, $this->outcomeOf($subject)));
    }
}
