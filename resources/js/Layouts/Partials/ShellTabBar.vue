<script setup>
// Barra de pestañas inferior (móvil < 1024 px): 4 destinos + «Más». La etiqueta va en su propio
// <span> para poder recortarla con puntos suspensivos a 360 px (B-14).
import { Link } from '@inertiajs/vue3';
import { iconClass } from '@design/vue/helpers.js';

defineProps({
    tabs: { type: Array, default: () => [] },
    moreOpen: { type: Boolean, default: false },
    moreActive: { type: Boolean, default: false },
});

const emit = defineEmits(['more']);
</script>

<template>
    <nav class="cap-shell-tabbar app-tabbar cap-glass" aria-label="Destinos principales">
        <Link v-for="tab in tabs" :key="tab.href" class="cap-shell-tabbar__tab" :href="tab.href" :aria-current="tab.active ? 'page' : undefined">
            <i class="mdi" :class="iconClass(tab)" aria-hidden="true" />
            <span class="app-tabbar__label">{{ tab.label }}</span>
        </Link>
        <button
            class="cap-shell-tabbar__tab cap-shell-tabbar__tab--more"
            :class="{ 'app-tabbar__more--active': moreActive }"
            type="button"
            :aria-expanded="String(moreOpen)"
            aria-controls="cap-more-sheet"
            @click="emit('more')"
        >
            <i class="mdi mdi-dots-horizontal" aria-hidden="true" />
            <span class="app-tabbar__label">Más</span>
        </button>
    </nav>
</template>
