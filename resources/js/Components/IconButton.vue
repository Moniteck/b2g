<script setup>
// Botón de solo ícono (36 × 36 px del recetario; 44 × 44 px en táctil, por debajo de `lg`, B-23). Siempre con `label`: es su nombre accesible y su tooltip.
// Props: icon (sin `mdi-`), label, href (Link de Inertia), external (<a>), disabled, type.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { iconButton } from '../lib/ui.js';

// Área táctil mínima de 44 px en tableta y móvil.
const TOUCH = 'max-lg:h-11 max-lg:w-11';

const props = defineProps({
    icon: { type: String, required: true },
    label: { type: String, required: true },
    href: { type: String, default: '' },
    external: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    type: { type: String, default: 'button' },
});

const tag = computed(() => (props.href ? (props.external ? 'a' : Link) : 'button'));
const attributes = computed(() => (props.href ? { href: props.href } : { type: props.type, disabled: props.disabled }));
</script>

<template>
    <component :is="tag" v-bind="attributes" :aria-label="label" :title="label" :class="[iconButton, TOUCH]">
        <i class="mdi text-xl leading-none" :class="`mdi-${icon}`" aria-hidden="true" />
    </component>
</template>
