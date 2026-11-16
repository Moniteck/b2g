<script setup>
// Zona para arrastrar o elegir archivos, con la lista de lo elegido debajo.
// Props: modelValue (v-model: lista de File válidos), accept ('.pdf,image/*': extensiones y tipos MIME),
//        maxSizeMb (tamaño máximo por archivo, visible en la zona), multiple, maxFiles,
//        uploader (lo que devuelve `useUpload()`: pinta progreso, éxito y error por archivo),
//        errors (errores del servidor por posición: { 0: 'texto' } o lista), title, hint, disabled,
//        invalid, id.
// Eventos: update:modelValue (archivos válidos), rejected ([{ file, reason }]).
// Teclado: la zona es un botón (Enter o espacio abren el selector); cada archivo tiene su «Quitar».
import { computed, ref } from 'vue';
import { useField } from '@/composables/useField.js';
import { fileSize } from '@/lib/format.js';
import { iconButton } from '@/lib/ui.js';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    accept: { type: String, default: '' },
    maxSizeMb: { type: Number, default: 20 },
    multiple: { type: Boolean, default: false },
    maxFiles: { type: Number, default: 10 },
    uploader: { type: Object, default: null },
    errors: { type: [Object, Array], default: () => ({}) },
    title: { type: String, default: '' },
    hint: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'rejected']);

const { id: fieldId, invalid: isInvalid, aria } = useField(props);
const input = ref(null);
const dragging = ref(false);
const rejected = ref([]);
let dragDepth = 0;

const acceptList = computed(() => props.accept.split(',').map((entry) => entry.trim().toLowerCase()).filter(Boolean));
const acceptLabel = computed(() => acceptList.value.map((entry) => (entry.startsWith('.') ? entry.slice(1).toUpperCase() : entry === 'image/*' ? 'imágenes' : entry.split('/').pop().toUpperCase())).join(', '));
const shownTitle = computed(() => props.title || (props.multiple ? 'Arrastra los archivos aquí o elígelos' : 'Arrastra el archivo aquí o elígelo'));
const limits = computed(() => [acceptLabel.value, `hasta ${props.maxSizeMb} MB${props.multiple ? ' cada uno' : ''}`, props.multiple ? `máximo ${props.maxFiles} archivos` : ''].filter(Boolean).join(' · '));
const busy = computed(() => Boolean(props.uploader?.uploading?.value ?? props.uploader?.uploading));

function matchesAccept(file) {
    if (acceptList.value.length === 0) return true;
    const name = file.name.toLowerCase();
    const type = (file.type || '').toLowerCase();
    return acceptList.value.some((entry) => {
        if (entry.startsWith('.')) return name.endsWith(entry);
        if (entry.endsWith('/*')) return type.startsWith(entry.slice(0, -1));
        return type === entry;
    });
}

function add(fileList) {
    const incoming = [...fileList];
    const accepted = props.multiple ? [...props.modelValue] : [];
    const refused = [];
    for (const file of incoming) {
        if (!matchesAccept(file)) refused.push({ file, reason: `Tipo no permitido. Acepta ${acceptLabel.value}.` });
        else if (file.size > props.maxSizeMb * 1024 ** 2) refused.push({ file, reason: `Pesa ${fileSize(file.size)}; el máximo es ${props.maxSizeMb} MB.` });
        else if (file.size === 0) refused.push({ file, reason: 'El archivo está vacío.' });
        else if (!props.multiple && accepted.length >= 1) refused.push({ file, reason: 'Solo se admite un archivo.' });
        else if (accepted.length >= props.maxFiles) refused.push({ file, reason: `Solo se admiten ${props.maxFiles} archivos.` });
        else if (accepted.some((existing) => existing.name === file.name && existing.size === file.size)) refused.push({ file, reason: 'Ya está en la lista.' });
        else accepted.push(file);
    }
    rejected.value = refused;
    emit('update:modelValue', accepted);
    if (refused.length) emit('rejected', refused);
}

function onPick(event) {
    add(event.target.files);
    event.target.value = '';
}

function onDrop(event) {
    dragDepth = 0;
    dragging.value = false;
    if (props.disabled || busy.value) return;
    add(event.dataTransfer?.files ?? []);
}

function onDragEnter() {
    dragDepth += 1;
    dragging.value = !props.disabled;
}

function onDragLeave() {
    dragDepth = Math.max(0, dragDepth - 1);
    if (dragDepth === 0) dragging.value = false;
}

function remove(file) {
    emit('update:modelValue', props.modelValue.filter((item) => item !== file));
}

function stateOf(file, index) {
    const upload = props.uploader?.stateOf?.(file);
    const serverError = Array.isArray(props.errors) ? props.errors[index] : props.errors?.[index];
    if (serverError) return { status: 'error', progress: 0, error: String(Array.isArray(serverError) ? serverError[0] : serverError) };
    return upload ?? { status: 'ready', progress: 0, error: '' };
}

function iconOf(file) {
    if ((file.type || '').startsWith('image/')) return 'file-image-outline';
    if (file.name.toLowerCase().endsWith('.pdf')) return 'file-pdf-box';
    if (/\.(xlsx?|csv)$/i.test(file.name)) return 'file-table-outline';
    return 'file-document-outline';
}

defineExpose({ open: () => input.value?.click() });
</script>

<template>
    <div class="min-w-0">
        <button
            :id="fieldId"
            type="button"
            v-bind="aria"
            :disabled="disabled || busy"
            class="m-dropzone flex w-full flex-col items-center justify-center gap-1 rounded-cap-card border border-dashed px-4 py-8 text-center focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-card disabled:cursor-not-allowed disabled:opacity-50 dark:focus-visible:ring-gray-100"
            :class="[dragging ? 'border-cap-brand bg-cap-card-2 dark:border-cap-brand-soft' : isInvalid ? 'border-cap-status-error bg-cap-card dark:border-cap-status-dark-error' : 'border-cap-input-line bg-cap-card hover:bg-cap-card-2']"
            :data-dragging="dragging ? '' : undefined"
            @click="input.click()"
            @dragenter.prevent="onDragEnter"
            @dragover.prevent
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onDrop"
        >
            <i class="m-dropzone__icon mdi mdi-tray-arrow-up text-cap-icon" :class="dragging ? 'text-cap-brand dark:text-cap-brand-soft' : 'text-gray-400'" aria-hidden="true" />
            <span class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ dragging ? 'Suelta para agregar' : shownTitle }}</span>
            <span class="text-xs text-cap-text-soft">{{ limits }}</span>
            <span v-if="hint" class="text-xs text-cap-text-soft">{{ hint }}</span>
        </button>
        <input ref="input" type="file" class="sr-only" tabindex="-1" aria-hidden="true" :accept="accept || undefined" :multiple="multiple" :disabled="disabled" @change="onPick">

        <TransitionGroup v-if="modelValue.length || rejected.length" tag="ul" name="m-list" class="relative mt-3 flex flex-col gap-2 [&>.m-list-leave-active]:w-full" aria-label="Archivos elegidos">
            <li v-for="(file, index) in modelValue" :key="`${file.name}-${file.size}-${file.lastModified}`" class="rounded-cap-control bg-cap-card-2 p-3">
                <div class="flex items-center gap-3">
                    <i class="mdi shrink-0 text-xl leading-none text-cap-text-soft" :class="`mdi-${iconOf(file)}`" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" :title="file.name">{{ file.name }}</p>
                        <p v-if="stateOf(file, index).status === 'error'" class="text-xs text-cap-status-error-ink" role="alert">{{ stateOf(file, index).error }}</p>
                        <p v-else class="text-xs tabular-nums text-cap-text-soft">
                            {{ fileSize(file.size) }}
                            <template v-if="stateOf(file, index).status === 'uploading'"> · Subiendo {{ stateOf(file, index).progress }} %</template>
                            <template v-else-if="stateOf(file, index).status === 'pending'"> · En espera</template>
                            <template v-else-if="stateOf(file, index).status === 'done'"> · Subido</template>
                        </p>
                    </div>
                    <i v-if="stateOf(file, index).status === 'done'" class="m-check mdi mdi-check-circle shrink-0 text-xl leading-none text-cap-status-ok dark:text-cap-status-dark-ok" aria-hidden="true" />
                    <i v-else-if="stateOf(file, index).status === 'error'" class="mdi mdi-alert-circle shrink-0 text-xl leading-none text-cap-status-error dark:text-cap-status-dark-error" aria-hidden="true" />
                    <button v-if="!['uploading', 'pending'].includes(stateOf(file, index).status)" type="button" :aria-label="`Quitar ${file.name}`" title="Quitar" :class="iconButton" @click="remove(file)">
                        <i class="mdi mdi-close text-xl leading-none" aria-hidden="true" />
                    </button>
                </div>
                <div v-if="stateOf(file, index).status === 'uploading'" class="mt-2 h-1 overflow-hidden rounded-full bg-cap-divider" role="progressbar" :aria-label="`Subiendo ${file.name}`" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="stateOf(file, index).progress">
                    <div class="h-full rounded-full bg-cap-brand transition-[width] duration-cap-slow ease-cap dark:bg-cap-brand-soft" :style="{ width: `${stateOf(file, index).progress}%` }" />
                </div>
            </li>
            <li v-for="entry in rejected" :key="`rejected-${entry.file.name}-${entry.file.size}`" class="flex items-center gap-3 rounded-cap-control border border-cap-status-error-tint-border bg-cap-status-error-tint p-3">
                <i class="mdi mdi-alert-circle-outline shrink-0 text-xl leading-none text-cap-status-error-ink" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" :title="entry.file.name">{{ entry.file.name }}</p>
                    <p class="text-xs text-cap-status-error-ink" role="alert">No se agregó. {{ entry.reason }}</p>
                </div>
                <button type="button" :aria-label="`Descartar aviso de ${entry.file.name}`" title="Descartar" :class="iconButton" @click="rejected = rejected.filter((item) => item !== entry)">
                    <i class="mdi mdi-close text-xl leading-none" aria-hidden="true" />
                </button>
            </li>
        </TransitionGroup>
    </div>
</template>
