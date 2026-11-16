<script setup>
// Avatar de una persona: foto o iniciales sobre el neutro cálido.
// Props: name (de ahí salen las iniciales y el `title`), src (foto), initials (las manda el servidor
//        en `auth.user.initials`), size ('sm' 24 px | 'md' 32 px | 'lg' 40 px).
// Decorativo junto al nombre; si va solo, el `title` da el nombre.
import { computed } from 'vue';
import { initialsOf } from '@design/vue/helpers.js';

const props = defineProps({
    name: { type: String, default: '' },
    src: { type: String, default: '' },
    initials: { type: String, default: '' },
    size: { type: String, default: 'md' },
});

const SIZES = { sm: 'h-6 w-6 text-xs', md: 'h-8 w-8 text-xs', lg: 'h-10 w-10 text-sm' };

const letters = computed(() => props.initials || initialsOf(props.name || '?'));
</script>

<template>
    <span class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-cap-taupe font-semibold text-cap-chrome" :class="SIZES[size] ?? SIZES.md" :title="name || undefined">
        <img v-if="src" :src="src" alt="" class="h-full w-full object-cover">
        <template v-else>{{ letters }}</template>
    </span>
</template>
