<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Models\Event;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\DeadlineService;
use App\Services\Notifications\ReminderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('mark_event_attended')]
#[Title('Marcar plazo como atendido')]
#[Description('Marca un plazo del calendario como atendido: detiene sus avisos pendientes para todos y registra quién lo marcó. Úsela solo cuando la persona confirme que el plazo ya se cumplió (p. ej. ya envió sus preguntas de aclaración). Repetirla no cambia nada.')]
#[IsDestructive(false)]
#[IsIdempotent]
class MarkEventAttendedTool extends BaseTool
{
    protected ?array $record = ['event_id', Event::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->integer()->description('Id del evento o plazo.')->required(),
            ...$this->companySchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $event = $this->find(Event::query()->visible(), $request->get('event_id'), 'el evento');
        $this->authorize('update', $event);

        $done = DB::transaction(function () use ($event, $user) {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->first();

            if ($locked === null || $locked->attended_at !== null) {
                return false;
            }

            $locked->forceFill(['attended_at' => now(), 'attended_by' => $user->id])->save();
            app(ReminderService::class)->cancelPending($locked, "Atendido por {$user->name}.");

            return true;
        });

        Cache::forget(DeadlineService::cacheKey($companyId));

        if ($done) {
            app(AuditLogger::class)->record('calendar.event.attended', $event);
        }

        return $this->ok(['changed' => $done, 'event' => Present::event($event->fresh())], $done ? 'Plazo marcado como atendido. Ya no enviará avisos.' : 'Este plazo ya estaba atendido.');
    }
}
