<?php

use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractFollowUp;
use App\Models\Document;
use App\Models\Opportunity;
use App\Models\ReminderDelivery;
use App\Models\Tender;
use App\Services\Notifications\CalendarSyncService;
use App\Services\Notifications\DailyDigestService;
use App\Services\Notifications\ReminderDispatcher;
use App\Services\Notifications\ReminderService;
use App\Services\System\SystemDiagnostics;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas del CRM B2G
|--------------------------------------------------------------------------
|
| Los recordatorios se programan al crear o editar cada plazo (observadores) y se despachan cada
| minuto; la sincronización nocturna es solo un respaldo. Todo corre sin empresa activa: cada
| servicio trabaja con el `company_id` de sus registros.
|
| En desarrollo las ejecuta `composer dev` (schedule:work); en producción, el cron
| `* * * * * php artisan schedule:run` (ver README). El correo sale por la cola: hace falta un
| worker (`php artisan queue:work`).
|
*/

Artisan::command('recordatorios:despachar', function (ReminderDispatcher $dispatcher) {
    $result = $dispatcher->dispatchDue();
    $this->info("Campana: {$result['campana']} · Correo encolado: {$result['correo']} · Cancelados: {$result['cancelados']}");
})->purpose('Envía los recordatorios cuya hora ya llegó (campana y correo).');

Artisan::command('calendario:sincronizar {--company= : Solo esta empresa}', function (CalendarSyncService $calendar, ReminderService $reminders) {
    $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
    $count = 0;

    foreach ([Tender::class, Opportunity::class, Contract::class, Document::class] as $model) {
        $model::query()->withoutGlobalScopes()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->chunkById(200, function ($records) use ($calendar, &$count) {
                foreach ($records as $record) {
                    $calendar->sync($record);
                    $count++;
                }
            });
    }

    // Entregas y seguimientos sin contrato vivo (huérfanos): sus eventos se cancelan.
    foreach ([ContractDelivery::class, ContractFollowUp::class] as $model) {
        $model::query()->whereNotIn('contract_id', Contract::query()->withoutGlobalScopes()->select('id'))
            ->chunkById(200, fn ($records) => $records->each(fn ($record) => $calendar->removed($record)));
    }

    $created = $reminders->scheduleUpcoming(60, $companyId);
    $this->info("Registros sincronizados: {$count}. Avisos nuevos: {$created}.");
})->purpose('Regenera los eventos derivados del calendario y reprograma sus avisos.');

Artisan::command('recordatorios:resumen', function (DailyDigestService $digest) {
    $this->info('Resúmenes enviados: '.$digest->sendAll());
})->purpose('Envía el resumen diario de plazos (hoy y próximos 7 días).');

// Latido: la pantalla de diagnóstico lo usa para saber si el scheduler corre.
Schedule::call(fn () => SystemDiagnostics::recordSchedulerHeartbeat())
    ->everyMinute()
    ->name('latido-scheduler');

// Despacho: corre en el propio scheduler (la campana no depende del worker; el correo sí).
Schedule::call(fn (ReminderDispatcher $dispatcher) => $dispatcher->dispatchDue())
    ->everyMinute()
    ->name('despachar-recordatorios')
    ->withoutOverlapping(5);

// Respaldo diario: plazos de todos los orígenes y avisos de los próximos 60 días.
Schedule::command('calendario:sincronizar')
    ->dailyAt('02:30')
    ->timezone('America/Tegucigalpa')
    ->name('sincronizar-calendario')
    ->withoutOverlapping(60);

Schedule::command('recordatorios:resumen')
    ->dailyAt('07:00')
    ->timezone('America/Tegucigalpa')
    ->name('resumen-diario')
    ->withoutOverlapping(30);

// Limpieza: avisos cancelados de hace más de 90 días (los enviados y fallidos se conservan).
Schedule::call(fn () => ReminderDelivery::query()->withoutGlobalScopes()
    ->where('status', 'Cancelado')
    ->where('updated_at', '<', now()->subDays(90))
    ->delete())
    ->dailyAt('03:30')
    ->timezone('America/Tegucigalpa')
    ->name('limpiar-recordatorios');

Schedule::command('queue:prune-batches --hours=48')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
