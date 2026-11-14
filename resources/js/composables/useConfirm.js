// Confirmación como promesa, sobre `CapConfirmDialog`. Reemplaza a `confirm()` del navegador.
// El diálogo lo pinta `<ConfirmDialog />`, que ya está montado en `AppLayout`.
//
//   const confirm = useConfirm();
//   const accepted = await confirm({
//       verb: 'Eliminar', object: `el contacto ${row.name}`, noun: 'contacto',
//       message: 'Se quitará del directorio de la institución. No se puede deshacer.',
//       action: () => visit('delete', `/contactos/${row.id}`),   // opcional: el botón queda con spinner
//   });
//
// Opciones: `verb` + `object` arman el título («¿Eliminar el contacto Ana?») y `verb` + `noun` el botón
// («Eliminar contacto»); `title` y `confirmLabel` los sustituyen. `message` es la consecuencia en una
// frase. `icon` (sin `mdi-`), `acknowledgeLabel` (casilla obligatoria para lo irreversible y masivo).
import { reactive, readonly } from 'vue';

const state = reactive({ open: false, loading: false, options: {} });
let settle = null;

function finish(result) {
    state.open = false;
    state.loading = false;
    settle?.(result);
    settle = null;
}

function normalize(options) {
    const verb = options.verb ?? 'Confirmar';
    return {
        title: options.title ?? `¿${verb} ${options.object ?? 'este registro'}?`,
        message: options.message ?? 'Esta acción no se puede deshacer.',
        confirmLabel: options.confirmLabel ?? [verb, options.noun].filter(Boolean).join(' '),
        icon: options.icon ?? 'alert-outline',
        acknowledgeLabel: options.acknowledgeLabel ?? '',
        action: options.action ?? null,
    };
}

function confirm(options = {}) {
    settle?.(false);
    state.options = normalize(options);
    state.loading = false;
    state.open = true;
    return new Promise((resolve) => {
        settle = resolve;
    });
}

/** Lo usa `ConfirmDialog.vue`; las páginas solo llaman a `useConfirm()`. */
export const confirmHost = {
    state: readonly(state),
    cancel() {
        if (!state.loading) finish(false);
    },
    async accept() {
        const { action } = state.options;
        if (!action) return finish(true);
        state.loading = true;
        try {
            await action();
            finish(true);
        } catch {
            // El error ya lo muestra quien lo produjo (validación en línea o toast de `flash.error`).
            finish(false);
        }
    },
};

export function useConfirm() {
    return confirm;
}
