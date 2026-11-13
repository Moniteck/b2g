<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\InstitutionController;
use Illuminate\Support\Facades\Route;

/* Catálogos compartidos del grupo: instituciones y contactos */

Route::get('/instituciones', [InstitutionController::class, 'index'])->name('institutions.index');
Route::get('/instituciones/buscar', [InstitutionController::class, 'search'])->name('institutions.search');
Route::post('/instituciones', [InstitutionController::class, 'store'])->name('institutions.store');
Route::get('/instituciones/{institution}', [InstitutionController::class, 'show'])->whereNumber('institution')->name('institutions.show');
Route::put('/instituciones/{institution}', [InstitutionController::class, 'update'])->whereNumber('institution')->name('institutions.update');
Route::delete('/instituciones/{institution}', [InstitutionController::class, 'destroy'])->whereNumber('institution')->name('institutions.destroy');

Route::get('/contactos', [ContactController::class, 'index'])->name('contacts.index');
Route::get('/contactos/buscar', [ContactController::class, 'search'])->name('contacts.search');
Route::post('/contactos', [ContactController::class, 'store'])->name('contacts.store');
Route::put('/contactos/{contact}', [ContactController::class, 'update'])->whereNumber('contact')->name('contacts.update');
Route::delete('/contactos/{contact}', [ContactController::class, 'destroy'])->whereNumber('contact')->name('contacts.destroy');
