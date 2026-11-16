// Contrato entre el marco persistente (`AppShell.vue`) y las páginas.
//
// El marco se monta una sola vez (layout por defecto de Inertia en `app.js`) y sobrevive a la
// navegación. Cada página sigue escribiendo `<AppLayout title="…" :sections="…" back-href="…">`:
// dentro del marco, `AppLayout` ya no dibuja nada del marco; solo publica aquí sus datos (título,
// migas, secciones, «Volver», botón flotante) y pinta su contenido. Los slots `header-actions`,
// `topbar-actions` y `search` viajan con <Teleport defer> a los huecos del marco.
//
// Para quien necesite publicar datos sin `AppLayout` (p. ej. una página con su propio contenedor):
//   import { usePageMeta } from '@/Layouts/shell.js';
//   usePageMeta(() => ({ title: 'Mi pantalla', backHref: '/lista' }));
import { inject, onBeforeUnmount, reactive, watchEffect } from 'vue';

export const SHELL_KEY = Symbol('app-shell');

/** Estado que el marco expone a las páginas. */
export function createShellState() {
    return reactive({
        meta: emptyMeta(),
        owner: 0,
        onFab: null,
    });
}

export function emptyMeta() {
    return { title: '', breadcrumbs: null, sections: [], backHref: '', fabLabel: '' };
}

let sequence = 0;

/**
 * Publica los datos de la página en el marco mientras la página está montada.
 * `source` es una función que devuelve `{ title, breadcrumbs, sections, backHref, fabLabel }`.
 * `onFab` recibe el clic del botón flotante (móvil). Devuelve el estado del marco, o null si la
 * página se pinta fuera de él.
 */
export function usePageMeta(source, { onFab = null } = {}) {
    const shell = inject(SHELL_KEY, null);
    if (!shell) return null;

    const id = ++sequence;
    shell.owner = id;
    shell.onFab = onFab;

    const stop = watchEffect(() => {
        const value = source() ?? {};
        if (shell.owner !== id) return;
        shell.meta = { ...emptyMeta(), ...value, sections: value.sections ?? [] };
    });

    onBeforeUnmount(() => {
        stop();
        // Si ya llegó la página siguiente, no se borra lo que ella publicó.
        if (shell.owner !== id) return;
        shell.owner = 0;
        shell.onFab = null;
    });

    return shell;
}

export function useShell() {
    return inject(SHELL_KEY, null);
}
