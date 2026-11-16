<?php

namespace App\Services\Notifications;

use App\Models\Event;
use App\Models\User;
use App\Services\System\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Eventos manuales del calendario: una sola implementación para la web (`CalendarController`) y
 * el MCP (`HandlesManualEvents`). Crear, cambiar, borrar, atender y reabrir reprograman los avisos,
 * limpian la caché de plazos de la empresa y dejan auditoría.
 *
 * La validación (`EventRequest`) y la autorización quedan en quien llama.
 */
class CalendarEventService
{
    public function __construct(
        protected ReminderService $reminders,
        protected AuditLogger $audit,
    ) {}

    /**
     * Atributos de un evento manual a partir de los datos ya validados con `EventRequest`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function attributes(array $data): array
    {
        $allDay = (bool) ($data['all_day'] ?? false);
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null;

        if ($allDay) {
            $startsAt = $startsAt->startOfDay();
            $endsAt = $endsAt?->endOfDay();
        }

        return [
            'title' => $data['title'],
            'type' => $data['type'] ?? 'Manual',
            'label' => $data['title'],
            'description' => $data['description'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'reminder_offsets' => ($data['custom_reminders'] ?? false) ? ReminderService::normalizeDays($data['reminders'] ?? []) : null,
            'recipients' => array_values(array_map('intval', $data['recipients'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes  salida de attributes()
     */
    public function create(array $attributes, User $actor, int $companyId): Event
    {
        $event = DB::transaction(function () use ($attributes, $actor, $companyId) {
            $event = new Event;
            $event->forceFill($attributes + [
                'company_id' => $companyId,
                'owner_id' => $actor->id,
                'created_by' => $actor->id,
                'origin' => 'manual',
                'remindable' => true,
                'priority' => 'Normal',
            ])->save();

            return $event;
        });

        $this->afterChange($event);
        $this->audit->record('calendar.event.created', $event, ['title' => $event->title, 'starts_at' => $event->starts_at?->toDateTimeString()], $actor, $companyId);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $attributes  salida de attributes()
     */
    public function update(Event $event, array $attributes, ?User $actor = null): Event
    {
        $before = $event->starts_at?->toDateTimeString();
        $event->forceFill($attributes)->save();
        $this->afterChange($event);
        $this->audit->record('calendar.event.updated', $event, ['starts_at' => ['antes' => $before, 'despues' => $event->starts_at?->toDateTimeString()]], $actor);

        return $event;
    }

    public function delete(Event $event, ?User $actor = null): void
    {
        DB::transaction(function () use ($event) {
            $this->reminders->cancelPending($event, 'El evento se eliminó.');
            $event->delete();
        });

        $this->forgetCaches((int) $event->company_id);
        $this->audit->record('calendar.event.deleted', $event, ['title' => $event->title], $actor);
    }

    /**
     * «Marcar como atendido»: detiene los avisos del plazo para todos. Idempotente: devuelve false
     * si ya estaba atendido.
     */
    public function attend(Event $event, User $actor): bool
    {
        $done = DB::transaction(function () use ($event, $actor) {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->first();

            if ($locked === null || $locked->attended_at !== null) {
                return false;
            }

            $locked->forceFill(['attended_at' => now(), 'attended_by' => $actor->id])->save();
            $this->reminders->cancelPending($locked, "Atendido por {$actor->name}.");

            return true;
        });

        $this->forgetCaches((int) $event->company_id);

        if ($done) {
            $this->audit->record('calendar.event.attended', $event, [], $actor);
        }

        return $done;
    }

    /** Devuelve el plazo a pendiente y reprograma sus avisos. Sin efecto si no estaba atendido. */
    public function reopen(Event $event, ?User $actor = null): bool
    {
        if ($event->attended_at === null) {
            return false;
        }

        $event->forceFill(['attended_at' => null, 'attended_by' => null])->save();
        $this->afterChange($event);
        $this->audit->record('calendar.event.reopened', $event, [], $actor);

        return true;
    }

    public function forgetCaches(?int $companyId): void
    {
        if ($companyId) {
            Cache::forget(DeadlineService::cacheKey($companyId));
        }
    }

    protected function afterChange(Event $event): void
    {
        $this->reminders->syncEvent($event);
        $this->forgetCaches((int) $event->company_id);
    }
}
