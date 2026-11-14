<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Mcp\Support\ToolError;
use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_events')]
#[Title('Eventos del calendario')]
#[Description('Plazos y eventos del calendario en un rango de fechas (máx. 100 días): cierres de aclaraciones, recepción y apertura de ofertas, contratos, entregas, seguimientos, vencimientos de documentos y eventos manuales. Incluye los que empiezan en el rango o lo cruzan. Por defecto, de hoy a 30 días.')]
#[IsReadOnly]
#[IsIdempotent]
class ListEventsTool extends BaseTool
{
    private const MAX_RANGE_DAYS = 100;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'from' => $schema->string()->description('Desde, «AAAA-MM-DD» (por defecto hoy).'),
            'to' => $schema->string()->description('Hasta, «AAAA-MM-DD» (por defecto hoy + 30 días).'),
            'types' => $schema->array()->items($schema->string()->enum(array_keys(Event::TYPES)))->description('Tipos: '.implode(', ', array_keys(Event::TYPES)).'.'),
            'pending_only' => $schema->boolean()->description('Solo plazos no atendidos.'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Event::class);

        $data = $this->validate($request->all(), [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', 'in:'.implode(',', array_keys(Event::TYPES))],
            'pending_only' => ['nullable', 'boolean'],
        ]);

        $from = Carbon::parse($data['from'] ?? today())->startOfDay();
        $to = Carbon::parse($data['to'] ?? today()->addDays(30))->endOfDay();

        if ($to->lt($from) || $from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw new ToolError('El rango debe ir de «from» a «to» y no superar '.self::MAX_RANGE_DAYS.' días.');
        }

        $events = Event::query()
            ->visible()
            ->when($data['pending_only'] ?? false, fn ($query) => $query->pending())
            ->where('starts_at', '<=', $to)
            ->where(fn ($query) => $query->where('starts_at', '>=', $from)
                ->orWhere(fn ($inner) => $inner->whereNotNull('ends_at')->where('ends_at', '>=', $from)))
            ->when(! empty($data['types']), fn ($query) => $query->whereIn('type', $data['types']))
            ->orderBy('starts_at')
            ->limit(500)
            ->get();

        return $this->ok([
            'company_id' => $companyId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'count' => $events->count(),
            'events' => $events->map(fn (Event $event) => Present::event($event))->all(),
        ]);
    }
}
