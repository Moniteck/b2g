<?php

use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\Calendar\NotificationController;
use App\Http\Middleware\RedirectBrowserToScreen;
use Illuminate\Support\Facades\Route;

/* Calendario de plazos (rutas estáticas antes de las que llevan {event}) */

Route::get('/calendario', [CalendarController::class, 'index'])->name('calendar.index');
Route::get('/calendario/eventos', [CalendarController::class, 'events'])->middleware(RedirectBrowserToScreen::class.':/calendario')->name('calendar.events');
Route::put('/calendario/reglas', [CalendarController::class, 'saveRules'])->name('calendar.rules.update');
Route::post('/calendario', [CalendarController::class, 'store'])->name('calendar.store');
Route::get('/calendario/{event}', [CalendarController::class, 'show'])->whereNumber('event')->middleware(RedirectBrowserToScreen::class.':/calendario?evento={event}')->name('calendar.show');
Route::put('/calendario/{event}', [CalendarController::class, 'update'])->whereNumber('event')->name('calendar.update');
Route::delete('/calendario/{event}', [CalendarController::class, 'destroy'])->whereNumber('event')->name('calendar.destroy');
Route::post('/calendario/{event}/atendido', [CalendarController::class, 'attend'])->whereNumber('event')->name('calendar.attend');
Route::delete('/calendario/{event}/atendido', [CalendarController::class, 'reopen'])->whereNumber('event')->name('calendar.reopen');

/* Campana, «Mis recordatorios» y plantillas de notificación */

Route::get('/notificaciones', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('/notificaciones/leer-todas', [NotificationController::class, 'readAll'])->name('notifications.read-all');
Route::get('/notificaciones/plantillas', [NotificationController::class, 'templates'])->name('notifications.templates');
Route::post('/notificaciones/plantillas', [NotificationController::class, 'storeTemplate'])->name('notifications.templates.store');
Route::put('/notificaciones/plantillas/{template}', [NotificationController::class, 'updateTemplate'])->whereNumber('template')->name('notifications.templates.update');
Route::delete('/notificaciones/plantillas/{template}', [NotificationController::class, 'destroyTemplate'])->whereNumber('template')->name('notifications.templates.destroy');
Route::post('/notificaciones/{delivery}/leer', [NotificationController::class, 'read'])->whereNumber('delivery')->name('notifications.read');
