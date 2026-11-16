<?php

use App\Http\Controllers\ContractController;
use App\Http\Middleware\RedirectBrowserToScreen;
use Illuminate\Support\Facades\Route;

/* Post-adjudicación: contratos, plazos, entregas, seguimiento y feriados */

Route::get('/post-adjudicacion', [ContractController::class, 'index'])->name('contracts.index');
Route::post('/post-adjudicacion', [ContractController::class, 'store'])->name('contracts.store');

// Rutas estáticas antes de las que llevan {contract}.
Route::get('/post-adjudicacion/plazo', [ContractController::class, 'term'])->middleware(RedirectBrowserToScreen::class.':/post-adjudicacion')->name('contracts.term');
Route::get('/post-adjudicacion/clientes', [ContractController::class, 'clients'])->middleware(RedirectBrowserToScreen::class.':/post-adjudicacion')->name('contracts.clients');
Route::get('/post-adjudicacion/feriados', [ContractController::class, 'holidays'])->middleware(RedirectBrowserToScreen::class.':/post-adjudicacion?panel=feriados')->name('contracts.holidays.index');
Route::post('/post-adjudicacion/feriados', [ContractController::class, 'storeHoliday'])->name('contracts.holidays.store');
Route::put('/post-adjudicacion/feriados/{holiday}', [ContractController::class, 'updateHoliday'])->whereNumber('holiday')->name('contracts.holidays.update');
Route::delete('/post-adjudicacion/feriados/{holiday}', [ContractController::class, 'destroyHoliday'])->whereNumber('holiday')->name('contracts.holidays.destroy');

Route::whereNumber('contract')->group(function () {
    Route::get('/post-adjudicacion/{contract}', [ContractController::class, 'show'])->name('contracts.show');
    Route::put('/post-adjudicacion/{contract}', [ContractController::class, 'update'])->name('contracts.update');
    Route::delete('/post-adjudicacion/{contract}', [ContractController::class, 'destroy'])->name('contracts.destroy');
    Route::patch('/post-adjudicacion/{contract}/estado', [ContractController::class, 'status'])->name('contracts.status');
    Route::patch('/post-adjudicacion/{contract}/completar', [ContractController::class, 'complete'])->name('contracts.complete');

    Route::post('/post-adjudicacion/{contract}/entregas', [ContractController::class, 'storeDelivery'])->name('contracts.deliveries.store');
    Route::put('/post-adjudicacion/{contract}/entregas/{delivery}', [ContractController::class, 'updateDelivery'])->whereNumber('delivery')->name('contracts.deliveries.update');
    Route::post('/post-adjudicacion/{contract}/entregas/{delivery}/entregada', [ContractController::class, 'deliver'])->whereNumber('delivery')->name('contracts.deliveries.deliver');
    Route::delete('/post-adjudicacion/{contract}/entregas/{delivery}', [ContractController::class, 'destroyDelivery'])->whereNumber('delivery')->name('contracts.deliveries.destroy');

    Route::post('/post-adjudicacion/{contract}/seguimientos', [ContractController::class, 'followUp'])->name('contracts.followups.store');
    Route::delete('/post-adjudicacion/{contract}/seguimientos/{followUp}', [ContractController::class, 'destroyFollowUp'])->whereNumber('followUp')->name('contracts.followups.destroy');
});
