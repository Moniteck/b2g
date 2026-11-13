<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\SigningAssetController;
use Illuminate\Support\Facades\Route;

/* Firma y sello */

Route::get('/firmas', [SigningAssetController::class, 'index'])->name('signing-assets.index');
Route::post('/firmas', [SigningAssetController::class, 'store'])->name('signing-assets.store');
Route::put('/firmas/{asset}', [SigningAssetController::class, 'update'])->name('signing-assets.update');
Route::patch('/firmas/{asset}/revocar', [SigningAssetController::class, 'revoke'])->name('signing-assets.revoke');
Route::delete('/firmas/{asset}', [SigningAssetController::class, 'destroy'])->name('signing-assets.destroy');

/* Firmar un documento */

Route::post('/documentos/{document}/autorizacion', [DocumentController::class, 'issueAuthorization'])->name('documents.authorize');
Route::post('/documentos/{document}/firmar', [DocumentController::class, 'sign'])->name('documents.sign');
