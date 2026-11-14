<?php

use App\Mcp\Servers\CrmServer;
use App\Mcp\Support\ClientJanitor;
use App\Mcp\Support\LimitClientRegistration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/*
|--------------------------------------------------------------------------
| Servidor MCP del CRM B2G
|--------------------------------------------------------------------------
|
| Punto de entrada para ChatGPT, Claude y cualquier cliente MCP remoto:
|   POST {APP_URL}/mcp/crm
|
| Autenticación: OAuth 2.1 con Authorization Code + PKCE (Passport) y registro
| dinámico de clientes (DCR). Al aprobar el acceso el usuario elige empresas y
| nivel (solo lectura o lectura y escritura) y nace la `McpConnection`
| (`McpConsentController`, en routes/modules/mcp-admin.php). El permiso efectivo
| lo aplica el CRM: conexión + rol por empresa + policy de cada recurso.
|
*/

Route::middleware('throttle:mcp')->group(function () {
    Mcp::web('/mcp/crm', CrmServer::class)
        ->middleware(['auth:api', 'mcp.scope']);

    // Metadatos OAuth (RFC 8414 y 9728). El registro va aparte, con límite por IP.
    Mcp::oauthRoutes(registration: false);
});

// Registro dinámico de clientes: como mucho N registros por hora y por IP.
Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware(LimitClientRegistration::class)
    ->name('mcp.oauth.register');

// Limpieza diaria de clientes OAuth huérfanos y de códigos de confirmación vencidos.
// (Este archivo también se carga en consola, donde corre el scheduler.)
Artisan::command('mcp:limpiar-clientes {--horas= : Antigüedad mínima del cliente huérfano}', function (ClientJanitor $janitor) {
    $result = $janitor->prune($this->option('horas') !== null ? (int) $this->option('horas') : null);
    $this->info("Clientes OAuth huérfanos eliminados: {$result['clients']}. Códigos de confirmación purgados: {$result['confirmations']}.");
})->purpose('Elimina clientes MCP registrados que nunca se aprobaron o ya no se usan.');

Schedule::command('mcp:limpiar-clientes')
    ->dailyAt('03:45')
    ->timezone('America/Tegucigalpa')
    ->name('mcp-limpiar-clientes')
    ->withoutOverlapping(30);
