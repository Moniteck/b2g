<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/* Dashboard, reportes e indicadores */

Route::get('/', DashboardController::class)->name('dashboard');
