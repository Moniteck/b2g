<?php

use App\Http\Controllers\AccessRequestController;
use Illuminate\Support\Facades\Route;

// Revisión de solicitudes de acceso desde Usuarios (el envío público vive en routes/web.php, grupo `guest`).
Route::post('/configuracion/usuarios/solicitudes/{accessRequest}/aprobar', [AccessRequestController::class, 'approve'])->whereNumber('accessRequest')->name('access-requests.approve');
Route::post('/configuracion/usuarios/solicitudes/{accessRequest}/rechazar', [AccessRequestController::class, 'reject'])->whereNumber('accessRequest')->name('access-requests.reject');
