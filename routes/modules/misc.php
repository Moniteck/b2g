<?php

use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\ComplianceMatrixController;
use App\Http\Controllers\ConsortiumController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\KpiGoalController;
use App\Http\Controllers\LegalChatController;
use App\Http\Controllers\PoaController;
use App\Http\Controllers\ProcessHistoryController;
use App\Http\Controllers\RelationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SpecializedToolsController;
use Illuminate\Support\Facades\Route;

/* Dashboard, reportes e indicadores */

Route::get('/', DashboardController::class)->name('dashboard');

Route::get('/reportes', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reportes/pdf', [ReportController::class, 'pdf'])->name('reports.pdf');
Route::get('/reportes/xlsx', [ReportController::class, 'xlsx'])->name('reports.xlsx');
Route::get('/reportes/csv', [ReportController::class, 'csv'])->name('reports.csv');

Route::get('/kpis', [KpiGoalController::class, 'index'])->name('kpis.index');
Route::post('/kpis', [KpiGoalController::class, 'store'])->name('kpis.store');
Route::put('/kpis/{kpiGoal}', [KpiGoalController::class, 'update'])->name('kpis.update');

/* Agente legal */

Route::get('/agente-legal', [LegalChatController::class, 'index'])->name('agente-legal');
Route::post('/agente-legal/chat', [LegalChatController::class, 'store'])->name('legal.chat');
Route::post('/agente-legal/escritos', [SpecializedToolsController::class, 'legalWriting'])->name('legal.writing');

/* Catálogos compartidos: instituciones y contactos */

Route::get('/directorio', [InstitutionController::class, 'index'])->name('institutions.index');
Route::put('/directorio/{institution}', [InstitutionController::class, 'update'])->name('institutions.update');

Route::get('/contactos', [ContactController::class, 'index'])->name('contacts.index');
Route::post('/contactos', [ContactController::class, 'store'])->name('contacts.store');
Route::delete('/contactos/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');

/* Historial de procesos */

Route::get('/historial', [ProcessHistoryController::class, 'index'])->name('process-history.index');
Route::post('/historial', [ProcessHistoryController::class, 'store'])->name('process-history.store');
Route::delete('/historial/{processHistory}', [ProcessHistoryController::class, 'destroy'])->name('process-history.destroy');

/* Inteligencia competitiva */

Route::get('/inteligencia-competitiva', [CompetitorController::class, 'index'])->name('competitors.index');
Route::post('/inteligencia-competitiva', [CompetitorController::class, 'store'])->name('competitors.store');
Route::put('/inteligencia-competitiva/{competitor}', [CompetitorController::class, 'update'])->name('competitors.update');
Route::delete('/inteligencia-competitiva/{competitor}', [CompetitorController::class, 'destroy'])->name('competitors.destroy');

/* Relaciones institucionales (CRM político) */

Route::get('/relaciones-institucionales', [RelationController::class, 'index'])->name('relations.index');
Route::post('/relaciones-institucionales', [RelationController::class, 'store'])->name('relations.store');
Route::put('/relaciones-institucionales/{relation}', [RelationController::class, 'update'])->name('relations.update');
Route::post('/relaciones-institucionales/{relation}/interacciones', [RelationController::class, 'interaction'])->name('relations.interactions.store');
Route::delete('/relaciones-institucionales/{relation}', [RelationController::class, 'destroy'])->name('relations.destroy');

/* Cobros */

Route::get('/cobros', [CollectionController::class, 'index'])->name('collections.index');
Route::post('/cobros', [CollectionController::class, 'store'])->name('collections.store');
Route::get('/cobros/export/pdf', [CollectionController::class, 'pdf'])->name('collections.pdf');
Route::get('/cobros/export/xlsx', [CollectionController::class, 'xlsx'])->name('collections.xlsx');
Route::patch('/cobros/{collectionInvoice}/pagar', [CollectionController::class, 'pay'])->name('collections.pay');
Route::patch('/cobros/{collectionInvoice}/nota', [CollectionController::class, 'note'])->name('collections.note');
Route::get('/cobros/{collectionInvoice}/whatsapp', [CollectionController::class, 'whatsapp'])->name('collections.whatsapp');
Route::get('/cobros/{collectionInvoice}/nota.pdf', [CollectionController::class, 'notePdf'])->name('collections.note.pdf');
Route::delete('/cobros/{collectionInvoice}', [CollectionController::class, 'destroy'])->name('collections.destroy');

/* Consorcios */

Route::get('/consorcios', [ConsortiumController::class, 'index'])->name('consortia.index');
Route::post('/consorcios', [ConsortiumController::class, 'store'])->name('consortia.store');
Route::put('/consorcios/{consortium}', [ConsortiumController::class, 'update'])->name('consortia.update');
Route::delete('/consorcios/{consortium}', [ConsortiumController::class, 'destroy'])->name('consortia.destroy');
Route::post('/consorcios/{consortium}/miembros', [ConsortiumController::class, 'member'])->name('consortia.members.store');
Route::put('/consorcios/{consortium}/miembros/{member}', [ConsortiumController::class, 'updateMember'])->name('consortia.members.update');
Route::delete('/consorcios/{consortium}/miembros/{member}', [ConsortiumController::class, 'removeMember'])->name('consortia.members.destroy');

/* Presupuesto revolvente y flujo de caja */

Route::get('/presupuesto-revolvente', [BudgetController::class, 'index'])->name('budget.index');
Route::post('/presupuesto-revolvente', [BudgetController::class, 'store'])->name('budget.store');
Route::post('/presupuesto-revolvente/inicializar', [BudgetController::class, 'initialize'])->name('budget.initialize');
Route::post('/presupuesto-revolvente/sincronizar', [BudgetController::class, 'sync'])->name('budget.sync');
Route::get('/presupuesto-revolvente/export/pdf', [BudgetController::class, 'pdf'])->name('budget.pdf');
Route::get('/presupuesto-revolvente/export/xlsx', [BudgetController::class, 'xlsx'])->name('budget.xlsx');
Route::delete('/presupuesto-revolvente/{budgetMovement}', [BudgetController::class, 'destroy'])->name('budget.destroy');

Route::get('/flujo-caja', [CashFlowController::class, 'index'])->name('cash-flow.index');
Route::post('/flujo-caja', [CashFlowController::class, 'store'])->name('cash-flow.store');
Route::delete('/flujo-caja/{cashIncome}', [CashFlowController::class, 'destroy'])->name('cash-flow.destroy');

/* Alertas POA */

Route::get('/alertas-poa', [PoaController::class, 'index'])->name('poa.index');
Route::post('/alertas-poa', [PoaController::class, 'store'])->name('poa.store');
Route::put('/alertas-poa/{poaEntry}', [PoaController::class, 'update'])->name('poa.update');
Route::patch('/alertas-poa/{poaEntry}/ejecutar', [PoaController::class, 'execute'])->name('poa.execute');
Route::delete('/alertas-poa/{poaEntry}', [PoaController::class, 'destroy'])->name('poa.destroy');

/* Matriz documental */

Route::get('/matriz-cumplimiento', [ComplianceMatrixController::class, 'index'])->name('compliance.index');
Route::post('/matriz-cumplimiento', [ComplianceMatrixController::class, 'store'])->name('compliance.store');
Route::patch('/matriz-cumplimiento/{complianceMatrix}/items/{item}', [ComplianceMatrixController::class, 'toggle'])->name('compliance.items.toggle');
Route::patch('/matriz-cumplimiento/{complianceMatrix}/items/{item}/renovar', [ComplianceMatrixController::class, 'renew'])->name('compliance.items.renew');

/* Generador de documentos, capacitación y procedimientos */

Route::get('/generador-documentos', [SpecializedToolsController::class, 'documents'])->name('generated-documents.index');
Route::post('/generador-documentos', [SpecializedToolsController::class, 'generateDocument'])->name('generated-documents.generate');
Route::get('/generador-documentos/{generatedDocument}/pdf', [SpecializedToolsController::class, 'documentPdf'])->name('generated-documents.pdf');

Route::get('/capacitacion', [SpecializedToolsController::class, 'training'])->name('training.index');
Route::put('/capacitacion', [SpecializedToolsController::class, 'saveTraining'])->name('training.save');

Route::get('/sops', [SpecializedToolsController::class, 'sops'])->name('sops.index');
Route::get('/sops/{sop}/pdf', [SpecializedToolsController::class, 'sopPdf'])->whereNumber('sop')->name('sops.pdf');
