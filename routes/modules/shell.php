<?php

use App\Http\Controllers\Shell\AccountController;
use App\Http\Controllers\Shell\ActiveCompanyController;
use App\Http\Controllers\Shell\SearchController;
use App\Http\Controllers\Shell\SupportModeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/* Rutas del marco: búsqueda global (paleta ⌘K), modo soporte de la raíz, empresa activa, Mi cuenta,
   vista previa de errores y la ruta de respaldo (404). */

Route::get('/buscar', SearchController::class)->middleware('throttle:120,1')->name('search');

Route::get('/soporte/estado', [SupportModeController::class, 'status'])->name('support.status');
Route::post('/soporte/entrar', [SupportModeController::class, 'enter'])->name('support.enter');
Route::post('/soporte/salir', [SupportModeController::class, 'leave'])->name('support.leave');

/* Empresa activa (selector de la cabecera) */

Route::post('/empresa-activa', ActiveCompanyController::class)->name('companies.switch');

/* Mi cuenta */

Route::get('/mi-cuenta', [AccountController::class, 'show'])->name('account.show');
Route::put('/mi-cuenta', [AccountController::class, 'update'])->name('account.update');
Route::put('/mi-cuenta/contrasena', [AccountController::class, 'password'])->middleware('throttle:10,1')->name('account.password');

// Vista previa de las páginas de error (solo en local, para la validación visual).
if (app()->isLocal()) {
    Route::get('/errores/{status}', fn (int $status) => abort($status))->whereIn('status', ['403', '404', '419', '429', '500', '503'])->name('errors.preview');
}

// Ruta inexistente (cualquier método): 404 propio con la sesión activa (con el marco). Laravel evalúa
// `fallback` al final; con `any` un POST a una URL inexistente da 404, y uno a una URL que solo
// existe con otro método sigue dando 405.
Route::any('{fallbackPlaceholder}', function (Request $request) {
    // La URL existe con otro método (p. ej. POST a una ruta solo GET): 405, como sin esta ruta.
    $otherMethod = collect(Route::getRoutes()->getRoutes())
        ->contains(fn (Illuminate\Routing\Route $route) => ! $route->isFallback && $route->matches($request, false));

    abort($otherMethod ? 405 : 404);
})->where('fallbackPlaceholder', '(?!api/|mcp|oauth|\.well-known).*')->fallback()->name('fallback');
