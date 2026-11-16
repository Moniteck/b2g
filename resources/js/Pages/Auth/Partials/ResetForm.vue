<script setup>
// Tarjeta «Nueva contraseña» (enlace del correo de recuperación): correo, contraseña nueva y su
// confirmación, con mostrar/ocultar. Al guardar, el servidor cierra las demás sesiones y vuelve
// a /login con un aviso. Lici se tapa los ojos con la contraseña como en «Ingresar».
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import { shakeErrors } from '@/Components/Mascot/motion.js';
import AuthButton from '@/Pages/Auth/Partials/AuthButton.vue';
import AuthField from '@/Pages/Auth/Partials/AuthField.vue';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});
const emit = defineEmits(['busy', 'failed', 'reveal', 'done']);

const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
const revealed = ref(false);
const formEl = ref(null);

watch(() => form.processing, (value) => emit('busy', value));
watch(revealed, (value) => emit('reveal', value));

const alert = computed(() => {
    const message = form.errors.email || form.errors.token;
    return message ? { status: 'error', title: 'No pudimos cambiar la contraseña', detail: message } : null;
});

function submit() {
    form.post('/restablecer-contrasena', {
        preserveScroll: true,
        onError: async () => {
            emit('failed', 'error');
            await nextTick();
            shakeErrors(formEl.value);
        },
        onSuccess: () => emit('done'),
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <form ref="formEl" class="auth-form auth-stagger" novalidate @submit.prevent="submit">
        <header class="auth-form__header">
            <h1 class="auth-form__title" tabindex="-1" data-face-title>Crea tu contraseña nueva</h1>
            <p class="auth-form__subtitle">Usa al menos 10 caracteres con letras y números. Al guardarla se cierran las sesiones abiertas en otros equipos.</p>
        </header>

        <Transition name="auth-pop">
            <div v-if="alert" id="reset-alert" :key="alert.detail" class="auth-form__alert"><CapAlert v-bind="alert" /></div>
        </Transition>

        <AuthField id="reset-email" label="Correo" :invalid="!!form.errors.email">
            <input id="reset-email" v-model="form.email" class="auth-field__input" type="email" name="email" autocomplete="username" placeholder="nombre@empresa.com" required :aria-invalid="form.errors.email ? 'true' : undefined" />
        </AuthField>

        <AuthField id="reset-password" label="Contraseña nueva" :error="form.errors.password" :invalid="!!form.errors.password">
            <input
                id="reset-password"
                v-model="form.password"
                class="auth-field__input"
                :type="revealed ? 'text' : 'password'"
                name="password"
                autocomplete="new-password"
                placeholder=" "
                required
                data-secret
                :aria-invalid="form.errors.password ? 'true' : undefined"
            />
            <template #trailing>
                <button
                    class="auth-field__icon-button"
                    type="button"
                    aria-controls="reset-password"
                    :aria-pressed="String(revealed)"
                    :aria-label="revealed ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    @click="revealed = !revealed"
                >
                    <Transition name="auth-icon" mode="out-in"><i :key="String(revealed)" class="mdi" :class="revealed ? 'mdi-eye-off-outline' : 'mdi-eye-outline'" aria-hidden="true" /></Transition>
                </button>
            </template>
        </AuthField>

        <AuthField id="reset-confirmation" label="Repite la contraseña">
            <input
                id="reset-confirmation"
                v-model="form.password_confirmation"
                class="auth-field__input"
                :type="revealed ? 'text' : 'password'"
                name="password_confirmation"
                autocomplete="new-password"
                placeholder=" "
                required
                data-secret
            />
        </AuthField>

        <AuthButton class="auth-submit" type="submit" :loading="form.processing" loading-label="Guardando…">Guardar contraseña <i class="mdi mdi-check auth-button__arrow" aria-hidden="true" /></AuthButton>

        <p class="auth-form__switch">
            ¿Recordaste tu contraseña?
            <button class="auth-link" type="button" @click="router.visit('/login')">Volver a ingresar <i class="mdi mdi-arrow-right" aria-hidden="true" /></button>
        </p>
    </form>
</template>
