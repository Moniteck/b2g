<?php

use App\Http\Controllers\TenderAiController;
use App\Http\Controllers\TenderBidDocumentController;
use App\Http\Controllers\TenderCaptureImportController;
use App\Http\Controllers\TenderController;
use Illuminate\Support\Facades\Route;

/* Licitaciones */

Route::get('/licitaciones', [TenderController::class, 'index'])->name('tenders.index');
Route::post('/licitaciones', [TenderController::class, 'store'])->name('tenders.store');

Route::get('/licitaciones/export/pdf', [TenderController::class, 'pdf'])->name('tenders.pdf');
Route::get('/licitaciones/export/xlsx', [TenderController::class, 'xlsx'])->name('tenders.xlsx');

/* Importar captura de HonduCompras (antes de las rutas con {tender}) */

Route::post('/licitaciones/importar', [TenderCaptureImportController::class, 'store'])->name('tenders.import.store');
Route::get('/licitaciones/importar/{token}', [TenderCaptureImportController::class, 'review'])->whereUuid('token')->name('tenders.import.review');
Route::get('/licitaciones/importar/{token}/imagen', [TenderCaptureImportController::class, 'image'])->whereUuid('token')->name('tenders.import.image');
Route::post('/licitaciones/importar/{token}', [TenderCaptureImportController::class, 'confirm'])->whereUuid('token')->name('tenders.import.confirm');

/* Ficha */

Route::get('/licitaciones/{tender}', [TenderController::class, 'show'])->whereNumber('tender')->name('tenders.show');
Route::put('/licitaciones/{tender}', [TenderController::class, 'update'])->whereNumber('tender')->name('tenders.update');
Route::delete('/licitaciones/{tender}', [TenderController::class, 'destroy'])->whereNumber('tender')->name('tenders.destroy');

/* Pliego: análisis con IA, revisión y acciones */

Route::post('/licitaciones/{tender}/pliego', [TenderBidDocumentController::class, 'store'])->whereNumber('tender')->name('tenders.bid.store');
Route::get('/licitaciones/{tender}/pliego/{extraction}/estado', [TenderBidDocumentController::class, 'status'])->whereNumber(['tender', 'extraction'])->name('tenders.bid.status');
Route::post('/licitaciones/{tender}/pliego/{extraction}/reintentar', [TenderBidDocumentController::class, 'retry'])->whereNumber(['tender', 'extraction'])->name('tenders.bid.retry');
Route::get('/licitaciones/{tender}/pliego/{extraction}/revision', [TenderBidDocumentController::class, 'review'])->whereNumber(['tender', 'extraction'])->name('tenders.bid.review');
Route::get('/licitaciones/{tender}/pliego/{extraction}/archivo', [TenderBidDocumentController::class, 'file'])->whereNumber(['tender', 'extraction'])->name('tenders.bid.file');
Route::post('/licitaciones/{tender}/pliego/{extraction}/confirmar', [TenderBidDocumentController::class, 'confirm'])->whereNumber(['tender', 'extraction'])->name('tenders.bid.confirm');
Route::post('/licitaciones/{tender}/pliego/{extraction}/matriz', [TenderBidDocumentController::class, 'matrix'])->whereNumber(['tender', 'extraction'])->name('tenders.bid.matrix');
Route::post('/licitaciones/{tender}/pliego/{extraction}/cotizacion', [TenderBidDocumentController::class, 'quote'])->whereNumber(['tender', 'extraction'])->name('tenders.bid.quote');

/* Recomendación de la IA (la antigua pantalla redirige a la lista) */

Route::post('/licitaciones/{tender}/recomendacion', [TenderAiController::class, 'recommend'])->whereNumber('tender')->name('tenders.ai.recommend');
Route::get('/ia-licitaciones', [TenderAiController::class, 'index'])->name('tender-ai.index');
