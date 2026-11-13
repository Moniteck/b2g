<?php

use App\Http\Controllers\SpecializedToolsController;
use Illuminate\Support\Facades\Route;

/* Calendario */

Route::get('/calendario', [SpecializedToolsController::class, 'calendar'])->name('calendar.index');
Route::post('/calendario', [SpecializedToolsController::class, 'saveEvent'])->name('calendar.store');
Route::put('/calendario/{event}', [SpecializedToolsController::class, 'saveEvent'])->name('calendar.update');
Route::delete('/calendario/{event}', [SpecializedToolsController::class, 'deleteEvent'])->name('calendar.destroy');

/* Plantillas de notificación */

Route::get('/notificaciones', [SpecializedToolsController::class, 'notifications'])->name('notifications.index');
Route::post('/notificaciones', [SpecializedToolsController::class, 'saveNotification'])->name('notifications.store');
Route::put('/notificaciones/{template}', [SpecializedToolsController::class, 'saveNotification'])->name('notifications.update');
Route::delete('/notificaciones/{template}', [SpecializedToolsController::class, 'deleteNotification'])->name('notifications.destroy');
