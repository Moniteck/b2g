<script setup>
// Campo de la pantalla de acceso con etiqueta flotante: la etiqueta vive dentro del campo y sube al
// enfocar o al escribir; el foco enciende un halo y una línea con el degradado de la marca.
// El control va en el slot por defecto (debe llevar class="auth-field__input", el `id` y un
// `placeholder`, aunque sea " ", para que la etiqueta sepa si hay texto) y los botones al final en
// el slot `trailing`. `floated` fija la etiqueta arriba (selectores).
//
//   <AuthField id="login-email" label="Correo" :error="form.errors.email">
//       <input id="login-email" v-model="form.email" class="auth-field__input" placeholder="nombre@empresa.com" />
//   </AuthField>
defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    error: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
    optional: { type: Boolean, default: false },
    floated: { type: Boolean, default: false },
});
</script>

<template>
    <div class="auth-field" :class="{ 'auth-field--error': error || invalid, 'auth-field--floated': floated }">
        <div class="auth-field__control">
            <slot />
            <label class="auth-field__label" :for="id">{{ label }}</label>
            <span v-if="optional" class="auth-field__hint">opcional</span>
            <slot name="trailing" />
            <span class="auth-field__line" aria-hidden="true" />
        </div>
        <Transition name="auth-pop">
            <p v-if="error" :id="`${id}-error`" class="auth-field-error"><i class="mdi mdi-alert-circle-outline" aria-hidden="true" /> {{ error }}</p>
        </Transition>
    </div>
</template>
