import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import AppShell from './Layouts/AppShell.vue';
import motion from './lib/motion/index.js';
import './pwa/index.js';

const SYSTEM_NAME = 'CRM B2G';

// Carga perezosa: cada página viaja en su propio trozo y solo se descarga al visitarla.
const pages = import.meta.glob('./Pages/**/*.vue');

// Marco persistente: toda página interna se pinta dentro de `AppShell`, que Inertia conserva entre
// visitas (no se vuelve a montar). Sin marco: el acceso y las páginas de error sin sesión.
function layoutFor(name, page) {
    if (name.startsWith('Auth/')) return null;
    if (name.startsWith('Errors/') && !page?.props?.auth?.user) return null;
    return AppShell;
}

createInertiaApp({
    title: (title) => (title ? `${title} · ${SYSTEM_NAME}` : SYSTEM_NAME),
    resolve: async (name) => {
        const load = pages[`./Pages/${name}.vue`];
        if (!load) throw new Error(`No existe la página «${name}».`);
        return (await load()).default;
    },
    layout: layoutFor,
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).use(motion).mount(el);
    },
    progress: { color: 'var(--cap-brand)', showSpinner: false, delay: 120 },
});

// Solo en desarrollo: las pruebas visuales (tests/visual) abren con esto una página sin pasar por su ruta.
if (import.meta.env.DEV) window.__inertia = { router };
