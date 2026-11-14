<script setup>
// Ficha de una empresa del grupo: datos fiscales, miembros, Blessing e impuestos, logo.
// El token de Blessing nunca llega al navegador: solo si está configurado y sus últimos caracteres.
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CapAlert from '../../../../design/vue/CapAlert.vue';
import { useToasts } from '../../../../design/vue/composables/useToasts.js';
import AppButton from '../../../Components/AppButton.vue';
import BaseModal from '../../../Components/BaseModal.vue';
import Checkbox from '../../../Components/Checkbox.vue';
import DataTable from '../../../Components/DataTable.vue';
import DropdownMenu from '../../../Components/DropdownMenu.vue';
import FileDropzone from '../../../Components/FileDropzone.vue';
import FormField from '../../../Components/FormField.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import SearchSelect from '../../../Components/SearchSelect.vue';
import SelectInput from '../../../Components/SelectInput.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import Tabs from '../../../Components/Tabs.vue';
import TextInput from '../../../Components/TextInput.vue';
import Toggle from '../../../Components/Toggle.vue';
import { useConfirm } from '../../../composables/useConfirm.js';
import { useUpload } from '../../../composables/useUpload.js';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { focusFirstError } from '../../../lib/errors.js';
import { number } from '../../../lib/format.js';
import { visit } from '../../../lib/http.js';
import { card } from '../../../lib/ui.js';

const props = defineProps({
    company: { type: Object, required: true },
    members: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    roles: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const confirm = useConfirm();
const toasts = useToasts();
const showErrors = (errors) => toasts.show({ status: 'error', title: Object.values(errors ?? {}).flat()[0] || 'No se pudo guardar el cambio.' });
const tab = ref('datos');
const tabs = computed(() => [
    { value: 'datos', label: 'Datos fiscales' },
    { value: 'miembros', label: 'Miembros', count: props.members.length },
    { value: 'blessing', label: 'Blessing e impuestos' },
    { value: 'logo', label: 'Logo' },
]);
const roleOptions = computed(() => Object.entries(props.roles).map(([value, label]) => ({ value, label })));
const ROLE_STATUS = { admin: 'info', operator: 'ok', viewer: 'neutral' };
const readonly = computed(() => !props.can.manage);

// Datos, Blessing e impuestos: un solo formulario, se guarda desde cualquiera de las dos pestañas.
const dataBody = ref(null);
const form = useForm({
    name: props.company.name ?? '',
    code: props.company.code ?? '',
    tax_id: props.company.tax_id ?? '',
    representative: props.company.representative ?? '',
    contact_name: props.company.contact_name ?? '',
    contact_email: props.company.contact_email ?? '',
    contact_phone: props.company.contact_phone ?? '',
    address: props.company.address ?? '',
    is_active: Boolean(props.company.is_active),
    blessing_base_url: props.company.blessing_base_url ?? '',
    blessing_price_list: props.company.blessing_price_list ?? '',
    blessing_token: '',
    clear_blessing_token: false,
    tax_rate: props.company.tax_rate ?? 15,
    prices_include_tax: Boolean(props.company.prices_include_tax),
});

function save() {
    form.put(`/empresas/${props.company.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.blessing_token = '';
            form.clear_blessing_token = false;
            form.defaults();
        },
        onError: () => focusFirstError(dataBody.value),
    });
}

// Miembros
const memberForm = useForm({ user_id: null, role: 'operator' });
const adding = ref(false);
function addMember() {
    memberForm.post(`/empresas/${props.company.id}/miembros`, {
        preserveScroll: true,
        onSuccess: () => {
            memberForm.reset();
            adding.value = false;
        },
    });
}
function changeRole(member, role) {
    if (role === member.role) return;
    router.put(`/empresas/${props.company.id}/miembros/${member.id}`, { role }, { preserveScroll: true, only: ['members', 'flash', 'errors'], onError: showErrors });
}
function removeMember(member) {
    confirm({
        verb: 'Quitar',
        object: `a ${member.name} de ${props.company.name}`,
        noun: 'miembro',
        message: 'Dejará de ver y operar los datos de esta empresa. Su cuenta no se borra.',
        icon: 'account-remove-outline',
        action: () => visit('delete', `/empresas/${props.company.id}/miembros/${member.id}`, {}, { only: ['members', 'flash', 'errors'], onError: showErrors }),
    });
}
const memberColumns = [
    { key: 'name', label: 'Usuario', primary: true, wrap: true },
    { key: 'role', label: 'Rol' },
];

// Logo
const files = ref([]);
const uploader = useUpload();
async function uploadLogo() {
    const done = await uploader.upload(`/empresas/${props.company.id}/logo`, files.value, { field: 'logo', only: ['company', 'flash', 'errors'] });
    if (done) {
        files.value = [];
        uploader.reset();
    }
}

function remove() {
    confirm({
        verb: 'Eliminar',
        object: `la empresa ${props.company.name}`,
        noun: 'empresa',
        message: 'Solo es posible si no tiene cotizaciones ni contratos. Sus miembros pierden el acceso a ella.',
        icon: 'trash-can-outline',
        acknowledgeLabel: 'Entiendo que no se puede deshacer',
        action: () => visit('delete', `/empresas/${props.company.id}`),
    });
}
</script>

<template>
    <AppLayout :title="company.name" back-href="/empresas" :breadcrumbs="[{ label: 'Empresas', href: '/empresas' }, { label: company.name }]">
        <PageHeader>
            <div class="flex flex-wrap items-center gap-2 text-sm text-cap-text-soft">
                <StatusBadge :value="company.is_active ? 'Activa' : 'Inactiva'" />
                <span>{{ number(stats.quotes) }} cotizaciones · {{ number(stats.contracts) }} contratos</span>
            </div>
            <template #actions>
                <AppButton v-if="can.delete" variant="danger" icon="trash-can-outline" @click="remove">Eliminar empresa</AppButton>
            </template>
        </PageHeader>

        <CapAlert v-if="readonly" status="info" title="Vista de solo lectura" detail="Solo la raíz del grupo edita los datos de las empresas." />

        <Tabs v-model="tab" :tabs="tabs" label="Secciones de la empresa">
            <template #datos>
                <form ref="dataBody" :class="[card, 'flex flex-col gap-6 p-4 sm:p-6']" @submit.prevent="save">
                    <fieldset :disabled="readonly" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                        <FormField label="Nombre" required :error="form.errors.name" class="md:col-span-2"><TextInput v-model="form.name" maxlength="255" /></FormField>
                        <FormField label="Código" optional :error="form.errors.code"><TextInput v-model="form.code" maxlength="50" /></FormField>
                        <FormField label="RTN" optional :error="form.errors.tax_id"><TextInput v-model="form.tax_id" maxlength="50" inputmode="numeric" /></FormField>
                        <FormField label="Representante legal" optional :error="form.errors.representative"><TextInput v-model="form.representative" maxlength="255" /></FormField>
                        <FormField label="Persona de contacto" optional :error="form.errors.contact_name"><TextInput v-model="form.contact_name" maxlength="255" /></FormField>
                        <FormField label="Correo" optional :error="form.errors.contact_email"><TextInput v-model="form.contact_email" type="email" maxlength="255" /></FormField>
                        <FormField label="Teléfono" optional :error="form.errors.contact_phone"><TextInput v-model="form.contact_phone" type="tel" maxlength="50" /></FormField>
                        <FormField label="Dirección" optional :error="form.errors.address" class="md:col-span-2"><TextInput v-model="form.address" maxlength="500" /></FormField>
                        <Toggle v-model="form.is_active" label="Empresa activa" description="Una empresa inactiva no aparece en el selector de la cabecera." class="md:col-span-2" />
                    </fieldset>
                    <div v-if="!readonly" class="flex justify-end">
                        <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">Guardar cambios</AppButton>
                    </div>
                </form>
            </template>

            <template #miembros>
                <div class="flex flex-col gap-4">
                    <div v-if="can.manage" class="flex justify-end">
                        <AppButton variant="primary" icon="account-plus-outline" @click="adding = true">Agregar miembro</AppButton>
                    </div>
                    <DataTable :columns="memberColumns" :rows="members" caption="Miembros" empty-icon="account-group-outline" empty-title="Sin miembros" empty-description="Agregue al menos un administrador.">
                        <template #cell-name="{ row }">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.name }}</p>
                                <p class="truncate text-xs text-cap-text-soft">{{ row.email }}</p>
                            </div>
                        </template>
                        <template #cell-role="{ row }">
                            <SelectInput v-if="can.members" :model-value="row.role" placeholder="" class="w-44" :aria-label="`Rol de ${row.name}`" :options="roleOptions" @update:model-value="(value) => changeRole(row, value)" />
                            <StatusBadge v-else :status="ROLE_STATUS[row.role] ?? 'neutral'" :label="roles[row.role] ?? row.role" />
                        </template>
                        <template #actions="{ row }">
                            <DropdownMenu v-if="can.members" :label="`Acciones de ${row.name}`" :items="[{ label: 'Quitar de la empresa', icon: 'account-remove-outline', danger: true, onSelect: () => removeMember(row) }]" width="w-56" />
                        </template>
                    </DataTable>
                </div>
            </template>

            <template #blessing>
                <form :class="[card, 'flex flex-col gap-6 p-4 sm:p-6']" @submit.prevent="save">
                    <div>
                        <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Conexión con Blessing</h2>
                        <p class="text-sm text-cap-text-soft">Inventario y precios sugeridos. El token se guarda cifrado y nunca se muestra completo.</p>
                    </div>
                    <fieldset :disabled="readonly" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                        <FormField label="URL base" optional :error="form.errors.blessing_base_url" class="md:col-span-2"><TextInput v-model="form.blessing_base_url" type="url" maxlength="255" placeholder="https://api.ejemplo.hn" /></FormField>
                        <FormField
                            label="Token de acceso"
                            optional
                            :error="form.errors.blessing_token"
                            :help="company.blessing_token_set ? `Guardado (${company.blessing_token_masked}). Déjelo vacío para conservarlo.` : 'Aún no hay token guardado.'"
                        >
                            <TextInput v-model="form.blessing_token" type="password" autocomplete="new-password" maxlength="2000" :disabled="form.clear_blessing_token" />
                        </FormField>
                        <FormField label="Lista de precios" optional :error="form.errors.blessing_price_list"><TextInput v-model="form.blessing_price_list" maxlength="100" /></FormField>
                        <Checkbox v-if="company.blessing_token_set" v-model="form.clear_blessing_token" label="Borrar el token guardado" class="md:col-span-2" />
                    </fieldset>
                    <div class="border-t border-cap-divider pt-6">
                        <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Impuesto sobre ventas</h2>
                        <p class="text-sm text-cap-text-soft">Se aplica igual en inventario, cotizaciones y calculadora de oferta.</p>
                    </div>
                    <fieldset :disabled="readonly" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                        <FormField label="Tasa de ISV (%)" :error="form.errors.tax_rate"><TextInput v-model="form.tax_rate" type="number" min="0" max="100" step="0.01" /></FormField>
                        <Toggle v-model="form.prices_include_tax" label="El precio de Blessing incluye ISV" description="Apagado: al precio sugerido se le suma el ISV." />
                    </fieldset>
                    <div v-if="!readonly" class="flex justify-end">
                        <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">Guardar cambios</AppButton>
                    </div>
                </form>
            </template>

            <template #logo>
                <section :class="[card, 'flex flex-col gap-6 p-4 sm:p-6']">
                    <div class="flex flex-wrap items-center gap-4">
                        <img v-if="company.logo_url" :src="company.logo_url" :alt="`Logo de ${company.name}`" class="h-20 max-w-60 rounded-cap-control border border-cap-divider bg-white object-contain p-2 dark:bg-white" />
                        <p v-else class="text-sm text-cap-text-soft">La empresa aún no tiene logo. Sale en el membrete de cotizaciones y documentos.</p>
                    </div>
                    <template v-if="can.manage">
                        <FileDropzone v-model="files" :uploader="uploader" accept=".png,.jpg,.jpeg,.webp" :max-size-mb="2" title="Arrastre el logo o elija una imagen" hint="PNG, JPG o WebP, hasta 2 MB. Mejor con fondo transparente." />
                        <div class="flex justify-end">
                            <AppButton variant="primary" icon="upload-outline" :disabled="!files.length" :loading="uploader.uploading.value" @click="uploadLogo">Subir logo</AppButton>
                        </div>
                    </template>
                </section>
            </template>
        </Tabs>

        <BaseModal v-model:open="adding" as="form" size="md" title="Agregar miembro" :description="`Usuario existente que se agregará a ${company.name}. Para crear una cuenta nueva use Usuarios.`" :persistent="memberForm.processing" @submit="addMember">
            <div class="flex flex-col gap-4">
                <FormField label="Usuario" required :error="memberForm.errors.user_id">
                    <SearchSelect v-model="memberForm.user_id" :url="`/empresas/${company.id}/usuarios-disponibles`" option-value="id" option-label="name" option-description="description" :min-chars="1" placeholder="Buscar por nombre o correo…" />
                </FormField>
                <FormField label="Rol" required :error="memberForm.errors.role"><SelectInput v-model="memberForm.role" placeholder="" :options="roleOptions" /></FormField>
            </div>
            <template #footer>
                <AppButton :disabled="memberForm.processing" @click="adding = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="account-plus-outline" :loading="memberForm.processing" :disabled="!memberForm.user_id">Agregar miembro</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
