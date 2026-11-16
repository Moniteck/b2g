<script setup>
// Mi cuenta: nombre, teléfono y contraseña del usuario que inició sesión.
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TextInput from '../../Components/TextInput.vue';
import Toggle from '../../Components/Toggle.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import PwaAccountSection from '../../pwa/AccountSection.vue';
import { focusFirstError } from '../../lib/errors.js';
import { reducedMotion, setMotionPreference, systemReducedMotion } from '../../lib/motion/index.js';
import { card, textLink } from '../../lib/ui.js';

const props = defineProps({
    account: { type: Object, required: true },
    memberships: { type: Array, default: () => [] },
});

const profileBody = ref(null);
const passwordBody = ref(null);

const profile = useForm({ name: props.account.name ?? '', phone: props.account.phone ?? '' });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });

function saveProfile() {
    profile.put('/mi-cuenta', { preserveScroll: true, onSuccess: () => profile.defaults(), onError: () => focusFirstError(profileBody.value) });
}

function savePassword() {
    password.put('/mi-cuenta/contrasena', {
        preserveScroll: true,
        onSuccess: () => password.reset(),
        onError: () => {
            password.reset('password', 'password_confirmation');
            focusFirstError(passwordBody.value);
        },
    });
}
</script>

<template>
    <AppLayout title="Mi cuenta">
        <PageHeader :description="account.email" />

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <form ref="profileBody" :class="[card, 'flex flex-col gap-4 p-4 sm:p-6']" @submit.prevent="saveProfile">
                <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Datos personales</h2>
                <FormField label="Nombre" required :error="profile.errors.name"><TextInput v-model="profile.name" maxlength="255" autocomplete="name" /></FormField>
                <FormField label="Correo" help="Para cambiarlo, pídaselo a un administrador."><TextInput :model-value="account.email" readonly /></FormField>
                <FormField label="Teléfono" optional :error="profile.errors.phone"><TextInput v-model="profile.phone" type="tel" maxlength="50" autocomplete="tel" /></FormField>
                <div class="flex justify-end">
                    <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="profile.processing" :disabled="!profile.isDirty">Guardar datos</AppButton>
                </div>
            </form>

            <form ref="passwordBody" :class="[card, 'flex flex-col gap-4 p-4 sm:p-6']" @submit.prevent="savePassword">
                <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Contraseña</h2>
                <FormField label="Contraseña actual" required :error="password.errors.current_password"><TextInput v-model="password.current_password" type="password" autocomplete="current-password" /></FormField>
                <FormField label="Contraseña nueva" required :error="password.errors.password" help="Al menos 10 caracteres, con letras y números."><TextInput v-model="password.password" type="password" autocomplete="new-password" /></FormField>
                <FormField label="Repita la contraseña nueva" required :error="password.errors.password_confirmation"><TextInput v-model="password.password_confirmation" type="password" autocomplete="new-password" /></FormField>
                <div class="flex justify-end">
                    <AppButton type="submit" icon="lock-reset" :loading="password.processing" :disabled="!password.current_password || !password.password">Cambiar contraseña</AppButton>
                </div>
            </form>
        </div>

        <section :class="[card, 'flex flex-col gap-3 p-4 sm:p-6']">
            <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Mis empresas</h2>
            <p v-if="account.is_root" class="text-sm text-cap-text-soft">Usted es raíz del grupo: opera en todas las empresas como administrador.</p>
            <ul v-stagger v-if="memberships.length" class="flex flex-col divide-y divide-cap-divider">
                <li v-for="membership in memberships" :key="membership.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ membership.name }}</span>
                    <StatusBadge status="neutral" :label="membership.role" />
                </li>
            </ul>
            <p v-else-if="!account.is_root" class="text-sm text-cap-text-soft">No pertenece a ninguna empresa.</p>
            <p class="text-sm text-cap-text-soft">La firma y el sello se administran en <Link href="/firmas" :class="textLink">Mi firma y sello</Link>.</p>
        </section>

        <PwaAccountSection />

        <section :class="[card, 'flex flex-col gap-2 p-4 sm:p-6']" aria-labelledby="account-preferences">
            <h2 id="account-preferences" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Preferencias de este navegador</h2>
            <Toggle
                :model-value="!reducedMotion"
                :disabled="systemReducedMotion"
                label="Animaciones completas"
                :description="systemReducedMotion ? 'Su sistema pide menos movimiento: las animaciones quedan reducidas a fundidos cortos.' : 'Desactívelas para ver los cambios al instante, solo con fundidos cortos.'"
                @update:model-value="(on) => setMotionPreference(on ? 'full' : 'reduced')"
            />
        </section>
    </AppLayout>
</template>
