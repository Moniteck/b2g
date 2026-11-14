<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Página de referencia de componentes: solo en entorno local.
if (app()->environment('local')) {
    Route::get('/dev/componentes', fn () => Inertia::render('Dev/Components'))->name('dev.components');
}
