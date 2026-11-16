<script setup>
// Página del enlace «Confirma tu correo» de una solicitud de acceso. Según el resultado:
// verified (recién confirmado: Lici celebra), already (ya lo estaba), expired (el enlace venció)
// o invalid (no corresponde a ninguna solicitud vigente).
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthButton from './AuthButton.vue';

const props = defineProps({
    confirmation: { type: Object, required: true },
});

const ok = computed(() => ['verified', 'already'].includes(props.confirmation.result));
const greeting = computed(() => (props.confirmation.name ? `, ${props.confirmation.name}` : ''));
const title = computed(() => {
    if (props.confirmation.result === 'verified') return `¡Correo confirmado${greeting.value}!`;
    if (props.confirmation.result === 'already') return 'Tu correo ya estaba confirmado';
    if (props.confirmation.result === 'expired') return 'El enlace venció';
    return 'El enlace no es válido';
});
const detail = computed(() => {
    if (ok.value) return props.confirmation.approved ? 'Tu acceso ya fue aprobado: entra con tu correo y la contraseña que elegiste.' : 'Gracias. Tu solicitud ya está en manos de los administradores.';
    if (props.confirmation.result === 'expired') return 'Los enlaces de confirmación duran 24 horas. Envía la solicitud de nuevo y te llegará uno nuevo.';
    return 'Puede que la solicitud ya no exista o que el enlace esté incompleto. Envía la solicitud de nuevo.';
});
</script>

<template>
    <div class="auth-form">
        <div class="auth-sent" role="status">
            <span class="auth-sent__badge" aria-hidden="true"><i class="mdi" :class="ok ? 'mdi-email-check-outline' : 'mdi-email-alert-outline'" /></span>
            <h1 class="auth-form__title" tabindex="-1" data-face-title>{{ title }}</h1>
            <p class="auth-form__subtitle">{{ detail }}</p>

            <ol v-if="ok && !confirmation.approved" class="auth-steps">
                <li class="auth-steps__item" style="--i: 0">
                    <span class="auth-steps__icon"><i class="mdi mdi-shield-check-outline" aria-hidden="true" /></span>
                    <span>Un administrador revisa tu solicitud y te asigna empresa y rol.</span>
                </li>
                <li class="auth-steps__item" style="--i: 1">
                    <span class="auth-steps__icon"><i class="mdi mdi-email-fast-outline" aria-hidden="true" /></span>
                    <span>Te avisamos por correo cuando quede aprobada.</span>
                </li>
                <li class="auth-steps__item" style="--i: 2">
                    <span class="auth-steps__icon"><i class="mdi mdi-account-check-outline" aria-hidden="true" /></span>
                    <span>Entras con tu correo y la contraseña que elegiste.</span>
                </li>
            </ol>

            <AuthButton v-if="ok" class="auth-submit" @click="router.visit('/login')">Ir a ingresar <i class="mdi mdi-arrow-right auth-button__arrow" aria-hidden="true" /></AuthButton>
            <AuthButton v-else class="auth-submit" @click="router.visit('/solicitar-acceso')">Enviar la solicitud de nuevo <i class="mdi mdi-arrow-right auth-button__arrow" aria-hidden="true" /></AuthButton>
        </div>
    </div>
</template>
