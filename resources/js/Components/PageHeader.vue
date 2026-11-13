<script setup>
// Barra de acciones de la página: primera fila del contenido. El título ya lo pone el header del
// marco (prop `title` de `AppLayout`), así que aquí solo va una línea de contexto y las acciones.
// Props: description. Slots: default (reemplaza la descripción), actions (secundarios y, al final,
// el único primario de la pantalla).
defineProps({
    description: { type: String, default: '' },
});
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0 text-sm text-cap-text-soft">
            <slot>{{ description }}</slot>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
