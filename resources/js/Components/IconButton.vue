<script setup>
// Botón de solo ícono (36 × 36 px) del recetario. Siempre con `label`: es su nombre accesible y su tooltip.
// Props: icon (sin `mdi-`), label, href (Link de Inertia), external (<a>), disabled, type.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { iconButton } from '../lib/ui.js';

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
    <component :is="tag" v-bind="attributes" :aria-label="label" :title="label" :class="iconButton">
        <i class="mdi text-xl leading-none" :class="`mdi-${icon}`" aria-hidden="true" />
    </component>
</template>
