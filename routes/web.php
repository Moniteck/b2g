<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web del CRM B2G
|--------------------------------------------------------------------------
|
| Este archivo solo contiene el acceso (login/logout). Las rutas de cada
| módulo viven en `routes/modules/<módulo>.php` y se cargan solas dentro del
| grupo `auth`: para añadir rutas a un módulo se edita SU archivo, y un módulo
| nuevo solo necesita crear el suyo (no hay que registrarlo en ningún sitio).
|
| Dentro de un archivo de módulo no hace falta repetir `auth`. Declara las
| rutas estáticas (`/cotizaciones/export/pdf`) antes de las que llevan
| parámetro (`/cotizaciones/{quote}`) o limita el parámetro con `whereNumber`.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    $modules = glob(__DIR__.'/modules/*.php') ?: [];
    sort($modules);

    foreach ($modules as $module) {
        require $module;
    }
});
