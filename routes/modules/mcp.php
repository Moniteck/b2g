<?php

use App\Http\Controllers\Mcp\McpConnectionController;
use App\Http\Controllers\Mcp\McpConsentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Conexiones MCP y consentimiento OAuth
|--------------------------------------------------------------------------
|
| Pantallas web para personas. El servidor MCP y las rutas OAuth/DCR viven en
| `routes/ai.php`. La pantalla de consentimiento la sirve Passport en
| GET /oauth/authorize y envía la elección (empresas y nivel) aquí.
|
*/

Route::post('/oauth/mcp/aprobar', [McpConsentController::class, 'approve'])
    ->middleware('throttle:20,1')
    ->name('mcp.consent.approve');

Route::get('/configuracion/mcp', [McpConnectionController::class, 'index'])->name('mcp.connections.index');
Route::delete('/configuracion/mcp/{connection}', [McpConnectionController::class, 'destroy'])
    ->whereNumber('connection')
    ->name('mcp.connections.destroy');
