<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

const props = defineProps({
    opportunities: { type: Object, default: () => ({ data: [] }) },
    institutions: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    stages: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const stageColors = {
    prospecto: '#64748b',
    oportunidad: '#0ea5e9',
    oferta_presentada: '#f59e0b',
    adjudicado: '#10b981',
    no_adjudicado: '#ef4444',
};

const items = computed(() => props.opportunities.data ?? []);
const stageList = computed(() => Object.entries(props.stages).map(([id, label]) => ({ id, label, color: stageColors[id] ?? '#64748b' })));

const search = ref(props.filters.search ?? '');
const filter = ref(props.filters.stage ?? 'Todos');
const modal = ref(false);
const resultModal = ref(false);
const selected = ref(null);
const toast = ref('');
const error = ref('');

const form = ref({ titulo: '', institutionId: null, monto: 0, probabilidad: 25, etapa: 'prospecto', cierre: '', siguienteAccion: '' });
const result = ref({ etapa: 'adjudicado', winner_name: '', awarded_amount: 0, lost_reason: '' });

const visible = computed(() => items.value);
const total = computed(() => items.value.reduce((value, item) => value + item.monto * item.probabilidad / 100, 0));
const money = (value) => `L ${new Intl.NumberFormat('es-HN').format(Number(value || 0))}`;

function notice(message) {
    toast.value = message;
    setTimeout(() => toast.value === message && (toast.value = ''), 2500);
}

function openNew() {
    form.value = { titulo: '', institutionId: props.institutions[0]?.id ?? null, monto: 0, probabilidad: 25, etapa: 'prospecto', cierre: '', siguienteAccion: '' };
    modal.value = true;
}

function save() {
    if (!form.value.titulo || !form.value.institutionId) {
        error.value = 'Completa la oportunidad y la institución.';
        return;
    }
    const payload = {
        title: form.value.titulo,
        institution_id: form.value.institutionId,
        amount: form.value.monto,
        probability: form.value.probabilidad,
        stage: form.value.etapa,
        expected_close_date: form.value.cierre || null,
        next_action: form.value.siguienteAccion || null,
    };
    router[selected.value ? 'put' : 'post'](selected.value ? `/pipeline/${selected.value.id}` : '/pipeline', payload, {
        preserveScroll: true,
        onSuccess: () => {
            modal.value = false;
            selected.value = null;
            error.value = '';
            notice('Oportunidad guardada correctamente.');
        },
        onError: () => { error.value = 'Revise los datos de la oportunidad.'; },
    });
}

function edit(item) {
    selected.value = item;
    form.value = {
        titulo: item.titulo,
        institutionId: item.institutionId,
        monto: item.monto,
        probabilidad: item.probabilidad,
        etapa: item.etapa,
        cierre: item.cierre ?? '',
        siguienteAccion: item.siguienteAccion ?? '',
    };
    modal.value = true;
}

function applyFilters() {
    router.get('/pipeline', { search: search.value || undefined, stage: filter.value === 'Todos' ? undefined : filter.value }, { preserveState: true, preserveScroll: true });
}

function changeStage(item, stage) {
    if (stage === 'adjudicado' || stage === 'no_adjudicado') {
        openResult(item, stage);
        return;
    }
    router.patch(`/pipeline/${item.id}/etapa`, { stage }, { preserveScroll: true, onSuccess: () => notice('Etapa actualizada.') });
}

function openResult(item, stage) {
    selected.value = item;
    result.value = {
        etapa: stage,
        winner_name: item.ganador ?? '',
        awarded_amount: item.montoAdjudicado ?? item.monto ?? 0,
        lost_reason: '',
    };
    error.value = '';
    resultModal.value = true;
}

function submitResult() {
    if (result.value.etapa === 'no_adjudicado' && !result.value.winner_name.trim()) {
        error.value = 'Indique quién ganó la oferta.';
        return;
    }
    router.patch(`/pipeline/${selected.value.id}/etapa`, result.value, {
        preserveScroll: true,
        onSuccess: () => {
            resultModal.value = false;
            selected.value = null;
            notice(result.value.etapa === 'adjudicado' ? 'Oportunidad adjudicada.' : 'Oportunidad marcada como no adjudicada.');
        },
        onError: () => { error.value = 'No fue posible registrar el resultado.'; },
    });
}

function openParticipants(item) {
    router.visit(`/cotizaciones?search=${encodeURIComponent(item.cotizacionNumero ?? '')}`);
}
</script>

<template>
    <Head title="Pipeline de Oportunidades" />
    <AppLayout>
        <div class="module-page">
            <div class="module-header">
                <div>
                    <h2>🎯 Pipeline de Oportunidades</h2>
                    <p>{{ items.length }} oportunidades · Pipeline ponderado {{ money(total) }}</p>
                </div>
                <button class="module-primary" @click="openNew">+ Nueva Oportunidad</button>
            </div>

            <div class="module-toolbar">
                <label>🔍<input v-model="search" @change="applyFilters" placeholder="Buscar oportunidad o institución..."></label>
                <select v-model="filter" @change="applyFilters">
                    <option value="Todos">Todas las etapas</option>
                    <option v-for="stage in stageList" :key="stage.id" :value="stage.id">{{ stage.label }}</option>
                </select>
            </div>

            <div class="kanban">
                <section v-for="stage in stageList" :key="stage.id" class="kanban-column">
                    <header :style="{ borderColor: stage.color }">
                        <span>{{ stage.label }}</span>
                        <b>{{ visible.filter((item) => item.etapa === stage.id).length }}</b>
                    </header>
                    <article v-for="item in visible.filter((entry) => entry.etapa === stage.id)" :key="item.id" class="kanban-card" @click="edit(item)">
                        <strong>{{ item.titulo }}</strong>
                        <span>🏛️ {{ item.institucion }}</span>
                        <div><b>{{ money(item.monto) }}</b><em>{{ item.probabilidad }}%</em></div>
                        <small>Cierre: {{ item.cierre || '—' }} · {{ item.responsable }}</small>
                        <small v-if="item.siguienteAccion">👉 {{ item.siguienteAccion }}</small>
                        <small v-if="item.ganador">🏆 Ganador: {{ item.ganador }}</small>
                        <button v-if="item.cotizacionId" class="kanban-link" @click.stop="openParticipants(item)">👥 Participantes</button>
                        <select :value="item.etapa" @click.stop @change="changeStage(item, $event.target.value)">
                            <option v-for="option in stageList" :key="option.id" :value="option.id">{{ option.label }}</option>
                        </select>
                    </article>
                    <p v-if="!visible.filter((item) => item.etapa === stage.id).length" class="kanban-empty">Sin oportunidades</p>
                </section>
            </div>
        </div>

        <div v-if="toast" class="quote-toast">{{ toast }}</div>

        <div v-if="modal" class="modal-overlay" @click.self="modal = false">
            <form class="quote-modal module-form" @submit.prevent="save">
                <header>
                    <div><h3>{{ selected ? 'Editar oportunidad' : 'Nueva oportunidad' }}</h3><p>Información comercial B2G</p></div>
                    <button type="button" class="modal-close" @click="modal = false">×</button>
                </header>
                <label>Oportunidad *<input v-model="form.titulo"></label>
                <div class="module-form-grid">
                    <label>Institución *<select v-model="form.institutionId"><option :value="null">Seleccionar</option><option v-for="item in institutions" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                    <label>Monto estimado<input v-model.number="form.monto" type="number" min="0" step=".01"></label>
                    <label>Probabilidad (%)<input v-model.number="form.probabilidad" type="number" min="0" max="100"></label>
                    <label>Cierre esperado<input v-model="form.cierre" type="date"></label>
                    <label>Etapa<select v-model="form.etapa"><option v-for="stage in stageList" :key="stage.id" :value="stage.id">{{ stage.label }}</option></select></label>
                    <label>Siguiente acción<input v-model="form.siguienteAccion"></label>
                </div>
                <p v-if="error" class="quote-error">{{ error }}</p>
                <footer class="quote-modal footer">
                    <button type="submit" class="quote-primary">Guardar</button>
                    <button type="button" class="quote-cancel" @click="modal = false">Cerrar</button>
                </footer>
            </form>
        </div>

        <div v-if="resultModal" class="modal-overlay" @click.self="resultModal = false">
            <form class="quote-modal module-form" @submit.prevent="submitResult">
                <header>
                    <div>
                        <h3>{{ result.etapa === 'adjudicado' ? 'Adjudicar oportunidad' : 'Registrar no adjudicado' }}</h3>
                        <p>{{ selected?.titulo }} · {{ selected?.institucion }}</p>
                    </div>
                    <button type="button" class="modal-close" @click="resultModal = false">×</button>
                </header>
                <div class="module-form-grid">
                    <label>Ganador<input v-model="result.winner_name" placeholder="Nombre del adjudicatario"></label>
                    <label>Monto adjudicado<input v-model.number="result.awarded_amount" type="number" min="0" step=".01"></label>
                </div>
                <label>Motivo / notas<textarea v-model="result.lost_reason" rows="3" style="width:100%;margin-top:5px;padding:9px;color:white;border:1px solid rgba(255,255,255,.12);border-radius:10px;background:rgba(255,255,255,.06)"></textarea></label>
                <p class="quote-muted" v-if="result.etapa === 'adjudicado'">Para crear el cliente y el expediente post-adjudicación use el botón <b>Ganada</b> en la cotización.</p>
                <p v-if="error" class="quote-error">{{ error }}</p>
                <footer class="quote-modal footer">
                    <button type="submit" class="quote-primary">Registrar</button>
                    <button type="button" class="quote-cancel" @click="resultModal = false">Cerrar</button>
                </footer>
            </form>
        </div>
    </AppLayout>
</template>
