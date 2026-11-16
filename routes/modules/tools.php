<?php

use App\Http\Controllers\Tools\CompetitorController;
use App\Http\Controllers\Tools\ComplianceMatrixController;
use App\Http\Controllers\Tools\ConsortiumController;
use App\Http\Controllers\Tools\DocumentGeneratorController;
use App\Http\Controllers\Tools\LegalChatController;
use App\Http\Controllers\Tools\OfferCalculatorController;
use App\Http\Controllers\Tools\RelationController;
use App\Http\Controllers\Tools\SopController;
use App\Http\Controllers\Tools\TrainingController;
use Illuminate\Support\Facades\Route;

/* Agente legal (las llamadas a la IA se limitan por usuario) */

Route::get('/agente-legal', [LegalChatController::class, 'index'])->name('agente-legal');
Route::post('/agente-legal/chat', [LegalChatController::class, 'store'])->middleware('throttle:ai')->name('legal.chat');
Route::post('/agente-legal/escritos', [LegalChatController::class, 'writing'])->middleware('throttle:ai')->name('legal.writing');
Route::post('/agente-legal/escritos/pdf', [LegalChatController::class, 'writingPdf'])->middleware(['throttle:exports', 'audit.export'])->name('legal.writing.pdf');
Route::get('/agente-legal/conversaciones/{conversation}', [LegalChatController::class, 'show'])->whereNumber('conversation')->name('legal.conversations.show');
Route::delete('/agente-legal/conversaciones/{conversation}', [LegalChatController::class, 'destroy'])->whereNumber('conversation')->name('legal.conversations.destroy');

/* Inteligencia competitiva */

Route::get('/inteligencia-competitiva', [CompetitorController::class, 'index'])->name('competitors.index');
Route::post('/inteligencia-competitiva', [CompetitorController::class, 'store'])->name('competitors.store');
Route::get('/inteligencia-competitiva/{competitor}/participaciones', [CompetitorController::class, 'participations'])->name('competitors.participations');
Route::put('/inteligencia-competitiva/{competitor}', [CompetitorController::class, 'update'])->name('competitors.update');
Route::delete('/inteligencia-competitiva/{competitor}', [CompetitorController::class, 'destroy'])->name('competitors.destroy');

/* Relaciones institucionales (CRM político) */

Route::get('/relaciones-institucionales', [RelationController::class, 'index'])->name('relations.index');
Route::post('/relaciones-institucionales', [RelationController::class, 'store'])->name('relations.store');
Route::get('/relaciones-institucionales/{relation}', [RelationController::class, 'show'])->name('relations.show');
Route::put('/relaciones-institucionales/{relation}', [RelationController::class, 'update'])->name('relations.update');
Route::post('/relaciones-institucionales/{relation}/interacciones', [RelationController::class, 'interaction'])->name('relations.interactions.store');
Route::delete('/relaciones-institucionales/{relation}', [RelationController::class, 'destroy'])->name('relations.destroy');

/* Consorcios (los miembros se guardan con el consorcio) */

Route::get('/consorcios', [ConsortiumController::class, 'index'])->name('consortia.index');
Route::post('/consorcios', [ConsortiumController::class, 'store'])->name('consortia.store');
Route::put('/consorcios/{consortium}', [ConsortiumController::class, 'update'])->name('consortia.update');
Route::delete('/consorcios/{consortium}', [ConsortiumController::class, 'destroy'])->name('consortia.destroy');

/* Matriz documental */

Route::get('/matriz-cumplimiento', [ComplianceMatrixController::class, 'index'])->name('compliance.index');
Route::post('/matriz-cumplimiento', [ComplianceMatrixController::class, 'store'])->name('compliance.store');
Route::get('/matriz-cumplimiento/{complianceMatrix}', [ComplianceMatrixController::class, 'show'])->name('compliance.show');
Route::put('/matriz-cumplimiento/{complianceMatrix}', [ComplianceMatrixController::class, 'update'])->name('compliance.update');
Route::delete('/matriz-cumplimiento/{complianceMatrix}', [ComplianceMatrixController::class, 'destroy'])->name('compliance.destroy');
Route::get('/matriz-cumplimiento/{complianceMatrix}/pdf', [ComplianceMatrixController::class, 'pdf'])->middleware(['throttle:exports', 'audit.export'])->name('compliance.pdf');
Route::post('/matriz-cumplimiento/{complianceMatrix}/items', [ComplianceMatrixController::class, 'storeItem'])->name('compliance.items.store');
Route::put('/matriz-cumplimiento/{complianceMatrix}/items/{item}', [ComplianceMatrixController::class, 'updateItem'])->whereNumber('item')->name('compliance.items.update');
Route::patch('/matriz-cumplimiento/{complianceMatrix}/items/{item}', [ComplianceMatrixController::class, 'toggle'])->whereNumber('item')->name('compliance.items.toggle');
Route::patch('/matriz-cumplimiento/{complianceMatrix}/items/{item}/renovar', [ComplianceMatrixController::class, 'renew'])->whereNumber('item')->name('compliance.items.renew');
Route::delete('/matriz-cumplimiento/{complianceMatrix}/items/{item}', [ComplianceMatrixController::class, 'destroyItem'])->whereNumber('item')->name('compliance.items.destroy');

/* Generador de documentos */

Route::get('/generador-documentos', [DocumentGeneratorController::class, 'index'])->name('generated-documents.index');
Route::post('/generador-documentos', [DocumentGeneratorController::class, 'store'])->middleware('throttle:ai')->name('generated-documents.generate');
Route::get('/generador-documentos/{generatedDocument}/pdf', [DocumentGeneratorController::class, 'pdf'])->middleware(['throttle:exports', 'audit.export'])->name('generated-documents.pdf');
Route::delete('/generador-documentos/{generatedDocument}', [DocumentGeneratorController::class, 'destroy'])->name('generated-documents.destroy');

/* Capacitación y procedimientos */

Route::get('/capacitacion', [TrainingController::class, 'index'])->name('training.index');
Route::put('/capacitacion', [TrainingController::class, 'update'])->name('training.save');

Route::get('/sops', [SopController::class, 'index'])->name('sops.index');
Route::put('/sops/{sop}/lectura', [SopController::class, 'read'])->where('sop', '[a-z0-9-]+')->name('sops.read');
Route::get('/sops/{sop}/pdf', [SopController::class, 'pdf'])->where('sop', '[a-z0-9-]+')->middleware(['throttle:exports', 'audit.export'])->name('sops.pdf');

/* Calculadora de oferta */

Route::get('/calculadora-oferta', [OfferCalculatorController::class, 'index'])->name('offer-calculator.index');
Route::post('/calculadora-oferta/calcular', [OfferCalculatorController::class, 'calculate'])->name('offer-calculator.calculate');
Route::post('/calculadora-oferta/pdf', [OfferCalculatorController::class, 'pdf'])->middleware(['throttle:exports', 'audit.export'])->name('offer-calculator.pdf');
