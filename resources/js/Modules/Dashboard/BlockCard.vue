<script setup>
// Tarjeta de un bloque del Dashboard: título con ícono, línea de contexto, enlace «Ver todo» y contenido.
// Props: title, description, icon (sin `mdi-`), href + linkLabel (enlace de la cabecera), id (para aria).
// Slots: default, actions (en lugar del enlace).
import { Link } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: String, default: '' },
    href: { type: String, default: '' },
    linkLabel: { type: String, default: 'Ver todo' },
    id: { type: String, required: true },
});
</script>

<template>
    <section class="flex min-w-0 flex-col gap-4 rounded-cap-card border border-cap-divider bg-cap-card p-4 sm:p-5" :aria-labelledby="id">
        <header class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-3">
                <span v-if="icon" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-cap-control bg-cap-red-50 text-cap-red-800 dark:bg-cap-red-600/15 dark:text-cap-brand-soft" aria-hidden="true">
                    <i class="mdi text-xl leading-none" :class="`mdi-${icon}`" />
                </span>
                <div class="min-w-0">
                    <h2 :id="id" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ title }}</h2>
                    <p v-if="description" class="mt-0.5 hidden text-xs text-cap-text-soft sm:block">{{ description }}</p>
                </div>
            </div>
            <slot name="actions">
                <Link v-if="href" :href="href" class="shrink-0 rounded-md text-sm text-gray-900 underline decoration-gray-400 underline-offset-2 transition-colors duration-cap-fast ease-cap hover:decoration-current focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-card dark:text-gray-100 dark:focus-visible:ring-gray-100">{{ linkLabel }}</Link>
            </slot>
        </header>
        <slot />
    </section>
</template>
