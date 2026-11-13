<script setup>
// Plantillas de notificación de la empresa activa (solo administradores).
// Props: templates (paginador: [{ id, name, channel, subject, body, active, updated_at }]), channels.
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DataTable from '../../Components/DataTable.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import FormField from '../../Components/FormField.vue';
import Pagination from '../../Components/Pagination.vue';
import SelectInput from '../../Components/SelectInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import Toggle from '../../Components/Toggle.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import { focusFirstError } from '../../lib/errors.js';
import { date } from '../../lib/format.js';
import { visit } from '../../lib/http.js';

const props = defineProps({
    templates: { type: Object, required: true },
    channels: { type: Array, default: () => [] },
});

const confirm = useConfirm();
const open = ref(false);
const editing = ref(null);
const panel = ref(null);
const form = useForm({ name: '', channel: 'correo', subject: '', body: '', active: true });

const CHANNEL_LABELS = { correo: 'Correo', campana: 'Campana', whatsapp: 'WhatsApp' };
const columns = [
    { key: 'name', label: 'Plantilla', primary: true },
    { key: 'channel', label: 'Canal', format: (value) => CHANNEL_LABELS[value] ?? value },
    { key: 'subject', label: 'Asunto' },
    { key: 'active', label: 'Estado' },
    { key: 'updated_at', label: 'Actualizada', format: (value) => date(value) },
];
const sections = [
    { label: 'Mis recordatorios', href: '/notificaciones' },
    { label: 'Calendario', href: '/calendario' },
    { label: 'Plantillas', href: '/notificaciones/plantillas', active: true },
];

function create() {
    editing.value = null;
    form.defaults({ name: '', channel: 'correo', subject: '', body: '', active: true });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(row) {
    editing.value = row;
    form.defaults({ name: row.name, channel: row.channel === 'bandeja' ? 'campana' : row.channel, subject: row.subject ?? '', body: row.body ?? '', active: Boolean(row.active) });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false), onError: () => focusFirstError(panel.value ?? document) };
    if (editing.value) form.put(`/notificaciones/plantillas/${editing.value.id}`, options);
    else form.post('/notificaciones/plantillas', options);
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `la plantilla ${row.name}`,
        noun: 'plantilla',
        message: 'Dejará de estar disponible. No se puede deshacer.',
        action: () => visit('delete', `/notificaciones/plantillas/${row.id}`),
    });
}
</script>

<template>
    <AppLayout title="Plantillas de notificación" :sections="sections" :fab-label="'Nueva plantilla'" @fab="create">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-cap-text-soft">Textos base para los avisos por correo y campana de la empresa activa.</p>
            <div v-if="templates.data.length" class="hidden md:block"><AppButton variant="primary" icon="plus" @click="create">Nueva plantilla</AppButton></div>
        </div>

        <DataTable :columns="columns" :rows="templates.data" clickable empty-title="Aún no hay plantillas" @row-click="edit">
            <template #cell-active="{ value }"><StatusBadge :status="value ? 'ok' : 'neutral'" :label="value ? 'Activa' : 'Inactiva'" /></template>
            <template #actions="{ row }">
                <DropdownMenu :label="`Acciones de ${row.name}`" :items="[
                    { label: 'Editar', icon: 'pencil-outline', onSelect: () => edit(row) },
                    { label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) },
                ]" />
            </template>
            <template #empty-action><AppButton variant="primary" icon="plus" @click="create">Nueva plantilla</AppButton></template>
        </DataTable>
        <Pagination :paginator="templates" :per-page-options="[]" :only="['templates']" />

        <BaseModal v-model:open="open" as="form" size="lg" :title="editing ? 'Editar plantilla' : 'Nueva plantilla'" :persistent="form.processing" @submit="save">
            <div ref="panel" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Nombre" required :error="form.errors.name"><TextInput v-model="form.name" maxlength="255" data-autofocus /></FormField>
                <FormField label="Canal" required :error="form.errors.channel"><SelectInput v-model="form.channel" :options="channels" placeholder="" /></FormField>
                <FormField label="Asunto" optional class="md:col-span-2" :error="form.errors.subject"><TextInput v-model="form.subject" maxlength="255" /></FormField>
                <FormField label="Contenido" required class="md:col-span-2" :error="form.errors.body"><TextArea v-model="form.body" rows="6" maxlength="5000" /></FormField>
                <Toggle v-model="form.active" label="Plantilla activa" />
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="open = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">{{ editing ? 'Guardar cambios' : 'Crear plantilla' }}</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
