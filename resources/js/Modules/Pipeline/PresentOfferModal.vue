<script setup>
// Pasar una oportunidad a Oferta Presentada: registra los participantes (al menos nuestra oferta con
// su monto, `ParticipantsEditor` del módulo de cotizaciones) y luego mueve la tarjeta. Si hay una
// cotización vinculada, el servidor la deja en Presentada.
// Props: open (v-model:open), card (tarjeta del tablero). Eventos: moved ({ message, card }).
// Cancelar o cerrar no mueve nada: la tarjeta sigue en su columna.
import { computed, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import ParticipantsEditor from '../Quotes/ParticipantsEditor.vue';
import { firstError } from '../Quotes/api.js';
import { moveOpportunity } from './pipeline.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    card: { type: Object, default: null },
});

const emit = defineEmits(['update:open', 'moved']);

const editor = ref(null);
const rows = ref([]);
const dirty = ref(false);
const canEdit = ref(false);
const sending = ref(false);
const error = ref('');

const subject = computed(() => (props.card ? { type: 'opportunity', id: props.card.id } : null));
const hasOurOffer = computed(() => rows.value.some((row) => row.is_ours && Number(row.amount) > 0));
const busy = computed(() => sending.value || Boolean(editor.value?.saving));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        rows.value = [];
        dirty.value = false;
        error.value = '';
    },
);

async function submit() {
    if (busy.value || !props.card) return;
    error.value = '';
    sending.value = true;
    try {
        if (dirty.value) {
            const saved = await editor.value?.save();
            if (!saved) return;
        }
        if (!hasOurOffer.value) {
            error.value = 'Agrega nuestra oferta con su monto (marca «Nuestra oferta» en una fila) y guarda antes de presentar.';
            return;
        }
        const response = await moveOpportunity(props.card.id, 'oferta_presentada');
        emit('moved', response);
        emit('update:open', false);
    } catch (failure) {
        error.value = firstError(failure, 'stage');
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <BaseModal
        :open="open"
        as="form"
        size="lg"
        :title="`Presentar oferta${card ? ' · ' + card.title : ''}`"
        description="Registra los participantes del proceso y sus montos. Se exige al menos nuestra oferta con su monto."
        :persistent="busy || dirty"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="flex flex-col gap-4">
            <CapAlert v-if="error" status="error" title="Aún no se puede presentar" :detail="error" />
            <ParticipantsEditor
                v-if="open && subject"
                ref="editor"
                :subject="subject"
                :show-actions="false"
                @dirty="dirty = $event"
                @loaded="(response) => { rows = response.data; canEdit = Boolean(response.can?.edit); }"
                @saved="(response) => (rows = response.data)"
            />
            <p v-if="card?.quote" class="text-sm text-cap-text-soft">La cotización {{ card.quote.number }} pasará a Presentada.</p>
        </div>
        <template #footer>
            <AppButton :disabled="busy" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton v-if="canEdit" variant="primary" type="submit" icon="send-check-outline" :loading="busy" :disabled="busy">{{ dirty ? 'Guardar y presentar' : 'Mover a Oferta Presentada' }}</AppButton>
        </template>
    </BaseModal>
</template>
