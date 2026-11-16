<?php

namespace App\Services\Notifications;

use App\Models\Event;
use App\Models\PushSubscription;
use App\Models\ReminderDelivery;
use App\Models\ReminderRule;
use App\Models\User;
use App\Services\Pwa\WebPushService;
use App\Support\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Planificador de recordatorios.
 *
 * Para cada evento vigente calcula los avisos que tocan (anticipaciones de la regla de la empresa
 * o del propio evento) × canales × destinatarios, y deja la tabla `reminder_deliveries` igual a ese
 * plan sin perder historia:
 *
 *  - Clave única `e{evento}|{anticipación}|{canal}|{destinatario}|{fecha del plazo}`: reejecutar no duplica.
 *  - Si la fecha del plazo cambia, los avisos PENDIENTES se reprograman en su fila (cambian hora y
 *    clave) y los ENVIADOS se conservan tal cual.
 *  - Una anticipación que ya pasó se omite, salvo que sea de hoy: entonces sale de inmediato.
 *  - Un plazo cargado con menos de 24 h de margen genera un «aviso inmediato» si aún no se avisó.
 *  - Evento cancelado, atendido, completado o vencido: se cancelan sus pendientes.
 */
class ReminderService
{
    public const CHANNELS = ReminderDelivery::CHANNELS;

    /** Horas de margen por debajo de las cuales se avisa de inmediato. */
    public const IMMEDIATE_WINDOW_HOURS = 24;

    /** @var array<string, array{days_before: array<int>, send_time: string, channels: array<int, string>, recipients: array<int, string>}> */
    protected array $ruleCache = [];

    /**
     * Regla efectiva de una empresa para un tipo de plazo.
     *
     * @return array{days_before: array<int>, send_time: string, channels: array<int, string>, recipients: array<int, string>, custom: bool}
     */
    public function rulesFor(?int $companyId, string $eventType): array
    {
        $cacheKey = ($companyId ?? 0).'|'.$eventType;

        if (isset($this->ruleCache[$cacheKey])) {
            return $this->ruleCache[$cacheKey];
        }

        $default = ReminderRule::defaults()[$eventType] ?? ReminderRule::defaults()['Manual'];
        $rule = $companyId === null ? null : ReminderRule::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->first();

        $resolved = $rule === null ? [
            'days_before' => $default['days_before'],
            'send_time' => $default['send_time'],
            'channels' => $default['channels'],
            'recipients' => [],
            'custom' => false,
        ] : [
            'days_before' => $rule->is_active ? self::normalizeDays((array) $rule->days_before) : [],
            'send_time' => self::normalizeTime($rule->send_time) ?? $default['send_time'],
            'channels' => array_values(array_intersect(array_keys(self::CHANNELS), (array) $rule->channels)),
            'recipients' => array_values(array_filter((array) ($rule->recipients ?? []), fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))),
            'custom' => true,
        ];

        return $this->ruleCache[$cacheKey] = $resolved;
    }

    public function forgetRules(): void
    {
        $this->ruleCache = [];
    }

    /**
     * Deja los avisos del evento iguales a su plan. Devuelve cuántos avisos nuevos creó.
     */
    public function syncEvent(Event $event, ?Carbon $now = null): int
    {
        $now ??= now();

        if (! $event->remindable || ! $event->isActive($now)) {
            $this->cancelPending($event, $this->inactiveReason($event, $now));

            return 0;
        }

        $rule = $this->rulesFor($event->company_id, (string) $event->type);
        $days = $event->reminder_offsets !== null ? self::normalizeDays((array) $event->reminder_offsets) : $rule['days_before'];
        $deadline = $event->deadline();
        $stamp = $event->starts_at->format('YmdHi');

        return DB::transaction(function () use ($event, $rule, $days, $deadline, $stamp, $now) {
            /** @var Collection<int, ReminderDelivery> $existing */
            $existing = ReminderDelivery::query()->withoutGlobalScopes()
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->get();

            $slots = $this->slots($event, $days, $rule['send_time'], $deadline, $stamp, $existing, $now);
            $desired = [];

            foreach ($slots as $offset => $scheduledFor) {
                foreach ($rule['channels'] as $channel) {
                    foreach ($this->recipientsFor($event, $channel, $rule['recipients']) as $recipient) {
                        $slot = "{$offset}|{$channel}|{$recipient['id']}";
                        $desired[$slot] = [
                            'offset' => $offset,
                            'channel' => $channel,
                            'user_id' => $recipient['user_id'],
                            'recipient' => $recipient['email'],
                            'scheduled_for' => $scheduledFor,
                            'key' => "e{$event->id}|{$slot}|{$stamp}",
                        ];
                    }
                }
            }

            $byKey = $existing->keyBy('idempotency_key');
            $used = [];
            $created = 0;

            foreach ($desired as $slot => $plan) {
                $row = $byKey->get($plan['key']);

                if ($row !== null) {
                    $used[$row->id] = true;
                    if ($row->status === 'Cancelado') {
                        // El plazo volvió a estar vigente (origen reabierto, atendido deshecho): se reactiva.
                        $row->forceFill(['status' => 'Programado', 'scheduled_for' => $plan['scheduled_for'], 'error' => null])->save();
                    } elseif ($row->status === 'Programado' && ! $row->scheduled_for?->equalTo($plan['scheduled_for'])) {
                        $row->forceFill(['scheduled_for' => $plan['scheduled_for']])->save();
                    }

                    continue;
                }

                // Mismo aviso de una fecha anterior del plazo, todavía pendiente: se reprograma en su fila.
                $pending = $existing->first(fn (ReminderDelivery $candidate) => ! isset($used[$candidate->id])
                    && $candidate->status === 'Programado'
                    && $this->slotOf($candidate) === $slot);

                if ($pending !== null) {
                    $used[$pending->id] = true;
                    $pending->forceFill([
                        'scheduled_for' => $plan['scheduled_for'],
                        'due_at' => $event->starts_at,
                        'idempotency_key' => $plan['key'],
                        'recipient' => $plan['recipient'],
                    ])->save();

                    continue;
                }

                if ($this->createDelivery($event, $plan)) {
                    $created++;
                }
            }

            $existing
                ->filter(fn (ReminderDelivery $row) => ! isset($used[$row->id]) && $row->status === 'Programado')
                ->each(fn (ReminderDelivery $row) => $row->forceFill(['status' => 'Cancelado', 'error' => 'El plan de avisos del plazo cambió.'])->save());

            return $created;
        });
    }

    /**
     * Cancela los avisos que aún no salieron (los enviados se conservan).
     */
    public function cancelPending(Event $event, string $reason = 'El plazo ya no requiere avisos.'): int
    {
        return ReminderDelivery::query()->withoutGlobalScopes()
            ->where('event_id', $event->id)
            ->whereIn('status', ReminderDelivery::PENDING)
            ->update(['status' => 'Cancelado', 'error' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
    }

    /** @deprecated Usa cancelPending(). */
    public function cancelForEvent(Event $event): void
    {
        $this->cancelPending($event);
    }

    /** @deprecated Usa syncEvent(). */
    public function scheduleForEvent(Event $event): int
    {
        return $this->syncEvent($event);
    }

    /**
     * Respaldo diario: vuelve a planificar los eventos vigentes de los próximos días de todas las
     * empresas (por si una regla cambió o un observador no corrió).
     */
    public function scheduleUpcoming(int $days = 45, ?int $companyId = null): int
    {
        $created = 0;

        Event::query()->withoutGlobalScopes()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->pending()
            ->where('remindable', true)
            ->whereBetween('starts_at', [now()->startOfDay(), now()->addDays($days)])
            ->orderBy('id')
            ->chunkById(200, function ($events) use (&$created) {
                foreach ($events as $event) {
                    $created += $this->syncEvent($event);
                }
            });

        return $created;
    }

    /**
     * Destinatarios por usuario: responsable, creador, destinatarios elegidos en el evento y
     * administradores de la empresa; en correo, además los correos extra de la regla.
     *
     * @param  array<int, string>  $extraEmails
     * @return array<int, array{id: string, user_id: int|null, email: string}>
     */
    public function recipientsFor(Event $event, string $channel, array $extraEmails = []): array
    {
        $userIds = $this->recipientUserIds($event);
        $recipients = [];

        // Push: solo quien tiene al menos un dispositivo suscrito, y nada si faltan las claves VAPID.
        if ($channel === 'push') {
            $userIds = app(WebPushService::class)->enabled() && $userIds !== []
                ? PushSubscription::query()->whereIn('user_id', $userIds)->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->all()
                : [];
        }

        if ($userIds !== []) {
            User::query()->whereIn('id', $userIds)->orderBy('id')->get(['id', 'email'])
                ->each(function (User $user) use (&$recipients) {
                    $recipients["u{$user->id}"] = ['id' => "u{$user->id}", 'user_id' => $user->id, 'email' => (string) $user->email];
                });
        }

        if ($channel === 'correo') {
            $known = array_map(fn ($recipient) => Str::lower($recipient['email']), $recipients);
            $emails = array_merge($extraEmails, array_filter((array) ($event->recipients ?? []), 'is_string'));

            foreach ($emails as $email) {
                $email = Str::lower(trim($email));
                if (filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $known, true)) {
                    $id = 'm'.substr(sha1($email), 0, 12);
                    $recipients[$id] = ['id' => $id, 'user_id' => null, 'email' => $email];
                    $known[] = $email;
                }
            }
        }

        return array_values($recipients);
    }

    /**
     * Usuarios que reciben los avisos del evento, solo si siguen siendo miembros de su empresa.
     *
     * @return array<int, int>
     */
    public function recipientUserIds(Event $event): array
    {
        if ($event->company_id === null) {
            return [];
        }

        $members = DB::table('company_user')->where('company_id', $event->company_id)->pluck('role', 'user_id')
            ->mapWithKeys(fn ($role, $userId) => [(int) $userId => (string) $role]);

        $chosen = array_map('intval', array_filter((array) ($event->recipients ?? []), 'is_numeric'));
        $candidates = array_merge(
            [(int) $event->owner_id, (int) $event->created_by, (int) $event->user_id],
            $chosen,
            $members->filter(fn ($role) => $role === Permissions::ROLE_ADMIN)->keys()->all(),
        );

        return array_values(array_unique(array_filter($candidates, fn (int $id) => $id > 0 && $members->has($id))));
    }

    /**
     * Momentos de envío: [anticipación => fecha y hora].
     *
     * @param  array<int>  $days
     * @param  Collection<int, ReminderDelivery>  $existing
     * @return array<string, Carbon>
     */
    protected function slots(Event $event, array $days, string $sendTime, Carbon $deadline, string $stamp, Collection $existing, Carbon $now): array
    {
        if ($days === []) {
            return [];
        }

        $alreadyForThisDate = $existing->filter(fn (ReminderDelivery $row) => $row->status !== 'Cancelado'
            && str_ends_with((string) $row->idempotency_key, "|{$stamp}"));
        $offsetsUsed = $alreadyForThisDate->pluck('offset_key')->unique()->all();
        $offsetsPending = $alreadyForThisDate->where('status', 'Programado')->pluck('offset_key')->unique()->all();

        $slots = [];
        $catchUp = false;

        foreach ($days as $day) {
            $offset = "d{$day}";
            $at = $event->starts_at->copy()->startOfDay()->subDays($day)->setTimeFromTimeString($sendTime);

            // El mismo día a una hora posterior al plazo: se avisa una hora antes.
            if ($day === 0 && ! $event->all_day && $at->greaterThanOrEqualTo($event->starts_at)) {
                $at = $event->starts_at->copy()->subHour()->max($event->starts_at->copy()->startOfDay());
            }

            // Futuro, o vencido hace instantes y aún en cola del despachador: se conserva.
            if ($at->greaterThan($now) || in_array($offset, $offsetsPending, true)) {
                $slots[$offset] = $at;

                continue;
            }

            // Ya pasó: si es de hoy y todavía no se avisó por esta fecha del plazo, sale ya.
            if ($at->isSameDay($now) && ! in_array($offset, $offsetsUsed, true)) {
                $catchUp = true;
            }
        }

        $immediate = $alreadyForThisDate->firstWhere('offset_key', 'now');
        $margin = $now->diffInMinutes($deadline, false) / 60;

        if ($immediate !== null) {
            $slots = ['now' => $immediate->scheduled_for->copy()] + $slots;
        } elseif ($catchUp || ($margin < self::IMMEDIATE_WINDOW_HOURS && $alreadyForThisDate->isEmpty())) {
            $slots = ['now' => $now->copy()] + $slots;
        }

        return $slots;
    }

    /**
     * @param  array{offset: string, channel: string, user_id: int|null, recipient: string, scheduled_for: Carbon, key: string}  $plan
     */
    protected function createDelivery(Event $event, array $plan): bool
    {
        try {
            ReminderDelivery::query()->withoutGlobalScopes()->create([
                'event_id' => $event->id,
                'company_id' => $event->company_id,
                'user_id' => $plan['user_id'],
                'channel' => $plan['channel'],
                'offset_key' => $plan['offset'],
                'recipient' => $plan['recipient'],
                'status' => 'Programado',
                'scheduled_for' => $plan['scheduled_for'],
                'due_at' => $event->starts_at,
                'idempotency_key' => $plan['key'],
            ]);

            return true;
        } catch (QueryException $exception) {
            // Otra petición creó el mismo aviso a la vez: la clave única lo impide y basta con uno.
            if (str_contains(strtolower($exception->getMessage()), 'unique') || $exception->getCode() === '23000') {
                return false;
            }

            throw $exception;
        }
    }

    protected function slotOf(ReminderDelivery $row): string
    {
        $parts = explode('|', (string) $row->idempotency_key);

        return count($parts) >= 5 ? "{$parts[1]}|{$parts[2]}|{$parts[3]}" : '';
    }

    protected function inactiveReason(Event $event, Carbon $now): string
    {
        return match (true) {
            $event->cancelled_at !== null => $event->cancel_reason ?: 'El plazo se canceló.',
            $event->attended_at !== null => 'El plazo se marcó como atendido.',
            $event->completed => 'El plazo se completó.',
            ! $event->remindable => 'El evento no lleva recordatorios.',
            default => 'El plazo ya pasó.',
        };
    }

    /**
     * @param  array<int, mixed>  $days
     * @return array<int, int>
     */
    public static function normalizeDays(array $days): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', $days), fn (int $day) => $day >= 0 && $day <= 365)));
        rsort($days);

        return $days;
    }

    public static function normalizeTime(?string $time): ?string
    {
        return $time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : null;
    }
}
