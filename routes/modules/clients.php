<?php

use App\Http\Controllers\ClientController;
use Illuminate\Support\Facades\Route;

/* Base de clientes: los clientes nacen de una adjudicación, no hay alta manual */

Route::get('/clientes', [ClientController::class, 'index'])->name('clients.index');
Route::get('/clientes/export/csv', [ClientController::class, 'export'])->name('clients.export');
Route::get('/clientes/{client}', [ClientController::class, 'show'])->whereNumber('client')->name('clients.show');
Route::put('/clientes/{client}', [ClientController::class, 'update'])->whereNumber('client')->name('clients.update');
Route::delete('/clientes/{client}', [ClientController::class, 'destroy'])->whereNumber('client')->name('clients.destroy');
