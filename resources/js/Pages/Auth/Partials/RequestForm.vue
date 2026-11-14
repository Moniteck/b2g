<script setup>
// Cara «Crear cuenta»: solicitud de acceso. La cuenta queda pendiente hasta que un administrador
// la aprueba; el servidor responde igual si el correo ya existe. Al enviarse muestra «¡Solicitud
// enviada!» con los próximos pasos (la celebración de Lici la lanza la página).
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { shakeErrors } from '../../../Modules/Auth/motion.js';

const OTHER = '__otra';

const props = defineProps({
    companies: { type: Array, default: () => [] },
});
const emit = defineEmits(['switch', 'busy', 'failed', 'sent', 'reveal']);

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
const describedBy = (field, extra = '') => [form.errors[field] ? `request-${field}-error` : '', extra].filter(Boolean).join(' ') || undefined;

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
                formEl.value?.querySelector('.cap-field--error input, .cap-field--error select')?.focus({ preventScroll: false });
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
                <button class="cap-button cap-button--primary auth-submit" type="button" @click="emit('switch', sent.email)">Volver a ingresar</button>
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
                    <div class="cap-field" :class="{ 'cap-field--error': errorOf('name') }">
                        <label class="cap-field__label" for="request-name">Nombre completo</label>
                        <div class="cap-field__control">
                            <input id="request-name" v-model="form.name" class="cap-field__input" type="text" name="name" autocomplete="name" maxlength="120" required :aria-invalid="errorOf('name') ? 'true' : undefined" :aria-describedby="describedBy('name')" />
                        </div>
                        <Transition name="auth-pop"><p v-if="errorOf('name')" id="request-name-error" class="auth-field-error">{{ errorOf('name') }}</p></Transition>
                    </div>

                    <div class="cap-field" :class="{ 'cap-field--error': errorOf('position') }">
                        <label class="cap-field__label" for="request-position">Cargo</label>
                        <div class="cap-field__control">
                            <input id="request-position" v-model="form.position" class="cap-field__input" type="text" name="position" autocomplete="organization-title" maxlength="120" placeholder="Analista de licitaciones" required :aria-invalid="errorOf('position') ? 'true' : undefined" :aria-describedby="describedBy('position')" />
                        </div>
                        <Transition name="auth-pop"><p v-if="errorOf('position')" id="request-position-error" class="auth-field-error">{{ errorOf('position') }}</p></Transition>
                    </div>

                    <div class="cap-field" :class="{ 'cap-field--error': errorOf('email') }">
                        <label class="cap-field__label" for="request-email">Correo</label>
                        <div class="cap-field__control">
                            <input id="request-email" v-model="form.email" class="cap-field__input" type="email" name="email" autocomplete="email" maxlength="160" placeholder="nombre@empresa.com" required :aria-invalid="errorOf('email') ? 'true' : undefined" :aria-describedby="describedBy('email')" />
                        </div>
                        <Transition name="auth-pop"><p v-if="errorOf('email')" id="request-email-error" class="auth-field-error">{{ errorOf('email') }}</p></Transition>
                    </div>

                    <div class="cap-field" :class="{ 'cap-field--error': errorOf('phone') }">
                        <label class="cap-field__label" for="request-phone">Teléfono <span class="auth-optional">(opcional)</span></label>
                        <div class="cap-field__control">
                            <input id="request-phone" v-model="form.phone" class="cap-field__input" type="tel" name="phone" autocomplete="tel" maxlength="40" placeholder="+504 9999-9999" :aria-invalid="errorOf('phone') ? 'true' : undefined" :aria-describedby="describedBy('phone')" />
                        </div>
                        <Transition name="auth-pop"><p v-if="errorOf('phone')" id="request-phone-error" class="auth-field-error">{{ errorOf('phone') }}</p></Transition>
                    </div>

                    <div class="cap-field auth-grid__full" :class="{ 'cap-field--error': errorOf('company') || (errorOf('company_name') && !otherCompany) }">
                        <label class="cap-field__label" for="request-company">Empresa a la que perteneces</label>
                        <div class="cap-field__control auth-select">
                            <select id="request-company" v-model="form.company" class="cap-field__input" name="company" :aria-describedby="describedBy(otherCompany ? 'company' : 'company_name')">
                                <option value="" disabled>Elige tu empresa</option>
                                <option v-for="item in companies" :key="item.value" :value="item.value">{{ item.label }}</option>
                                <option :value="OTHER">Otra o no estoy seguro</option>
                            </select>
                            <i class="mdi mdi-chevron-down auth-select__chevron" aria-hidden="true" />
                        </div>
                        <Transition name="auth-pop"><p v-if="!otherCompany && errorOf('company_name')" id="request-company_name-error" class="auth-field-error">{{ errorOf('company_name') }}</p></Transition>
                    </div>

                    <Transition name="auth-pop">
                        <div v-if="otherCompany" class="cap-field auth-grid__full" :class="{ 'cap-field--error': errorOf('company_name') }">
                            <label class="cap-field__label" for="request-company-name">Nombre de tu empresa</label>
                            <div class="cap-field__control">
                                <input id="request-company-name" v-model="form.company_name" class="cap-field__input" type="text" name="company_name" autocomplete="organization" maxlength="160" :aria-invalid="errorOf('company_name') ? 'true' : undefined" :aria-describedby="describedBy('company_name')" />
                            </div>
                            <Transition name="auth-pop"><p v-if="errorOf('company_name')" id="request-company_name-error" class="auth-field-error">{{ errorOf('company_name') }}</p></Transition>
                        </div>
                    </Transition>

                    <div class="cap-field" :class="{ 'cap-field--error': errorOf('password') }">
                        <label class="cap-field__label" for="request-password">Contraseña</label>
                        <div class="cap-field__control">
                            <input
                                id="request-password"
                                v-model="form.password"
                                class="cap-field__input"
                                :type="revealed ? 'text' : 'password'"
                                name="password"
                                autocomplete="new-password"
                                required
                                data-secret
                                :aria-invalid="errorOf('password') ? 'true' : undefined"
                                :aria-describedby="describedBy('password', 'request-strength')"
                            />
                            <button
                                class="cap-field__reveal"
                                type="button"
                                aria-controls="request-password request-password-confirmation"
                                :aria-pressed="String(revealed)"
                                :aria-label="revealed ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                @click="revealed = !revealed"
                            >
                                <i class="mdi" :class="revealed ? 'mdi-eye-off-outline' : 'mdi-eye-outline'" aria-hidden="true" />
                            </button>
                        </div>
                        <Transition name="auth-pop"><p v-if="errorOf('password')" id="request-password-error" class="auth-field-error">{{ errorOf('password') }}</p></Transition>
                    </div>

                    <div class="cap-field" :class="{ 'cap-field--error': confirmState === 'mismatch' }">
                        <label class="cap-field__label" for="request-password-confirmation">Confirma la contraseña</label>
                        <div class="cap-field__control">
                            <input
                                id="request-password-confirmation"
                                v-model="form.password_confirmation"
                                class="cap-field__input"
                                :type="revealed ? 'text' : 'password'"
                                name="password_confirmation"
                                autocomplete="new-password"
                                required
                                data-secret
                                aria-describedby="request-confirm-state"
                            />
                            <span class="auth-confirm" :class="confirmState && `auth-confirm--${confirmState}`" aria-hidden="true">
                                <i v-if="confirmState" class="mdi" :class="confirmState === 'match' ? 'mdi-check-circle' : 'mdi-close-circle-outline'" />
                            </span>
                        </div>
                        <p id="request-confirm-state" class="auth-sr-only" aria-live="polite">{{ confirmState === 'match' ? 'Las contraseñas coinciden.' : confirmState === 'mismatch' ? 'Las contraseñas no coinciden.' : '' }}</p>
                    </div>

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

                <button class="cap-button cap-button--primary auth-submit" type="submit" :disabled="form.processing" :aria-busy="String(form.processing)">
                    {{ form.processing ? 'Enviando…' : 'Enviar solicitud' }}
                </button>
            </form>
        </Transition>
    </div>
</template>
