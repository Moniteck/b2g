{{--
    Consentimiento OAuth del servidor MCP (Passport::authorizationView).
    Passport entrega: $client, $user, $scopes, $request, $authToken.
    Se elige en qué empresas y con qué nivel opera el asistente; al aprobar, McpConsentController
    crea la conexión. Misma estructura que la pantalla de acceso (<x-cap.login>).
--}}
@php
    $redirectUri = $request->query('redirect_uri', $client->redirect_uris[0] ?? null);
    $screen = \App\Http\Controllers\Mcp\McpConsentController::screen($user, $client, is_string($redirectUri) ? $redirectUri : null);
    // Tras un error se respeta lo que la persona envió (también «ninguna»); si no, la selección por defecto.
    $selectedCompanies = collect(session()->hasOldInput() ? (array) old('company_ids', []) : collect($screen['companies'])->where('checked', true)->pluck('id')->all())->map(fn ($id) => (int) $id)->all();
    $level = $screen['level'];
    $clientName = $client->name ?: 'Cliente MCP';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    @include('mcp.partials.head', ['title' => 'Autorizar acceso'])
</head>
<body>
<main class="cap-login">
    <section class="cap-login__photo">
        <div class="cap-login__brand-tag cap-glass"><span class="cap-shell-logo" aria-hidden="true">{{ config('design.company.mark', 'C') }}</span>CRM B2G</div>
        <div class="cap-login__quote cap-glass">
            <p class="cap-login__quote-text">Conecta tu asistente de IA al CRM: consulta licitaciones, plazos, documentos e inventario sin salir de la conversación.</p>
            <small class="cap-login__quote-owner">{{ config('design.company.loginOwner') }}</small>
        </div>
    </section>

    <section class="cap-login__card cap-glass--light">
        <div class="cap-login__form">
            <form method="POST" action="{{ route('mcp.consent.approve') }}" data-mcp-consent data-busy-label="Autorizando…" novalidate>
                @csrf
                <input type="hidden" name="auth_token" value="{{ $authToken }}">

                <h1 class="cap-login__title">Autorizar a {{ $clientName }}</h1>
                <p class="cap-login__subtitle">{{ $clientName }} quiere usar el CRM B2G en tu nombre. Revisa qué podrá hacer antes de aprobar.</p>

                @if ($screen['unverified'] ?? false)
                    <div class="mb-4" data-mcp-unverified>
                        <x-cap.alert status="warning" icon="shield-alert-outline" title="Cliente no verificado" detail="Este cliente se registró solo: el nombre «{{ $clientName }}» y su sitio los declaró él mismo y nadie los comprobó. Aprueba únicamente si tú iniciaste la conexión desde tu asistente y reconoces la dirección de regreso." />
                    </div>
                @endif

                @if ($errors->any())
                    <x-cap.alert status="error" title="Revisa la autorización" :detail="$errors->first()" />
                @endif

                <dl class="mb-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-cap-control border border-cap-divider bg-cap-card px-3 py-2.5 text-sm">
                    <dt class="text-cap-text-soft">Cuenta</dt>
                    <dd class="min-w-0 truncate font-medium text-gray-900 dark:text-gray-100">{{ $user->email }}</dd>
                    @if ($screen['redirectHost'])
                        <dt class="text-cap-text-soft">Regresa a</dt>
                        <dd class="min-w-0 break-all font-medium text-gray-900 dark:text-gray-100">{{ $screen['redirectHost'] }}</dd>
                    @endif
                </dl>

                <fieldset class="mb-4">
                    <legend class="mb-2 font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Empresas a las que podrá acceder</legend>
                    @if ($screen['companies'] === [])
                        <x-cap.alert status="warning" title="No perteneces a ninguna empresa" detail="Pide a un administrador que te agregue a una empresa antes de conectar un asistente." />
                    @else
                        <div class="flex max-h-48 flex-col gap-2 overflow-y-auto">
                            @foreach ($screen['companies'] as $company)
                                <label class="cap-check rounded-cap-control border border-cap-divider bg-cap-card px-3 py-2.5 text-sm text-gray-900 dark:text-gray-100">
                                    <input class="cap-check__box" type="checkbox" name="company_ids[]" value="{{ $company['id'] }}" @checked(in_array($company['id'], $selectedCompanies, true))>
                                    <span class="min-w-0 flex-1 truncate font-medium">{{ $company['name'] }}</span>
                                    <span class="shrink-0 text-xs text-cap-text-soft">{{ $company['role'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('company_ids')<p class="mt-1 text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ $message }}</p>@enderror
                    @error('company_ids.*')<p class="mt-1 text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ $message }}</p>@enderror
                </fieldset>

                <fieldset class="mb-4">
                    <legend class="mb-2 font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Nivel de acceso</legend>
                    <div class="flex flex-col gap-2">
                        <label class="cap-check items-start rounded-cap-control border border-cap-divider bg-cap-card px-3 py-2.5 text-sm">
                            <input class="cap-check__box mt-0.5 rounded-full" type="radio" name="level" value="read" @checked($level === 'read')>
                            <span>
                                <span class="block font-medium text-gray-900 dark:text-gray-100">Solo lectura</span>
                                <span class="block text-xs text-cap-text-soft">Consulta licitaciones, calendario, documentos, inventario y clientes. No cambia nada.</span>
                            </span>
                        </label>
                        <label class="cap-check items-start rounded-cap-control border border-cap-divider bg-cap-card px-3 py-2.5 text-sm">
                            <input class="cap-check__box mt-0.5 rounded-full" type="radio" name="level" value="write" @checked($level === 'write')>
                            <span>
                                <span class="block font-medium text-gray-900 dark:text-gray-100">Lectura y escritura</span>
                                <span class="block text-xs text-cap-text-soft">Además crea y actualiza registros con los permisos de tu rol en cada empresa.</span>
                            </span>
                        </label>
                    </div>
                    @error('level')<p class="mt-1 text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ $message }}</p>@enderror
                </fieldset>

                <p class="mb-4 text-xs text-cap-text-soft">
                    Firmar, registrar una oferta como Ganada o Perdida y eliminar siempre piden una confirmación adicional.
                    El acceso vence en {{ $screen['days'] }} días y puedes revocarlo cuando quieras en Configuración › Conexiones MCP.
                </p>

                <button class="cap-button cap-button--primary cap-login__submit" type="submit" @disabled($screen['companies'] === [])>Autorizar acceso</button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="mt-3">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->query('state') }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button class="cap-button cap-button--secondary cap-login__submit" type="submit">Cancelar</button>
            </form>
        </div>
    </section>

    <button class="cap-login__theme" type="button" data-mcp-theme aria-label="Alternar modo oscuro"><i class="mdi mdi-weather-night" aria-hidden="true"></i></button>
</main>

<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    // Botón ocupado al enviar (evita el doble clic) y conmutador de tema, como en la pantalla de acceso.
    document.querySelectorAll('[data-mcp-consent]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = form.dataset.busyLabel;
        });
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        document.querySelectorAll('[data-mcp-consent] [type="submit"]').forEach((button) => {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = 'Autorizar acceso';
        });
    });
    document.querySelector('[data-mcp-theme]')?.addEventListener('click', () => {
        const dark = document.documentElement.classList.toggle('dark');
        try { localStorage.theme = dark ? 'dark' : 'light'; } catch { /* almacenamiento bloqueado */ }
    });
</script>
</body>
</html>
