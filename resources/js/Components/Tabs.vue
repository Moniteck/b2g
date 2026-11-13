<script setup>
// Pestañas para repartir el contenido de una ficha o de una modal (Datos · Documentos · Historial).
// No es navegación del módulo (eso son las `sections` del layout) ni un filtro (eso es `CapSegmented`).
// Props: tabs ([{ value, label, icon?, count?, disabled? }]), modelValue (v-model), label (nombre accesible).
// Slots: uno por pestaña con el nombre de su `value`; solo se pinta el activo. Sin slots, solo la barra.
// Teclado: ← → Inicio Fin cambian de pestaña.
import { useId } from 'vue';

const props = defineProps({
    tabs: { type: Array, required: true },
    modelValue: { type: [String, Number], default: null },
    label: { type: String, default: 'Secciones' },
});

const emit = defineEmits(['update:modelValue']);

const baseId = useId();

function onKeydown(event) {
    const enabled = props.tabs.filter((tab) => !tab.disabled);
    const current = enabled.findIndex((tab) => tab.value === props.modelValue);
    const targets = { ArrowRight: current + 1, ArrowLeft: current - 1, Home: 0, End: enabled.length - 1 };
    if (!(event.key in targets)) return;
    event.preventDefault();
    const next = enabled[(targets[event.key] + enabled.length) % enabled.length];
    emit('update:modelValue', next.value);
    event.currentTarget.querySelector(`[data-tab="${next.value}"]`)?.focus();
}
</script>

<template>
    <div class="min-w-0">
        <div role="tablist" :aria-label="label" class="flex gap-1 overflow-x-auto border-b border-cap-divider" @keydown="onKeydown">
            <button
                v-for="tab in tabs"
                :id="`${baseId}-tab-${tab.value}`"
                :key="tab.value"
                type="button"
                role="tab"
                :data-tab="tab.value"
                :aria-selected="tab.value === modelValue ? 'true' : 'false'"
                :aria-controls="`${baseId}-panel-${tab.value}`"
                :tabindex="tab.value === modelValue ? 0 : -1"
                :disabled="tab.disabled"
                class="-mb-px inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 text-sm transition-colors duration-cap-fast ease-cap focus:outline-none focus-visible:bg-cap-card-2 disabled:cursor-not-allowed disabled:opacity-50"
                :class="tab.value === modelValue ? 'border-cap-brand font-semibold text-gray-900 dark:border-cap-brand-soft dark:text-gray-100' : 'border-transparent text-cap-text-soft hover:text-gray-900 dark:hover:text-gray-100'"
                @click="emit('update:modelValue', tab.value)"
            >
                <i v-if="tab.icon" class="mdi text-xl leading-none" :class="`mdi-${tab.icon}`" aria-hidden="true" />
                {{ tab.label }}
                <span v-if="tab.count != null" class="rounded-md bg-cap-card-2 px-1.5 py-0.5 text-xs font-medium tabular-nums text-cap-text-soft">{{ tab.count }}</span>
            </button>
        </div>
        <template v-for="tab in tabs" :key="tab.value">
            <div v-if="tab.value === modelValue && $slots[tab.value]" :id="`${baseId}-panel-${tab.value}`" role="tabpanel" :aria-labelledby="`${baseId}-tab-${tab.value}`" tabindex="0" class="pt-4 focus:outline-none">
                <slot :name="tab.value" />
            </div>
        </template>
    </div>
</template>
