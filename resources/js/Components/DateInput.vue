<script setup>
// Fecha con formato «dd/mm/aaaa» (B-07): campo de texto con máscara y calendario propio (popover en
// escritorio, hoja inferior en móvil). El valor sigue siendo «AAAA-MM-DD», como lo espera Laravel
// (y `null` si se borra); acepta también «2026-03-05 00:00:00» o un ISO completo del servidor.
// Props: modelValue (v-model), min, max, invalid, readonly, id, required, placeholder.
// Atributos: `class` y `style` van al contenedor; el resto (aria-label, title…) al campo.
// Teclado: escribir la fecha (las barras se ponen solas; también «hoy», «mañana»), Alt+↓ o el botón
// abren el calendario (← → ↑ ↓ días, Re Pág / Av Pág meses, Enter elige, Esc cierra).
import { computed, nextTick, onBeforeUnmount, ref, useAttrs, useId, watch } from 'vue';
import { onPointerOutside, useFloating } from '../composables/useFloating.js';
import { useField } from '../composables/useField.js';
import { inputClass } from '../lib/ui.js';
import DateCalendar from './DateCalendar.vue';
import { isValidIso, maskDate, parseDisplay, toDisplay } from './dates.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    min: { type: String, default: '' },
    max: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    id: { type: String, default: '' },
    required: { type: Boolean, default: false },
    placeholder: { type: String, default: 'dd/mm/aaaa' },
});

const emit = defineEmits(['update:modelValue']);

const attrs = useAttrs();
const { id: fieldId, invalid: isInvalid, aria } = useField(props);
const popupId = useId();

const iso = computed(() => {
    const value = String(props.modelValue ?? '').slice(0, 10);
    return isValidIso(value) ? value : null;
});
const minIso = computed(() => String(props.min ?? '').slice(0, 10));
const maxIso = computed(() => String(props.max ?? '').slice(0, 10));

const text = ref(toDisplay(iso.value));
const typedWrong = ref(false);
watch(iso, (value) => {
    if (parseDisplay(text.value) !== value) text.value = toDisplay(value);
    typedWrong.value = false;
});

const outOfRange = computed(() => Boolean(iso.value && ((minIso.value && iso.value < minIso.value) || (maxIso.value && iso.value > maxIso.value))));
const showInvalid = computed(() => isInvalid.value || typedWrong.value || outOfRange.value);

const rootAttrs = computed(() => ({ class: attrs.class, style: attrs.style }));
const inputAttrs = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs;
    return rest;
});

function onInput(event) {
    const raw = event.target.value;
    const masked = /[a-zñ]/i.test(raw) ? raw : maskDate(raw);
    text.value = masked;
    if (masked !== raw) event.target.value = masked;
    typedWrong.value = false;
    const parsed = parseDisplay(masked);
    if (parsed && masked.replace(/\D/g, '').length === 8) emit('update:modelValue', parsed);
    if (!masked) emit('update:modelValue', null);
}

function commit() {
    const value = text.value.trim();
    if (!value) {
        typedWrong.value = false;
        if (iso.value) emit('update:modelValue', null);
        return;
    }
    const parsed = parseDisplay(value);
    if (!parsed) {
        typedWrong.value = true;
        return;
    }
    text.value = toDisplay(parsed);
    if (parsed !== iso.value) emit('update:modelValue', parsed);
}

// ───────── Calendario ─────────
const open = ref(false);
const anchor = ref(null);
const panel = ref(null);
const input = ref(null);
const sheet = ref(false);
const { style, teleportTarget } = useFloating(anchor, panel, open, { placement: 'bottom-start', offset: 6 });
let releaseOutside = null;

function openCalendar() {
    if (props.readonly) return;
    commit();
    sheet.value = window.matchMedia('(max-width: 767.98px)').matches;
    open.value = true;
}

function closeCalendar({ focus = true } = {}) {
    if (!open.value) return;
    open.value = false;
    if (focus) nextTick(() => input.value?.focus());
}

function select(value) {
    text.value = toDisplay(value);
    typedWrong.value = false;
    emit('update:modelValue', value);
    closeCalendar();
}

watch(open, (isOpen) => {
    releaseOutside?.();
    releaseOutside = null;
    if (isOpen) releaseOutside = onPointerOutside(() => [anchor.value, panel.value], () => closeCalendar({ focus: false }));
});
onBeforeUnmount(() => releaseOutside?.());

function onKeydown(event) {
    if (event.altKey && event.key === 'ArrowDown') {
        event.preventDefault();
        openCalendar();
    } else if (event.key === 'Enter') {
        commit();
    }
}
</script>

<template>
    <span ref="anchor" class="app-date-input relative inline-flex w-40 max-w-full" v-bind="rootAttrs">
        <input
            :id="fieldId"
            ref="input"
            v-bind="{ ...inputAttrs, ...aria }"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            :value="text"
            :placeholder="placeholder"
            maxlength="10"
            :readonly="readonly"
            :aria-invalid="showInvalid ? 'true' : aria['aria-invalid']"
            :aria-haspopup="readonly ? undefined : 'dialog'"
            :aria-expanded="readonly ? undefined : String(open)"
            :aria-controls="open ? popupId : undefined"
            :class="[inputClass({ invalid: showInvalid, readonly }), 'h-10 w-full pr-10 tabular-nums']"
            @input="onInput"
            @blur="commit"
            @keydown="onKeydown"
        />
        <button
            v-if="!readonly"
            type="button"
            class="app-date-input__button"
            :aria-label="iso ? `Elegir fecha, seleccionada ${text}` : 'Elegir fecha en el calendario'"
            tabindex="-1"
            @click="open ? closeCalendar() : openCalendar()"
        >
            <i class="mdi mdi-calendar-blank-outline" aria-hidden="true" />
        </button>
        <Teleport :to="sheet ? 'body' : teleportTarget">
            <Transition :name="sheet ? 'm-slide-up' : 'm-menu'">
                <div v-if="open && sheet" class="app-date-sheet" data-floating-root>
                    <div class="app-date-sheet__backdrop" aria-hidden="true" @click="closeCalendar()" />
                    <div :id="popupId" ref="panel" class="app-date-sheet__panel" role="dialog" aria-modal="true" aria-label="Elegir fecha">
                        <DateCalendar :model-value="iso" :min="minIso" :max="maxIso" @select="select" @close="closeCalendar()" />
                    </div>
                </div>
                <div v-else-if="open" :id="popupId" ref="panel" class="app-date-popover z-cap-dropdown" :style="style" role="dialog" aria-label="Elegir fecha">
                    <DateCalendar :model-value="iso" :min="minIso" :max="maxIso" @select="select" @close="closeCalendar()" />
                </div>
            </Transition>
        </Teleport>
    </span>
</template>
