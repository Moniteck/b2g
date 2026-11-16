<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DiagnosticsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Auth\AccessRequestController;
use Illuminate\Support\Facades\Route;

/* Empresas del grupo y membresías (administración completa: solo raíz) */

Route::get('/empresas', [CompanyController::class, 'index'])->name('companies.index');
Route::post('/empresas', [CompanyController::class, 'store'])->name('companies.store');
Route::get('/empresas/{company}', [CompanyController::class, 'show'])->whereNumber('company')->name('companies.show');
Route::put('/empresas/{company}', [CompanyController::class, 'update'])->whereNumber('company')->name('companies.update');
Route::delete('/empresas/{company}', [CompanyController::class, 'destroy'])->whereNumber('company')->name('companies.destroy');
Route::get('/empresas/{company}/logo', [CompanyController::class, 'logo'])->whereNumber('company')->name('companies.logo');
Route::post('/empresas/{company}/logo', [CompanyController::class, 'uploadLogo'])->whereNumber('company')->name('companies.logo.store');
Route::get('/empresas/{company}/usuarios-disponibles', [CompanyController::class, 'availableUsers'])->whereNumber('company')->name('companies.available-users');
Route::post('/empresas/{company}/miembros', [CompanyController::class, 'member'])->whereNumber('company')->name('companies.members.store');
Route::put('/empresas/{company}/miembros/{user}', [CompanyController::class, 'updateMember'])->whereNumber(['company', 'user'])->name('companies.members.update');
Route::delete('/empresas/{company}/miembros/{user}', [CompanyController::class, 'removeMember'])->whereNumber(['company', 'user'])->name('companies.members.destroy');

/* Configuración de la empresa activa. El sembrado de datos es solo por consola. */

Route::get('/configuracion', [SettingsController::class, 'show'])->name('settings.index');
Route::put('/configuracion', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/configuracion/logo', [SettingsController::class, 'uploadLogo'])->name('settings.logo');
Route::delete('/configuracion/logo', [SettingsController::class, 'deleteLogo'])->name('settings.logo.destroy');
Route::get('/configuracion/preview.pdf', [SettingsController::class, 'preview'])->middleware(['throttle:exports', 'audit.export'])->name('settings.preview');
Route::post('/configuracion/reset', [SettingsController::class, 'reset'])->name('settings.reset');

Route::get('/configuracion/usuarios', [AdminUserController::class, 'index'])->name('admin.users');
Route::post('/configuracion/usuarios', [AdminUserController::class, 'store'])->name('admin.users.store');
Route::put('/configuracion/usuarios/{user}/rol', [AdminUserController::class, 'updateRole'])->whereNumber('user')->name('admin.users.role');
Route::post('/configuracion/usuarios/{user}/contrasena', [AdminUserController::class, 'resetPassword'])->whereNumber('user')->middleware('throttle:10,1')->name('admin.users.password');
Route::delete('/configuracion/usuarios/{user}', [AdminUserController::class, 'destroy'])->whereNumber('user')->name('admin.users.destroy');

Route::get('/configuracion/diagnostico', DiagnosticsController::class)->name('settings.diagnostics');
Route::get('/configuracion/auditoria', [AuditLogController::class, 'index'])->name('admin.audit');

/* Página índice de Administración (las tarjetas se filtran por permiso; cada destino autoriza su ruta). */

Route::inertia('/administracion', 'Admin/Index')->name('admin.index');

// Revisión de solicitudes de acceso desde Usuarios (el envío público vive en routes/web.php, grupo `guest`).
Route::post('/configuracion/usuarios/solicitudes/{accessRequest}/aprobar', [AccessRequestController::class, 'approve'])->whereNumber('accessRequest')->name('access-requests.approve');
Route::post('/configuracion/usuarios/solicitudes/{accessRequest}/rechazar', [AccessRequestController::class, 'reject'])->whereNumber('accessRequest')->name('access-requests.reject');
