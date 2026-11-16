<script setup>
// Participantes de un proceso (oferentes y sus montos). Reutilizable: cotización o oportunidad (kanban).
// Ver README.md de esta carpeta.
// Props: subject ({ type: 'quote' | 'opportunity', id }), showActions (botón «Guardar» propio; con
//        false, la pantalla llama a `save()` desde su pie), compact, initial (opcional: respuesta ya
//        conocida de GET …/participantes, p. ej. la que trae el detalle de la oportunidad; con ella
//        no se pide nada al montar).
// Solo vuelve a pedir si cambia el sujeto (tipo o id), no si el padre pasa un objeto nuevo igual.
// Eventos: saved ({ data, summary, message }), loaded ({ data, summary, outcome, can }), dirty (bool).
// Expone: save(), reload(), saving, dirty, canEdit.
// Una fila es «nuestra oferta» (única) y, conocido el resultado, una es el ganador (única). Se guarda
// por diferencias: las filas con id se actualizan, las nuevas se crean y las quitadas se borran.
import { computed, onMounted, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import { useToasts } from '../../../design/vue/composables/useToasts.js';
import AppButton from '../../Components/AppButton.vue';
import Checkbox from '../../Components/Checkbox.vue';
import EmptyState from '../../Components/EmptyState.vue';
import ErrorState from '../../Components/ErrorState.vue';
import FormField from '../../Components/FormField.vue';
import IconButton from '../../Components/IconButton.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import TextInput from '../../Components/TextInput.vue';
import { money, percent } from '../../lib/format.js';
import { fetchParticipants, flatErrors, saveParticipants } from './api.js';

const props = defineProps({
    subject: { type: Object, required: true },
    showActions: { type: Boolean, default: true },
    compact: { type: Boolean, default: false },
    initial: { type: Object, default: null },
});

const emit = defineEmits(['saved', 'loaded', 'dirty']);

const toasts = useToasts();
const rows = ref([]);
const original = ref('[]');
const outcome = ref('Pendiente');
const referenceAmount = ref(0);
const canEdit = ref(false);
const loading = ref(true);
const loadError = ref('');
const saving = ref(false);
const errors = ref({});
const saveError = ref('');
let seed = 0;

const keyed = (row) => ({ key: `p${(seed += 1)}`, id: null, name: '', tax_id: '', amount: null, currency: 'HNL', includes_tax: true, is_ours: false, is_winner: false, notes: '', ...row });
const serialize = (list) => JSON.stringify(list.map(({ key, position, ...rest }) => rest));
const dirty = computed(() => serialize(rows.value) !== original.value);
const known = computed(() => outcome.value !== 'Pendiente');

watch(dirty, (value) => emit('dirty', value));

function hydrate(response) {
    rows.value = response.data.map((row) => keyed({ ...row, tax_id: row.tax_id ?? '', notes: row.notes ?? '' }));
    original.value = serialize(rows.value);
    outcome.value = response.outcome;
    referenceAmount.value = response.reference_amount ?? 0;
    canEdit.value = Boolean(response.can?.edit);
    errors.value = {};
    loadError.value = '';
    loading.value = false;
    emit('loaded', response);
}

async function reload() {
    loading.value = true;
    loadError.value = '';
    try {
        hydrate(await fetchParticipants(props.subject));
    } catch (error) {
        loadError.value = error.message || 'No se pudieron cargar los participantes.';
    } finally {
        loading.value = false;
    }
}

onMounted(() => (props.initial?.data ? hydrate(props.initial) : reload()));
// Clave primitiva: un `subject` nuevo con el mismo tipo e id no dispara otra petición.
watch(() => `${props.subject?.type}:${props.subject?.id}`, reload);

function add() {
    const hasOurs = rows.value.some((row) => row.is_ours);
    rows.value = [...rows.value, keyed(hasOurs ? {} : { is_ours: true, amount: referenceAmount.value || null })];
}

function remove(index) {
    rows.value = rows.value.filter((_, position) => position !== index);
}

function mark(index, field, value) {
    rows.value = rows.value.map((row, position) => ({ ...row, [field]: position === index ? value : value ? false : row[field] }));
}

/** Resumen en vivo: posición de nuestra oferta y diferencia contra la más baja. */
const summary = computed(() => {
    const priced = rows.value.filter((row) => Number(row.amount) > 0).sort((a, b) => Number(a.amount) - Number(b.amount));
    const ours = priced.find((row) => row.is_ours);
    const lowest = priced[0];
    const winner = rows.value.find((row) => row.is_winner);
    const difference = ours && lowest ? Math.round((Number(ours.amount) - Number(lowest.amount)) * 100) / 100 : null;
    return {
        count: rows.value.length,
        position: ours ? priced.findIndex((row) => Number(row.amount) >= Number(ours.amount)) + 1 : null,
        lowest,
        difference,
        differencePercent: difference !== null && Number(lowest.amount) > 0 ? (difference / Number(lowest.amount)) * 100 : null,
        winner,
    };
});

const error = (index, field) => errors.value[`participants.${index}.${field}`];

async function save() {
    if (saving.value || !canEdit.value) return false;
    saving.value = true;
    errors.value = {};
    saveError.value = '';
    try {
        const payload = rows.value.map(({ key, position, ...row }) => ({ ...row, amount: row.amount === null || row.amount === '' ? null : Number(row.amount), is_winner: known.value ? row.is_winner : false }));
        const response = await saveParticipants(props.subject, payload);
        rows.value = response.data.map((row) => keyed({ ...row, tax_id: row.tax_id ?? '', notes: row.notes ?? '' }));
        original.value = serialize(rows.value);
        toasts.show({ status: 'ok', title: response.message ?? 'Participantes guardados.' });
        emit('saved', response);
        return true;
    } catch (failure) {
        errors.value = flatErrors(failure);
        saveError.value = Object.keys(errors.value).length ? 'Revisa los campos marcados.' : failure.message || 'No se pudieron guardar los participantes.';
        return false;
    } finally {
        saving.value = false;
    }
}

defineExpose({ save, reload, saving, dirty, canEdit });
</script>

<template>
    <div class="flex flex-col gap-4" :aria-busy="loading ? 'true' : undefined">
        <div v-if="loading" class="flex flex-col gap-3" aria-label="Cargando participantes">
            <CapSkeleton variant="line" />
            <CapSkeleton variant="line" />
            <CapSkeleton variant="line" />
        </div>

        <ErrorState v-else-if="loadError" compact title="No se pudieron cargar los participantes" :description="loadError" @retry="reload" />

        <template v-else>
            <div v-if="summary.count" class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-live="polite">
                <div class="rounded-cap-control bg-cap-card-2 p-3">
                    <p class="text-xs text-cap-text-soft">Posición de nuestra oferta</p>
                    <p class="font-display text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ summary.position ? `${summary.position} de ${summary.count}` : '—' }}</p>
                </div>
                <div class="rounded-cap-control bg-cap-card-2 p-3">
                    <p class="text-xs text-cap-text-soft">Oferta más baja</p>
                    <p class="truncate font-display text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100" :title="summary.lowest?.name">{{ summary.lowest ? money(summary.lowest.amount) : '—' }}</p>
                    <p v-if="summary.lowest" class="truncate text-xs text-cap-text-soft">{{ summary.lowest.name }}</p>
                </div>
                <div class="rounded-cap-control bg-cap-card-2 p-3">
                    <p class="text-xs text-cap-text-soft">Diferencia contra la más baja</p>
                    <p class="font-display text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ summary.difference === null ? '—' : summary.difference === 0 ? 'Somos la más baja' : `+ ${money(summary.difference)}` }}</p>
                    <p v-if="summary.differencePercent" class="text-xs text-cap-text-soft">{{ percent(summary.differencePercent) }} por encima</p>
                </div>
            </div>

            <CapAlert v-if="!canEdit" status="info" title="Solo lectura" detail="No puedes cambiar los participantes de este proceso." />
            <CapAlert v-if="saveError" status="error" title="No se guardaron los participantes" :detail="saveError" />

            <EmptyState v-if="!rows.length" compact icon="account-group-outline" title="Sin participantes" description="Registra los oferentes y sus montos. Una fila es nuestra oferta.">
                <template v-if="canEdit" #action><AppButton icon="plus" @click="add">Agregar participante</AppButton></template>
            </EmptyState>

            <ul v-else class="flex flex-col gap-3">
                <li v-for="(row, index) in rows" :key="row.key" class="rounded-cap-control border p-3" :class="row.is_ours ? 'border-cap-brand dark:border-cap-brand-soft' : 'border-cap-divider'" :data-participant="index">
                    <div class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-[minmax(0,1fr)_10rem_13rem]">
                        <FormField label="Nombre del oferente" required :error="error(index, 'name')">
                            <TextInput v-model="row.name" maxlength="255" :readonly="!canEdit" />
                        </FormField>
                        <FormField label="RTN" optional :error="error(index, 'tax_id')">
                            <TextInput v-model="row.tax_id" maxlength="100" :readonly="!canEdit" />
                        </FormField>
                        <FormField label="Monto ofertado" required :error="error(index, 'amount')">
                            <MoneyInput v-model="row.amount" v-model:currency="row.currency" :currencies="['HNL', 'USD']" :min="0" :readonly="!canEdit" />
                        </FormField>
                        <FormField label="Observaciones" optional class="md:col-span-3" :error="error(index, 'notes')">
                            <TextInput v-model="row.notes" maxlength="2000" :readonly="!canEdit" />
                        </FormField>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2">
                        <Checkbox v-model="row.includes_tax" label="Incluye ISV" :disabled="!canEdit" />
                        <Checkbox :model-value="row.is_ours" label="Nuestra oferta" :disabled="!canEdit" :invalid="Boolean(error(index, 'is_ours'))" @update:model-value="mark(index, 'is_ours', $event)" />
                        <Checkbox v-if="known" :model-value="row.is_winner" label="Ganador" :disabled="!canEdit" :invalid="Boolean(error(index, 'is_winner'))" @update:model-value="mark(index, 'is_winner', $event)" />
                        <span v-if="error(index, 'is_ours') || error(index, 'is_winner')" class="text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ error(index, 'is_ours') || error(index, 'is_winner') }}</span>
                        <span class="ml-auto"><IconButton v-if="canEdit" icon="trash-can-outline" :label="`Quitar a ${row.name || 'este participante'}`" @click="remove(index)" /></span>
                    </div>
                </li>
            </ul>

            <div v-if="canEdit && (rows.length || showActions)" class="flex flex-wrap items-center gap-2">
                <AppButton v-if="rows.length" icon="plus" @click="add">Agregar participante</AppButton>
                <span v-if="dirty" class="text-xs text-cap-text-soft">Cambios sin guardar</span>
                <AppButton v-if="showActions" variant="primary" icon="content-save-outline" class="ml-auto" :loading="saving" :disabled="!dirty" @click="save">Guardar participantes</AppButton>
            </div>
        </template>
    </div>
</template>
