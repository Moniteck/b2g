<script setup>
// Datos de la pantalla para el marco y envoltorio del contenido.
//
//   <AppLayout title="Licitaciones" :sections="…" back-href="/licitaciones" fab-label="Nueva" @fab="crear"> …contenido… </AppLayout>
//
// El marco (sidebar, cabecera, barra móvil, hoja «Más», toasts, paleta ⌘K) es `AppShell.vue` y es
// persistente: Inertia lo monta una sola vez como layout por defecto (`app.js`) y no se vuelve a
// montar al navegar. Este componente solo publica en él los datos de la página (`usePageMeta`) y
// pinta el contenido; las páginas no cambian.
// Props (todas opcionales):
//   title        título de la cabecera (escritorio y móvil) y de la pestaña; por defecto, el del menú.
//   breadcrumbs  [{ label, href? }]; por defecto, el grupo del menú.
//   sections     [{ label, href, active? }] de 2 a 5 vistas hermanas del módulo (píldoras en la cabecera).
//   backHref     flecha «Volver» en la barra superior móvil (pantallas de detalle).
//   fabLabel     botón flotante de crear en móvil (emite `fab`).
// Slots: default (contenido; ya va envuelto en `flex flex-col gap-6`), header-actions (cabecera de
//        escritorio), topbar-actions (barra superior móvil), search (buscador propio en la cabecera).
// Si la página se pinta sin el marco persistente (p. ej. una prueba que la monta sola), este
// componente monta el marco completo alrededor del contenido, como antes.
import { Head } from '@inertiajs/vue3';
import { useShell, usePageMeta } from './shell.js';
import AppShell from './AppShell.vue';

const props = defineProps({
    title: { type: String, default: '' },
    breadcrumbs: { type: Array, default: null },
    sections: { type: Array, default: () => [] },
    backHref: { type: String, default: '' },
    fabLabel: { type: String, default: '' },
});

const emit = defineEmits(['fab']);

const insideShell = Boolean(useShell());

if (insideShell) {
    usePageMeta(
        () => ({
            title: props.title,
            breadcrumbs: props.breadcrumbs,
            sections: props.sections,
            backHref: props.backHref,
            fabLabel: props.fabLabel,
        }),
        { onFab: () => emit('fab') },
    );
}
</script>

<template>
    <template v-if="insideShell">
        <Head v-if="title" :title="title" />
        <Teleport v-if="$slots['header-actions']" defer to="#app-shell-header-actions"><slot name="header-actions" /></Teleport>
        <Teleport v-if="$slots['topbar-actions']" defer to="#app-shell-topbar-actions"><slot name="topbar-actions" /></Teleport>
        <Teleport v-if="$slots.search" defer to="#app-shell-search"><slot name="search" /></Teleport>
        <div class="m-page flex min-w-0 flex-col gap-6 [&>*]:min-w-0">
            <slot />
        </div>
    </template>
    <AppShell v-else>
        <AppLayout v-bind="props" @fab="emit('fab')">
            <template v-for="(_, name) in $slots" #[name]="scope"><slot :name="name" v-bind="scope ?? {}" /></template>
        </AppLayout>
    </AppShell>
</template>
