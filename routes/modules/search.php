<?php

use App\Http\Controllers\SearchController;
use App\Http\Controllers\SupportModeController;
use Illuminate\Support\Facades\Route;

/* Rutas del marco: búsqueda global (paleta ⌘K), modo soporte de la raíz y página índice de
   Administración. */

Route::get('/buscar', SearchController::class)->middleware('throttle:120,1')->name('search');

Route::get('/soporte/estado', [SupportModeController::class, 'status'])->name('support.status');
Route::post('/soporte/entrar', [SupportModeController::class, 'enter'])->name('support.enter');
Route::post('/soporte/salir', [SupportModeController::class, 'leave'])->name('support.leave');

// Las tarjetas se filtran por permiso en la página (cada destino sigue autorizando su ruta).
Route::inertia('/administracion', 'Admin/Index')->name('admin.index');

// Vista previa de las páginas de error (solo en local, para la validación visual).
if (app()->isLocal()) {
    Route::get('/errores/{status}', fn (int $status) => abort($status))->whereIn('status', ['403', '404', '419', '429', '500', '503'])->name('errors.preview');
}
