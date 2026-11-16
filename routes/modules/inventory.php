<?php

use App\Http\Controllers\Inventory\InventoryController;
use App\Http\Middleware\RedirectBrowserToScreen;
use Illuminate\Support\Facades\Route;

/* Inventario (Blessing) */

Route::get('/inventario', [InventoryController::class, 'index'])->name('inventory.index');
Route::get('/inventario/buscar', [InventoryController::class, 'search'])->name('inventory.search');
Route::get('/inventario/sincronizaciones', [InventoryController::class, 'runs'])->middleware(RedirectBrowserToScreen::class.':/inventario?panel=sincronizaciones')->name('inventory.runs');
Route::post('/inventario/sincronizar', [InventoryController::class, 'sync'])->middleware('throttle:6,1')->name('inventory.sync');
Route::get('/inventario/{inventoryItem}', [InventoryController::class, 'show'])->whereNumber('inventoryItem')->middleware(RedirectBrowserToScreen::class.':/inventario?ver={inventoryItem}')->name('inventory.show');

/* Calculadora de oferta */
