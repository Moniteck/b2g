<?php

use App\Http\Controllers\SpecializedToolsController;
use Illuminate\Support\Facades\Route;

/* Inventario (Blessing) y calculadora de oferta */

Route::get('/inventario', [SpecializedToolsController::class, 'inventory'])->name('inventory.index');

Route::get('/calculadora-oferta', [SpecializedToolsController::class, 'offerCalculator'])->name('offer-calculator.index');
Route::post('/calculadora-oferta/calcular', [SpecializedToolsController::class, 'calculateOffer'])->name('offer-calculator.calculate');
