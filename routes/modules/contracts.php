<?php

use App\Http\Controllers\ContractController;
use Illuminate\Support\Facades\Route;

/* Post-adjudicación: contratos, entregas y seguimiento */

Route::get('/post-adjudicacion', [ContractController::class, 'index'])->name('contracts.index');
Route::post('/post-adjudicacion', [ContractController::class, 'store'])->name('contracts.store');

Route::put('/post-adjudicacion/{contract}', [ContractController::class, 'update'])->name('contracts.update');
Route::delete('/post-adjudicacion/{contract}', [ContractController::class, 'destroy'])->name('contracts.destroy');
Route::post('/post-adjudicacion/{contract}/ordenes', [ContractController::class, 'order'])->name('contracts.orders.store');
Route::post('/post-adjudicacion/{contract}/certificados', [ContractController::class, 'certificate'])->name('contracts.certificates.store');
Route::post('/post-adjudicacion/{contract}/entregas', [ContractController::class, 'delivery'])->name('contracts.deliveries.store');
Route::post('/post-adjudicacion/{contract}/seguimientos', [ContractController::class, 'followUp'])->name('contracts.followups.store');
Route::patch('/post-adjudicacion/{contract}/completar', [ContractController::class, 'complete'])->name('contracts.complete');
