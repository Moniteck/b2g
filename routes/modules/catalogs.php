<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\InstitutionController;
use Illuminate\Support\Facades\Route;

/* Catálogos compartidos: instituciones y contactos */

Route::get('/instituciones', [InstitutionController::class, 'index'])->name('institutions.index');
Route::put('/instituciones/{institution}', [InstitutionController::class, 'update'])->name('institutions.update');

Route::get('/contactos', [ContactController::class, 'index'])->name('contacts.index');
Route::post('/contactos', [ContactController::class, 'store'])->name('contacts.store');
Route::delete('/contactos/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
