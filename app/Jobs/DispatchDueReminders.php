<?php

namespace App\Jobs;

use App\Services\Notifications\ReminderDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Despacha los recordatorios vencidos (campana en el acto, correo a la cola).
 * El scheduler llama al despachador directamente cada minuto; este trabajo sirve para lanzarlo
 * desde la cola o a mano (`DispatchDueReminders::dispatchSync()`).
 */
class DispatchDueReminders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function handle(ReminderDispatcher $dispatcher): void
    {
        $dispatcher->dispatchDue();
    }
}
