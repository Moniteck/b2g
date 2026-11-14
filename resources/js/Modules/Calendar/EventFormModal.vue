<script setup>
// Crear o editar un evento manual: título, tipo, inicio, fin, todo el día, descripción,
// recordatorios (los de la regla del tipo o personalizados) y destinatarios.
// Props: open (v-model:open), event (detalle para editar; null para crear), date (día sugerido),
//        types ([{ value, label }]), defaults ({ tipo: [días] }), members ([{ value, label }]).
// Eventos: saved.
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import Checkbox from '../../Components/Checkbox.vue';
import DateInput from '../../Components/DateInput.vue';
import DateTimeInput from '../../Components/DateTimeInput.vue';
import FormField from '../../Components/FormField.vue';
import SelectInput from '../../Components/SelectInput.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import Toggle from '../../Components/Toggle.vue';
import { focusFirstError } from '../../lib/errors.js';
import { offsetLabel } from './offsets.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    event: { type: Object, default: null },
    date: { type: String, default: '' },
    types: { type: Array, default: () => [] },
    defaults: { type: Object, default: () => ({}) },
    members: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open', 'saved']);

const OFFSETS = [30, 15, 7, 3, 2, 1, 0];
const panel = ref(null);

const form = useForm({
    title: '',
    type: 'Manual',
    starts_at: '',
    ends_at: '',
    all_day: false,
    description: '',
    custom_reminders: false,
    reminders: [],
    recipients: [],
});

const editing = computed(() => Boolean(props.event?.id));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.clearErrors();
        const source = props.event;
        if (source) {
            form.defaults({
                title: source.title,
                type: source.type,
                starts_at: source.all_day ? source.starts_at.slice(0, 10) : source.starts_at.slice(0, 16).replace(' ', 'T'),
                ends_at: source.ends_at ? (source.all_day ? source.ends_at.slice(0, 10) : source.ends_at.slice(0, 16).replace(' ', 'T')) : '',
                all_day: Boolean(source.all_day),
                description: source.description ?? '',
                custom_reminders: Array.isArray(source.reminders),
                reminders: Array.isArray(source.reminders) ? [...source.reminders] : [...(props.defaults[source.type] ?? [])],
                recipients: [...(source.recipients ?? [])],
            });
        } else {
            const day = props.date || new Date().toISOString().slice(0, 10);
            form.defaults({ title: '', type: 'Manual', starts_at: `${day}T09:00`, ends_at: '', all_day: false, description: '', custom_reminders: false, reminders: [...(props.defaults.Manual ?? [1, 0])], recipients: [] });
        }
        form.reset();
    },
    { immediate: true },
);

// Al cambiar a «todo el día» las fechas pasan a solo fecha, y al revés.
watch(
    () => form.all_day,
    (allDay) => {
        if (allDay) {
            form.starts_at = String(form.starts_at).slice(0, 10);
            form.ends_at = form.ends_at ? String(form.ends_at).slice(0, 10) : '';
        } else {
            if (form.starts_at && form.starts_at.length === 10) form.starts_at = `${form.starts_at}T09:00`;
            if (form.ends_at && form.ends_at.length === 10) form.ends_at = `${form.ends_at}T10:00`;
        }
    },
);

watch(
    () => form.type,
    (type) => {
        if (!form.custom_reminders) form.reminders = [...(props.defaults[type] ?? [])];
    },
);

const ruleText = computed(() => {
    const days = props.defaults[form.type] ?? [];
    return days.length ? days.map(offsetLabel).join(', ') : 'Este tipo no envía avisos por defecto';
});

function submit() {
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            emit('update:open', false);
            emit('saved');
        },
        onError: () => focusFirstError(panel.value ?? document),
    };
    const data = (payload) => ({ ...payload, ends_at: payload.ends_at || null, reminders: payload.custom_reminders ? payload.reminders : [] });
    if (editing.value) form.transform(data).put(`/calendario/${props.event.id}`, options);
    else form.transform(data).post('/calendario', options);
}
</script>

<template>
    <BaseModal
        :open="open"
        as="form"
        size="lg"
        :title="editing ? 'Editar evento' : 'Nuevo evento'"
        description="Los eventos manuales avisan a quien los crea, a los administradores y a los destinatarios que elijas."
        :persistent="form.processing || form.isDirty"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div ref="panel" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="Título" required class="md:col-span-2" :error="form.errors.title">
                <TextInput v-model="form.title" maxlength="255" data-autofocus />
            </FormField>
            <FormField label="Tipo" required :error="form.errors.type">
                <SelectInput v-model="form.type" :options="types" placeholder="" />
            </FormField>
            <div class="flex items-end pb-2">
                <Toggle v-model="form.all_day" label="Todo el día" />
            </div>
            <FormField label="Inicio" required :error="form.errors.starts_at">
                <DateInput v-if="form.all_day" v-model="form.starts_at" />
                <DateTimeInput v-else v-model="form.starts_at" :step="300" />
            </FormField>
            <FormField label="Fin" optional :error="form.errors.ends_at">
                <DateInput v-if="form.all_day" v-model="form.ends_at" />
                <DateTimeInput v-else v-model="form.ends_at" :step="300" />
            </FormField>
            <FormField label="Descripción" optional class="md:col-span-2" :error="form.errors.description">
                <TextArea v-model="form.description" maxlength="2000" rows="3" />
            </FormField>

            <fieldset class="flex flex-col gap-3 md:col-span-2">
                <legend class="mb-1 font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Recordatorios</legend>
                <Toggle v-model="form.custom_reminders" label="Personalizar los avisos de este evento" :description="form.custom_reminders ? '' : `Regla de la empresa: ${ruleText}.`" />
                <div v-if="form.custom_reminders" class="flex flex-wrap gap-x-5 gap-y-2">
                    <Checkbox v-for="days in OFFSETS" :key="days" v-model="form.reminders" :value="days" :label="offsetLabel(days)" />
                </div>
                <p v-if="form.errors.reminders || form.errors['reminders.0']" class="text-sm text-cap-status-error-ink">{{ form.errors.reminders || form.errors['reminders.0'] }}</p>
            </fieldset>

            <fieldset v-if="members.length" class="flex flex-col gap-2 md:col-span-2">
                <legend class="mb-1 font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Destinatarios adicionales</legend>
                <div class="grid max-h-44 grid-cols-1 gap-2 overflow-y-auto rounded-cap-control border border-cap-divider p-3 sm:grid-cols-2">
                    <Checkbox v-for="member in members" :key="member.value" v-model="form.recipients" :value="member.value" :label="member.label" />
                </div>
                <p v-if="form.errors['recipients.0'] || form.errors.recipients" class="text-sm text-cap-status-error-ink">{{ form.errors['recipients.0'] || form.errors.recipients }}</p>
            </fieldset>
        </div>

        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">{{ editing ? 'Guardar cambios' : 'Crear evento' }}</AppButton>
        </template>
    </BaseModal>
</template>
