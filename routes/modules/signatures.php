<?php

use App\Http\Controllers\SignatureController;
use App\Http\Controllers\SigningAssetController;
use Illuminate\Support\Facades\Route;

/* Mi firma y sello (personales del usuario autenticado) */

Route::get('/firmas', [SigningAssetController::class, 'index'])->name('signatures.index');
Route::get('/firmas/estado', [SigningAssetController::class, 'state'])->name('signatures.state');
Route::post('/firmas/activos', [SigningAssetController::class, 'store'])->name('signatures.assets.store');
Route::get('/firmas/activos/{type}/imagen', [SigningAssetController::class, 'image'])->whereIn('type', ['firma', 'sello'])->name('signatures.assets.image');
Route::delete('/firmas/activos/{type}', [SigningAssetController::class, 'destroy'])->whereIn('type', ['firma', 'sello'])->name('signatures.assets.destroy');

/* Firmar un documento del sistema o un PDF suelto */

Route::post('/firmas/firmar', [SignatureController::class, 'sign'])->middleware('throttle:20,1')->name('signatures.sign');
Route::post('/firmas/firmar-archivo', [SignatureController::class, 'signUpload'])->middleware('throttle:20,1')->name('signatures.sign-upload');
Route::get('/firmas/documentos', [SignatureController::class, 'documents'])->name('signatures.documents');
Route::get('/firmas/documentos/{document}/vista', [SignatureController::class, 'preview'])->name('signatures.documents.preview');
Route::get('/firmas/aplicadas/{application}/descargar', [SignatureController::class, 'download'])->name('signatures.applications.download');
Route::get('/firmas/aplicadas/{application}/vista', [SignatureController::class, 'view'])->name('signatures.applications.view');
