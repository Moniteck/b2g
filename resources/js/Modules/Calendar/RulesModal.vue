<script setup>
// «Reglas de recordatorio» de la empresa activa (solo administradores): por tipo de plazo, días de
// anticipación (0 = el mismo día), hora de envío, canales y correos extra.
// Props: open (v-model:open), rules ([{ event_type, label, days_before, send_time, channels, recipients, is_active }]).
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import { focusFirstError } from '@/lib/errors.js';
import { inputClass } from '@/lib/ui.js';
import { parseDays } from '@/Modules/Calendar/offsets.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    rules: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open', 'saved']);

const panel = ref(null);
const localErrors = ref({});
const form = useForm({ rules: [] });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        localErrors.value = {};
        form.clearErrors();
        form.defaults({
            rules: props.rules.map((rule) => ({
                event_type: rule.event_type,
                label: rule.label,
                is_active: rule.is_active !== false,
                days: (rule.days_before ?? []).join(', '),
                send_time: rule.send_time,
                channels: [...(rule.channels ?? [])],
                recipients: (rule.recipients ?? []).join(', '),
            })),
        });
        form.reset();
    },
    { immediate: true },
);

function errorOf(index, field) {
    const prefix = `rules.${index}.${field}`;
    const key = Object.keys(form.errors).find((name) => name === prefix || name.startsWith(`${prefix}.`));
    return localErrors.value[`${index}.${field}`] || (key ? form.errors[key] : '');
}

function submit() {
    localErrors.value = {};
    form.rules.forEach((rule, index) => {
        if (parseDays(rule.days) === null) localErrors.value[`${index}.days_before`] = 'Escribe días en números separados por comas (0 es el mismo día).';
    });
    if (Object.keys(localErrors.value).length) {
        focusFirstError(panel.value ?? document);
        return;
    }

    form
        .transform((data) => ({
            rules: data.rules.map((rule) => ({
                event_type: rule.event_type,
                is_active: rule.is_active,
                days_before: parseDays(rule.days) ?? [],
                send_time: rule.send_time,
                channels: rule.channels,
                recipients: rule.recipients,
            })),
        }))
        .put('/calendario/reglas', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                emit('update:open', false);
                emit('saved');
            },
            onError: () => focusFirstError(panel.value ?? document),
        });
}
</script>

<template>
    <BaseModal
        :open="open"
        as="form"
        size="xl"
        title="Reglas de recordatorio"
        description="Días de anticipación (0 es el mismo día) y hora de envío por tipo de plazo. Sin regla propia se usan los valores por defecto."
        :persistent="form.processing || form.isDirty"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div ref="panel" class="flex flex-col gap-3">
            <section v-for="(rule, index) in form.rules" :key="rule.event_type" class="rounded-cap-control border border-cap-divider p-3" :aria-label="rule.label">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-display text-sm font-semibold text-gray-900 dark:text-gray-100">{{ rule.label }}</h3>
                    <Toggle v-model="rule.is_active" :label="rule.is_active ? 'Avisos activos' : 'Sin avisos'" />
                </div>
                <div class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.2fr)_8rem_minmax(0,1fr)_minmax(0,1.6fr)]">
                    <FormField label="Días antes" help="Ej.: 7, 3, 1, 0" :error="errorOf(index, 'days_before')">
                        <TextInput v-model="rule.days" inputmode="numeric" :disabled="!rule.is_active" />
                    </FormField>
                    <FormField label="Hora" :error="errorOf(index, 'send_time')" :for="`rule-time-${index}`">
                        <input :id="`rule-time-${index}`" v-model="rule.send_time" type="time" step="300" :class="inputClass({ invalid: Boolean(errorOf(index, 'send_time')) })" class="h-10" :disabled="!rule.is_active" />
                    </FormField>
                    <fieldset class="flex flex-col gap-1.5">
                        <legend class="mb-1 text-sm font-medium text-gray-900 dark:text-gray-100">Canales</legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <Checkbox v-model="rule.channels" value="correo" label="Correo" :disabled="!rule.is_active" />
                            <Checkbox v-model="rule.channels" value="campana" label="Campana" :disabled="!rule.is_active" />
                            <Checkbox v-model="rule.channels" value="push" label="Push" :disabled="!rule.is_active" />
                        </div>
                        <p v-if="errorOf(index, 'channels')" class="text-xs text-cap-status-error-ink">{{ errorOf(index, 'channels') }}</p>
                    </fieldset>
                    <FormField label="Correos extra" optional help="Separados por comas" :error="errorOf(index, 'recipients')">
                        <TextInput v-model="rule.recipients" type="text" inputmode="email" autocomplete="off" :disabled="!rule.is_active" />
                    </FormField>
                </div>
            </section>
        </div>

        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">Guardar reglas</AppButton>
        </template>
    </BaseModal>
</template>
