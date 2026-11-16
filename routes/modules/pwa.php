<?php

use App\Http\Controllers\Pwa\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

/* Aplicación instalable: suscripciones Web Push de cada dispositivo (Mi cuenta) */

Route::post('/pwa/push/suscripciones', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('pwa.push.subscribe');
Route::delete('/pwa/push/suscripciones', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:20,1')->name('pwa.push.unsubscribe');
Route::post('/pwa/push/prueba', [PushSubscriptionController::class, 'test'])->middleware('throttle:5,1')->name('pwa.push.test');
