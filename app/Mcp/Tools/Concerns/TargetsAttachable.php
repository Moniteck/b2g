<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\ToolError;
use App\Services\Documents\Attachables;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;

/**
 * Entidad con adjuntos (`Attachables::TYPES`: quote, tender, opportunity, contract, client).
 */
trait TargetsAttachable
{
    /**
     * @return array<string, mixed>
     */
    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'entity_type' => $schema->string()->enum(array_keys(Attachables::TYPES))->description('Tipo de registro: '.implode(', ', array_keys(Attachables::TYPES)).'.')->required(),
            'entity_id' => $schema->integer()->description('Id del registro.')->required(),
        ];
    }

    protected function recordFor(Request $request): ?array
    {
        $type = $request->get('entity_type');

        return is_string($type) && Attachables::exists($type) ? ['entity_id', Attachables::TYPES[$type]['model']] : null;
    }

    protected function entity(Request $request): Model
    {
        $type = $request->get('entity_type');

        if (! is_string($type) || ! Attachables::exists($type)) {
            throw new ToolError('entity_type debe ser uno de: '.implode(', ', array_keys(Attachables::TYPES)).'.');
        }

        return $this->find(Attachables::TYPES[$type]['model'], $request->get('entity_id'), 'el registro ('.Attachables::TYPES[$type]['label'].')');
    }
}
