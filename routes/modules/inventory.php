<?php

use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OfferCalculatorController;
use Illuminate\Support\Facades\Route;

/* Inventario (Blessing) */

Route::get('/inventario', [InventoryController::class, 'index'])->name('inventory.index');
Route::get('/inventario/buscar', [InventoryController::class, 'search'])->name('inventory.search');
Route::get('/inventario/sincronizaciones', [InventoryController::class, 'runs'])->middleware(\App\Http\Middleware\RedirectBrowserToScreen::class.':/inventario?panel=sincronizaciones')->name('inventory.runs');
Route::post('/inventario/sincronizar', [InventoryController::class, 'sync'])->middleware('throttle:6,1')->name('inventory.sync');
Route::get('/inventario/{inventoryItem}', [InventoryController::class, 'show'])->whereNumber('inventoryItem')->middleware(\App\Http\Middleware\RedirectBrowserToScreen::class.':/inventario?ver={inventoryItem}')->name('inventory.show');

/* Calculadora de oferta */

Route::get('/calculadora-oferta', [OfferCalculatorController::class, 'index'])->name('offer-calculator.index');
Route::post('/calculadora-oferta/calcular', [OfferCalculatorController::class, 'calculate'])->name('offer-calculator.calculate');
Route::post('/calculadora-oferta/pdf', [OfferCalculatorController::class, 'pdf'])->middleware(['throttle:exports', 'audit.export'])->name('offer-calculator.pdf');
