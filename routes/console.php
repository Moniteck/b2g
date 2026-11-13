<?php

use App\Jobs\DispatchDueReminders;
use App\Services\Notifications\ReminderService;
use App\Services\System\SystemDiagnostics;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas del CRM B2G
|--------------------------------------------------------------------------
|
| Los recordatorios se generan por adelantado y se despachan cuando vence su
| fecha de envío, de modo que la bandeja y el correo se mantienen al día.
|
| En desarrollo las ejecuta `composer dev` (schedule:work); en producción, el
| cron `* * * * * php artisan schedule:run` (ver README).
|
*/

// Latido: la pantalla de diagnóstico lo usa para saber si el scheduler corre.
Schedule::call(fn () => SystemDiagnostics::recordSchedulerHeartbeat())
    ->everyMinute()
    ->name('latido-scheduler');

Schedule::job(new DispatchDueReminders)->everyMinute()->withoutOverlapping();

Schedule::call(function (ReminderService $reminders) {
    $reminders->scheduleUpcoming();
})->dailyAt('03:00')->name('programar-recordatorios')->withoutOverlapping();

Schedule::command('queue:prune-batches --hours=48')->daily();
