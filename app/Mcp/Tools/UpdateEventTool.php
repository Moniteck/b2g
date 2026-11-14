<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\HandlesManualEvents;
use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_event')]
#[Title('Actualizar evento del calendario')]
#[Description('Cambia un evento MANUAL (título, fechas, descripción, avisos, destinatarios); solo los campos enviados. Los avisos pendientes se reprograman conservando el historial. Un plazo derivado (de una licitación, contrato o documento) se cambia en su registro de origen, p. ej. con update_tender.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateEventTool extends BaseTool
{
    use HandlesManualEvents;

    protected ?array $record = ['event_id', Event::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->integer()->description('Id del evento.')->required(),
            ...$this->companySchema($schema),
            ...$this->eventSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $event = $this->find(Event::query()->visible(), $request->get('event_id'), 'el evento');
        $this->authorize('update', $event);

        if ($event->isDerived()) {
            throw new ToolError('Este plazo viene de su registro de origen ('.$event->sourceLabel().'): se cambia desde allí.');
        }

        $current = [
            'title' => $event->title,
            'starts_at' => $event->starts_at?->format('Y-m-d H:i:s'),
            'ends_at' => $event->ends_at?->format('Y-m-d H:i:s'),
            'all_day' => (bool) $event->all_day,
            'description' => $event->description,
            'recipients' => array_values(array_filter((array) ($event->recipients ?? []), 'is_numeric')),
        ];
        if ($event->reminder_offsets !== null) {
            $current['reminders'] = $event->reminder_offsets;
        }

        $input = array_intersect_key($request->all(), array_flip(['title', 'starts_at', 'ends_at', 'all_day', 'description', 'reminders', 'recipients']));

        if ($input === []) {
            throw new ToolError('Indica al menos un campo para actualizar.');
        }

        $this->calendarEvents()->update($event, $this->eventAttributes($input + $current), $user);

        return $this->ok(['event' => Present::event($event->fresh())], 'Evento actualizado. Los recordatorios pendientes se reprogramaron.');
    }
}
