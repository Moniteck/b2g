<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LegalChatController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteExportController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\TenderController;
use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\ProcessHistoryController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\SigningAssetController;
use App\Http\Controllers\SpecializedToolsController;
use App\Http\Controllers\{ReportController, KpiGoalController};
use App\Http\Controllers\{BudgetController, CashFlowController, PoaController};
use App\Http\Controllers\{ContractController,ConsortiumController,DocumentController,CollectionController,RelationController,ComplianceMatrixController};

Route::middleware('guest')->group(function () { Route::get('/login', [AuthController::class, 'create'])->name('login'); Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1')->name('login.store'); });
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
Route::get('/', [DashboardController::class])->name('dashboard');
Route::get('/reportes', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reportes/pdf', [ReportController::class, 'pdf'])->name('reports.pdf');
Route::get('/reportes/xlsx', [ReportController::class, 'xlsx'])->name('reports.xlsx');
Route::get('/reportes/csv', [ReportController::class, 'csv'])->name('reports.csv');
Route::get('/kpis', [KpiGoalController::class, 'index'])->name('kpis.index');
Route::post('/kpis', [KpiGoalController::class, 'store'])->name('kpis.store');
Route::put('/kpis/{kpiGoal}', [KpiGoalController::class, 'update'])->name('kpis.update');
Route::get('/agente-legal', fn () => Inertia::render('LegalAgent'))->name('agente-legal');
Route::post('/agente-legal/chat', [LegalChatController::class, 'store'])->name('legal.chat');
Route::post('/agente-legal/escritos', [SpecializedToolsController::class, 'legalWriting'])->name('legal.writing');
Route::get('/configuracion/usuarios', [AdminUserController::class, 'index'])->name('admin.users');
Route::put('/configuracion/usuarios/{user}/rol', [AdminUserController::class, 'updateRole'])->name('admin.users.role');

/* Empresas del grupo y membresias */
Route::get('/empresas', [CompanyController::class, 'index'])->name('companies.index');
Route::post('/empresas', [CompanyController::class, 'store'])->name('companies.store');
Route::put('/empresas/{company}', [CompanyController::class, 'update'])->name('companies.update');
Route::delete('/empresas/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
Route::post('/empresas/{company}/miembros', [CompanyController::class, 'member'])->name('companies.members.store');
Route::delete('/empresas/{company}/miembros/{user}', [CompanyController::class, 'removeMember'])->name('companies.members.destroy');

/* Cotizaciones */
Route::get('/cotizaciones', [QuoteController::class, 'index'])->name('cotizaciones');
Route::get('/cotizaciones/export/pdf', [QuoteExportController::class, 'pdf'])->name('quotes.pdf');
Route::get('/cotizaciones/export/list-pdf', [QuoteExportController::class, 'listPdf'])->name('quotes.list-pdf');
Route::get('/cotizaciones/export/excel', [QuoteExportController::class, 'excel'])->name('quotes.excel');
Route::post('/cotizaciones/{quote}/email', [QuoteExportController::class, 'mail'])->name('quotes.mail');
Route::post('/cotizaciones', [QuoteController::class, 'store'])->name('quotes.store');
Route::put('/cotizaciones/{quote}', [QuoteController::class, 'update'])->name('quotes.update');
Route::delete('/cotizaciones/{quote}', [QuoteController::class, 'destroy'])->name('quotes.destroy');
Route::patch('/cotizaciones/{quote}/estado', [QuoteController::class, 'status'])->name('quotes.status');
Route::patch('/cotizaciones/{quote}/resultado', [QuoteController::class, 'outcome'])->name('quotes.outcome');
Route::put('/cotizaciones/{quote}/participantes', [QuoteController::class, 'participants'])->name('quotes.participants');
Route::get('/cotizaciones/{quote}/documentos', [QuoteController::class, 'documents'])->name('quotes.documents');
Route::post('/cotizaciones/{quote}/documentos', [QuoteController::class, 'attachDocument'])->name('quotes.documents.attach');
Route::delete('/cotizaciones/{quote}/documentos/{document}', [QuoteController::class, 'detachDocument'])->name('quotes.documents.detach');

/* Pipeline */
Route::get('/pipeline', [OpportunityController::class, 'index'])->name('pipeline');
Route::get('/pipeline/{opportunity}', [OpportunityController::class, 'show'])->whereNumber('opportunity')->name('opportunities.show');
Route::post('/pipeline', [OpportunityController::class, 'store'])->name('opportunities.store');
Route::put('/pipeline/{opportunity}', [OpportunityController::class, 'update'])->name('opportunities.update');
Route::delete('/pipeline/{opportunity}', [OpportunityController::class, 'destroy'])->name('opportunities.destroy');
Route::patch('/pipeline/{opportunity}/etapa', [OpportunityController::class, 'stage'])->name('opportunities.stage');
Route::get('/pipeline/export/pdf', [OpportunityController::class, 'pdf'])->name('opportunities.pdf');
Route::get('/pipeline/export/xlsx', [OpportunityController::class, 'xlsx'])->name('opportunities.xlsx');

/* Base de clientes: solo lectura, los clientes nacen de una adjudicacion */
Route::get('/clientes', [ClientController::class, 'index'])->name('clients.index');
Route::put('/clientes/{client}', [ClientController::class, 'update'])->name('clients.update');
Route::delete('/clientes/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
Route::get('/clientes/export/csv', [ClientController::class, 'export'])->name('clients.export');

Route::get('/directorio', [InstitutionController::class, 'index'])->name('institutions.index');
Route::put('/directorio/{institution}', [InstitutionController::class, 'update'])->name('institutions.update');

Route::get('/contactos', [ContactController::class, 'index'])->name('contacts.index');
Route::post('/contactos', [ContactController::class, 'store'])->name('contacts.store');
Route::delete('/contactos/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');

Route::get('/licitaciones', [TenderController::class, 'index'])->name('tenders.index');
Route::post('/licitaciones', [TenderController::class, 'store'])->name('tenders.store');
Route::put('/licitaciones/{tender}', [TenderController::class, 'update'])->name('tenders.update');
Route::delete('/licitaciones/{tender}', [TenderController::class, 'destroy'])->name('tenders.destroy');
Route::get('/licitaciones/export/pdf', [TenderController::class, 'pdf'])->name('tenders.pdf');
Route::get('/licitaciones/export/xlsx', [TenderController::class, 'xlsx'])->name('tenders.xlsx');

Route::get('/historial', [ProcessHistoryController::class, 'index'])->name('process-history.index');
Route::post('/historial', [ProcessHistoryController::class, 'store'])->name('process-history.store');
Route::delete('/historial/{processHistory}', [ProcessHistoryController::class, 'destroy'])->name('process-history.destroy');

Route::get('/inteligencia-competitiva', [CompetitorController::class, 'index'])->name('competitors.index');
Route::post('/inteligencia-competitiva', [CompetitorController::class, 'store'])->name('competitors.store');
Route::put('/inteligencia-competitiva/{competitor}', [CompetitorController::class, 'update'])->name('competitors.update');
Route::delete('/inteligencia-competitiva/{competitor}', [CompetitorController::class, 'destroy'])->name('competitors.destroy');

Route::get('/post-adjudicacion', [ContractController::class, 'index'])->name('contracts.index');
Route::post('/post-adjudicacion', [ContractController::class, 'store'])->name('contracts.store');
Route::put('/post-adjudicacion/{contract}', [ContractController::class, 'update'])->name('contracts.update');
Route::delete('/post-adjudicacion/{contract}', [ContractController::class, 'destroy'])->name('contracts.destroy');
Route::post('/post-adjudicacion/{contract}/ordenes', [ContractController::class, 'order'])->name('contracts.orders.store');
Route::post('/post-adjudicacion/{contract}/certificados', [ContractController::class, 'certificate'])->name('contracts.certificates.store');
Route::post('/post-adjudicacion/{contract}/entregas', [ContractController::class, 'delivery'])->name('contracts.deliveries.store');
Route::post('/post-adjudicacion/{contract}/seguimientos', [ContractController::class, 'followUp'])->name('contracts.followups.store');
Route::patch('/post-adjudicacion/{contract}/completar', [ContractController::class, 'complete'])->name('contracts.complete');

Route::get('/relaciones-institucionales', [RelationController::class, 'index'])->name('relations.index');
Route::post('/relaciones-institucionales', [RelationController::class, 'store'])->name('relations.store');
Route::put('/relaciones-institucionales/{relation}', [RelationController::class, 'update'])->name('relations.update');
Route::post('/relaciones-institucionales/{relation}/interacciones', [RelationController::class, 'interaction'])->name('relations.interactions.store');
Route::delete('/relaciones-institucionales/{relation}', [RelationController::class, 'destroy'])->name('relations.destroy');

Route::get('/cobros', [CollectionController::class, 'index'])->name('collections.index');
Route::post('/cobros', [CollectionController::class, 'store'])->name('collections.store');
Route::patch('/cobros/{collectionInvoice}/pagar', [CollectionController::class, 'pay'])->name('collections.pay');
Route::patch('/cobros/{collectionInvoice}/nota', [CollectionController::class, 'note'])->name('collections.note');
Route::get('/cobros/{collectionInvoice}/whatsapp', [CollectionController::class, 'whatsapp'])->name('collections.whatsapp');
Route::get('/cobros/export/pdf', [CollectionController::class, 'pdf'])->name('collections.pdf');
Route::get('/cobros/export/xlsx', [CollectionController::class, 'xlsx'])->name('collections.xlsx');
Route::get('/cobros/{collectionInvoice}/nota.pdf', [CollectionController::class, 'notePdf'])->name('collections.note.pdf');
Route::delete('/cobros/{collectionInvoice}', [CollectionController::class, 'destroy'])->name('collections.destroy');

Route::get('/consorcios', [ConsortiumController::class, 'index'])->name('consortia.index');
Route::post('/consorcios', [ConsortiumController::class, 'store'])->name('consortia.store');
Route::put('/consorcios/{consortium}', [ConsortiumController::class, 'update'])->name('consortia.update');
Route::delete('/consorcios/{consortium}', [ConsortiumController::class, 'destroy'])->name('consortia.destroy');
Route::post('/consorcios/{consortium}/miembros', [ConsortiumController::class, 'member'])->name('consortia.members.store');
Route::put('/consorcios/{consortium}/miembros/{member}', [ConsortiumController::class, 'updateMember'])->name('consortia.members.update');
Route::delete('/consorcios/{consortium}/miembros/{member}', [ConsortiumController::class, 'removeMember'])->name('consortia.members.destroy');

Route::get('/presupuesto-revolvente', [BudgetController::class, 'index'])->name('budget.index');
Route::post('/presupuesto-revolvente/inicializar', [BudgetController::class, 'initialize'])->name('budget.initialize');
Route::post('/presupuesto-revolvente', [BudgetController::class, 'store'])->name('budget.store');
Route::post('/presupuesto-revolvente/sincronizar', [BudgetController::class, 'sync'])->name('budget.sync');
Route::delete('/presupuesto-revolvente/{budgetMovement}', [BudgetController::class, 'destroy'])->name('budget.destroy');
Route::get('/presupuesto-revolvente/export/pdf', [BudgetController::class, 'pdf'])->name('budget.pdf');
Route::get('/presupuesto-revolvente/export/xlsx', [BudgetController::class, 'xlsx'])->name('budget.xlsx');

Route::get('/flujo-caja', [CashFlowController::class, 'index'])->name('cash-flow.index');
Route::post('/flujo-caja', [CashFlowController::class, 'store'])->name('cash-flow.store');
Route::delete('/flujo-caja/{cashIncome}', [CashFlowController::class, 'destroy'])->name('cash-flow.destroy');

Route::get('/alertas-poa', [PoaController::class, 'index'])->name('poa.index');
Route::post('/alertas-poa', [PoaController::class, 'store'])->name('poa.store');
Route::put('/alertas-poa/{poaEntry}', [PoaController::class, 'update'])->name('poa.update');
Route::patch('/alertas-poa/{poaEntry}/ejecutar', [PoaController::class, 'execute'])->name('poa.execute');
Route::delete('/alertas-poa/{poaEntry}', [PoaController::class, 'destroy'])->name('poa.destroy');

Route::get('/matriz-cumplimiento', [ComplianceMatrixController::class, 'index'])->name('compliance.index');
Route::post('/matriz-cumplimiento', [ComplianceMatrixController::class, 'store'])->name('compliance.store');
Route::patch('/matriz-cumplimiento/{complianceMatrix}/items/{item}', [ComplianceMatrixController::class, 'toggle'])->name('compliance.items.toggle');
Route::patch('/matriz-cumplimiento/{complianceMatrix}/items/{item}/renovar', [ComplianceMatrixController::class, 'renew'])->name('compliance.items.renew');

/* Directorio documental y firma */
Route::get('/documentos', [DocumentController::class, 'index'])->name('documents.index');
Route::post('/documentos', [DocumentController::class, 'store'])->name('documents.store');
Route::put('/documentos/{document}', [DocumentController::class, 'update'])->name('documents.update');
Route::get('/documentos/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
Route::get('/documentos/{document}/vista', [DocumentController::class, 'preview'])->name('documents.preview');
Route::post('/documentos/{document}/replace', [DocumentController::class, 'replace'])->name('documents.replace');
Route::get('/documentos/{document}/versiones', [DocumentController::class, 'versions'])->name('documents.versions');
Route::patch('/documentos/{document}/renovar', [DocumentController::class, 'renew'])->name('documents.renew');
Route::post('/documentos/{document}/autorizacion', [DocumentController::class, 'issueAuthorization'])->name('documents.authorize');
Route::post('/documentos/{document}/firmar', [DocumentController::class, 'sign'])->name('documents.sign');
Route::delete('/documentos/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

Route::get('/firmas', [SigningAssetController::class, 'index'])->name('signing-assets.index');
Route::post('/firmas', [SigningAssetController::class, 'store'])->name('signing-assets.store');
Route::put('/firmas/{asset}', [SigningAssetController::class, 'update'])->name('signing-assets.update');
Route::patch('/firmas/{asset}/revocar', [SigningAssetController::class, 'revoke'])->name('signing-assets.revoke');
Route::delete('/firmas/{asset}', [SigningAssetController::class, 'destroy'])->name('signing-assets.destroy');

Route::get('/inventario', [SpecializedToolsController::class, 'inventory'])->name('inventory.index');
Route::get('/ia-licitaciones', [SpecializedToolsController::class, 'tenderAi'])->name('tender-ai.index');
Route::post('/ia-licitaciones/{tender}/analizar', [SpecializedToolsController::class, 'analyzeTender'])->name('tender-ai.analyze');
Route::get('/calculadora-oferta', [SpecializedToolsController::class, 'offerCalculator'])->name('offer-calculator.index');
Route::post('/calculadora-oferta/calcular', [SpecializedToolsController::class, 'calculateOffer'])->name('offer-calculator.calculate');
Route::get('/generador-documentos', [SpecializedToolsController::class, 'documents'])->name('generated-documents.index');
Route::post('/generador-documentos', [SpecializedToolsController::class, 'generateDocument'])->name('generated-documents.generate');
Route::get('/generador-documentos/{generatedDocument}/pdf', [SpecializedToolsController::class, 'documentPdf'])->name('generated-documents.pdf');
Route::get('/notificaciones', [SpecializedToolsController::class, 'notifications'])->name('notifications.index');
Route::post('/notificaciones', [SpecializedToolsController::class, 'saveNotification'])->name('notifications.store');
Route::put('/notificaciones/{template}', [SpecializedToolsController::class, 'saveNotification'])->name('notifications.update');
Route::delete('/notificaciones/{template}', [SpecializedToolsController::class, 'deleteNotification'])->name('notifications.destroy');
Route::get('/calendario', [SpecializedToolsController::class, 'calendar'])->name('calendar.index');
Route::post('/calendario', [SpecializedToolsController::class, 'saveEvent'])->name('calendar.store');
Route::put('/calendario/{event}', [SpecializedToolsController::class, 'saveEvent'])->name('calendar.update');
Route::delete('/calendario/{event}', [SpecializedToolsController::class, 'deleteEvent'])->name('calendar.destroy');
Route::get('/capacitacion', [SpecializedToolsController::class, 'training'])->name('training.index');
Route::put('/capacitacion', [SpecializedToolsController::class, 'saveTraining'])->name('training.save');
Route::get('/configuracion', [SpecializedToolsController::class, 'settings'])->name('settings.index');
Route::put('/configuracion', [SpecializedToolsController::class, 'saveSettings'])->name('settings.update');
Route::post('/configuracion/logo', [SpecializedToolsController::class, 'uploadLogo'])->name('settings.logo');
Route::get('/configuracion/preview.pdf', [SpecializedToolsController::class, 'settingsPreview'])->name('settings.preview');
Route::post('/configuracion/seed', [SpecializedToolsController::class, 'seed'])->name('settings.seed');
Route::post('/configuracion/reset', [SpecializedToolsController::class, 'reset'])->name('settings.reset');
Route::get('/sops', [SpecializedToolsController::class, 'sops'])->name('sops.index');
Route::get('/sops/{sop}/pdf', [SpecializedToolsController::class, 'sopPdf'])->whereNumber('sop')->name('sops.pdf');

});
