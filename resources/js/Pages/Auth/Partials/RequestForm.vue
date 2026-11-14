<script setup>
// Cara «Crear cuenta»: solicitud de acceso. La cuenta queda pendiente hasta que un administrador
// la aprueba; el servidor responde igual si el correo ya existe. Al enviarse muestra «¡Solicitud
// enviada!» con los próximos pasos (la celebración de Lici la lanza la página).
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { shakeErrors } from '../../../Modules/Auth/motion.js';
import AuthButton from './AuthButton.vue';
import AuthField from './AuthField.vue';

const OTHER = '__otra';

const props = defineProps({
    companies: { type: Array, default: () => [] },
});
const emit = defineEmits(['switch', 'busy', 'failed', 'sent', 'reveal', 'cheer']);

const form = useForm({
    name: '',
    email: '',
    phone: '',
    company: '',
    company_name: '',
    position: '',
    password: '',
    password_confirmation: '',
    website: '',
});
const revealed = ref(false);
const formEl = ref(null);
const sent = ref(null);

watch(() => form.processing, (value) => emit('busy', value));
watch(revealed, (value) => emit('reveal', value));

const otherCompany = computed(() => form.company === OTHER || (props.companies.length === 0 && form.company === ''));
const companyLabel = computed(() => props.companies.find((item) => item.value === form.company)?.label ?? form.company_name.trim());

// Fortaleza: largo mínimo de 10, letras y números; mayúsculas y símbolos la suben a «Excelente».
const strength = computed(() => {
    const value = form.password;
    if (!value) return { score: 0, label: 'Elige una contraseña segura', tone: 'neutral' };
    const long = value.length >= 10;
    const mixed = /[a-z]/i.test(value) && /\d/.test(value);
    const cases = /[a-z]/.test(value) && /[A-Z]/.test(value);
    const extra = /[^A-Za-z0-9]/.test(value) || value.length >= 14;
    const score = !long ? 1 : 1 + Number(mixed) + Number(mixed && (cases || extra)) + Number(mixed && cases && extra);
    return [null, { label: 'Débil', tone: 'error' }, { label: 'Aceptable', tone: 'warning' }, { label: 'Buena', tone: 'ok' }, { label: 'Excelente', tone: 'ok' }].map((item, index) => item && { ...item, score: index })[score];
});
const rules = computed(() => [
    { ok: form.password.length >= 10, text: 'Al menos 10 caracteres' },
    { ok: /[a-z]/i.test(form.password) && /\d/.test(form.password), text: 'Letras y números' },
    { ok: /[A-Z]/.test(form.password) && /[^A-Za-z0-9]/.test(form.password), text: 'Mayúscula y símbolo (opcional)' },
]);
const confirmState = computed(() => {
    if (!form.password_confirmation) return null;
    if (form.password_confirmation === form.password) return 'match';
    return form.password_confirmation.length >= form.password.length ? 'mismatch' : null;
});

const errorOf = (field) => form.errors[field];
const describedBy = (field, extra = '', target = field.replace('_', '-')) => [form.errors[field] ? `request-${target}-error` : '', extra].filter(Boolean).join(' ') || undefined;

// Lici se alegra cuando la contraseña llega a «Excelente» o la confirmación coincide.
watch(() => strength.value.score === 4, (now, before) => now && !before && emit('cheer'));
watch(confirmState, (now, before) => now === 'match' && before !== 'match' && emit('cheer'));

function submit() {
    form
        .transform((data) => ({
            ...data,
            company: data.company && data.company !== OTHER ? data.company : null,
            company_name: data.company && data.company !== OTHER ? null : data.company_name,
        }))
        .post('/solicitar-acceso', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                sent.value = { email: form.email.trim(), company: companyLabel.value };
                emit('sent', sent.value);
                form.reset();
                revealed.value = false;
                nextTick(() => formEl.value?.querySelector('[data-face-title]')?.focus());
            },
            onError: async () => {
                emit('failed', 'error');
                await nextTick();
                shakeErrors(formEl.value);
                formEl.value?.querySelector('.auth-field--error input, .auth-field--error select')?.focus({ preventScroll: false });
            },
        });
}

function another() {
    sent.value = null;
    nextTick(() => formEl.value?.querySelector('[data-face-title]')?.focus());
}
</script>

<template>
    <div ref="formEl" class="auth-form auth-form--request">
        <Transition name="auth-swap" mode="out-in">
            <div v-if="sent" key="sent" class="auth-sent" role="status">
                <span class="auth-sent__badge" aria-hidden="true"><i class="mdi mdi-check" /></span>
                <h2 class="auth-form__title" tabindex="-1" data-face-title>¡Solicitud enviada!</h2>
                <p class="auth-form__subtitle">Gracias. Esto es lo que sigue:</p>
                <ol class="auth-steps">
                    <li class="auth-steps__item" style="--i: 0">
                        <span class="auth-steps__icon"><i class="mdi mdi-shield-check-outline" aria-hidden="true" /></span>
                        <span>Un administrador {{ sent.company ? `de ${sent.company}` : 'del sistema' }} revisa tu solicitud y te asigna un rol.</span>
                    </li>
                    <li class="auth-steps__item" style="--i: 1">
                        <span class="auth-steps__icon"><i class="mdi mdi-email-fast-outline" aria-hidden="true" /></span>
                        <span>Te avisamos a <strong>{{ sent.email }}</strong> cuando quede aprobada.</span>
                    </li>
                    <li class="auth-steps__item" style="--i: 2">
                        <span class="auth-steps__icon"><i class="mdi mdi-account-check-outline" aria-hidden="true" /></span>
                        <span>Entras con tu correo y la contraseña que acabas de elegir.</span>
                    </li>
                </ol>
                <AuthButton class="auth-submit" @click="emit('switch', sent.email)">Volver a ingresar <i class="mdi mdi-arrow-right auth-button__arrow" aria-hidden="true" /></AuthButton>
                <p class="auth-form__switch"><button class="auth-link" type="button" @click="another">Enviar otra solicitud</button></p>
            </div>

            <form v-else key="form" class="auth-stagger" novalidate @submit.prevent="submit">
                <header class="auth-form__header">
                    <button class="auth-back" type="button" @click="emit('switch')"><i class="mdi mdi-arrow-left" aria-hidden="true" /> Ya tengo cuenta</button>
                    <h2 class="auth-form__title" tabindex="-1" data-face-title>Solicita tu acceso</h2>
                    <p class="auth-form__subtitle">Un administrador de tu empresa la aprueba y te asigna un rol. Mientras tanto la cuenta queda pendiente.</p>
                </header>

                <!-- Trampa para robots: oculta para las personas y para los lectores de pantalla. -->
                <div class="auth-hp" aria-hidden="true">
                    <label for="request-website">Sitio web</label>
                    <input id="request-website" v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" />
                </div>

                <div class="auth-grid auth-stagger">
                    <AuthField id="request-name" label="Nombre completo" :error="errorOf('name')">
                        <input id="request-name" v-model="form.name" class="auth-field__input" type="text" name="name" autocomplete="name" maxlength="120" placeholder="Nombre y apellido" required :aria-invalid="errorOf('name') ? 'true' : undefined" :aria-describedby="describedBy('name')" />
                    </AuthField>

                    <AuthField id="request-position" label="Cargo" :error="errorOf('position')">
                        <input id="request-position" v-model="form.position" class="auth-field__input" type="text" name="position" autocomplete="organization-title" maxlength="120" placeholder="Analista de licitaciones" required :aria-invalid="errorOf('position') ? 'true' : undefined" :aria-describedby="describedBy('position')" />
                    </AuthField>

                    <AuthField id="request-email" label="Correo" :error="errorOf('email')">
                        <input id="request-email" v-model="form.email" class="auth-field__input" type="email" name="email" autocomplete="email" maxlength="160" placeholder="nombre@empresa.com" required :aria-invalid="errorOf('email') ? 'true' : undefined" :aria-describedby="describedBy('email')" />
                    </AuthField>

                    <AuthField id="request-phone" label="Teléfono" optional :error="errorOf('phone')">
                        <input id="request-phone" v-model="form.phone" class="auth-field__input" type="tel" name="phone" autocomplete="tel" maxlength="40" placeholder="+504 9999-9999" :aria-invalid="errorOf('phone') ? 'true' : undefined" :aria-describedby="describedBy('phone')" />
                    </AuthField>

                    <AuthField id="request-company" class="auth-grid__full" label="Empresa a la que perteneces" floated :error="otherCompany ? '' : errorOf('company_name')">
                        <select id="request-company" v-model="form.company" class="auth-field__input auth-field__select" name="company" :aria-describedby="otherCompany ? undefined : describedBy('company_name', '', 'company')">
                            <option value="" disabled>Elige tu empresa</option>
                            <option v-for="item in companies" :key="item.value" :value="item.value">{{ item.label }}</option>
                            <option :value="OTHER">Otra o no estoy seguro</option>
                        </select>
                        <template #trailing><i class="mdi mdi-chevron-down auth-field__chevron" aria-hidden="true" /></template>
                    </AuthField>

                    <Transition name="auth-pop">
                        <AuthField v-if="otherCompany" id="request-company-name" class="auth-grid__full" label="Nombre de tu empresa" :error="errorOf('company_name')">
                            <input id="request-company-name" v-model="form.company_name" class="auth-field__input" type="text" name="company_name" autocomplete="organization" maxlength="160" placeholder="Como aparece en tus documentos" :aria-invalid="errorOf('company_name') ? 'true' : undefined" :aria-describedby="describedBy('company_name')" />
                        </AuthField>
                    </Transition>

                    <AuthField id="request-password" label="Contraseña" :error="errorOf('password')">
                        <input
                            id="request-password"
                            v-model="form.password"
                            class="auth-field__input"
                            :type="revealed ? 'text' : 'password'"
                            name="password"
                            autocomplete="new-password"
                            placeholder=" "
                            required
                            data-secret
                            :aria-invalid="errorOf('password') ? 'true' : undefined"
                            :aria-describedby="describedBy('password', 'request-strength')"
                        />
                        <template #trailing>
                            <button
                                class="auth-field__icon-button"
                                type="button"
                                aria-controls="request-password request-password-confirmation"
                                :aria-pressed="String(revealed)"
                                :aria-label="revealed ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                @click="revealed = !revealed"
                            >
                                <Transition name="auth-icon" mode="out-in"><i :key="String(revealed)" class="mdi" :class="revealed ? 'mdi-eye-off-outline' : 'mdi-eye-outline'" aria-hidden="true" /></Transition>
                            </button>
                        </template>
                    </AuthField>

                    <AuthField id="request-password-confirmation" label="Confirma la contraseña" :invalid="confirmState === 'mismatch'">
                        <input
                            id="request-password-confirmation"
                            v-model="form.password_confirmation"
                            class="auth-field__input"
                            :type="revealed ? 'text' : 'password'"
                            name="password_confirmation"
                            autocomplete="new-password"
                            placeholder=" "
                            required
                            data-secret
                            aria-describedby="request-confirm-state"
                        />
                        <template #trailing>
                            <span class="auth-confirm" :class="confirmState && `auth-confirm--${confirmState}`" aria-hidden="true">
                                <i v-if="confirmState" :key="confirmState" class="mdi" :class="confirmState === 'match' ? 'mdi-check-circle' : 'mdi-close-circle-outline'" />
                            </span>
                        </template>
                    </AuthField>
                    <p id="request-confirm-state" class="auth-sr-only" aria-live="polite">{{ confirmState === 'match' ? 'Las contraseñas coinciden.' : confirmState === 'mismatch' ? 'Las contraseñas no coinciden.' : '' }}</p>

                    <div id="request-strength" class="auth-strength auth-grid__full" :class="`auth-strength--${strength.tone}`">
                        <div class="auth-strength__bar" role="meter" aria-label="Fortaleza de la contraseña" aria-valuemin="0" aria-valuemax="4" :aria-valuenow="strength.score" :aria-valuetext="strength.label">
                            <span class="auth-strength__fill" :style="{ width: `${strength.score * 25}%` }" />
                        </div>
                        <div class="auth-strength__row">
                            <span class="auth-strength__label">{{ strength.label }}</span>
                            <ul class="auth-strength__rules">
                                <li v-for="rule in rules" :key="rule.text" :class="{ 'is-ok': rule.ok }"><i class="mdi" :class="rule.ok ? 'mdi-check' : 'mdi-circle-small'" aria-hidden="true" />{{ rule.text }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <AuthButton class="auth-submit" type="submit" :loading="form.processing" loading-label="Enviando…">Enviar solicitud <i class="mdi mdi-send-outline auth-button__arrow" aria-hidden="true" /></AuthButton>
            </form>
        </Transition>
    </div>
</template>
