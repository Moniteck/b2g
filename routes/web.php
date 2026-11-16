<?php

use App\Http\Controllers\Auth\AccessRequestController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
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
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
    // «Crear cuenta»: solicitud de acceso pendiente de aprobación (límite por IP aquí y por correo en el controlador).
    Route::get('/solicitar-acceso', [AccessRequestController::class, 'create'])->name('access-request.create');
    Route::post('/solicitar-acceso', [AccessRequestController::class, 'store'])->middleware('throttle:10,10,solicitud-acceso')->name('access-request.store');
});

// Recuperar la contraseña por correo (enlace con token de 60 minutos).
Route::middleware('guest')->group(function () {
    Route::post('/olvide-contrasena', [PasswordResetController::class, 'send'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/restablecer-contrasena/{token}', [PasswordResetController::class, 'show'])->name('password.reset');
    Route::post('/restablecer-contrasena', [PasswordResetController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');
});

// Confirmación del correo de una solicitud de acceso (enlace firmado de 24 horas; sin `guest`
// para que funcione aunque haya otra sesión abierta en el navegador).
Route::get('/solicitar-acceso/confirmar/{accessRequest}/{hash}', [AccessRequestController::class, 'verify'])
    ->whereNumber('accessRequest')->middleware('throttle:20,1')->name('access-request.verify');

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    $modules = glob(__DIR__.'/modules/*.php') ?: [];
    sort($modules);

    foreach ($modules as $module) {
        require $module;
    }
});
