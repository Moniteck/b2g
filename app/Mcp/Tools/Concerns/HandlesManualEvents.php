<?php

namespace App\Mcp\Tools\Concerns;

use App\Http\Requests\Calendar\EventRequest;
use App\Services\Notifications\CalendarEventService;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Eventos manuales del calendario por MCP con las mismas reglas que la web
 * (`EventRequest` y `CalendarEventService`). Los plazos derivados (licitación,
 * contrato, documento…) no se editan aquí: se cambian en su registro de origen.
 */
trait HandlesManualEvents
{
    /**
     * @return array<string, mixed>
     */
    protected function eventSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Título del evento.'),
            'starts_at' => $schema->string()->description('Inicio, «AAAA-MM-DD HH:MM» en hora de Honduras (o «AAAA-MM-DD» si es de día completo).'),
            'ends_at' => $schema->string()->description('Fin (opcional), mismo formato.'),
            'all_day' => $schema->boolean()->description('Evento de día completo.'),
            'description' => $schema->string()->description('Descripción.'),
            'reminders' => $schema->array()->items($schema->integer())->description('Días de anticipación de los avisos (0 = el mismo día), p. ej. [7, 1, 0]. Omitirlo usa las reglas de la empresa.'),
            'recipients' => $schema->array()->items($schema->integer())->description('Ids de usuarios de la empresa que también reciben los avisos.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function eventAttributes(array $input): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = $input[$field] === '' ? null : str_replace('T', ' ', $input[$field]);
            }
        }

        $input['all_day'] = filter_var($input['all_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $input['custom_reminders'] = array_key_exists('reminders', $input) && $input['reminders'] !== null;

        $form = new EventRequest;
        $data = $this->validate($input, $form->rules(), $form->messages(), $form->attributes());

        // Por MCP solo se crean eventos «Manual»: el tipo no se acepta.
        unset($data['type']);

        return $this->calendarEvents()->attributes($data);
    }

    protected function calendarEvents(): CalendarEventService
    {
        return app(CalendarEventService::class);
    }
}
