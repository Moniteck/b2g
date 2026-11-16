<script setup>
// Error de una petición de Inertia que no trae una página (419 sesión expirada, 500, sin conexión…):
// se muestra en una modal propia en español en lugar de la página de error dentro de un iframe.
import { computed } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';

const props = defineProps({
    status: { type: Number, default: 500 },
});

const emit = defineEmits(['close']);

const COPY = {
    0: { title: 'Sin conexión con el servidor', detail: 'Revisa tu conexión a internet e inténtalo de nuevo. No se guardó ningún cambio.', icon: 'wifi-off' },
    403: { title: 'No tienes permiso para esta acción', detail: 'Tu rol en la empresa activa no permite hacerlo. Si crees que es un error, pídeselo a un administrador.', icon: 'lock-outline' },
    404: { title: 'No encontramos lo que buscabas', detail: 'Puede que el registro se haya eliminado o pertenezca a otra empresa.', icon: 'file-question-outline' },
    419: { title: 'Tu sesión expiró', detail: 'Por seguridad, la sesión se cierra tras un tiempo sin actividad. Recarga la página para continuar; si estabas escribiendo, copia el texto antes.', icon: 'timer-sand-complete' },
    429: { title: 'Demasiadas solicitudes seguidas', detail: 'Espera un momento e inténtalo de nuevo.', icon: 'speedometer' },
    503: { title: 'El sistema está en mantenimiento', detail: 'Volvemos en unos minutos. Inténtalo de nuevo más tarde.', icon: 'tools' },
};

const copy = computed(() => COPY[props.status] ?? { title: 'Algo salió mal', detail: 'El servidor no pudo completar la solicitud. Inténtalo de nuevo; si sigue pasando, avisa al administrador.', icon: 'alert-circle-outline' });
const reloads = computed(() => [419, 0, 503].includes(props.status));

function reload() {
    window.location.reload();
}
</script>

<template>
    <BaseModal :open="true" size="sm" :title="copy.title" @update:open="(value) => !value && emit('close')">
        <div class="flex items-start gap-3">
            <i class="mdi m-error-icon text-3xl leading-none text-cap-brand dark:text-cap-brand-soft" :class="`mdi-${copy.icon}`" aria-hidden="true" />
            <p>{{ copy.detail }}</p>
        </div>
        <template #footer>
            <AppButton @click="emit('close')">Cerrar</AppButton>
            <AppButton v-if="reloads" variant="primary" icon="refresh" @click="reload">Recargar la página</AppButton>
        </template>
    </BaseModal>
</template>
