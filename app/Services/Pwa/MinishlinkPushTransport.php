<?php

namespace App\Services\Pwa;

use App\Models\PushSubscription;
use GuzzleHttp\Client as GuzzleClient;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * Envío real con `minishlink/web-push` (cifrado aes128gcm del RFC 8291 y firma VAPID del RFC 8292).
 *
 * Se eligió esta librería porque es la implementación PHP de referencia (MIT, mantenida desde 2015,
 * la misma que usa `laravel-notification-channels/webpush` por dentro) y se usa directamente para
 * no sumar una capa más. Necesita OpenSSL con curvas EC: en Windows, `OPENSSL_CONF` debe apuntar a
 * un `openssl.cnf` (en Linux ya viene configurado).
 */
class MinishlinkPushTransport implements PushTransport
{
    protected ?WebPush $client = null;

    public function __construct(protected ?ClientInterface $http = null) {}

    public function send(PushSubscription $subscription, string $payload, array $options = []): array
    {
        $report = $this->client()->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
            ]),
            $payload,
            array_filter($options, fn ($value) => $value !== null),
        );

        if ($report->isSuccess()) {
            return ['status' => 'sent', 'reason' => null];
        }

        return ['status' => $report->isSubscriptionExpired() ? 'expired' : 'failed', 'reason' => $report->getReason()];
    }

    protected function client(): WebPush
    {
        return $this->client ??= new WebPush(
            ['VAPID' => [
                'subject' => (string) config('pwa.vapid.subject'),
                'publicKey' => (string) config('pwa.vapid.public_key'),
                'privateKey' => (string) config('pwa.vapid.private_key'),
            ]],
            [],
            $this->http ?? new GuzzleClient(['timeout' => 10, 'connect_timeout' => 5, 'http_errors' => false]),
        );
    }
}
