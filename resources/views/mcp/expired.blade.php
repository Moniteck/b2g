{{-- La solicitud OAuth ya no está en la sesión (venció, se usó o se abrió en otra pestaña). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    @include('mcp.partials.head', ['title' => 'Autorización vencida'])
</head>
<body>
<main class="cap-login">
    <section class="cap-login__photo">
        <div class="cap-login__brand-tag cap-glass"><span class="cap-shell-logo" aria-hidden="true">{{ config('design.company.mark', 'C') }}</span>CRM B2G</div>
    </section>
    <section class="cap-login__card cap-glass--light">
        <div class="cap-login__form">
            <h1 class="cap-login__title">La autorización venció</h1>
            <p class="cap-login__subtitle">Esta solicitud ya se usó o expiró. Vuelve a conectar el CRM desde tu asistente (ChatGPT o Claude) para empezar de nuevo.</p>
            <x-cap.alert status="info" title="No se concedió ningún acceso" detail="Puedes cerrar esta ventana." />
        </div>
    </section>
</main>
</body>
</html>
