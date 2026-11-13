<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\Event;
use App\Models\ReminderDelivery;
use App\Models\ReminderRule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Programa los recordatorios de fechas criticas.
 *
 * Cada combinacion (evento, anticipacion, canal, destinatario) genera una
 * entrega con clave de idempotencia, de modo que reejecutar el servicio no
 * duplica avisos.
 */
class ReminderService
{
    public const CHANNELS = ['correo' => 'Correo electronico', 'bandeja' => 'Bandeja interna'];

    /**
     * @return array{days_before: array<int>, channels: array<int>, recipients: array<int>}
     */
    public function rulesFor(?int $companyId, string $eventType): array
    {
        if ($companyId !== null) {
            $rule = ReminderRule::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('event_type', $eventType)
                ->where('is_active', true)
                ->first();

            if ($rule) {
                return [
                    'days_before' => array_map('intval', (array) $rule->days_before),
                    'channels' => (array) $rule->channels,
                    'recipients' => (array) ($rule->recipients ?? []),
                ];
            }
        }

        $default = collect(ReminderRule::defaults())->firstWhere('event_type', $eventType);

        return [
            'days_before' => $default['days_before'] ?? [1],
            'channels' => $default['channels'] ?? ['bandeja'],
            'recipients' => [],
        ];
    }

    /**
     * Programa los recordatorios de un evento concreto.
     */
    public function scheduleForEvent(Event $event): int
    {
        if (! $event->remindable || $event->completed || $event->starts_at === null) {
            return 0;
        }

        $rules = $this->rulesFor($event->company_id, (string) $event->type);
        $offsets = $event->reminder_offsets !== null
            ? array_map('intval', (array) $event->reminder_offsets)
            : $rules['days_before'];

        $recipients = $this->recipientsFor($event, $rules['recipients']);
        $channels = $event->recipients !== null && $rules['channels'] === []
            ? $rules['channels']
            : $rules['channels'];

        $created = 0;

        foreach ($offsets as $daysBefore) {
            $scheduledFor = $event->starts_at->copy()->subDays($daysBefore);

            if ($scheduledFor->isPast()) {
                continue;
            }

            foreach ($channels as $channel) {
                foreach ($recipients as $recipient) {
                    if ($this->createDelivery($event, $channel, $recipient, $scheduledFor, $daysBefore)) {
                        $created++;
                    }
                }
            }
        }

        return $created;
    }

    /**
     * Programa recordatorios para los eventos proximos de todas las empresas.
     */
    public function scheduleUpcoming(int $days = 45): int
    {
        $created = 0;
        $until = now()->addDays($days);

        Event::query()
            ->withoutGlobalScopes()
            ->where('remindable', true)
            ->where('completed', false)
            ->whereBetween('starts_at', [now(), $until])
            ->orderBy('starts_at')
            ->chunk(200, function ($events) use (&$created) {
                foreach ($events as $event) {
                    $created += $this->scheduleForEvent($event);
                }
            });

        return $created;
    }

    /**
     * Cancela los recordatorios programados que ya no corresponden.
     */
    public function cancelForEvent(Event $event): void
    {
        ReminderDelivery::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['Programado'])
            ->update(['status' => 'Cancelado']);
    }

    protected function createDelivery(
        Event $event,
        string $channel,
        string $recipient,
        Carbon $scheduledFor,
        int $daysBefore,
    ): bool {
        $key = implode('|', [
            'evt', $event->id, $daysBefore, $channel, Str::lower($recipient),
        ]);

        $exists = ReminderDelivery::query()->where('idempotency_key', $key)->exists();
        if ($exists) {
            return false;
        }

        ReminderDelivery::create([
            'event_id' => $event->id,
            'company_id' => $event->company_id,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => 'Programado',
            'scheduled_for' => $scheduledFor,
            'idempotency_key' => $key,
        ]);

        return true;
    }

    /**
     * @param  array<int>  $configured
     * @return array<int>
     */
    protected function recipientsFor(Event $event, array $configured): array
    {
        $recipients = [];

        foreach ((array) ($event->recipients ?? []) as $recipient) {
            if (is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $recipient;
            }
        }

        foreach ($configured as $recipient) {
            if (is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $recipient;
            }
        }

        if ($event->company_id !== null) {
            $company = Company::query()->withoutGlobalScopes()->find($event->company_id);
            if ($company?->contact_email) {
                $recipients[] = $company->contact_email;
            }
        }

        if ($event->user_id !== null) {
            $user = User::query()->find($event->user_id);
            if ($user?->email) {
                $recipients[] = $user->email;
            }
        }

        return array_values(array_unique($recipients));
    }
}
