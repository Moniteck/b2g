<?php

namespace App\Mcp\Tools;

use App\Models\Event;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_my_reminders')]
#[Title('Mis recordatorios')]
#[Description('Recordatorios de la campana del usuario dueño de la conexión en una empresa («Mis recordatorios»), del más reciente al más antiguo, con su plazo y si ya se leyeron. Filtro `status`: all, unread o read.')]
#[IsReadOnly]
#[IsIdempotent]
class ListMyRemindersTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'status' => $schema->string()->enum(['all', 'unread', 'read'])->description('Por defecto all.'),
            'type' => $schema->string()->enum(array_keys(Event::TYPES))->description('Tipo de plazo.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Event::class);

        $data = $this->validate($request->all(), [
            'status' => ['nullable', 'in:all,unread,read'],
            'type' => ['nullable', 'in:'.implode(',', array_keys(Event::TYPES))],
        ]);
        $status = $data['status'] ?? 'all';
        $feed = app(NotificationFeed::class);

        $query = $feed->query($user->id, $companyId)
            ->when($status === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($status === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->when(! empty($data['type']), fn ($query) => $query->whereHas('event', fn ($inner) => $inner->withoutGlobalScopes()->where('type', $data['type'])))
            ->with(['event' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'title', 'label', 'type', 'starts_at', 'all_day', 'reference_type', 'reference_id', 'contract_id', 'tender_id'])])
            ->orderByDesc('sent_at')
            ->orderByDesc('id');

        return $this->ok([
            'unread' => $feed->query($user->id, $companyId)->whereNull('read_at')->count(),
            ...$this->paginate($query, $request, function (ReminderDelivery $delivery) use ($feed) {
                $item = $feed->present($delivery);

                return [
                    'id' => $item['id'],
                    'event_id' => $delivery->event_id,
                    'type' => $item['type'],
                    'offset' => $item['offset'],
                    'severity' => $item['severity'],
                    'due_at' => ($delivery->due_at ?? $delivery->event?->starts_at)?->format('Y-m-d H:i:s'),
                    'sent_at' => $item['created_at'],
                    'read' => $item['read_at'] !== null,
                    ...$this->untrusted(['title' => $item['title'], 'body' => $item['body']], 500),
                ];
            }),
        ]);
    }
}
