<script setup>
// Resumen flotante de la selección del inventario: productos elegidos, subtotal, ISV y total,
// con «Llevar a una cotización» y «Limpiar». En escritorio es la barra de selección de vidrio
// del recetario; en móvil, una tarjeta fija sobre la barra de pestañas.
import CapButton from '../../../design/vue/CapButton.vue';
import { money, number } from '../../lib/format.js';

defineProps({
    count: { type: Number, required: true },
    totals: { type: Object, required: true },
    currency: { type: String, default: 'HNL' },
    canQuote: { type: Boolean, default: false },
    blockedReason: { type: String, default: '' },
});

const emit = defineEmits(['quote', 'clear']);
</script>

<template>
    <div v-if="count > 0">
        <!-- Escritorio -->
        <div class="cap-selection-bar cap-glass w-max max-w-[calc(100vw-2rem)] whitespace-nowrap" role="region" aria-label="Productos elegidos">
            <span class="cap-selection-bar__count" aria-live="polite">{{ number(count) }} {{ count === 1 ? 'producto' : 'productos' }}</span>
            <span class="hidden items-center gap-4 pr-2 text-sm tabular-nums text-cap-frame-ink xl:flex">
                <span><span class="text-cap-frame-ink-soft">Subtotal</span> {{ money(totals.subtotal, currency) }}</span>
                <span><span class="text-cap-frame-ink-soft">ISV</span> {{ money(totals.tax, currency) }}</span>
            </span>
            <span class="pr-2 text-sm font-semibold tabular-nums text-cap-frame-ink" data-testid="seleccion-total">Total {{ money(totals.total, currency) }}</span>
            <CapButton variant="secondary" icon="close" @click="emit('clear')">Limpiar</CapButton>
            <CapButton v-if="canQuote" variant="secondary" icon="file-document-arrow-right-outline" :disabled="Boolean(blockedReason)" :title="blockedReason || undefined" @click="emit('quote')">Llevar a una cotización</CapButton>
        </div>

        <!-- Móvil -->
        <section class="fixed inset-x-3 bottom-20 z-cap-dropdown rounded-cap-card border border-cap-divider bg-cap-card p-4 shadow-xl lg:hidden dark:bg-cap-card-2" aria-label="Productos elegidos">
            <div class="flex items-baseline justify-between gap-3">
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100" aria-live="polite">{{ number(count) }} {{ count === 1 ? 'producto' : 'productos' }}</p>
                <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">Total {{ money(totals.total, currency) }}</p>
            </div>
            <p class="mt-1 text-xs tabular-nums text-cap-text-soft">Subtotal {{ money(totals.subtotal, currency) }} · ISV {{ money(totals.tax, currency) }}</p>
            <div class="mt-3 flex gap-2">
                <CapButton class="flex-1" variant="secondary" @click="emit('clear')">Limpiar</CapButton>
                <CapButton v-if="canQuote" class="flex-1" variant="secondary" :disabled="Boolean(blockedReason)" @click="emit('quote')">A cotización</CapButton>
            </div>
        </section>
    </div>
</template>
