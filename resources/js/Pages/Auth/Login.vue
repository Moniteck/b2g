<script setup>
// Pantalla de acceso con `CapLogin`. Es la única sin marco de navegación y nunca usa toasts.
// Props del servidor (opcionales): helpEmail (correo de la mesa de ayuda), photoUrl (foto del panel
// izquierdo), canResetPassword + recoverUrl (si existe la recuperación por correo).
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CapLogin from '../../../design/vue/CapLogin.vue';
import { SYSTEM_NAME } from '../../config/navigation.js';

const props = defineProps({
    helpEmail: { type: String, default: '' },
    photoUrl: { type: String, default: '' },
    canResetPassword: { type: Boolean, default: false },
    recoverUrl: { type: String, default: '/forgot-password' },
});

const form = useForm({ email: '', password: '', remember: false });
const recovery = useForm({ email: '' });
const mode = ref('login');
const notice = ref(null);

const alert = computed(() => {
    const credentialError = form.errors.email || form.errors.password;
    if (mode.value === 'login' && credentialError) return { status: 'error', title: 'No pudimos ingresar', detail: credentialError };
    if (mode.value === 'recover' && recovery.errors.email) return { status: 'error', title: 'No se envió el enlace', detail: recovery.errors.email };
    return notice.value;
});

function submit(credentials) {
    notice.value = null;
    form.transform(() => credentials).post('/login', { onFinish: () => form.reset('password') });
}

function forgot() {
    form.clearErrors();
    if (props.canResetPassword) {
        notice.value = null;
        mode.value = 'recover';
        return;
    }
    notice.value = { status: 'info', title: 'Pide una contraseña nueva a tu administrador', detail: 'El administrador de tu empresa puede restablecerla desde Usuarios.' };
}

function recover(email) {
    notice.value = null;
    recovery.transform(() => ({ email })).post(props.recoverUrl, {
        onSuccess: () => {
            notice.value = { status: 'ok', title: 'Te enviamos el enlace', detail: 'Revisa tu correo y sigue las instrucciones para crear una contraseña nueva.' };
        },
    });
}

function back() {
    notice.value = null;
    recovery.clearErrors();
    mode.value = 'login';
}
</script>

<template>
    <Head title="Ingresar" />
    <div :class="{ 'login-without-help': !helpEmail && mode === 'login' }">
        <CapLogin
            :system-name="SYSTEM_NAME"
            tagline="Licitaciones, cotizaciones y contratos con el Estado, de la oportunidad al cobro."
            :photo-url="photoUrl"
            :help-email="helpEmail"
            :mode="mode"
            :alert="alert"
            :loading="form.processing || recovery.processing"
            @submit="submit"
            @forgot="forgot"
            @recover="recover"
            @back="back"
        />
    </div>
</template>

<style scoped>
/* Sin correo de mesa de ayuda configurado no se muestra la línea «¿Problemas para entrar?». */
.login-without-help :deep(.cap-login__help) {
    display: none;
}
</style>
