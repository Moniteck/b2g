<?php

namespace App\Services\Pwa;

use App\Models\PushSubscription;

/**
 * Entrega de un aviso ya serializado a un servicio push. La implementación real es
 * `MinishlinkPushTransport`; las pruebas enlazan una falsa en el contenedor.
 */
interface PushTransport
{
    /**
     * @param  array{TTL?: int, urgency?: string, topic?: string|null}  $options
     * @return array{status: 'sent'|'expired'|'failed', reason: string|null}
     */
    public function send(PushSubscription $subscription, string $payload, array $options = []): array;
}
