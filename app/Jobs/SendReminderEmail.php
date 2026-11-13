<?php

namespace App\Jobs;

use App\Mail\ReminderMail;
use App\Models\ReminderDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envia un recordatorio por correo y actualiza el estado de la entrega.
 */
class SendReminderEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = ReminderDelivery::with('event')->find($this->deliveryId);

        if (! $delivery || $delivery->status === 'Enviado' || $delivery->status === 'Cancelado') {
            return;
        }

        if (! $delivery->event) {
            $delivery->update(['status' => 'Cancelado', 'error' => 'El evento origen ya no existe.']);

            return;
        }

        try {
            Mail::to($delivery->recipient)->send(new ReminderMail($delivery->event));

            $delivery->update([
                'status' => 'Enviado',
                'sent_at' => now(),
                'attempts' => $delivery->attempts + 1,
                'error' => null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Fallo el envio del recordatorio por correo.', [
                'delivery_id' => $delivery->id,
                'error' => $exception->getMessage(),
            ]);

            $delivery->update([
                'status' => $delivery->attempts + 1 >= $this->tries ? 'Fallido' : 'Encolado',
                'attempts' => $delivery->attempts + 1,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
