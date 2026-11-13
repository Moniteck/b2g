<?php

use App\Http\Controllers\SpecializedToolsController;
use App\Http\Controllers\TenderController;
use Illuminate\Support\Facades\Route;

/* Licitaciones */

Route::get('/licitaciones', [TenderController::class, 'index'])->name('tenders.index');
Route::post('/licitaciones', [TenderController::class, 'store'])->name('tenders.store');

Route::get('/licitaciones/export/pdf', [TenderController::class, 'pdf'])->name('tenders.pdf');
Route::get('/licitaciones/export/xlsx', [TenderController::class, 'xlsx'])->name('tenders.xlsx');

Route::put('/licitaciones/{tender}', [TenderController::class, 'update'])->name('tenders.update');
Route::delete('/licitaciones/{tender}', [TenderController::class, 'destroy'])->name('tenders.destroy');

/* Análisis de licitaciones con IA */

Route::get('/ia-licitaciones', [SpecializedToolsController::class, 'tenderAi'])->name('tender-ai.index');
Route::post('/ia-licitaciones/{tender}/analizar', [SpecializedToolsController::class, 'analyzeTender'])->name('tender-ai.analyze');
