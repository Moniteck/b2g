<script setup>
// Página de error propia (403, 404, 419, 429, 500, 503), en español y con Lici en un estado acorde.
// Con sesión se pinta dentro del marco persistente (app.js); sin sesión, sola sobre el lienzo.
// Props: status, message (del servidor, `App\Support\ErrorPage`), retryAfter (segundos, 429).
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import '@/Modules/Auth/auth.css';
import Mascot from '@/Modules/Auth/Mascot.vue';
import AppButton from '@/Components/AppButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    status: { type: Number, required: true },
    message: { type: String, default: '' },
    retryAfter: { type: [String, Number], default: null },
});

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const COPY = {
    403: { title: 'No tienes acceso a esta pantalla', detail: 'Tu rol en la empresa activa no lo permite. Si lo necesitas, pídeselo a un administrador o cambia de empresa.', mascot: 'shy' },
    404: { title: 'No encontramos esta página', detail: 'El enlace puede estar mal escrito, el registro se eliminó o pertenece a otra empresa del grupo.', mascot: 'thinking' },
    419: { title: 'Tu sesión expiró', detail: 'Por seguridad la sesión se cierra tras un tiempo sin actividad. Recarga la página para continuar.', mascot: 'sleepy' },
    429: { title: 'Vas muy rápido', detail: 'Hiciste demasiadas solicitudes seguidas.', mascot: 'worried' },
    500: { title: 'Algo salió mal', detail: 'El servidor no pudo completar la solicitud. Ya quedó registrado; inténtalo de nuevo en un momento.', mascot: 'worried' },
    503: { title: 'Estamos en mantenimiento', detail: 'Estamos actualizando el sistema. Vuelve a intentarlo en unos minutos.', mascot: 'sleepy' },
};

const copy = computed(() => COPY[props.status] ?? COPY[500]);
const detail = computed(() => (props.status === 429 && props.message ? props.message : copy.value.detail));
const reloadable = computed(() => [419, 429, 500, 503].includes(props.status));
const home = computed(() => (signedIn.value ? '/' : '/login'));

function reload() {
    window.location.reload();
}
</script>

<template>
    <component :is="signedIn ? AppLayout : 'div'" :title="signedIn ? copy.title : undefined" :class="signedIn ? undefined : 'app-error-standalone'">
        <Head v-if="!signedIn" :title="copy.title" />
        <section class="app-error" :aria-labelledby="`error-${status}`">
            <Mascot :state="copy.mascot" :size="148" :label="`Lici, ${copy.title.toLowerCase()}`" />
            <p class="app-error__code">Error {{ status }}</p>
            <h2 :id="`error-${status}`" class="app-error__title">{{ copy.title }}</h2>
            <p class="app-error__detail">{{ detail }}</p>
            <div class="app-error__actions">
                <AppButton v-if="reloadable" variant="primary" icon="refresh" @click="reload">Recargar la página</AppButton>
                <AppButton :variant="reloadable ? 'secondary' : 'primary'" icon="home-outline" :href="home">{{ signedIn ? 'Ir al Dashboard' : 'Ir al inicio de sesión' }}</AppButton>
                <Link v-if="signedIn && status === 403" href="/administracion" class="app-error__link">Ver lo que sí puedo administrar</Link>
            </div>
        </section>
    </component>
</template>
