<?php

use App\Models\Company;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| Servidor MCP del CRM B2G
|--------------------------------------------------------------------------
|
| Punto de entrada para ChatGPT, Claude y cualquier cliente MCP remoto.
|
| Autenticacion: OAuth 2.1 con Authorization Code + PKCE (Passport), con
| registro dinamico de clientes (DCR). El permiso efectivo lo aplica el CRM
| segun empresa, rol y recurso, nunca el cliente MCP.
|
*/

Route::middleware('throttle:mcp')->group(function () {
    Mcp::web('/mcp/crm', \App\Mcp\Servers\CrmServer::class)
        ->middleware(['auth:api', 'mcp.scope']);
});

Route::middleware('throttle:mcp')->group(function () {
    Mcp::oauthRoutes(registration: true);
});
