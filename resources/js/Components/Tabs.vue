<script setup>
// Pestañas para repartir el contenido de una ficha o de una modal (Datos · Documentos · Historial).
// No es navegación del módulo (eso son las `sections` del layout) ni un filtro (eso es `CapSegmented`).
// Props: tabs ([{ value, label, icon?, count?, disabled? }]), modelValue (v-model), label (nombre accesible).
// Slots: uno por pestaña con el nombre de su `value`; solo se pinta el activo. Sin slots, solo la barra.
// Teclado: ← → Inicio Fin cambian de pestaña.
// Movimiento: un indicador se desliza bajo la pestaña activa y el panel entra desde el lado hacia el
// que se avanzó (m-slide-left / m-slide-right en resources/css/motion.css).
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

const props = defineProps({
    tabs: { type: Array, required: true },
    modelValue: { type: [String, Number], default: null },
    label: { type: String, default: 'Secciones' },
});

const emit = defineEmits(['update:modelValue']);

const baseId = useId();
const list = ref(null);
const indicator = ref(null);
const direction = ref('m-slide-left');
let resizeObserver = null;

const activeIndex = computed(() => props.tabs.findIndex((tab) => tab.value === props.modelValue));
const activeTab = computed(() => props.tabs[activeIndex.value] ?? null);

function placeIndicator(instant = false) {
    const button = list.value?.querySelector(`[data-tab="${CSS.escape(String(props.modelValue))}"]`);
    if (!indicator.value) return;
    if (!button) {
        indicator.value.style.opacity = '0';
        return;
    }
    indicator.value.toggleAttribute('data-instant', instant);
    indicator.value.style.opacity = '1';
    indicator.value.style.transform = `translateX(${button.offsetLeft}px) scaleX(${button.offsetWidth / 100})`;
}

watch(activeIndex, async (next, previous) => {
    direction.value = next >= previous ? 'm-slide-left' : 'm-slide-right';
    await nextTick();
    placeIndicator();
});
watch(() => props.tabs.map((tab) => `${tab.value}:${tab.label}:${tab.count ?? ''}`).join('|'), async () => {
    await nextTick();
    placeIndicator(true);
});

onMounted(() => {
    placeIndicator(true);
    if (typeof ResizeObserver === 'function' && list.value) {
        resizeObserver = new ResizeObserver(() => placeIndicator(true));
        resizeObserver.observe(list.value);
    }
});
onBeforeUnmount(() => resizeObserver?.disconnect());

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
        <div ref="list" role="tablist" :aria-label="label" class="relative flex gap-1 overflow-x-auto border-b border-cap-divider" @keydown="onKeydown">
            <span ref="indicator" class="m-tab-indicator" aria-hidden="true" />
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
                :class="tab.value === modelValue ? 'border-transparent font-semibold text-gray-900 dark:text-gray-100' : 'border-transparent text-cap-text-soft hover:text-gray-900 dark:hover:text-gray-100'"
                @click="emit('update:modelValue', tab.value)"
            >
                <i v-if="tab.icon" class="mdi text-xl leading-none" :class="`mdi-${tab.icon}`" aria-hidden="true" />
                {{ tab.label }}
                <span v-if="tab.count != null" class="rounded-md bg-cap-card-2 px-1.5 py-0.5 text-xs font-medium tabular-nums text-cap-text-soft">{{ tab.count }}</span>
            </button>
        </div>
        <Transition :name="direction" mode="out-in">
            <div v-if="activeTab && $slots[activeTab.value]" :id="`${baseId}-panel-${activeTab.value}`" :key="activeTab.value" role="tabpanel" :aria-labelledby="`${baseId}-tab-${activeTab.value}`" tabindex="0" class="pt-4 focus:outline-none">
                <slot :name="activeTab.value" />
            </div>
        </Transition>
    </div>
</template>
