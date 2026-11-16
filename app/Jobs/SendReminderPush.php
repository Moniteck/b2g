<?php

namespace App\Jobs;

use App\Models\ReminderDelivery;
use App\Services\Notifications\NotificationFeed;
use App\Services\Pwa\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Envía un recordatorio como notificación push a todos los dispositivos del destinatario.
 *
 * - Push sin configurar (sin claves VAPID) o el usuario ya no tiene dispositivos: «Cancelado» con motivo.
 * - Al menos un dispositivo lo recibió: «Enviado».
 * - Ningún dispositivo lo recibió por error del servicio: «Fallido» (los caducados ya se borraron).
 * La entrega se bloquea durante el envío para que nunca salga dos veces.
 */
class SendReminderPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(WebPushService $push): void
    {
        DB::transaction(function () use ($push) {
            $delivery = ReminderDelivery::query()->withoutGlobalScopes()->lockForUpdate()->find($this->deliveryId);

            if ($delivery === null || in_array($delivery->status, ['Enviado', 'Cancelado', 'Fallido'], true)) {
                return;
            }

            $event = $delivery->event()->withoutGlobalScopes()->first();

            if ($event === null || ! $event->isActive()) {
                $delivery->forceFill(['status' => 'Cancelado', 'error' => 'El plazo ya no requiere avisos.'])->save();

                return;
            }

            if (! $push->enabled() || $delivery->user_id === null) {
                $delivery->forceFill(['status' => 'Cancelado', 'error' => 'Las notificaciones push no están configuradas.'])->save();

                return;
            }

            $dueAt = $delivery->due_at ?? $event->starts_at;
            $result = $push->sendToUser($delivery->user_id, [
                'title' => NotificationFeed::headline($event, $dueAt).': '.($event->label ?: $event->title),
                'body' => NotificationFeed::when($event, $dueAt).' · '.$delivery->offsetLabel(),
                'url' => $event->sourceHref(),
                'tag' => 'evento-'.$event->id,
            ]);

            $attempts = $delivery->attempts + 1;

            if ($result['sent'] > 0) {
                $delivery->forceFill(['status' => 'Enviado', 'sent_at' => now(), 'attempts' => $attempts, 'error' => null])->save();
            } elseif ($result['failed'] > 0) {
                $delivery->forceFill(['status' => 'Fallido', 'attempts' => $attempts, 'error' => 'El servicio push no aceptó el aviso.'])->save();
            } else {
                $delivery->forceFill(['status' => 'Cancelado', 'attempts' => $attempts, 'error' => 'La persona ya no tiene dispositivos con avisos activos.'])->save();
            }
        });
    }
}
