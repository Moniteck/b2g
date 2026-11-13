<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

/* Directorio documental */

Route::get('/documentos', [DocumentController::class, 'index'])->name('documents.index');
Route::post('/documentos', [DocumentController::class, 'store'])->name('documents.store');

Route::put('/documentos/{document}', [DocumentController::class, 'update'])->name('documents.update');
Route::delete('/documentos/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
Route::get('/documentos/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
Route::get('/documentos/{document}/vista', [DocumentController::class, 'preview'])->name('documents.preview');
Route::post('/documentos/{document}/replace', [DocumentController::class, 'replace'])->name('documents.replace');
Route::get('/documentos/{document}/versiones', [DocumentController::class, 'versions'])->name('documents.versions');
Route::patch('/documentos/{document}/renovar', [DocumentController::class, 'renew'])->name('documents.renew');
