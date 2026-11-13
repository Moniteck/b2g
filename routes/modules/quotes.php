<?php

use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteExportController;
use Illuminate\Support\Facades\Route;

/* Cotizaciones */

Route::get('/cotizaciones', [QuoteController::class, 'index'])->name('cotizaciones');
Route::post('/cotizaciones', [QuoteController::class, 'store'])->name('quotes.store');

Route::get('/cotizaciones/export/pdf', [QuoteExportController::class, 'pdf'])->name('quotes.pdf');
Route::get('/cotizaciones/export/list-pdf', [QuoteExportController::class, 'listPdf'])->name('quotes.list-pdf');
Route::get('/cotizaciones/export/excel', [QuoteExportController::class, 'excel'])->name('quotes.excel');

Route::put('/cotizaciones/{quote}', [QuoteController::class, 'update'])->name('quotes.update');
Route::delete('/cotizaciones/{quote}', [QuoteController::class, 'destroy'])->name('quotes.destroy');
Route::post('/cotizaciones/{quote}/email', [QuoteExportController::class, 'mail'])->name('quotes.mail');
Route::patch('/cotizaciones/{quote}/estado', [QuoteController::class, 'status'])->name('quotes.status');
Route::patch('/cotizaciones/{quote}/resultado', [QuoteController::class, 'outcome'])->name('quotes.outcome');
Route::put('/cotizaciones/{quote}/participantes', [QuoteController::class, 'participants'])->name('quotes.participants');

Route::get('/cotizaciones/{quote}/documentos', [QuoteController::class, 'documents'])->name('quotes.documents');
Route::post('/cotizaciones/{quote}/documentos', [QuoteController::class, 'attachDocument'])->name('quotes.documents.attach');
Route::delete('/cotizaciones/{quote}/documentos/{document}', [QuoteController::class, 'detachDocument'])->name('quotes.documents.detach');
