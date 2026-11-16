<?php

use App\Http\Middleware\ApplyMcpConnection;
use App\Http\Middleware\AuditExport;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PrivateResponses;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\ResolveCompanyContext;
use App\Services\Tenancy\CompanyContext;
use App\Support\TooManyRequests;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class, ResolveCompanyContext::class]);
        // Seguridad: una sesión cuya contraseña cambió queda cerrada (M1) y cabeceras con CSP (M3).
        $middleware->authenticateSessions();
        $middleware->web(append: [SecurityHeaders::class, PrivateResponses::class]);
        // Solo se atienden peticiones dirigidas al host de APP_URL (B3); fuera de `local`.
        $middleware->prepend(TrustHosts::class);
        $middleware->api(prepend: [ResolveCompanyContext::class]);
        // La empresa activa debe fijarse después de autenticar y ANTES de resolver
        // los modelos de la ruta, para que un id de otra empresa responda 404.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveCompanyContext::class,
        );
        $middleware->alias([
            'mcp.scope' => ApplyMcpConnection::class,
            'audit.export' => AuditExport::class,
        ]);
    })
    ->withSingletons([
        CompanyContext::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp*') || $request->expectsJson(),
        );
        // Límite de peticiones superado: aviso en español (toast en pantallas, JSON 429 en la API).
        $exceptions->render(fn (ThrottleRequestsException $exception, Request $request) => TooManyRequests::respond(
            $request,
            TooManyRequests::message((int) ($exception->getHeaders()['Retry-After'] ?? 60)),
            $exception->getHeaders(),
        ));
    })->create();
