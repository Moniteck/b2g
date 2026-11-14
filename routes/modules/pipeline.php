<?php

use App\Http\Controllers\OpportunityController;
use Illuminate\Support\Facades\Route;

/* Pipeline de oportunidades (kanban). Resultado y participantes: routes/modules/quotes.php (/oportunidades/{id}/…). */

Route::get('/pipeline', [OpportunityController::class, 'index'])->name('pipeline');
Route::post('/pipeline', [OpportunityController::class, 'store'])->name('opportunities.store');

Route::get('/pipeline/export/pdf', [OpportunityController::class, 'pdf'])->name('opportunities.pdf');
Route::get('/pipeline/export/xlsx', [OpportunityController::class, 'xlsx'])->name('opportunities.xlsx');

Route::whereNumber('opportunity')->group(function () {
    Route::get('/pipeline/{opportunity}', [OpportunityController::class, 'show'])->name('opportunities.show');
    Route::put('/pipeline/{opportunity}', [OpportunityController::class, 'update'])->name('opportunities.update');
    Route::delete('/pipeline/{opportunity}', [OpportunityController::class, 'destroy'])->name('opportunities.destroy');
    Route::patch('/pipeline/{opportunity}/etapa', [OpportunityController::class, 'stage'])->middleware('throttle:60,1')->name('opportunities.stage');
});
