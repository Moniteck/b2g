<script setup>
// Botón del sistema (píldora de 44 px) sobre `CapButton`, con `Link` de Inertia cuando lleva `href`.
// Props: variant ('primary' | 'secondary' | 'danger'; un solo primario por pantalla), type, href,
//        external (usa <a>, para descargas y enlaces fuera de Inertia), icon (sin `mdi-`),
//        disabled, loading (spinner y bloqueo mientras envía).
// Movimiento: presión al pulsar, el primario se inclina hacia el puntero (v-magnetic) y, tras un envío
// correcto de Inertia, muestra un check animado (lib/motion/navigation.js).
import { Link } from '@inertiajs/vue3';
import CapButton from '@design/vue/CapButton.vue';

defineProps({
    variant: { type: String, default: 'secondary' },
    type: { type: String, default: 'button' },
    href: { type: String, default: '' },
    external: { type: Boolean, default: false },
    icon: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});
</script>

<template>
    <CapButton v-magnetic="variant === 'primary' && !disabled ? 3 : 0" :variant="variant" :type="type" :href="href" :icon="icon" :disabled="disabled" :loading="loading" :link-component="external ? 'a' : Link">
        <slot />
    </CapButton>
</template>
