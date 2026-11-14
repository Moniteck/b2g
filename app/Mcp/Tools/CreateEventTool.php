<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Mcp\Tools\Concerns\HandlesManualEvents;
use App\Models\Event;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_event')]
#[Title('Crear evento del calendario')]
#[Description('Crea un evento manual en el calendario de la empresa (reunión, visita, recordatorio propio) y programa sus avisos por campana y correo. Los plazos de licitaciones, contratos y documentos NO se crean aquí: nacen solos de su registro.')]
#[IsDestructive(false)]
class CreateEventTool extends BaseTool
{
    use HandlesManualEvents;

    public function schema(JsonSchema $schema): array
    {
        $fields = $this->eventSchema($schema);
        $fields['title'] = $fields['title']->required();
        $fields['starts_at'] = $fields['starts_at']->required();

        return [...$this->companySchema($schema), ...$fields];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Event::class);

        $attributes = $this->eventAttributes($request->all());

        $event = DB::transaction(function () use ($attributes, $user, $companyId) {
            $event = new Event;
            $event->forceFill($attributes + [
                'company_id' => $companyId,
                'user_id' => $user->id,
                'owner_id' => $user->id,
                'created_by' => $user->id,
                'origin' => 'manual',
                'remindable' => true,
                'priority' => 'Normal',
            ])->save();

            return $event;
        });

        $this->afterEventChange($event);
        app(AuditLogger::class)->record('calendar.event.created', $event, ['title' => $event->title, 'starts_at' => $event->starts_at?->toDateTimeString()]);

        return $this->ok(['event' => Present::event($event->fresh())], 'Evento creado. Sus recordatorios quedaron programados.');
    }
}
