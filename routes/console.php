<?php

use App\Jobs\DispatchDueReminders;
use App\Services\Notifications\ReminderService;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas del CRM B2G
|--------------------------------------------------------------------------
|
| Los recordatorios se generan por adelantado y se despachan cuando vence su
| fecha de envio, de modo que la bandeja y el correo se mantienen al dia.
|
*/

Schedule::job(new DispatchDueReminders)->everyMinute()->withoutOverlapping();

Schedule::call(function (ReminderService $reminders) {
    $reminders->scheduleUpcoming();
})->dailyAt('03:00')->name('programar-recordatorios')->withoutOverlapping();

Schedule::command('queue:prune-batches --hours=48')->daily();
