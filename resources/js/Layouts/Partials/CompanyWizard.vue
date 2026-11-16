<script setup>
// Asistente de alta de empresa (solo raíz), en 4 pasos con los endpoints existentes:
//   1. Datos fiscales           POST /empresas                     (crea y abre su ficha detrás)
//   2. Logo (opcional)          POST /empresas/{id}/logo
//   3. Primer administrador     POST /empresas/{id}/miembros       (usuario existente)
//                               o invitación: entra en modo soporte en la empresa nueva, abre
//                               Usuarios y POST /configuracion/usuarios (allí se ve la contraseña temporal)
//   4. Blessing e ISV (opcional) PUT /empresas/{id}
// Vive en el marco persistente: sigue abierto aunque la página de atrás cambie.
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FormField from '@/Components/FormField.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import TextInput from '@/Components/TextInput.vue';
import { endpoints } from '@/config/navigation.js';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open']);

const STEPS = ['Datos fiscales', 'Logo', 'Administrador', 'Blessing e ISV'];
const page = usePage();
const step = ref(0);
const companyId = ref(null);
const done = ref(false);

const fiscal = useForm({ name: '', code: '', tax_id: '', representative: '', address: '', contact_email: '', contact_phone: '' });
const logo = useForm({ logo: null });
const member = useForm({ user_id: null, role: 'admin' });
const invite = useForm({ name: '', email: '', role: 'admin' });
const adminMode = ref('existing');
const settings = useForm({ blessing_base_url: '', blessing_token: '', blessing_price_list: '', tax_rate: 15, prices_include_tax: false });

const busy = computed(() => fiscal.processing || logo.processing || member.processing || invite.processing || settings.processing);
const logoPreview = ref('');

function reset() {
    step.value = 0;
    companyId.value = null;
    done.value = false;
    [fiscal, logo, member, invite, settings].forEach((form) => {
        form.reset();
        form.clearErrors();
    });
    adminMode.value = 'existing';
    logoPreview.value = '';
}

function close() {
    emit('update:open', false);
    if (done.value || companyId.value === null) setTimeout(reset, 250);
}

function next() {
    step.value = Math.min(step.value + 1, STEPS.length - 1);
}

function saveFiscal() {
    if (companyId.value !== null) return next();
    fiscal.post('/empresas', {
        preserveScroll: true,
        onSuccess: (response) => {
            companyId.value = response.props.company?.id ?? null;
            if (companyId.value !== null) next();
        },
    });
}

function pickLogo(event) {
    const file = event.target.files?.[0] ?? null;
    logo.logo = file;
    logoPreview.value = file ? URL.createObjectURL(file) : '';
}

function saveLogo() {
    if (!logo.logo) return next();
    logo.post(`/empresas/${companyId.value}/logo`, { forceFormData: true, preserveScroll: true, onSuccess: next });
}

function saveAdmin() {
    if (adminMode.value === 'existing') {
        if (!member.user_id) return next();
        member.post(`/empresas/${companyId.value}/miembros`, { preserveScroll: true, onSuccess: next });
        return;
    }
    // Invitación: la cuenta se crea en la empresa activa, así que se entra en ella en modo soporte y
    // se envía desde Usuarios, que es donde se muestra la contraseña temporal.
    router.post(endpoints.supportEnter, { company_id: companyId.value }, {
        preserveScroll: true,
        onSuccess: () =>
            router.visit('/configuracion/usuarios', {
                onSuccess: () => invite.post('/configuracion/usuarios', { preserveScroll: true, onSuccess: next }),
            }),
    });
}

function saveSettings() {
    const payload = { ...fiscal.data(), ...settings.data() };
    settings
        .transform(() => payload)
        .put(`/empresas/${companyId.value}`, {
            preserveScroll: true,
            onSuccess: () => {
                done.value = true;
            },
        });
}

function finish() {
    done.value = true;
    close();
    if (!page.url.startsWith(`/empresas/${companyId.value}`) && !page.url.startsWith('/configuracion/usuarios')) router.visit(`/empresas/${companyId.value}`);
}

const submit = () => [saveFiscal, saveLogo, saveAdmin, saveSettings][step.value]();
const primaryLabel = computed(() => ['Crear empresa', logo.logo ? 'Subir logo' : 'Omitir', adminMode.value === 'invite' ? 'Invitar administrador' : member.user_id ? 'Agregar administrador' : 'Omitir', 'Guardar y terminar'][step.value]);
</script>

<template>
    <BaseModal :open="open" as="form" size="lg" title="Nueva empresa del grupo" :persistent="busy || companyId !== null" @update:open="(value) => !value && close()" @submit="submit">
        <ol class="mb-6 grid grid-cols-4 gap-2" aria-label="Pasos">
            <li v-for="(label, index) in STEPS" :key="label" class="flex min-w-0 flex-col gap-1.5" :aria-current="index === step ? 'step' : undefined">
                <span class="h-1 rounded-full" :class="index <= step ? 'bg-cap-brand' : 'bg-cap-divider'" />
                <span class="truncate text-xs" :class="index === step ? 'font-semibold text-gray-900 dark:text-gray-100' : 'text-cap-text-soft'">{{ index + 1 }}. {{ label }}</span>
            </li>
        </ol>

        <div v-if="done" class="flex flex-col items-center gap-3 py-6 text-center">
            <i class="mdi mdi-check-circle-outline m-check text-5xl leading-none text-cap-status-ok" aria-hidden="true" />
            <p class="font-display text-lg font-semibold text-gray-900 dark:text-gray-100">{{ fiscal.name }} quedó lista</p>
            <p class="text-cap-text-soft">Puedes completar el resto de sus datos y miembros desde su ficha.</p>
        </div>

        <div v-else-if="step === 0" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="Nombre" required :error="fiscal.errors.name" class="md:col-span-2"><TextInput v-model="fiscal.name" maxlength="255" data-autofocus /></FormField>
            <FormField label="Código" optional :error="fiscal.errors.code" help="Abreviatura interna, única."><TextInput v-model="fiscal.code" maxlength="50" /></FormField>
            <FormField label="RTN" optional :error="fiscal.errors.tax_id"><TextInput v-model="fiscal.tax_id" maxlength="50" inputmode="numeric" /></FormField>
            <FormField label="Representante legal" optional :error="fiscal.errors.representative"><TextInput v-model="fiscal.representative" maxlength="255" /></FormField>
            <FormField label="Teléfono" optional :error="fiscal.errors.contact_phone"><TextInput v-model="fiscal.contact_phone" maxlength="50" inputmode="tel" /></FormField>
            <FormField label="Correo de contacto" optional :error="fiscal.errors.contact_email"><TextInput v-model="fiscal.contact_email" type="email" maxlength="255" /></FormField>
            <FormField label="Dirección" optional :error="fiscal.errors.address"><TextInput v-model="fiscal.address" maxlength="500" /></FormField>
        </div>

        <div v-else-if="step === 1" class="flex flex-col gap-4">
            <p>El logo aparece en el selector de empresa y en el membrete de los PDF. PNG, JPG o WebP de hasta 2 MB.</p>
            <label class="flex cursor-pointer flex-col items-center gap-3 rounded-cap-card border border-dashed border-cap-divider p-6 text-center hover:bg-cap-card-2">
                <img v-if="logoPreview" :src="logoPreview" alt="Vista previa del logo" class="h-20 max-w-full object-contain" />
                <i v-else class="mdi mdi-image-plus-outline text-4xl leading-none text-cap-text-soft" aria-hidden="true" />
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ logo.logo ? logo.logo.name : 'Elegir imagen' }}</span>
                <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="pickLogo" />
            </label>
            <p v-if="logo.errors.logo" class="text-sm text-cap-status-error-ink">{{ logo.errors.logo }}</p>
        </div>

        <div v-else-if="step === 2" class="flex flex-col gap-4">
            <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Cómo agregar al administrador">
                <AppButton :variant="adminMode === 'existing' ? 'primary' : 'secondary'" icon="account-search-outline" role="radio" :aria-checked="adminMode === 'existing'" @click="adminMode = 'existing'">Usuario existente</AppButton>
                <AppButton :variant="adminMode === 'invite' ? 'primary' : 'secondary'" icon="email-plus-outline" role="radio" :aria-checked="adminMode === 'invite'" @click="adminMode = 'invite'">Invitar a alguien nuevo</AppButton>
            </div>
            <FormField v-if="adminMode === 'existing'" label="Usuario" optional :error="member.errors.user_id" help="Será administrador de la empresa nueva.">
                <SearchSelect v-model="member.user_id" :url="`/empresas/${companyId}/usuarios-disponibles`" option-value="id" option-label="name" option-description="description" />
            </FormField>
            <template v-else>
                <FormField label="Nombre" required :error="invite.errors.name"><TextInput v-model="invite.name" maxlength="255" /></FormField>
                <FormField label="Correo" required :error="invite.errors.email"><TextInput v-model="invite.email" type="email" maxlength="255" /></FormField>
                <p class="text-xs text-cap-text-soft">Entrarás en modo soporte en la empresa nueva y se abrirá Usuarios, donde verás la contraseña temporal para entregársela.</p>
            </template>
        </div>

        <div v-else class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="URL de Blessing" optional :error="settings.errors.blessing_base_url" class="md:col-span-2"><TextInput v-model="settings.blessing_base_url" type="url" placeholder="https://api.blessing.hn" /></FormField>
            <FormField label="Token de Blessing" optional :error="settings.errors.blessing_token"><TextInput v-model="settings.blessing_token" type="password" autocomplete="off" /></FormField>
            <FormField label="Lista de precios" optional :error="settings.errors.blessing_price_list"><TextInput v-model="settings.blessing_price_list" maxlength="100" /></FormField>
            <FormField label="Tasa de ISV (%)" :error="settings.errors.tax_rate"><TextInput v-model="settings.tax_rate" type="number" min="0" max="100" step="0.01" /></FormField>
            <div class="flex items-end pb-2"><Checkbox v-model="settings.prices_include_tax" label="Los precios incluyen ISV" /></div>
        </div>

        <template #footer>
            <template v-if="done">
                <AppButton variant="primary" icon="domain" @click="finish">Ver la empresa</AppButton>
            </template>
            <template v-else>
                <AppButton v-if="companyId === null" :disabled="busy" @click="close">Cancelar</AppButton>
                <AppButton v-else :disabled="busy" @click="finish">Terminar después</AppButton>
                <AppButton variant="primary" type="submit" :loading="busy" :icon="step === 0 ? 'plus' : 'arrow-right'">{{ primaryLabel }}</AppButton>
            </template>
        </template>
    </BaseModal>
</template>
