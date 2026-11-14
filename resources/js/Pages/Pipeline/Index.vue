<script setup>
// Pipeline de oportunidades (kanban, Fase 8): Prospecto | Oportunidad | Oferta Presentada | Adjudicado |
// No Adjudicado. Arrastrar (ratón o teclado) o «Mover a…» en el menú ⋮ de la tarjeta.
// - Oferta Presentada abre la modal de participantes (exige nuestra oferta con monto).
// - Adjudicado abre «Ganada» y No Adjudicado abre «Perdida» (AdjudicationService, módulo Cotizaciones).
// - Salir de un resultado solo con «Revertir resultado» (administrador).
// Si el servidor rechaza el movimiento o el usuario cancela la modal, la tarjeta vuelve a su columna.
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { formatCount } from '../../../design/shell/js/count-up.js';
import CapKanban from '../../../design/vue/CapKanban.vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import { useToasts } from '../../../design/vue/composables/useToasts.js';
import AppButton from '../../Components/AppButton.vue';
import DateInput from '../../Components/DateInput.vue';
import EmptyState from '../../Components/EmptyState.vue';
import ErrorState from '../../Components/ErrorState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import SelectInput from '../../Components/SelectInput.vue';
import StatCard from '../../Components/StatCard.vue';
import Toolbar from '../../Components/Toolbar.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { number } from '../../lib/format.js';
import { getJson, visit } from '../../lib/http.js';
import OpportunityCard from '../../Modules/Pipeline/OpportunityCard.vue';
import OpportunityDetailModal from '../../Modules/Pipeline/OpportunityDetailModal.vue';
import OpportunityFormModal from '../../Modules/Pipeline/OpportunityFormModal.vue';
import PresentOfferModal from '../../Modules/Pipeline/PresentOfferModal.vue';
import { CLOSED_STAGES, CREATABLE_STAGES, PARTICIPANT_STAGES, isClosed, moveOpportunity, transitionError } from '../../Modules/Pipeline/pipeline.js';
import LostModal from '../../Modules/Quotes/LostModal.vue';
import ParticipantsModal from '../../Modules/Quotes/ParticipantsModal.vue';
import RevertModal from '../../Modules/Quotes/RevertModal.vue';
import WonModal from '../../Modules/Quotes/WonModal.vue';
import { firstError } from '../../Modules/Quotes/api.js';
import { celebrate } from '../../lib/motion/index.js';

const props = defineProps({
    opportunities: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    stages: { type: Array, default: () => [] },
    transitions: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    filterOptions: { type: Object, default: () => ({}) },
    recentDays: { type: Number, default: 90 },
    focus: { type: Number, default: null },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const toasts = useToasts();
const confirm = useConfirm();

const cards = ref([...props.opportunities]);
watch(
    () => props.opportunities,
    (list) => (cards.value = [...list]),
);

const state = reactive({
    search: props.filters.search ?? '',
    owner_id: props.filters.owner_id ?? '',
    institution_id: props.filters.institution_id ?? null,
    close_from: props.filters.close_from ?? '',
    close_to: props.filters.close_to ?? '',
    cerradas: Boolean(props.filters.cerradas),
});
const loading = ref(false);
const loadError = ref('');

const modal = reactive({ form: false, detail: false, present: false, participants: false, won: false, lost: false, revert: false });
const selected = ref(null);
const detailId = ref(null);
const detailTab = ref('datos');
const editing = ref(null);
const createStage = ref('prospecto');
const detailModal = ref(null);

const query = computed(() => Object.fromEntries(Object.entries(state).filter(([key, value]) => value !== null && value !== '' && value !== false).map(([key, value]) => [key, key === 'cerradas' ? 1 : value])));
const exportQuery = computed(() => new URLSearchParams(Object.fromEntries(Object.entries(query.value).filter(([key]) => key !== 'cerradas'))).toString());
const filtered = computed(() => Boolean(state.search || state.owner_id || state.institution_id || state.close_from || state.close_to));
const moreFilters = computed(() => Boolean(state.owner_id || state.institution_id || state.close_from || state.close_to));
const totalCount = computed(() => Object.values(props.summary).reduce((sum, column) => sum + (column?.count ?? 0), 0));
const ownerOptions = computed(() => (props.filterOptions.owners ?? []).map((owner) => ({ value: owner.id, label: owner.name })));

const EMPTY_HINTS = {
    prospecto: 'Crea una oportunidad o impórtala desde una licitación.',
    oportunidad: 'Arrastra aquí un prospecto calificado.',
    oferta_presentada: 'Al soltar aquí se registran los participantes.',
    adjudicado: 'Suelta aquí una oferta ganada.',
    no_adjudicado: 'Suelta aquí una oferta perdida: se pide quién ganó.',
};

const columns = computed(() => props.stages.map((stage) => ({ id: stage.id, title: stage.title, tone: stage.tone, emptyTitle: 'Sin oportunidades', emptyHint: EMPTY_HINTS[stage.id] })));
const boardCards = computed(() => cards.value.map((card) => ({ ...card, id: String(card.id), column: card.stage })));

/** Cerradas antiguas que no se cargaron (se ven con «Ver más»). */
const hiddenClosed = computed(() =>
    Object.fromEntries(CLOSED_STAGES.map((stage) => [stage, Math.max(0, (props.summary[stage]?.count ?? 0) - cards.value.filter((card) => card.stage === stage).length)])),
);
const hiddenTotal = computed(() => hiddenClosed.value.adjudicado + hiddenClosed.value.no_adjudicado);

const subject = computed(() =>
    selected.value
        ? { type: 'opportunity', id: selected.value.id, label: selected.value.title, amount: selected.value.amount, outcome: selected.value.stage === 'adjudicado' ? 'Ganada' : 'Perdida' }
        : null,
);

// ───────────────────────────── Carga ─────────────────────────────

function reload() {
    loadError.value = '';
    router.get('/pipeline', query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['opportunities', 'summary', 'filters', 'filterOptions'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
        onError: () => (loadError.value = 'No se pudo cargar el tablero.'),
    });
}

watch(() => [state.search, state.owner_id, state.institution_id, state.close_from, state.close_to, state.cerradas], reload);

function clearFilters() {
    Object.assign(state, { search: '', owner_id: '', institution_id: null, close_from: '', close_to: '' });
}

function refresh(only = ['opportunities', 'summary']) {
    router.reload({ only, preserveScroll: true, onError: () => (loadError.value = 'No se pudo actualizar el tablero.') });
}

const stopListener = router.on('exception', () => (loadError.value = 'No se pudo cargar el tablero.'));
onBeforeUnmount(() => stopListener());

// ───────────────────────────── Movimientos ─────────────────────────────

const findCard = (id) => cards.value.find((card) => String(card.id) === String(id));

function replaceCard(next) {
    cards.value = cards.value.map((card) => (card.id === next.id ? next : card));
}

function onBoardMove(detail) {
    const card = findCard(detail.cardId);
    if (!card || detail.from === detail.to) return;
    requestMove(card, detail.to);
}

async function requestMove(card, to) {
    if (!props.can.update) {
        toasts.show({ status: 'warning', title: 'Solo lectura', detail: 'Tu rol permite ver el tablero, no mover oportunidades.' });
        return;
    }
    const error = transitionError(props.transitions, props.stages, card.stage, to);
    if (error) {
        toasts.show({ status: 'error', title: 'Movimiento no permitido', detail: error });
        return;
    }
    if (to === 'oferta_presentada') return open('present', card);
    if (isClosed(to)) {
        if (!props.can.adjudicate) {
            toasts.show({ status: 'warning', title: 'Sin permiso', detail: 'Tu rol no permite registrar el resultado.' });
            return;
        }
        return open(to === 'adjudicado' ? 'won' : 'lost', card);
    }

    const previous = card.stage;
    replaceCard({ ...card, stage: to });
    try {
        const response = await moveOpportunity(card.id, to);
        replaceCard(response.card);
        toasts.show({ status: 'ok', title: response.message });
        refresh(['summary']);
    } catch (failure) {
        replaceCard({ ...card, stage: previous });
        toasts.show({ status: 'error', title: 'No se movió la oportunidad', detail: firstError(failure, 'stage') });
    }
}

function onPresented(response) {
    replaceCard(response.card);
    toasts.show({ status: 'ok', title: response.message });
    refresh();
}

// Ganada: celebración con confeti de la marca (con animaciones reducidas solo queda el toast).
function onWon(result, message) {
    celebrate();
    onOutcome(result, message);
}

function onOutcome(result, message) {
    if (message) toasts.show({ status: 'ok', title: message });
    refresh();
    if (modal.detail) detailModal.value?.reload();
}

// ───────────────────────────── Acciones ─────────────────────────────

function open(name, card) {
    selected.value = card;
    modal[name] = true;
}

function setUrl(path) {
    const url = `${path}${window.location.search}`;
    window.history.replaceState({ ...window.history.state, url }, '', url);
}

function openDetail(card, tab = 'datos') {
    selected.value = card;
    detailId.value = card.id;
    detailTab.value = tab;
    modal.detail = true;
    setUrl(`/pipeline/${card.id}`);
}

watch(
    () => modal.detail,
    (isOpen) => {
        if (!isOpen && window.location.pathname !== '/pipeline') setUrl('/pipeline');
    },
);

function create(stage = 'prospecto') {
    editing.value = null;
    createStage.value = CREATABLE_STAGES.includes(stage) ? stage : 'prospecto';
    modal.form = true;
}

function editFromDetail(detail) {
    editing.value = { id: detail.data.id, form: detail.form, institution: detail.data.institution, tender: detail.data.tender };
    modal.form = true;
}

async function edit(card) {
    try {
        editFromDetail(await getJson(`/pipeline/${card.id}`));
    } catch (failure) {
        toasts.show({ status: 'error', title: 'No se pudo abrir la oportunidad', detail: failure.message });
    }
}

function remove(card) {
    confirm({
        verb: 'Eliminar',
        object: `la oportunidad «${card.title}»`,
        noun: 'oportunidad',
        message: 'Se quita del tablero con sus participantes registrados desde el pipeline. Sus cotizaciones se conservan. No se puede deshacer.',
        action: () =>
            visit('delete', `/pipeline/${card.id}`, {}, { preserveState: false }).then(() => {
                modal.detail = false;
            }),
    });
}

function revertFromDetail(detail) {
    open('revert', findCard(detail.data.id) ?? { id: detail.data.id, title: detail.data.title, amount: detail.data.amount, stage: detail.data.stage });
}

function menuOf(card) {
    const closed = isClosed(card.stage);
    const moves = props.can.update
        ? (props.transitions[card.stage] ?? []).map((to, index) => ({
              label: to === 'adjudicado' ? 'Registrar como Ganada' : to === 'no_adjudicado' ? 'Registrar como Perdida' : `Mover a ${props.stages.find((stage) => stage.id === to)?.title ?? to}`,
              icon: to === 'adjudicado' ? 'trophy-outline' : to === 'no_adjudicado' ? 'close-circle-outline' : 'arrow-right-bold-box-outline',
              separator: index === 0,
              onSelect: () => requestMove(card, to),
          }))
        : [];
    return [
        { label: 'Ver detalle', icon: 'eye-outline', onSelect: () => openDetail(card) },
        ...(PARTICIPANT_STAGES.includes(card.stage) || card.participantsCount ? [{ label: 'Participantes', icon: 'account-group-outline', onSelect: () => open('participants', card) }] : []),
        ...(card.stage === 'no_adjudicado' ? [{ label: 'Quién ganó', icon: 'trophy-variant-outline', onSelect: () => openDetail(card, 'ganador') }] : []),
        ...(props.can.update && !closed ? [{ label: 'Editar datos', icon: 'pencil-outline', onSelect: () => edit(card) }] : []),
        ...moves,
        ...(props.can.revert && closed ? [{ label: 'Revertir resultado', icon: 'undo-variant', separator: true, onSelect: () => open('revert', card) }] : []),
        ...(props.can.delete && !closed ? [{ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(card) }] : []),
    ];
}

function onColumnAdd(stage) {
    if (props.can.create && CREATABLE_STAGES.includes(stage)) create(stage);
}

onMounted(() => {
    if (props.focus) openDetail(findCard(props.focus) ?? { id: props.focus });
});

const activeUserId = computed(() => page.props.auth?.user?.id ?? null);
</script>

<template>
    <AppLayout title="Pipeline" :fab-label="can.create ? 'Nueva oportunidad' : ''" @fab="create()">
        <PageHeader description="Oportunidades de la empresa activa, de prospecto a resultado. Arrastra una tarjeta o usa «Mover a…» en su menú.">
            <template #actions>
                <AppButton external :href="`/pipeline/export/pdf?${exportQuery}`" icon="file-pdf-box">PDF</AppButton>
                <AppButton external :href="`/pipeline/export/xlsx?${exportQuery}`" icon="file-excel-outline">Excel</AppButton>
                <span v-if="can.create && totalCount > 0" class="hidden lg:contents">
                    <AppButton variant="primary" icon="plus" @click="create()">Nueva oportunidad</AppButton>
                </span>
            </template>
        </PageHeader>

        <div class="cap-stagger grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5" aria-label="Resumen por etapa">
            <!-- Sin «count»: la cifra animada no se actualiza al cambiar el resumen tras mover una tarjeta. -->
            <StatCard
                v-for="stage in stages"
                :key="stage.id"
                :variant="stage.id === 'adjudicado' ? 'featured' : 'normal'"
                :label="stage.title"
                :value="formatCount(summary[stage.id]?.amount ?? 0, 'currency-compact')"
                :hint="`${number(summary[stage.id]?.count ?? 0)} ${(summary[stage.id]?.count ?? 0) === 1 ? 'oportunidad' : 'oportunidades'}`"
                :class="stage.id === 'no_adjudicado' ? 'col-span-2 sm:col-span-1' : ''"
                :data-summary="stage.id"
            />
        </div>

        <Toolbar v-model:search="state.search" search-placeholder="Título, institución o expediente" search-label="Buscar oportunidades">
            <template #filters>
                <div class="grid w-full grid-cols-1 gap-2 sm:grid-cols-2 lg:flex lg:w-auto lg:flex-wrap">
                    <SelectInput v-model="state.owner_id" :options="ownerOptions" placeholder="Todos los responsables" aria-label="Filtrar por responsable" class="lg:w-52" />
                    <SearchSelect v-model="state.institution_id" url="/instituciones/buscar" option-value="id" option-label="name" :selected="filterOptions.institution" placeholder="Institución…" aria-label="Filtrar por institución" class="lg:w-56" />
                    <DateInput v-model="state.close_from" aria-label="Cierre desde" title="Cierre desde" class="lg:w-40" :max="state.close_to || undefined" />
                    <DateInput v-model="state.close_to" aria-label="Cierre hasta" title="Cierre hasta" class="lg:w-40" :min="state.close_from || undefined" />
                    <AppButton v-if="moreFilters" icon="filter-remove-outline" @click="clearFilters">Limpiar filtros</AppButton>
                </div>
            </template>
        </Toolbar>

        <ErrorState v-if="loadError" title="No se pudo cargar el tablero" :description="loadError" @retry="reload" />

        <CapSkeleton v-else-if="loading" variant="kanban" />

        <EmptyState v-else-if="totalCount === 0 && !filtered" icon="view-column-outline" title="Aún no hay oportunidades" description="Crea la primera o impórtala al confirmar una licitación.">
            <template v-if="can.create" #action><AppButton variant="primary" icon="plus" @click="create()">Nueva oportunidad</AppButton></template>
        </EmptyState>

        <EmptyState v-else-if="totalCount === 0" variant="no-results" @clear="clearFilters" />

        <div v-else v-settle class="pipeline-board min-w-0" :data-readonly="can.create ? undefined : 'true'">
            <CapKanban :columns="columns" :cards="boardCards" label="Pipeline de oportunidades" picker-label="Etapa" @move="onBoardMove" @add="onColumnAdd">
                <template #card="{ card }">
                    <OpportunityCard :card="findCard(card.id) ?? card" :menu="menuOf(findCard(card.id) ?? card)" :show-participants="PARTICIPANT_STAGES.includes(card.column)" @open="openDetail(findCard(card.id))" @participants="open('participants', findCard(card.id))" />
                </template>
            </CapKanban>
            <p v-if="hiddenTotal > 0 || state.cerradas" class="mt-1 flex flex-wrap items-center gap-2 text-sm text-cap-text-soft">
                <template v-if="!state.cerradas">
                    {{ number(hiddenTotal) }} {{ hiddenTotal === 1 ? 'oportunidad cerrada' : 'oportunidades cerradas' }} hace más de {{ recentDays }} días no se muestran.
                    <AppButton icon="chevron-down" @click="state.cerradas = true">Ver más</AppButton>
                </template>
                <template v-else>
                    Se muestran todas las cerradas.
                    <AppButton icon="chevron-up" @click="state.cerradas = false">Ocultar las de más de {{ recentDays }} días</AppButton>
                </template>
            </p>
        </div>

        <OpportunityFormModal v-model:open="modal.form" :opportunity="editing" :stage="createStage" :owners="filterOptions.owners ?? []" :tenders="filterOptions.tenders ?? []" :user-id="activeUserId" @saved="() => { refresh(); detailModal?.reload(); }" />
        <OpportunityDetailModal ref="detailModal" v-model:open="modal.detail" :opportunity-id="detailId" :tab="detailTab" :stages="stages" @edit="editFromDetail" @revert="revertFromDetail" @remove="(detail) => remove(detail.data)" @changed="refresh()" />
        <PresentOfferModal v-model:open="modal.present" :card="selected" @moved="onPresented" />
        <ParticipantsModal v-model:open="modal.participants" :subject="subject" :title="`Participantes · ${selected?.title ?? ''}`" @saved="refresh()" />
        <WonModal v-model:open="modal.won" :subject="subject" @done="onWon" />
        <LostModal v-model:open="modal.lost" :subject="subject" @done="onOutcome" />
        <RevertModal v-model:open="modal.revert" :subject="subject" @done="onOutcome" />
    </AppLayout>
</template>

<style scoped>
/* CapKanban no tiene props para ocultar sus acciones de cabecera: el «+» solo en Prospecto y
   Oportunidad para quien puede crear; las opciones de columna no se usan en el pipeline. */
.pipeline-board :deep(.cap-kanban__action[aria-label='Opciones de la columna']),
.pipeline-board :deep([data-cap-kanban-column='oferta_presentada'] .cap-kanban__action),
.pipeline-board :deep([data-cap-kanban-column='adjudicado'] .cap-kanban__action),
.pipeline-board :deep([data-cap-kanban-column='no_adjudicado'] .cap-kanban__action),
.pipeline-board[data-readonly='true'] :deep(.cap-kanban__action) {
    display: none;
}

.pipeline-board :deep(.cap-kanban__count) {
    margin-right: auto;
}

/* En móvil la columna visible ocupa el ancho disponible: sin esto, los textos truncados (título,
   expediente) fijan el ancho mínimo de la rejilla y la tarjeta se sale de la pantalla. */
@media (max-width: 1023.98px) {
    .pipeline-board :deep(.cap-kanban) {
        grid-auto-columns: minmax(0, 1fr);
    }

    .pipeline-board :deep(.cap-kanban__column),
    .pipeline-board :deep(.cap-kanban-card) {
        min-width: 0;
    }
}
</style>
