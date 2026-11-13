<?php

use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\ComplianceMatrixController;
use App\Http\Controllers\ConsortiumController;
use App\Http\Controllers\LegalChatController;
use App\Http\Controllers\ProcessHistoryController;
use App\Http\Controllers\RelationController;
use App\Http\Controllers\SpecializedToolsController;
use Illuminate\Support\Facades\Route;

/* Agente legal */

Route::get('/agente-legal', [LegalChatController::class, 'index'])->name('agente-legal');
Route::post('/agente-legal/chat', [LegalChatController::class, 'store'])->name('legal.chat');
Route::post('/agente-legal/escritos', [SpecializedToolsController::class, 'legalWriting'])->name('legal.writing');

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

/* Consorcios */

Route::get('/consorcios', [ConsortiumController::class, 'index'])->name('consortia.index');
Route::post('/consorcios', [ConsortiumController::class, 'store'])->name('consortia.store');
Route::put('/consorcios/{consortium}', [ConsortiumController::class, 'update'])->name('consortia.update');
Route::delete('/consorcios/{consortium}', [ConsortiumController::class, 'destroy'])->name('consortia.destroy');
Route::post('/consorcios/{consortium}/miembros', [ConsortiumController::class, 'member'])->name('consortia.members.store');
Route::put('/consorcios/{consortium}/miembros/{member}', [ConsortiumController::class, 'updateMember'])->name('consortia.members.update');
Route::delete('/consorcios/{consortium}/miembros/{member}', [ConsortiumController::class, 'removeMember'])->name('consortia.members.destroy');

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
