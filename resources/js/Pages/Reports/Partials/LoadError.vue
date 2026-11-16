<script setup>
// Aviso en línea cuando falla la recarga de una sección, con «Reintentar».
// Props: title, message. Eventos: retry.
import CapAlert from '@design/vue/CapAlert.vue';
import AppButton from '@/Components/AppButton.vue';

defineProps({
    title: { type: String, default: 'No se pudo actualizar la pantalla' },
    message: { type: String, required: true },
});

defineEmits(['retry']);
</script>

<template>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <CapAlert class="flex-1" status="error" :title="title" :detail="message" />
        <AppButton icon="refresh" @click="$emit('retry')">Reintentar</AppButton>
    </div>
</template>
