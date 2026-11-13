import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';

const SYSTEM_NAME = 'CRM B2G';

// Carga perezosa: cada página viaja en su propio trozo y solo se descarga al visitarla.
const pages = import.meta.glob('./Pages/**/*.vue');

createInertiaApp({
    title: (title) => (title ? `${title} · ${SYSTEM_NAME}` : SYSTEM_NAME),
    resolve: async (name) => {
        const load = pages[`./Pages/${name}.vue`];
        if (!load) throw new Error(`No existe la página «${name}».`);
        return (await load()).default;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: 'var(--cap-brand)', showSpinner: false },
});

// Solo en desarrollo: las pruebas visuales (tests/visual) abren con esto una página sin pasar por su ruta.
if (import.meta.env.DEV) window.__inertia = { router };
