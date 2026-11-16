<script setup>
// Usuarios de la empresa activa: invitar, cambiar rol, restablecer contraseña y quitar de la empresa.
// La contraseña temporal llega una sola vez (`credentials`, dato flash) y se muestra en una modal.
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DataTable from '@/Components/DataTable.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import Toolbar from '@/Components/Toolbar.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { focusFirstError } from '@/lib/errors.js';
import { visit } from '@/lib/http.js';
import { card } from '@/lib/ui.js';
import { useListFilters } from '@/composables/useListFilters.js';
import AccessRequestsPanel from '@/Pages/Admin/Partials/AccessRequestsPanel.vue';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    roles: { type: Object, default: () => ({}) },
    company: { type: Object, required: true },
    credentials: { type: Object, default: null },
    isRoot: { type: Boolean, default: false },
    accessRequests: { type: Array, default: undefined },
    approvalCompanies: { type: Array, default: () => [] },
});

const { filters, loading, error, active, reset, reload } = useListFilters('/configuracion/usuarios', props.filters, {
    only: ['users', 'filters', 'counts'],
    defaults: { search: '', role: 'all' },
});

const confirm = useConfirm();
const toasts = useToasts();
const showErrors = (errors) => toasts.show({ status: 'error', title: Object.values(errors ?? {}).flat()[0] || 'No se pudo completar la acción.' });
const ROLE_STATUS = { admin: 'info', operator: 'ok', viewer: 'neutral' };
const roleOptions = computed(() => Object.entries(props.roles).map(([value, label]) => ({ value, label })));

const columns = computed(() => [
    { key: 'name', label: 'Usuario', primary: true, wrap: true },
    { key: 'role', label: 'Rol' },
    ...(props.isRoot ? [{ key: 'companies', label: 'Empresas', wrap: true, hideOnMobile: true }] : []),
]);

// Invitar
const inviting = ref(false);
const inviteBody = ref(null);
const invite = useForm({ name: '', email: '', role: 'operator' });
function openInvite() {
    invite.reset();
    invite.clearErrors();
    inviting.value = true;
}
function sendInvite() {
    invite.post('/configuracion/usuarios', {
        preserveScroll: true,
        onSuccess: () => (inviting.value = false),
        onError: () => focusFirstError(inviteBody.value),
    });
}

// Cambiar rol
const roleTarget = ref(null);
const roleForm = useForm({ role: '' });
function openRole(row) {
    roleForm.clearErrors();
    roleForm.role = row.role;
    roleTarget.value = row;
}
const roleOpen = computed({ get: () => roleTarget.value !== null, set: (value) => !value && (roleTarget.value = null) });
function saveRole() {
    roleForm.put(`/configuracion/usuarios/${roleTarget.value.id}/rol`, { preserveScroll: true, onSuccess: () => (roleTarget.value = null) });
}

// Contraseña temporal (una sola vez)
const shown = ref(null);
const copied = ref(false);
watch(
    () => props.credentials,
    (value) => {
        if (value?.password) {
            shown.value = value;
            copied.value = false;
        }
    },
    { immediate: true },
);
const shownOpen = computed({ get: () => shown.value !== null, set: (value) => !value && (shown.value = null) });
async function copyPassword() {
    try {
        await navigator.clipboard.writeText(shown.value.password);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}

function actionsOf(row) {
    const items = [];
    if (!row.is_self || !row.last_admin) items.push({ label: 'Cambiar rol', icon: 'account-switch-outline', onSelect: () => openRole(row) });
    if (row.can_reset_password) items.push({ label: 'Restablecer contraseña', icon: 'lock-reset', onSelect: () => resetPassword(row) });
    if (!row.is_self && !row.last_admin) items.push({ label: 'Quitar de la empresa', icon: 'account-remove-outline', danger: true, separator: true, onSelect: () => removeUser(row) });
    return items;
}

function resetPassword(row) {
    confirm({
        verb: 'Restablecer',
        object: `la contraseña de ${row.name}`,
        noun: 'contraseña',
        message: 'Su contraseña actual dejará de funcionar. Verá la temporal una sola vez para entregársela.',
        icon: 'lock-reset',
        action: () => visit('post', `/configuracion/usuarios/${row.id}/contrasena`, {}, { only: ['users', 'credentials', 'flash'] }),
    });
}

function removeUser(row) {
    confirm({
        verb: 'Quitar',
        object: `a ${row.name} de ${props.company.name}`,
        noun: 'de la empresa',
        message: 'Dejará de ver y operar los datos de esta empresa. Su cuenta y sus otras empresas no cambian.',
        icon: 'account-remove-outline',
        action: () => visit('delete', `/configuracion/usuarios/${row.id}`, {}, { only: ['users', 'counts', 'flash', 'errors'], onError: showErrors }),
    });
}
</script>

<template>
    <AppLayout title="Usuarios" :fab-label="'Agregar usuario'" @fab="openInvite">
        <PageHeader :description="`Miembros de ${company.name} y su rol en la empresa`">
            <template #actions>
                <AppButton class="max-lg:hidden" variant="primary" icon="account-plus-outline" @click="openInvite">Agregar usuario</AppButton>
            </template>
        </PageHeader>

        <AccessRequestsPanel :requests="accessRequests" :roles="roles" :companies="approvalCompanies" :active-company-id="company.id" />

        <Toolbar
            v-model:search="filters.search"
            v-model:filter="filters.role"
            search-placeholder="Buscar por nombre o correo"
            filter-label="Rol"
            :filters="[
                { label: 'Todos', value: 'all', count: counts.all },
                { label: 'Administradores', value: 'admin', count: counts.admin },
                { label: 'Operadores', value: 'operator', count: counts.operator },
                { label: 'Solo lectura', value: 'viewer', count: counts.viewer },
            ]"
        />

        <div>
            <DataTable
                :columns="columns"
                :rows="users.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                caption="Usuarios de la empresa"
                empty-icon="account-group-outline"
                empty-title="La empresa aún no tiene usuarios"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-name="{ row }">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100">
                            {{ row.name }}<span v-if="row.is_self" class="font-normal text-cap-text-soft"> (usted)</span>
                        </p>
                        <p class="truncate text-xs text-cap-text-soft">{{ row.email }}</p>
                    </div>
                </template>
                <template #cell-role="{ row }">
                    <div class="flex flex-wrap items-center gap-1">
                        <StatusBadge :status="ROLE_STATUS[row.role] ?? 'neutral'" :label="roles[row.role] ?? row.role" />
                        <StatusBadge v-if="row.is_root" status="warning" label="Raíz" />
                    </div>
                </template>
                <template #cell-companies="{ row }">
                    <span class="text-sm">{{ (row.companies ?? []).map((item) => `${item.name} (${roles[item.role] ?? item.role})`).join(', ') || '—' }}</span>
                </template>
                <template #actions="{ row }"><DropdownMenu v-if="actionsOf(row).length" :label="`Acciones de ${row.name}`" :items="actionsOf(row)" width="w-60" /></template>
            </DataTable>
            <Pagination :paginator="users" :only="['users', 'filters']" />
        </div>

        <section :class="[card, 'p-4 text-sm text-cap-text-soft sm:p-6']">
            <p class="mb-2 font-semibold text-gray-900 dark:text-gray-100">Qué puede hacer cada rol</p>
            <ul class="flex flex-col gap-1">
                <li><strong class="text-gray-900 dark:text-gray-100">Administrador:</strong> todo, incluidos usuarios, configuración, eliminar y revertir resultados.</li>
                <li><strong class="text-gray-900 dark:text-gray-100">Operador:</strong> crea y edita licitaciones, cotizaciones, contratos y documentos; firma.</li>
                <li><strong class="text-gray-900 dark:text-gray-100">Solo lectura:</strong> consulta todo sin modificar nada.</li>
            </ul>
        </section>

        <BaseModal v-model:open="inviting" as="form" size="md" title="Agregar usuario" :description="`Se agregará a ${company.name}.`" :persistent="invite.processing" @submit="sendInvite">
            <div ref="inviteBody" class="flex flex-col gap-4">
                <FormField label="Nombre" required :error="invite.errors.name"><TextInput v-model="invite.name" maxlength="255" autocomplete="off" /></FormField>
                <FormField label="Correo" required :error="invite.errors.email" help="Si ya tiene cuenta, se agrega a la empresa con su contraseña actual."><TextInput v-model="invite.email" type="email" maxlength="255" autocomplete="off" /></FormField>
                <FormField label="Rol" required :error="invite.errors.role"><SelectInput v-model="invite.role" placeholder="" :options="roleOptions" /></FormField>
            </div>
            <template #footer>
                <AppButton :disabled="invite.processing" @click="inviting = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="account-plus-outline" :loading="invite.processing">Agregar usuario</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="roleOpen" as="form" size="sm" title="Cambiar rol" :description="roleTarget?.name" :persistent="roleForm.processing" @submit="saveRole">
            <FormField label="Rol en la empresa" :error="roleForm.errors.role"><SelectInput v-model="roleForm.role" placeholder="" :options="roleOptions" /></FormField>
            <template #footer>
                <AppButton :disabled="roleForm.processing" @click="roleTarget = null">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="roleForm.processing">Guardar rol</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="shownOpen" size="md" persistent :title="shown?.reset ? 'Contraseña restablecida' : 'Usuario creado'">
            <div class="flex flex-col gap-4">
                <CapAlert status="warning" title="Esta contraseña se muestra una sola vez" detail="Cópiela y entréguesela por un medio seguro. Al cerrar no se puede volver a ver." />
                <dl class="grid grid-cols-1 gap-3 text-sm">
                    <div><dt class="text-cap-text-soft">Usuario</dt><dd class="break-all font-medium text-gray-900 dark:text-gray-100">{{ shown?.name }} · {{ shown?.email }}</dd></div>
                    <div>
                        <dt class="text-cap-text-soft">Contraseña temporal</dt>
                        <dd class="mt-1 flex flex-wrap items-center gap-2">
                            <code data-qa="temporary-password" class="rounded-cap-control border border-cap-divider bg-cap-card-2 px-3 py-2 text-base font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ shown?.password }}</code>
                            <AppButton :icon="copied ? 'check' : 'content-copy'" @click="copyPassword">{{ copied ? 'Copiada' : 'Copiar' }}</AppButton>
                        </dd>
                    </div>
                </dl>
                <p class="text-sm text-cap-text-soft">Pídale que la cambie en «Mi cuenta» al entrar.</p>
            </div>
            <template #footer>
                <AppButton variant="primary" icon="check" @click="shown = null">Ya la copié</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
