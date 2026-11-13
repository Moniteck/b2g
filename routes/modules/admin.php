<?php

use App\Http\Controllers\ActiveCompanyController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\SpecializedToolsController;
use Illuminate\Support\Facades\Route;

/* Empresa activa (selector de la cabecera) */

Route::post('/empresa-activa', ActiveCompanyController::class)->name('companies.switch');

/* Empresas del grupo y membresías */

Route::get('/empresas', [CompanyController::class, 'index'])->name('companies.index');
Route::post('/empresas', [CompanyController::class, 'store'])->name('companies.store');
Route::put('/empresas/{company}', [CompanyController::class, 'update'])->name('companies.update');
Route::delete('/empresas/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
Route::post('/empresas/{company}/miembros', [CompanyController::class, 'member'])->name('companies.members.store');
Route::delete('/empresas/{company}/miembros/{user}', [CompanyController::class, 'removeMember'])->name('companies.members.destroy');

/* Configuración de la empresa activa. El sembrado de datos es solo por consola. */

Route::get('/configuracion', [SpecializedToolsController::class, 'settings'])->name('settings.index');
Route::put('/configuracion', [SpecializedToolsController::class, 'saveSettings'])->name('settings.update');
Route::post('/configuracion/logo', [SpecializedToolsController::class, 'uploadLogo'])->name('settings.logo');
Route::get('/configuracion/preview.pdf', [SpecializedToolsController::class, 'settingsPreview'])->name('settings.preview');
Route::post('/configuracion/reset', [SpecializedToolsController::class, 'reset'])->name('settings.reset');

Route::get('/configuracion/usuarios', [AdminUserController::class, 'index'])->name('admin.users');
Route::put('/configuracion/usuarios/{user}/rol', [AdminUserController::class, 'updateRole'])->name('admin.users.role');

Route::get('/configuracion/diagnostico', DiagnosticsController::class)->name('settings.diagnostics');
