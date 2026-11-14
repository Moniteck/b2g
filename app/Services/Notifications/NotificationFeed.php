<?php

namespace App\Services\Notifications;

use App\Models\Event;
use App\Models\ReminderDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Campana de la cabecera: avisos del canal «campana» ya enviados al usuario en una empresa.
 *
 * La consulta es barata (índice `user_id, company_id, channel, status, sent_at`) y se guarda unos
 * segundos por usuario y empresa; leer o recibir un aviso invalida esa caché.
 */
class NotificationFeed
{
    public const TTL_SECONDS = 15;

    public const ITEMS = 10;

    public static function cacheKey(int $userId, ?int $companyId): string
    {
        return "bell.{$userId}.".($companyId ?? 0);
    }

    public function forget(int $userId, ?int $companyId): void
    {
        Cache::forget(self::cacheKey($userId, $companyId));
    }

    /**
     * @return array{unread: int, items: array<int, array<string, mixed>>}
     */
    public function forUser(int $userId, ?int $companyId): array
    {
        if ($companyId === null) {
            return ['unread' => 0, 'items' => []];
        }

        return Cache::remember(self::cacheKey($userId, $companyId), self::TTL_SECONDS, function () use ($userId, $companyId) {
            $items = $this->query($userId, $companyId)
                ->with(['event' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'title', 'label', 'type', 'starts_at', 'all_day', 'reference_type', 'reference_id', 'contract_id'])])
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->limit(self::ITEMS)
                ->get(['id', 'event_id', 'offset_key', 'title', 'body', 'href', 'due_at', 'sent_at', 'read_at'])
                ->map(fn (ReminderDelivery $delivery) => $this->present($delivery))
                ->all();

            return [
                'unread' => $this->query($userId, $companyId)->whereNull('read_at')->count(),
                'items' => $items,
            ];
        });
    }

    /**
     * @return Builder<ReminderDelivery>
     */
    public function query(int $userId, int $companyId): Builder
    {
        return ReminderDelivery::query()->withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('channel', 'campana')
            ->where('status', 'Enviado');
    }

    public function markRead(int $userId, int $companyId, int $deliveryId): bool
    {
        $updated = $this->query($userId, $companyId)->whereKey($deliveryId)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        $exists = $updated > 0 || $this->query($userId, $companyId)->whereKey($deliveryId)->exists();
        $this->forget($userId, $companyId);

        return $exists;
    }

    public function markAllRead(int $userId, int $companyId): int
    {
        $updated = $this->query($userId, $companyId)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        $this->forget($userId, $companyId);

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ReminderDelivery $delivery): array
    {
        $event = $delivery->event;
        $dueAt = $delivery->due_at ?? $event?->starts_at;

        // Avisos sin plazo del calendario (p. ej. solicitudes de acceso) traen su propio texto.
        if ($event === null && $delivery->title !== null) {
            return ['id' => $delivery->id, 'title' => $delivery->title, 'body' => (string) $delivery->body, 'href' => $delivery->href, 'read_at' => $delivery->read_at?->toDateTimeString(), 'created_at' => ($delivery->sent_at ?? $delivery->created_at)?->toDateTimeString(), 'severity' => 'info', 'type' => null, 'offset' => $delivery->offsetLabel()];
        }

        return [
            'id' => $delivery->id,
            'title' => $event ? self::headline($event, $dueAt) : 'Recordatorio',
            'body' => $event ? trim(($event->label ?: $event->title).' · '.self::when($event, $dueAt)) : '',
            'href' => $event?->sourceHref(),
            'read_at' => $delivery->read_at?->toDateTimeString(),
            'created_at' => ($delivery->sent_at ?? $delivery->created_at)?->toDateTimeString(),
            'severity' => self::severity($dueAt),
            'type' => $event?->type,
            'offset' => $delivery->offsetLabel(),
        ];
    }

    /** «Mañana cierra el plazo de aclaraciones». */
    public static function headline(Event $event, $dueAt = null): string
    {
        $phrase = Event::SUBJECT_PHRASES[$event->type] ?? 'es el plazo';

        return self::dayWord($dueAt ?? $event->starts_at).' '.$phrase;
    }

    /** «Hoy», «Mañana», «En 3 días», «Hace 2 días». */
    public static function dayWord($dueAt): string
    {
        if ($dueAt === null) {
            return 'Pronto';
        }

        $days = (int) now()->startOfDay()->diffInDays($dueAt->copy()->startOfDay(), false);

        return match (true) {
            $days === 0 => 'Hoy',
            $days === 1 => 'Mañana',
            $days === -1 => 'Ayer',
            $days > 1 => "En {$days} días",
            default => 'Hace '.abs($days).' días',
        };
    }

    public static function when(Event $event, $dueAt = null): string
    {
        $dueAt ??= $event->starts_at;

        if ($dueAt === null) {
            return '';
        }

        return $event->all_day ? $dueAt->format('d/m/Y') : $dueAt->format('d/m/Y').', '.self::time($dueAt);
    }

    /** Hora en formato de la guía: «2:05 p. m.». */
    public static function time($moment): string
    {
        return $moment->format('g:i').' '.($moment->format('A') === 'AM' ? 'a. m.' : 'p. m.');
    }

    public static function severity($dueAt): string
    {
        if ($dueAt === null) {
            return 'info';
        }

        $days = (int) now()->startOfDay()->diffInDays($dueAt->copy()->startOfDay(), false);

        return match (true) {
            $days <= 0 => 'error',
            $days === 1 => 'warning',
            default => 'info',
        };
    }
}
