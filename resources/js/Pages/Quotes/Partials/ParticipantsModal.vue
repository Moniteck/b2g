<script setup>
// Modal de participantes sobre `ParticipantsEditor` (cotización u oportunidad).
// Props: open (v-model:open), subject ({ type, id }), title. Eventos: saved.
import { ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import ParticipantsEditor from '@/Pages/Quotes/Partials/ParticipantsEditor.vue';

defineProps({
    open: { type: Boolean, default: false },
    subject: { type: Object, default: null },
    title: { type: String, default: 'Participantes' },
});

const emit = defineEmits(['update:open', 'saved']);

const editor = ref(null);
const dirty = ref(false);
const canEdit = ref(false);

async function save() {
    if (await editor.value?.save()) emit('update:open', false);
}
</script>

<template>
    <BaseModal :open="open" as="form" size="lg" :title="title" description="Oferentes del proceso y sus montos. Se guarda solo lo que cambies." :persistent="dirty || editor?.saving" @update:open="emit('update:open', $event)" @submit="save">
        <ParticipantsEditor v-if="open && subject" ref="editor" :subject="subject" :show-actions="false" @dirty="dirty = $event" @loaded="canEdit = Boolean($event.can?.edit)" @saved="emit('saved', $event)" />
        <template #footer>
            <AppButton :disabled="editor?.saving" @click="emit('update:open', false)">{{ canEdit ? 'Cancelar' : 'Cerrar' }}</AppButton>
            <AppButton v-if="canEdit" variant="primary" type="submit" icon="content-save-outline" :loading="editor?.saving" :disabled="!dirty">Guardar participantes</AppButton>
        </template>
    </BaseModal>
</template>
