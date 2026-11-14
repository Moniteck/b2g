<?php

use App\Http\Middleware\ApplyMcpConnection;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveCompanyContext;
use App\Services\Tenancy\CompanyContext;
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
        $middleware->api(prepend: [ResolveCompanyContext::class]);
        // La empresa activa debe fijarse después de autenticar y ANTES de resolver
        // los modelos de la ruta, para que un id de otra empresa responda 404.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveCompanyContext::class,
        );
        $middleware->alias([
            'mcp.scope' => ApplyMcpConnection::class,
        ]);
    })
    ->withSingletons([
        CompanyContext::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp*') || $request->expectsJson(),
        );
    })->create();
