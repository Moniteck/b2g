<script setup>
// Cara «Ingresar»: correo, contraseña (con mostrar/ocultar), mantener la sesión y acceso a la
// solicitud. Usa la ruta y la validación de siempre (`POST /login`, con su límite de intentos).
// La salida animada al entrar la maneja la página (interceptor de la respuesta de Inertia).
// «¿Olvidaste tu contraseña?» cambia la tarjeta a pedir el enlace de recuperación por correo
// (`POST /olvide-contrasena`); la respuesta es la misma exista o no la cuenta.
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import CapAlert from '../../../../design/vue/CapAlert.vue';
import { shakeErrors } from '../../../Modules/Auth/motion.js';
import AuthButton from './AuthButton.vue';
import AuthField from './AuthField.vue';

const props = defineProps({
    systemName: { type: String, required: true },
    subtitle: { type: String, default: '' },
    helpEmail: { type: String, default: '' },
    prefillEmail: { type: String, default: '' },
    status: { type: String, default: '' },
});
const emit = defineEmits(['switch', 'busy', 'failed', 'reveal', 'valid']);

const form = useForm({ email: props.prefillEmail, password: '', remember: false });
const view = ref('login'); // 'login' | 'forgot'
const forgotForm = useForm({ email: '' });
const revealed = ref(false);
const notice = ref(null);
const formEl = ref(null);

watch(() => props.prefillEmail, (value) => value && (form.email = value));
watch(() => form.processing || forgotForm.processing, (value) => emit('busy', value));
watch(() => props.status, (value) => value && (notice.value = { status: 'success', icon: 'email-fast-outline', title: 'Revisa tu correo', detail: value }), { immediate: true });
watch(revealed, (value) => emit('reveal', value));
watch(
    () => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(form.email),
    (valid, before) => valid && !before && emit('valid'),
);

const access = computed(() => form.errors.access ?? null);
const credentialError = computed(() => !access.value && (form.errors.email || form.errors.password));
const alert = computed(() => {
    if (access.value === 'pending') return { status: 'warning', icon: 'account-clock-outline', title: 'Tu solicitud está en revisión', detail: form.errors.email };
    if (access.value === 'rejected') return { status: 'error', title: 'Tu solicitud no fue aprobada', detail: form.errors.email };
    if (access.value === 'unverified') return { status: 'warning', icon: 'email-alert-outline', title: 'Confirma tu correo', detail: form.errors.email };
    if (credentialError.value) return { status: 'error', title: 'No pudimos ingresar', detail: credentialError.value };
    return notice.value;
});

function submit() {
    notice.value = null;
    form.post('/login', {
        preserveScroll: true,
        preserveState: true,
        onError: async () => {
            emit('failed', access.value ?? 'error');
            await nextTick();
            if (!access.value) shakeErrors(formEl.value);
        },
        onFinish: () => form.reset('password'),
    });
}

function forgot() {
    form.clearErrors();
    notice.value = null;
    forgotForm.email = form.email;
    forgotForm.clearErrors();
    view.value = 'forgot';
    nextTick(() => formEl.value?.querySelector('#forgot-email')?.focus());
}

function backToLogin() {
    view.value = 'login';
    nextTick(() => formEl.value?.querySelector('[data-face-title]')?.focus());
}

function sendLink() {
    forgotForm.post('/olvide-contrasena', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.email = forgotForm.email;
            view.value = 'login';
            emit('valid');
        },
        onError: async () => {
            emit('failed', 'error');
            await nextTick();
            shakeErrors(formEl.value);
        },
    });
}
</script>

<template>
    <form v-if="view === 'forgot'" ref="formEl" class="auth-form auth-stagger" novalidate @submit.prevent="sendLink">
        <header class="auth-form__header">
            <h1 class="auth-form__title" tabindex="-1" data-face-title>Recupera tu contraseña</h1>
            <p class="auth-form__subtitle">Escribe el correo de tu cuenta y te enviaremos un enlace para crear una contraseña nueva. Vence en 60 minutos.</p>
        </header>

        <Transition name="auth-pop">
            <div v-if="forgotForm.errors.email || forgotForm.errors.throttle" id="forgot-alert" class="auth-form__alert"><CapAlert status="error" title="No pudimos enviar el enlace" :detail="forgotForm.errors.email || forgotForm.errors.throttle" /></div>
        </Transition>

        <AuthField id="forgot-email" label="Correo" :invalid="!!forgotForm.errors.email">
            <input id="forgot-email" v-model="forgotForm.email" class="auth-field__input" type="email" name="email" autocomplete="username" placeholder="nombre@empresa.com" required :aria-invalid="forgotForm.errors.email ? 'true' : undefined" />
        </AuthField>

        <AuthButton class="auth-submit" type="submit" :loading="forgotForm.processing" loading-label="Enviando…">Enviar enlace <i class="mdi mdi-email-fast-outline auth-button__arrow" aria-hidden="true" /></AuthButton>

        <p class="auth-form__switch">
            <button class="auth-link" type="button" @click="backToLogin"><i class="mdi mdi-arrow-left" aria-hidden="true" /> Volver a ingresar</button>
        </p>
    </form>

    <form v-else ref="formEl" class="auth-form auth-stagger" novalidate @submit.prevent="submit">
        <header class="auth-form__header">
            <h1 class="auth-form__title" tabindex="-1" data-face-title>Ingresa a {{ systemName }}</h1>
            <p class="auth-form__subtitle">{{ subtitle }}</p>
        </header>

        <Transition name="auth-pop">
            <div v-if="alert" id="login-alert" :key="alert.title" class="auth-form__alert"><CapAlert v-bind="alert" /></div>
        </Transition>

        <AuthField id="login-email" label="Correo" :invalid="!!credentialError">
            <input
                id="login-email"
                v-model="form.email"
                class="auth-field__input"
                type="email"
                name="email"
                autocomplete="username"
                placeholder="nombre@empresa.com"
                required
                :aria-invalid="credentialError ? 'true' : undefined"
                :aria-describedby="alert ? 'login-alert' : undefined"
            />
        </AuthField>

        <AuthField id="login-password" label="Contraseña" :invalid="!!credentialError">
            <input
                id="login-password"
                v-model="form.password"
                class="auth-field__input"
                :type="revealed ? 'text' : 'password'"
                name="password"
                autocomplete="current-password"
                placeholder=" "
                required
                data-secret
                :aria-invalid="credentialError ? 'true' : undefined"
            />
            <template #trailing>
                <button
                    class="auth-field__icon-button"
                    type="button"
                    aria-controls="login-password"
                    :aria-pressed="String(revealed)"
                    :aria-label="revealed ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    @click="revealed = !revealed"
                >
                    <Transition name="auth-icon" mode="out-in"><i :key="String(revealed)" class="mdi" :class="revealed ? 'mdi-eye-off-outline' : 'mdi-eye-outline'" aria-hidden="true" /></Transition>
                </button>
            </template>
        </AuthField>

        <div class="auth-form__options">
            <label class="cap-check"><input v-model="form.remember" class="cap-check__box" type="checkbox" name="remember" />Mantener la sesión</label>
            <button class="auth-link" type="button" @click="forgot">¿Olvidaste tu contraseña?</button>
        </div>

        <AuthButton class="auth-submit" type="submit" :loading="form.processing" loading-label="Ingresando…">Ingresar <i class="mdi mdi-arrow-right auth-button__arrow" aria-hidden="true" /></AuthButton>

        <p class="auth-form__switch">
            ¿Aún no tienes cuenta?
            <button class="auth-link" type="button" @click="emit('switch')">Solicitar acceso <i class="mdi mdi-arrow-right" aria-hidden="true" /></button>
        </p>
        <p v-if="helpEmail" class="auth-form__help">¿Problemas para entrar? Escribe a <a class="auth-link" :href="`mailto:${helpEmail}`">{{ helpEmail }}</a></p>
    </form>
</template>
