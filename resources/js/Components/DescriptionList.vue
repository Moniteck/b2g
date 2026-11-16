<script setup>
// Datos de un registro: etiqueta encima del valor, dos columnas desde `md`.
// Props: items ([{ key?, label, value, wide? }]; `wide` ocupa las dos columnas; sin valor muestra «—»),
//        columns (1, 2 o 3). Slot por ítem con el nombre de su `key` ({ item }) para badges, enlaces o montos.
import { text } from '@/lib/format.js';

defineProps({
    items: { type: Array, required: true },
    columns: { type: Number, default: 2 },
});

const GRIDS = { 1: '', 2: 'md:grid-cols-2', 3: 'md:grid-cols-3' };
</script>

<template>
    <dl class="grid gap-x-6 gap-y-4" :class="GRIDS[columns] ?? GRIDS[2]">
        <div v-for="item in items" :key="item.key ?? item.label" class="min-w-0" :class="item.wide ? 'md:col-span-full' : ''">
            <dt class="text-xs font-medium text-cap-text-soft">{{ item.label }}</dt>
            <dd class="mt-1 break-words text-sm text-gray-900 dark:text-gray-100">
                <slot :name="item.key ?? item.label" :item="item">{{ text(item.value) }}</slot>
            </dd>
        </div>
    </dl>
</template>
