<?php

namespace App\Jobs;

use App\Mail\ReminderMail;
use App\Models\ReminderDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía un recordatorio por correo. Reintenta con espera creciente; al agotar los intentos la
 * entrega queda «Fallido» con el motivo, visible en el detalle del evento y en «Mis recordatorios».
 */
class SendReminderEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $failure = null;

        DB::transaction(function () use (&$failure) {
            // Bloqueo de la fila: si el mismo trabajo corre dos veces, el segundo ve «Enviado» y sale.
            $delivery = ReminderDelivery::query()->withoutGlobalScopes()->lockForUpdate()->find($this->deliveryId);

            if ($delivery === null || in_array($delivery->status, ['Enviado', 'Cancelado', 'Fallido'], true)) {
                return;
            }

            $event = $delivery->event()->withoutGlobalScopes()->first();

            if ($event === null || ! $event->isActive()) {
                $delivery->forceFill(['status' => 'Cancelado', 'error' => 'El plazo ya no requiere avisos.'])->save();

                return;
            }

            try {
                Mail::to($delivery->recipient)->send(new ReminderMail($delivery, $event));
            } catch (Throwable $exception) {
                // Se registra el intento dentro de la misma transacción (sin relanzar aquí, para no
                // deshacerlo) y se relanza fuera para que la cola reintente con espera.
                $attempts = $delivery->attempts + 1;
                $delivery->forceFill([
                    'status' => $attempts >= $this->tries ? 'Fallido' : 'Encolado',
                    'attempts' => $attempts,
                    'error' => mb_substr($exception->getMessage(), 0, 1000),
                ])->save();

                Log::warning('Falló el envío del recordatorio por correo.', ['delivery_id' => $delivery->id, 'intento' => $attempts, 'error' => $exception->getMessage()]);
                $failure = $exception;

                return;
            }

            $delivery->forceFill(['status' => 'Enviado', 'sent_at' => now(), 'attempts' => $delivery->attempts + 1, 'error' => null])->save();
        });

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ReminderDelivery::query()->withoutGlobalScopes()
            ->whereKey($this->deliveryId)
            ->whereNotIn('status', ['Enviado', 'Cancelado'])
            ->update(['status' => 'Fallido', 'error' => mb_substr((string) $exception?->getMessage(), 0, 1000), 'updated_at' => now()]);
    }
}
