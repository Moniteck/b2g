<script setup>
// Franja visible del «modo soporte»: la raíz opera una empresa del grupo de la que no es miembro.
// Siempre a la vista (arriba del contenido) con un botón para volver a su empresa.
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { endpoints } from '@/config/navigation.js';

defineProps({
    company: { type: Object, required: true },
});

const leaving = ref(false);

function leave() {
    if (leaving.value) return;
    leaving.value = true;
    router.post(endpoints.supportLeave, {}, { preserveScroll: true, onFinish: () => (leaving.value = false) });
}
</script>

<template>
    <div class="app-support-banner" role="status">
        <i class="mdi mdi-lifebuoy text-lg leading-none" aria-hidden="true" />
        <p class="min-w-0 flex-1 truncate">
            <strong>Modo soporte en {{ company.name }}</strong>
            <span class="max-md:hidden"> · No eres miembro de esta empresa; lo que hagas queda en la auditoría.</span>
        </p>
        <button type="button" class="app-support-banner__action" :aria-busy="leaving ? 'true' : undefined" @click="leave">Salir del modo soporte</button>
    </div>
</template>
