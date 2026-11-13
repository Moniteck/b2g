<?php

namespace App\Jobs;

use App\Models\ReminderDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Encola los recordatorios cuya fecha de envio ya venció.
 */
class DispatchDueReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        ReminderDelivery::query()
            ->withoutGlobalScopes()
            ->whereIn('status', ['Programado', 'Encolado'])
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->limit(500)
            ->each(function (ReminderDelivery $delivery) {
                if ($delivery->channel === 'correo') {
                    SendReminderEmail::dispatch($delivery->id)->after(now());
                }

                $delivery->update(['status' => 'Encolado']);
            });
    }
}
