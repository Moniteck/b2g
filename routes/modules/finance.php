<?php

use App\Http\Controllers\Finance\BudgetController;
use App\Http\Controllers\Finance\CashFlowController;
use App\Http\Controllers\Finance\CollectionController;
use App\Http\Controllers\Finance\KpiGoalController;
use App\Http\Controllers\Finance\PoaController;
use App\Http\Controllers\Finance\ReportController;
use Illuminate\Support\Facades\Route;

/* Reportes e indicadores */

Route::get('/reportes', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reportes/pdf', [ReportController::class, 'pdf'])->middleware(['throttle:exports', 'audit.export'])->name('reports.pdf');
Route::get('/reportes/xlsx', [ReportController::class, 'xlsx'])->middleware(['throttle:exports', 'audit.export'])->name('reports.xlsx');
Route::get('/reportes/csv', [ReportController::class, 'csv'])->middleware(['throttle:exports', 'audit.export'])->name('reports.csv');

/* KPI y metas */

Route::get('/kpis', [KpiGoalController::class, 'index'])->name('kpis.index');
Route::post('/kpis', [KpiGoalController::class, 'store'])->name('kpis.store');
Route::put('/kpis/{kpiGoal}', [KpiGoalController::class, 'update'])->name('kpis.update');
Route::delete('/kpis/{kpiGoal}', [KpiGoalController::class, 'destroy'])->name('kpis.destroy');

/* Cobros */

Route::get('/cobros', [CollectionController::class, 'index'])->name('collections.index');
Route::post('/cobros', [CollectionController::class, 'store'])->name('collections.store');
Route::get('/cobros/export/pdf', [CollectionController::class, 'pdf'])->middleware(['throttle:exports', 'audit.export'])->name('collections.pdf');
Route::get('/cobros/export/xlsx', [CollectionController::class, 'xlsx'])->middleware(['throttle:exports', 'audit.export'])->name('collections.xlsx');
Route::put('/cobros/{collectionInvoice}', [CollectionController::class, 'update'])->name('collections.update');
Route::patch('/cobros/{collectionInvoice}/pagar', [CollectionController::class, 'pay'])->name('collections.pay');
Route::patch('/cobros/{collectionInvoice}/nota', [CollectionController::class, 'note'])->name('collections.note');
Route::get('/cobros/{collectionInvoice}/whatsapp', [CollectionController::class, 'whatsapp'])->name('collections.whatsapp');
Route::get('/cobros/{collectionInvoice}/nota.pdf', [CollectionController::class, 'notePdf'])->middleware(['throttle:exports', 'audit.export'])->name('collections.note.pdf');
Route::delete('/cobros/{collectionInvoice}', [CollectionController::class, 'destroy'])->name('collections.destroy');

/* Presupuesto revolvente y flujo de caja */

Route::get('/presupuesto-revolvente', [BudgetController::class, 'index'])->name('budget.index');
Route::post('/presupuesto-revolvente', [BudgetController::class, 'store'])->name('budget.store');
Route::post('/presupuesto-revolvente/inicializar', [BudgetController::class, 'initialize'])->name('budget.initialize');
Route::post('/presupuesto-revolvente/sincronizar', [BudgetController::class, 'sync'])->name('budget.sync');
Route::get('/presupuesto-revolvente/export/pdf', [BudgetController::class, 'pdf'])->middleware(['throttle:exports', 'audit.export'])->name('budget.pdf');
Route::get('/presupuesto-revolvente/export/xlsx', [BudgetController::class, 'xlsx'])->middleware(['throttle:exports', 'audit.export'])->name('budget.xlsx');
Route::delete('/presupuesto-revolvente/{budgetMovement}', [BudgetController::class, 'destroy'])->name('budget.destroy');

Route::get('/flujo-caja', [CashFlowController::class, 'index'])->name('cash-flow.index');
Route::post('/flujo-caja', [CashFlowController::class, 'store'])->name('cash-flow.store');
Route::put('/flujo-caja/{cashIncome}', [CashFlowController::class, 'update'])->name('cash-flow.update');
Route::delete('/flujo-caja/{cashIncome}', [CashFlowController::class, 'destroy'])->name('cash-flow.destroy');

/* Alertas POA */

Route::get('/alertas-poa', [PoaController::class, 'index'])->name('poa.index');
Route::post('/alertas-poa', [PoaController::class, 'store'])->name('poa.store');
Route::put('/alertas-poa/{poaEntry}', [PoaController::class, 'update'])->name('poa.update');
Route::patch('/alertas-poa/{poaEntry}/ejecutar', [PoaController::class, 'execute'])->name('poa.execute');
Route::delete('/alertas-poa/{poaEntry}', [PoaController::class, 'destroy'])->name('poa.destroy');
