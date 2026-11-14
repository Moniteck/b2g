<script setup>
// «Solicitudes» de Usuarios: solicitudes de acceso pendientes que revisa el administrador de la
// empresa activa (la raíz ve también las que no eligieron empresa). Aprobar pide empresa y rol;
// rechazar admite un motivo. Se monta en Pages/Admin/Users.vue con la prop diferida `accessRequests`.
//
//   <AccessRequestsPanel :requests="accessRequests" :roles="roles" :companies="approvalCompanies" :active-company-id="company.id" />
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import FormField from '../../Components/FormField.vue';
import SelectInput from '../../Components/SelectInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TextArea from '../../Components/TextArea.vue';
import { dateTime } from '../../lib/format.js';
import { card } from '../../lib/ui.js';

const props = defineProps({
    requests: { type: Array, default: undefined },
    roles: { type: Object, default: () => ({}) },
    companies: { type: Array, default: () => [] },
    activeCompanyId: { type: Number, required: true },
});

const loading = computed(() => props.requests === undefined);
const items = computed(() => props.requests ?? []);
const roleOptions = computed(() => Object.entries(props.roles).map(([value, label]) => ({ value, label })));

// Aprobar
const approving = ref(null);
const approve = useForm({ company_id: null, role: 'operator' });
const approveOpen = computed({ get: () => approving.value !== null, set: (value) => !value && (approving.value = null) });
function openApprove(item) {
    approve.clearErrors();
    approve.company_id = props.activeCompanyId;
    approve.role = 'operator';
    approving.value = item;
}
function sendApprove() {
    approve.post(`/configuracion/usuarios/solicitudes/${approving.value.id}/aprobar`, {
        preserveScroll: true,
        only: ['accessRequests', 'users', 'counts', 'flash', 'errors'],
        onSuccess: () => (approving.value = null),
    });
}

// Rechazar
const rejecting = ref(null);
const reject = useForm({ reason: '' });
const rejectOpen = computed({ get: () => rejecting.value !== null, set: (value) => !value && (rejecting.value = null) });
function openReject(item) {
    reject.reset();
    reject.clearErrors();
    rejecting.value = item;
}
function sendReject() {
    reject.post(`/configuracion/usuarios/solicitudes/${rejecting.value.id}/rechazar`, {
        preserveScroll: true,
        only: ['accessRequests', 'flash', 'errors'],
        onSuccess: () => (rejecting.value = null),
    });
}

const where = (item) => item.company ?? (item.company_name ? `Dice ser de «${item.company_name}»` : 'Sin empresa indicada');
</script>

<template>
    <section v-if="loading || items.length" id="solicitudes" :class="[card, 'p-4 sm:p-6']" aria-labelledby="solicitudes-titulo">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h2 id="solicitudes-titulo" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Solicitudes de acceso</h2>
            <StatusBadge v-if="!loading" status="warning" :label="`${items.length} pendiente${items.length === 1 ? '' : 's'}`" />
        </div>
        <p class="mb-4 text-sm text-cap-text-soft">Personas que pidieron una cuenta desde la pantalla de acceso. No pueden entrar hasta que alguien las apruebe.</p>

        <div v-if="loading" class="flex flex-col gap-3" aria-busy="true">
            <div v-for="index in 2" :key="index" class="cap-skeleton h-16 rounded-cap-control" />
        </div>

        <ul v-else class="flex flex-col divide-y divide-cap-divider">
            <li v-for="item in items" :key="item.id" class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ item.name }} <span class="font-normal text-cap-text-soft">· {{ item.position }}</span></p>
                    <p class="truncate text-sm text-cap-text-soft">{{ item.email }}<template v-if="item.phone"> · {{ item.phone }}</template></p>
                    <p class="text-xs text-cap-text-soft">{{ where(item) }} · {{ dateTime(item.created_at) }}</p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <AppButton icon="close" @click="openReject(item)">Rechazar</AppButton>
                    <AppButton icon="check" @click="openApprove(item)">Aprobar</AppButton>
                </div>
            </li>
        </ul>

        <BaseModal v-model:open="approveOpen" as="form" size="sm" title="Aprobar solicitud" :description="approving ? `${approving.name} · ${approving.email}` : ''" :persistent="approve.processing" @submit="sendApprove">
            <div class="flex flex-col gap-4">
                <p v-if="approving" class="text-sm text-cap-text-soft">{{ where(approving) }}. Entrará con la contraseña que eligió y recibirá un aviso por correo.</p>
                <FormField label="Empresa" required :error="approve.errors.company_id">
                    <SelectInput v-model="approve.company_id" placeholder="" :options="companies" :disabled="companies.length <= 1" />
                </FormField>
                <FormField label="Rol en la empresa" required :error="approve.errors.role || approve.errors.request">
                    <SelectInput v-model="approve.role" placeholder="" :options="roleOptions" />
                </FormField>
            </div>
            <template #footer>
                <AppButton :disabled="approve.processing" @click="approving = null">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="account-check-outline" :loading="approve.processing">Aprobar acceso</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="rejectOpen" as="form" size="sm" title="Rechazar solicitud" :description="rejecting ? `${rejecting.name} · ${rejecting.email}` : ''" :persistent="reject.processing" @submit="sendReject">
            <FormField label="Motivo (opcional)" :error="reject.errors.reason || reject.errors.request" help="Queda en la bitácora; la persona no lo recibe.">
                <TextArea v-model="reject.reason" rows="3" maxlength="500" />
            </FormField>
            <template #footer>
                <AppButton :disabled="reject.processing" @click="rejecting = null">Cancelar</AppButton>
                <AppButton variant="danger" type="submit" icon="close" :loading="reject.processing">Rechazar solicitud</AppButton>
            </template>
        </BaseModal>
    </section>
</template>
