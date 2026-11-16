<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Pwa\WebPushService;
use Illuminate\Notifications\Notification;

/**
 * Canal de notificaciones de Laravel para Web Push. Cualquier módulo puede usarlo:
 *
 *   public function via($notifiable): array { return [WebPushChannel::class]; }
 *   public function toWebPush($notifiable): array { return ['title' => '…', 'body' => '…', 'url' => '/calendario']; }
 *
 * Sin claves VAPID no hace nada (no lanza errores).
 */
class WebPushChannel
{
    public function __construct(protected WebPushService $push) {}

    /**
     * @return array{sent: int, expired: int, failed: int}|null
     */
    public function send(object $notifiable, Notification $notification): ?array
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toWebPush') || ! $this->push->enabled()) {
            return null;
        }

        return $this->push->sendToUser($notifiable, $notification->toWebPush($notifiable));
    }
}
