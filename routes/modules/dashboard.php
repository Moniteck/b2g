<?php

use App\Http\Controllers\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

/* Dashboard, reportes e indicadores */

Route::get('/', DashboardController::class)->name('dashboard');
