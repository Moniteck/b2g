<?php

use App\Http\Controllers\AdjudicationController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteExportController;
use Illuminate\Support\Facades\Route;

/* Cotizaciones */

Route::get('/cotizaciones', [QuoteController::class, 'index'])->name('cotizaciones');
Route::post('/cotizaciones', [QuoteController::class, 'store'])->name('quotes.store');

Route::get('/cotizaciones/export/pdf', [QuoteExportController::class, 'pdf'])->middleware(['throttle:exports', 'audit.export'])->name('quotes.pdf');
Route::get('/cotizaciones/export/list-pdf', [QuoteExportController::class, 'listPdf'])->middleware(['throttle:exports', 'audit.export'])->name('quotes.list-pdf');
Route::get('/cotizaciones/export/excel', [QuoteExportController::class, 'excel'])->middleware(['throttle:exports', 'audit.export'])->name('quotes.excel');

Route::whereNumber('quote')->group(function () {
    Route::get('/cotizaciones/{quote}', [QuoteController::class, 'show'])->middleware(\App\Http\Middleware\RedirectBrowserToScreen::class.':/cotizaciones?ver={quote}')->name('quotes.show');
    Route::put('/cotizaciones/{quote}', [QuoteController::class, 'update'])->name('quotes.update');
    Route::delete('/cotizaciones/{quote}', [QuoteController::class, 'destroy'])->name('quotes.destroy');
    Route::patch('/cotizaciones/{quote}/estado', [QuoteController::class, 'status'])->name('quotes.status');

    Route::get('/cotizaciones/{quote}/pdf', [QuoteController::class, 'pdf'])->middleware(['throttle:exports', 'audit.export'])->name('quotes.pdf.show');
    Route::post('/cotizaciones/{quote}/pdf/documento', [QuoteController::class, 'snapshot'])->middleware('throttle:30,1')->name('quotes.pdf.snapshot');
    Route::post('/cotizaciones/{quote}/email', [QuoteExportController::class, 'mail'])->middleware('throttle:6,10')->name('quotes.mail');

    Route::post('/cotizaciones/{quote}/ganada', [AdjudicationController::class, 'win'])->name('quotes.win');
    Route::post('/cotizaciones/{quote}/perdida', [AdjudicationController::class, 'lose'])->name('quotes.lose');
    Route::post('/cotizaciones/{quote}/revertir', [AdjudicationController::class, 'revert'])->name('quotes.revert');
    Route::get('/cotizaciones/{quote}/participantes', [AdjudicationController::class, 'participants'])->name('quotes.participants');
    Route::put('/cotizaciones/{quote}/participantes', [AdjudicationController::class, 'saveParticipants'])->name('quotes.participants.save');
});

/* Resultado y participantes por oportunidad (kanban; misma lógica que la cotización). */

Route::whereNumber('opportunity')->group(function () {
    Route::post('/oportunidades/{opportunity}/ganada', [AdjudicationController::class, 'win'])->name('opportunities.win');
    Route::post('/oportunidades/{opportunity}/perdida', [AdjudicationController::class, 'lose'])->name('opportunities.lose');
    Route::post('/oportunidades/{opportunity}/revertir', [AdjudicationController::class, 'revert'])->name('opportunities.revert');
    Route::get('/oportunidades/{opportunity}/cotizaciones-vivas', [AdjudicationController::class, 'liveQuotes'])->name('opportunities.live-quotes');
    Route::get('/oportunidades/{opportunity}/participantes', [AdjudicationController::class, 'participants'])->name('opportunities.participants');
    Route::put('/oportunidades/{opportunity}/participantes', [AdjudicationController::class, 'saveParticipants'])->name('opportunities.participants.save');
});
