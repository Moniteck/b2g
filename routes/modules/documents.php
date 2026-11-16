<?php

use App\Http\Controllers\Documents\AttachmentController;
use App\Http\Controllers\Documents\DocumentController;
use App\Services\Documents\Attachables;
use Illuminate\Support\Facades\Route;

/* Directorio Institucional (documentos de la empresa) */

Route::get('/directorio', [DocumentController::class, 'index'])->name('directory.index');
Route::get('/directorio/paquete', [DocumentController::class, 'package'])->middleware(['throttle:exports', 'audit.export'])->name('directory.package');

// Vista de prueba del panel de adjuntos (solo local y pruebas): la usa tests/visual/documentos.mjs.
if (app()->environment(['local', 'testing'])) {
    Route::get('/directorio/adjuntos-prueba/{type}/{id}', [AttachmentController::class, 'preview'])
        ->whereIn('type', array_keys(Attachables::TYPES))->whereNumber('id')->name('attachments.preview');
}

/* Documentos: los nombres de ruta se conservan porque otros módulos los usan */

Route::get('/documentos', [DocumentController::class, 'legacyIndex'])->name('documents.index');
Route::post('/documentos', [DocumentController::class, 'store'])->name('documents.store');

Route::whereNumber('document')->group(function () {
    Route::put('/documentos/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::delete('/documentos/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('/documentos/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/documentos/{document}/vista', [DocumentController::class, 'preview'])->name('documents.preview');
    Route::post('/documentos/{document}/replace', [DocumentController::class, 'replace'])->name('documents.replace');
    Route::get('/documentos/{document}/versiones', [DocumentController::class, 'versions'])->name('documents.versions');
    Route::patch('/documentos/{document}/renovar', [DocumentController::class, 'renew'])->name('documents.renew');

    Route::scopeBindings()->whereNumber('version')->group(function () {
        Route::get('/documentos/{document}/versiones/{version}/descargar', [DocumentController::class, 'versionDownload'])->name('documents.versions.download');
        Route::get('/documentos/{document}/versiones/{version}/vista', [DocumentController::class, 'versionPreview'])->name('documents.versions.preview');
    });
});

/* Adjuntos de cualquier entidad (tipos en App\Services\Documents\Attachables) */

Route::whereIn('type', array_keys(Attachables::TYPES))->whereNumber(['id', 'document'])->group(function () {
    Route::get('/adjuntos/{type}/{id}', [AttachmentController::class, 'index'])->name('attachments.index');
    Route::post('/adjuntos/{type}/{id}', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::delete('/adjuntos/{type}/{id}/{document}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
});
