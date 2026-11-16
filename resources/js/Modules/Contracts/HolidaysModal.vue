<script setup>
// Feriados de la empresa activa: cuentan como días no hábiles en los plazos de los contratos.
// Vienen sembrados los nacionales de Honduras; los marcados «Por revisar» son fechas estimadas.
// Props: open (v-model:open), canManage (solo administrador).
import { computed, reactive, ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import CapSegmented from '@design/vue/CapSegmented.vue';
import CapSkeleton from '@design/vue/CapSkeleton.vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DateInput from '@/Components/DateInput.vue';
import ErrorState from '@/Components/ErrorState.vue';
import FormField from '@/Components/FormField.vue';
import IconButton from '@/Components/IconButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import { useToasts } from '@design/vue/composables/useToasts.js';
import { date } from '@/lib/format.js';
import { getJson, postJson } from '@/lib/http.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open']);

const confirm = useConfirm();
const toasts = useToasts();
const items = ref([]);
const loading = ref(false);
const error = ref('');
const year = ref(new Date().getFullYear());
const draft = reactive({ holiday_date: '', name: '' });
const draftErrors = ref({});
const saving = ref(false);
const editing = ref(null);
const editErrors = ref({});

const years = computed(() => [...new Set(items.value.map((item) => Number(item.holiday_date.slice(0, 4))))].sort());
const visible = computed(() => items.value.filter((item) => item.holiday_date.startsWith(String(year.value))));
const pendingReview = computed(() => visible.value.filter((item) => item.is_pending_review).length);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const response = await getJson('/post-adjudicacion/feriados');
        items.value = response.data;
        if (!years.value.includes(year.value) && years.value.length) year.value = years.value[0];
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            editing.value = null;
            load();
        }
    },
);

function sortItems() {
    items.value.sort((a, b) => a.holiday_date.localeCompare(b.holiday_date));
}

async function add() {
    saving.value = true;
    draftErrors.value = {};
    try {
        const response = await postJson('/post-adjudicacion/feriados', { ...draft });
        items.value.push(response.data);
        sortItems();
        year.value = Number(response.data.holiday_date.slice(0, 4));
        draft.holiday_date = '';
        draft.name = '';
        toasts.show({ status: 'ok', title: response.message });
    } catch (exception) {
        draftErrors.value = exception.errors ?? {};
        if (!Object.keys(draftErrors.value).length) toasts.show({ status: 'error', title: exception.message });
    } finally {
        saving.value = false;
    }
}

async function save(item, values) {
    editErrors.value = {};
    try {
        const response = await postJson(`/post-adjudicacion/feriados/${item.id}?_method=PUT`, { _method: 'PUT', ...values });
        Object.assign(item, response.data);
        sortItems();
        editing.value = null;
        toasts.show({ status: 'ok', title: response.message });
    } catch (exception) {
        editErrors.value = exception.errors ?? {};
        if (!Object.keys(editErrors.value).length) toasts.show({ status: 'error', title: exception.message });
    }
}

function startEdit(item) {
    editErrors.value = {};
    editing.value = { id: item.id, holiday_date: item.holiday_date, name: item.name };
}

function remove(item) {
    confirm({
        verb: 'Eliminar',
        object: `el feriado ${item.name}`,
        noun: 'feriado',
        message: `El ${date(item.holiday_date, 'long')} volverá a contar como día hábil en los plazos nuevos.`,
        action: async () => {
            const response = await postJson(`/post-adjudicacion/feriados/${item.id}?_method=DELETE`, { _method: 'DELETE' });
            items.value = items.value.filter((entry) => entry.id !== item.id);
            toasts.show({ status: 'ok', title: response.message });
        },
    });
}
</script>

<template>
    <BaseModal :open="open" size="lg" title="Feriados" description="Días no hábiles para los plazos en días hábiles (lunes a viernes)." @update:open="emit('update:open', $event)">
        <div class="flex flex-col gap-4">
            <CapSegmented v-if="years.length > 1" v-model="year" label="Año" :items="years.map((value) => ({ label: String(value), value }))" />

            <CapAlert v-if="pendingReview" status="warning" :title="`${pendingReview} ${pendingReview === 1 ? 'fecha por revisar' : 'fechas por revisar'}`" detail="Son estimaciones (la Semana Morazánica la fija el Gobierno cada año). Confírmalas o corrígelas cuando salga el decreto." />

            <CapSkeleton v-if="loading" variant="line" :rows="6" label="Cargando feriados" />
            <ErrorState v-else-if="error" compact title="No se pudieron cargar los feriados" @retry="load" />
            <p v-else-if="!visible.length" class="text-sm text-cap-text-soft">No hay feriados registrados en {{ year }}.</p>
            <ul v-else class="divide-y divide-cap-divider rounded-cap-card border border-cap-divider">
                <li v-for="item in visible" :key="item.id" class="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center">
                    <template v-if="editing?.id === item.id">
                        <div class="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-[10rem_1fr]">
                            <FormField label="Fecha" hide-label :error="editErrors.holiday_date"><DateInput v-model="editing.holiday_date" /></FormField>
                            <FormField label="Nombre" hide-label :error="editErrors.name"><TextInput v-model="editing.name" maxlength="150" /></FormField>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <AppButton @click="editing = null">Cancelar</AppButton>
                            <AppButton icon="content-save-outline" @click="save(item, { holiday_date: editing.holiday_date, name: editing.name })">Guardar</AppButton>
                        </div>
                    </template>
                    <template v-else>
                        <span class="w-32 shrink-0 text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ date(item.holiday_date, 'medium') }}</span>
                        <span class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ item.name }}</span>
                        <StatusBadge v-if="item.is_pending_review" status="warning" label="Por revisar" />
                        <div v-if="canManage" class="flex shrink-0 items-center gap-1">
                            <IconButton v-if="item.is_pending_review" icon="check" :label="`Confirmar ${item.name}`" @click="save(item, { holiday_date: item.holiday_date, name: item.name })" />
                            <IconButton icon="pencil-outline" :label="`Editar ${item.name}`" @click="startEdit(item)" />
                            <IconButton icon="trash-can-outline" :label="`Eliminar ${item.name}`" @click="remove(item)" />
                        </div>
                    </template>
                </li>
            </ul>

            <form v-if="canManage" class="grid grid-cols-1 gap-3 rounded-cap-card bg-cap-card-2 p-3 sm:grid-cols-[10rem_1fr_auto] sm:items-end" @submit.prevent="add">
                <FormField label="Fecha" :error="draftErrors.holiday_date"><DateInput v-model="draft.holiday_date" /></FormField>
                <FormField label="Nombre" :error="draftErrors.name"><TextInput v-model="draft.name" maxlength="150" placeholder="Ej.: Feriado local" /></FormField>
                <AppButton type="submit" icon="plus" :loading="saving" :disabled="!draft.holiday_date || !draft.name">Agregar</AppButton>
            </form>
            <p v-else class="text-xs text-cap-text-soft">Solo un administrador de la empresa puede modificar los feriados.</p>
        </div>
        <template #footer>
            <AppButton @click="emit('update:open', false)">Cerrar</AppButton>
        </template>
    </BaseModal>
</template>
