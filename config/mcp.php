<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    // ChatGPT y Claude (web y escritorio) por defecto. `http://localhost` (clientes locales como
    // Claude Code o el inspector MCP) solo fuera de producción, y en producción nunca «*».
    'redirect_domains' => (static function (): array {
        $production = env('APP_ENV') === 'production';
        $configured = explode(',', (string) env('MCP_REDIRECT_DOMAINS', 'https://chatgpt.com,https://claude.ai,https://claude.com'));

        if (! $production) {
            $configured[] = 'http://localhost';
            $configured[] = 'http://127.0.0.1';
        }

        return array_values(array_unique(array_filter(array_map('trim', $configured), function (string $domain) use ($production): bool {
            if ($domain === '') {
                return false;
            }

            return ! $production || ($domain !== '*' && ! preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])#i', $domain));
        })));
    })(),

    // Orígenes de navegador aceptados por `ValidateOrigin` además de APP_URL y localhost.
    // ChatGPT y Claude llaman desde su servidor (sin cabecera Origin): no hace falta abrirlo.
    // Nunca «*».
    'allowed_origins' => [],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MCP_CUSTOM_SCHEMES', 'claude,cursor,vscode'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The max
    | number of tool calls limits how many tools search requests can call
    | while the maximum output bytes value will limit the result sizes.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Clients
    |--------------------------------------------------------------------------
    |
    | Here you may configure named MCP clients that connect to external MCP
    | servers. Every client may define a URL for the remote server, or a
    | command and arguments for local servers plus connection options.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | CRM B2G: conexiones, tokens y confirmaciones
    |--------------------------------------------------------------------------
    |
    | Vigencia de una conexión aprobada (luego hay que volver a aprobarla), de
    | los códigos de confirmación de las acciones sensibles, tope de subida
    | por MCP, registros de clientes por IP y limpieza de clientes huérfanos.
    |
    */

    'crm' => [
        'connection_days' => 90,
        'confirmation_minutes' => 10,
        'upload_max_kilobytes' => 10 * 1024,
        'registrations_per_hour' => 10,
        'orphan_client_hours' => 24,
        'last_used_resolution_seconds' => 60,
    ],

    'clients' => [
        'github' => [
            'url' => env('MCP_GITHUB_URL', 'https://api.githubcopilot.com/mcp/'),
            'token' => env('MCP_GITHUB_TOKEN'),
            'headers' => [
                'X-MCP-Readonly' => 'true',
            ],
        ],

        'linear' => [
            'url' => env('MCP_LINEAR_URL', 'https://mcp.linear.app/mcp'),
            'token' => env('MCP_LINEAR_TOKEN'),
        ],

        'playwright' => [
            'command' => 'npx',
            'args' => ['-y', '@playwright/mcp@latest', '--headless'],
        ],
    ],

];
