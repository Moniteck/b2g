<?php

namespace App\Services\Pwa;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envío de notificaciones Web Push (VAPID) a los dispositivos de un usuario.
 *
 *   app(WebPushService::class)->sendToUser($user, ['title' => 'Plazo', 'body' => '…', 'url' => '/calendario']);
 *
 * - Sin las claves de `config/pwa.php`, `enabled()` es falso y todo se omite sin errores.
 * - Las suscripciones que el servicio push declara caducadas (404 o 410) se borran al instante;
 *   tras `MAX_FAILURES` fallos seguidos de otro tipo, también.
 */
class WebPushService
{
    public const MAX_FAILURES = 5;

    protected PushTransport $transport;

    public function __construct(?PushTransport $transport = null)
    {
        $this->transport = $transport ?? new MinishlinkPushTransport;
    }

    public function enabled(): bool
    {
        return filled(config('pwa.vapid.public_key'))
            && filled(config('pwa.vapid.private_key'))
            && filled(config('pwa.vapid.subject'));
    }

    public function publicKey(): ?string
    {
        return $this->enabled() ? (string) config('pwa.vapid.public_key') : null;
    }

    /**
     * Envía a todos los dispositivos del usuario.
     *
     * @param  array{title: string, body?: string, url?: string, tag?: string}  $message
     * @return array{sent: int, expired: int, failed: int}
     */
    public function sendToUser(User|int $user, array $message): array
    {
        $result = ['sent' => 0, 'expired' => 0, 'failed' => 0];

        if (! $this->enabled()) {
            return $result;
        }

        PushSubscription::query()->where('user_id', $user instanceof User ? $user->id : $user)->orderBy('id')->get()
            ->each(function (PushSubscription $subscription) use ($message, &$result) {
                $result[$this->sendTo($subscription, $message)]++;
            });

        return $result;
    }

    /**
     * @param  array{title: string, body?: string, url?: string, tag?: string}  $message
     * @return 'sent'|'expired'|'failed'
     */
    public function sendTo(PushSubscription $subscription, array $message): string
    {
        $payload = $this->payload($message);

        try {
            $outcome = $this->transport->send(
                $subscription,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ['TTL' => (int) config('pwa.ttl', 86400), 'urgency' => 'high', 'topic' => $this->topic($payload['tag'])],
            );
        } catch (Throwable $exception) {
            $outcome = ['status' => 'failed', 'reason' => $exception->getMessage()];
        }

        if ($outcome['status'] === 'sent') {
            $subscription->forceFill(['last_success_at' => now(), 'failures' => 0])->save();

            return 'sent';
        }

        if ($outcome['status'] === 'expired') {
            $subscription->delete();

            return 'expired';
        }

        $this->markFailure($subscription, (string) $outcome['reason']);

        return 'failed';
    }

    /**
     * Carga útil que lee `public/sw.js`. Solo se admiten enlaces internos (rutas que empiezan con «/»).
     *
     * @param  array{title: string, body?: string, url?: string, tag?: string}  $message
     * @return array{title: string, body: string, url: string, tag: string|null}
     */
    public function payload(array $message): array
    {
        $url = (string) ($message['url'] ?? '/');
        if (! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            $url = '/';
        }

        return [
            'title' => Str::limit(trim((string) $message['title']), 120, '…'),
            'body' => Str::limit(trim((string) ($message['body'] ?? '')), 240, '…'),
            'url' => $url,
            'tag' => isset($message['tag']) ? Str::limit((string) $message['tag'], 64, '') : null,
        ];
    }

    /** El «topic» hace que el servicio push reemplace un aviso aún no entregado del mismo tema. */
    protected function topic(?string $tag): ?string
    {
        $topic = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $tag), 0, 32);

        return $topic === '' ? null : $topic;
    }

    protected function markFailure(PushSubscription $subscription, string $reason): void
    {
        Log::warning('Falló un envío push.', ['subscription_id' => $subscription->id, 'error' => mb_substr($reason, 0, 300)]);

        $failures = $subscription->failures + 1;

        if ($failures >= self::MAX_FAILURES) {
            $subscription->delete();

            return;
        }

        $subscription->forceFill(['failures' => $failures, 'last_failure_at' => now()])->save();
    }
}
