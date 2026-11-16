<script setup>
// Pestañas para repartir el contenido de una ficha o de una modal (Datos · Documentos · Historial).
// No es navegación del módulo (eso son las `sections` del layout) ni un filtro (eso es `CapSegmented`).
// Props: tabs ([{ value, label, icon?, count?, disabled? }]), modelValue (v-model), label (nombre accesible).
// Slots: uno por pestaña con el nombre de su `value`. Cada panel se monta la primera vez que se abre y
//        después se conserva (v-show): volver a una pestaña no la vuelve a montar ni a pedir datos.
//        Sin slots, solo la barra.
// Teclado: ← → Inicio Fin cambian de pestaña.
// Movimiento: un indicador se desliza bajo la pestaña activa y el panel entra desde el lado hacia el
// que se avanzó sin esperar a que salga el anterior (app-tab-left / app-tab-right en resources/css/shell.css).
// Si las pestañas no caben (móvil), la barra se desplaza en horizontal con una sombra en el borde
// que indica que hay más, y la pestaña activa se lleva a la vista.
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
const direction = ref('app-tab-left');
const overflow = ref({ start: false, end: false });
const visited = ref(new Set(props.modelValue != null ? [props.modelValue] : []));
let resizeObserver = null;

const activeIndex = computed(() => props.tabs.findIndex((tab) => tab.value === props.modelValue));

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

function updateOverflow() {
    const box = list.value;
    if (!box) return;
    overflow.value = { start: box.scrollLeft > 2, end: box.scrollLeft + box.clientWidth < box.scrollWidth - 2 };
}

function revealActive() {
    const box = list.value;
    const button = box?.querySelector(`[data-tab="${CSS.escape(String(props.modelValue))}"]`);
    if (!box || !button) return;
    const left = button.offsetLeft - 24;
    const right = button.offsetLeft + button.offsetWidth + 24;
    if (left < box.scrollLeft) box.scrollTo({ left, behavior: 'smooth' });
    else if (right > box.scrollLeft + box.clientWidth) box.scrollTo({ left: right - box.clientWidth, behavior: 'smooth' });
}

watch(
    () => props.modelValue,
    (value) => {
        if (value != null && !visited.value.has(value)) visited.value = new Set([...visited.value, value]);
    },
);

watch(activeIndex, async (next, previous) => {
    direction.value = next >= previous ? 'app-tab-left' : 'app-tab-right';
    await nextTick();
    placeIndicator();
    revealActive();
});
watch(() => props.tabs.map((tab) => `${tab.value}:${tab.label}:${tab.count ?? ''}`).join('|'), async () => {
    await nextTick();
    placeIndicator(true);
});

onMounted(() => {
    placeIndicator(true);
    updateOverflow();
    revealActive();
    if (typeof ResizeObserver === 'function' && list.value) {
        resizeObserver = new ResizeObserver(() => {
            placeIndicator(true);
            updateOverflow();
        });
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
        <div class="app-tabs" :data-overflow-start="overflow.start ? 'true' : undefined" :data-overflow-end="overflow.end ? 'true' : undefined">
        <div ref="list" role="tablist" :aria-label="label" class="app-tabs__list relative flex gap-1 overflow-x-auto border-b border-cap-divider" @keydown="onKeydown" @scroll.passive="updateOverflow">
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
        </div>
        <template v-for="tab in tabs" :key="tab.value">
            <Transition :name="direction">
                <div
                    v-if="$slots[tab.value] && visited.has(tab.value)"
                    v-show="tab.value === modelValue"
                    :id="`${baseId}-panel-${tab.value}`"
                    role="tabpanel"
                    :aria-labelledby="`${baseId}-tab-${tab.value}`"
                    tabindex="0"
                    class="pt-4 focus:outline-none"
                >
                    <slot :name="tab.value" />
                </div>
            </Transition>
        </template>
    </div>
</template>
