<?php

namespace App\Mcp\Tools;

use App\Models\Event;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_events')]
#[Title('Consultar calendario y vencimientos')]
#[Description('Lista los eventos del calendario de una empresa: cierres de aclaraciones y presentacion, aperturas, vigencias de contrato, entregas y vencimientos documentales, con los recordatorios programados.')]
#[IsReadOnly]
class ListEventsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Empresa. Omitirlo usa la empresa activa.'),
            'desde' => $schema->string()->description('Fecha inicial en formato YYYY-MM-DD.'),
            'hasta' => $schema->string()->description('Fecha final en formato YYYY-MM-DD.'),
            'tipo' => $schema->string()->description('Filtra por tipo de evento.'),
            'proximos_dias' => $schema->integer()->description('Muestra solo los eventos de los proximos N dias.'),
            'page' => $schema->integer()->description('Pagina solicitada.'),
            'per_page' => $schema->integer()->description('Registros por pagina (maximo 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $query = Event::query()
            ->where('company_id', $companyId)
            ->with('deliveries')
            ->orderBy('starts_at');

        if ($from = $request->get('desde')) {
            $query->where('starts_at', '>=', $from);
        }

        if ($to = $request->get('hasta')) {
            $query->where('starts_at', '<=', $to);
        }

        if ($days = (int) $request->get('proximos_dias', 0)) {
            $query->whereBetween('starts_at', [now(), now()->addDays($days)]);
        }

        if ($type = $request->get('tipo')) {
            $query->where('type', $type);
        }

        $result = $this->paginate($query, $request);

        $result['data'] = collect($result['data'])->map(fn (Event $event) => [
            'id' => $event->id,
            'titulo' => $event->title,
            'tipo' => $event->type,
            'inicio' => $event->starts_at?->toDateTimeString(),
            'fin' => $event->ends_at?->toDateTimeString(),
            'prioridad' => $event->priority,
            'completado' => $event->completed,
            'origen' => $event->origin,
            'referencia' => $event->reference_type ? [
                'tipo' => $event->reference_type,
                'id' => $event->reference_id,
            ] : null,
            'recordatorios' => $event->deliveries->map(fn ($delivery) => [
                'canal' => $delivery->channel,
                'destinatario' => $delivery->recipient,
                'estado' => $delivery->status,
                'programado_para' => $delivery->scheduled_for?->toDateTimeString(),
            ])->all(),
        ])->all();

        return $this->ok($result);
    }
}
