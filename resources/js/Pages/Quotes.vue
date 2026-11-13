<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

const props = defineProps({
    quotes: { type: Object, default: () => ({ data: [], links: [] }) },
    companies: { type: Array, default: () => [] },
    institutions: { type: Array, default: () => [] },
    contacts: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => ['Borrador', 'Emitida', 'Presentada', 'Sustituida', 'Anulada'] },
    outcomes: { type: Array, default: () => ['Pendiente', 'Ganada', 'Perdida'] },
    filters: { type: Object, default: () => ({}) },
});

const allQuotes = computed(() => props.quotes.data ?? []);
const companyId = ref(props.companies[0]?.id ?? null);
const search = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? 'Todos');
const outcomeFilter = ref(props.filters.outcome ?? 'Todos');
const typeFilter = ref(props.filters.type ?? 'Todos');

const flash = ref('');
const error = ref('');
const selected = ref(null);
const formOpen = ref(false);
const pdfOpen = ref(false);
const outcomeOpen = ref(false);
const participantsOpen = ref(false);

const uploadFile = ref(null);
const uploadName = ref('');
const uploadKind = ref('proceso');
const uploadCategory = ref('');
const uploadExpiry = ref('');
const availableDocuments = ref([]);
const loadingDocuments = ref(false);

const outcomeForm = ref({ outcome: 'Ganada', awarded_amount: null, awarded_at: '', winner_name: '', lost_reason: '' });
const participants = ref([]);
const participantDraft = ref({ name: '', tax_id: '', amount: 0, currency: 'HNL', includes_tax: true, is_ours: false, position: 1, notes: '' });

const company = computed(() => props.companies.find((item) => item.id === companyId.value) ?? { name: 'Empresa', tax_id: '', representative: '' });

function money(amount) {
    return new Intl.NumberFormat('es-HN', { style: 'currency', currency: 'HNL', minimumFractionDigits: 2 }).format(Number(amount || 0)).replace('HNL', 'L');
}

function notify(message) {
    flash.value = message;
    window.setTimeout(() => { if (flash.value === message) flash.value = ''; }, 3500);
}

function outcomeBadge(resultado) {
    return ({ Ganada: '🏆 Ganada', Perdida: '❌ Perdida', Pendiente: '⏳ Pendiente' })[resultado] || resultado;
}

function statusBadge(estado) {
    return ({ Borrador: '📝 Borrador', Emitida: '📤 Emitida', Presentada: '📄 Presentada', Sustituida: '🔁 Sustituida', Anulada: '🚫 Anulada' })[estado] || estado;
}

function applyFilters() {
    router.get('/cotizaciones', {
        search: search.value || undefined,
        status: statusFilter.value === 'Todos' ? undefined : statusFilter.value,
        outcome: outcomeFilter.value === 'Todos' ? undefined : outcomeFilter.value,
        type: typeFilter.value === 'Todos' ? undefined : typeFilter.value,
    }, { preserveState: true, preserveScroll: true });
}

function changeCompany() {
    router.get('/cotizaciones', { empresa: companyId.value }, { preserveState: true, preserveScroll: true });
}

function updateStatus(quote, status) {
    router.patch(`/cotizaciones/${quote.id}/estado`, { status }, {
        preserveScroll: true,
        onSuccess: () => notify(`${quote.numero} pasó a ${status}.`),
    });
}

function openOutcome(quote, outcome) {
    selected.value = quote;
    outcomeForm.value = {
        outcome,
        awarded_amount: outcome === 'Ganada' ? quote.total : null,
        awarded_at: new Date().toISOString().slice(0, 10),
        winner_name: '',
        lost_reason: '',
    };
    outcomeOpen.value = true;
}

function submitOutcome() {
    error.value = '';
    if (outcomeForm.value.outcome === 'Perdida' && !outcomeForm.value.winner_name.trim()) {
        error.value = 'Indique quién ganó la oferta.';
        return;
    }
    router.patch(`/cotizaciones/${selected.value.id}/resultado`, outcomeForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            outcomeOpen.value = false;
            selected.value = null;
            notify(outcomeForm.value.outcome === 'Ganada' ? 'Oferta registrada como ganada. Cliente y expediente creados.' : 'Oferta registrada como no adjudicada.');
        },
        onError: () => { error.value = 'No fue posible registrar el resultado.'; },
    });
}

function openParticipants(quote) {
    selected.value = quote;
    participants.value = [];
    participantDraft.value = { name: '', tax_id: '', amount: 0, currency: 'HNL', includes_tax: true, is_ours: false, position: 1, notes: '' };
    participantsOpen.value = true;
}

function addParticipant() {
    if (!participantDraft.value.name.trim()) {
        error.value = 'Indique el nombre del participante.';
        return;
    }
    participants.value.push({ ...participantDraft.value, position: participants.value.length + 1 });
    participantDraft.value = { name: '', tax_id: '', amount: 0, currency: 'HNL', includes_tax: true, is_ours: false, position: participants.value.length + 1, notes: '' };
    error.value = '';
}

function removeParticipant(index) {
    participants.value.splice(index, 1);
    participants.value.forEach((item, position) => { item.position = position + 1; });
}

function submitParticipants() {
    if (!participants.value.length) {
        error.value = 'Registre al menos un participante.';
        return;
    }
    router.put(`/cotizaciones/${selected.value.id}/participantes`, { participants: participants.value }, {
        preserveScroll: true,
        onSuccess: () => {
            participantsOpen.value = false;
            notify('Participantes registrados.');
        },
        onError: () => { error.value = 'No fue posible registrar los participantes.'; },
    });
}

async function openPdf(quote) {
    selected.value = quote;
    pdfOpen.value = true;
    loadingDocuments.value = true;
    availableDocuments.value = [];
    try {
        const response = await fetch(`/cotizaciones/${quote.id}/documentos`, { headers: { Accept: 'application/json' } });
        if (response.ok) {
            const payload = await response.json();
            availableDocuments.value = payload.data ?? [];
        }
    } catch (exception) {
        availableDocuments.value = [];
    } finally {
        loadingDocuments.value = false;
    }
}

function pickFile(event) {
    uploadFile.value = event.target.files?.[0] ?? null;
    if (uploadFile.value && !uploadName.value) uploadName.value = uploadFile.value.name;
}

function openUpload(quote) {
    selected.value = quote;
    uploadFile.value = null;
    uploadName.value = '';
    uploadCategory.value = '';
    uploadExpiry.value = '';
    error.value = '';
    formOpen.value = true;
}

function uploadPdf() {
    if (!uploadFile.value) {
        error.value = 'Seleccione un archivo.';
        return;
    }
    const payload = new FormData();
    payload.append('name', uploadName.value || uploadFile.value.name);
    payload.append('kind', uploadKind.value);
    payload.append('category', uploadCategory.value);
    payload.append('expires_at', uploadExpiry.value || '');
    payload.append('cotizacion_id', selected.value?.id ?? '');
    payload.append('file', uploadFile.value);

    router.post('/documentos', payload, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadFile.value = null;
            uploadName.value = '';
            uploadCategory.value = '';
            uploadExpiry.value = '';
            formOpen.value = false;
            notify('PDF cargado y vinculado a la cotización.');
        },
        onError: () => { error.value = 'No fue posible cargar el archivo.'; },
    });
}

function downloadPdf(quote) {
    window.location.assign(`/cotizaciones/export/pdf?quote=${quote.id}`);
}
</script>

<template>
    <Head title="Cotizaciones" />
    <AppLayout>
        <div class="quotes-page">
            <div class="quotes-heading">
                <div>
                    <h2>📄 Cotizaciones</h2>
                    <p>{{ allQuotes.length }} cotizaciones en esta página</p>
                </div>
                <div class="quote-head-actions">
                    <label>Empresa emisora
                        <select v-model="companyId" @change="changeCompany">
                            <option v-for="item in companies" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                    </label>
                    <button class="quote-primary" @click="router.visit('/cotizaciones')">+ Nueva Cotización</button>
                </div>
            </div>

            <div class="company-banner">
                <span>🏢</span>
                <div><strong>{{ company.name }}</strong><small>RTN: {{ company.tax_id }} · {{ company.representative }}</small></div>
                <b>💰 Precios con impuesto incluido</b>
            </div>

            <div class="quote-filters">
                <label class="quote-search">🔍<input v-model="search" @change="applyFilters" placeholder="Buscar por número, institución o tipo..."></label>
                <select v-model="statusFilter" @change="applyFilters">
                    <option>Todos</option>
                    <option v-for="item in statuses" :key="item">{{ item }}</option>
                </select>
                <select v-model="outcomeFilter" @change="applyFilters">
                    <option>Todos</option>
                    <option v-for="item in outcomes" :key="item">{{ item }}</option>
                </select>
                <a class="export excel" href="/cotizaciones/export/excel">Excel</a>
            </div>

            <div class="quote-tip">
                <span>💡</span>
                <p><strong>Resultado comercial:</strong> use <b>Ganada</b> o <b>Perdida</b> en cada cotización. Al marcar <b>Ganada</b> el sistema crea el cliente y abre el expediente post-adjudicación automáticamente.</p>
            </div>

            <div class="quote-table-wrap">
                <table class="quote-table">
                    <thead>
                        <tr>
                            <th>Número</th><th>Institución</th><th>Fecha</th><th>Tipo</th>
                            <th>Total</th><th>Estado</th><th>Resultado</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!allQuotes.length"><td colspan="8" class="quote-empty">No hay cotizaciones para los filtros aplicados.</td></tr>
                        <tr v-for="quote in allQuotes" :key="quote.id">
                            <td class="quote-number">{{ quote.numero }}</td>
                            <td class="quote-institution">{{ quote.institucion }}</td>
                            <td>{{ quote.fecha }}</td>
                            <td>{{ quote.tipoCompra }}</td>
                            <td class="quote-total">{{ money(quote.total) }}</td>
                            <td><span class="status" :class="quote.estado.toLowerCase()">{{ statusBadge(quote.estado) }}</span></td>
                            <td><span class="status" :class="quote.resultado.toLowerCase()">{{ outcomeBadge(quote.resultado) }}</span></td>
                            <td>
                                <div class="quote-state-actions">
                                    <button @click="openPdf(quote)">👁 Ver</button>
                                    <button @click="openUpload(quote)">📎 PDF</button>
                                    <button @click="openParticipants(quote)">👥 Participantes</button>
                                    <button v-if="quote.resultado === 'Pendiente'" @click="openOutcome(quote, 'Ganada')">🏆 Ganada</button>
                                    <button v-if="quote.resultado === 'Pendiente'" @click="openOutcome(quote, 'Perdida')">❌ Perdida</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <nav v-if="quotes.links?.length" class="quote-pagination" aria-label="Paginación de cotizaciones">
            <button v-for="link in quotes.links" :key="link.label" :disabled="!link.url" @click="link.url && router.visit(link.url, { preserveScroll: true })" v-html="link.label" />
        </nav>

        <div v-if="flash" class="quote-toast">{{ flash }}</div>

        <!-- Modal: ver cotización y sus documentos -->
        <div v-if="selected && pdfOpen" class="modal-overlay" @click.self="pdfOpen = false; selected = null">
            <section class="quote-modal quote-detail quote-document-modal">
                <header>
                    <div>
                        <h3>{{ selected.numero }} · {{ selected.institucion }}</h3>
                        <p>{{ selected.tipoCompra }} · {{ selected.fecha }} · Total <b>{{ money(selected.total) }}</b></p>
                    </div>
                    <button class="modal-close" @click="pdfOpen = false; selected = null">×</button>
                </header>

                <div class="quote-document-viewer">
                    <div class="quote-document-list">
                        <h4>Documentos adjuntos ({{ availableDocuments.length }})</h4>
                        <p v-if="loadingDocuments" class="quote-muted">Cargando...</p>
                        <p v-else-if="!availableDocuments.length" class="quote-muted">Sin documentos adjuntos.</p>
                        <ul v-else>
                            <li v-for="document in availableDocuments" :key="document.id">
                                <a :href="document.url" target="_blank" rel="noopener">{{ document.nombre }}</a>
                                <small>v{{ document.version }}{{ document.firmado ? ' · firmado' : '' }}</small>
                            </li>
                        </ul>
                    </div>
                </div>

                <footer>
                    <button class="quote-primary" @click="downloadPdf(selected)">📄 Descargar PDF</button>
                    <button class="quote-secondary" @click="openUpload(selected)">📎 Agregar PDF</button>
                    <button class="quote-cancel" @click="pdfOpen = false; selected = null">Cerrar</button>
                </footer>
            </section>
        </div>

        <!-- Modal: cargar PDF -->
        <div v-if="formOpen" class="modal-overlay" @click.self="formOpen = false">
            <form class="quote-modal quote-form" @submit.prevent="uploadPdf">
                <header>
                    <div><h3>Agregar PDF</h3><p>El archivo se guarda en el directorio documental de la empresa</p></div>
                    <button type="button" class="modal-close" @click="formOpen = false">×</button>
                </header>
                <div class="quote-form-grid">
                    <label>Nombre del documento<input v-model="uploadName" required></label>
                    <label>Tipo de expediente
                        <select v-model="uploadKind">
                            <option value="proceso">Documento de proceso</option>
                            <option value="empresa">Documento de empresa</option>
                            <option value="institucion">Documento de institución</option>
                            <option value="contrato">Documento de contrato</option>
                        </select>
                    </label>
                    <label>Categoría
                        <select v-model="uploadCategory">
                            <option value="">Sin categoría</option>
                            <option>RTN</option><option>DNI</option><option>Solvencia</option>
                            <option>Escritura</option><option>Poder</option><option>Registro</option><option>Otro</option>
                        </select>
                    </label>
                    <label>Vencimiento<input v-model="uploadExpiry" type="date"></label>
                </div>
                <label class="quote-file">Archivo PDF<input type="file" accept=".pdf,.png,.jpg,.jpeg" @change="pickFile"></label>
                <p v-if="error" class="quote-error">{{ error }}</p>
                <footer>
                    <button type="submit" class="quote-primary">Cargar documento</button>
                    <button type="button" class="quote-cancel" @click="formOpen = false">Cerrar</button>
                </footer>
            </form>
        </div>

        <!-- Modal: resultado comercial -->
        <div v-if="outcomeOpen" class="modal-overlay" @click.self="outcomeOpen = false">
            <form class="quote-modal quote-form" @submit.prevent="submitOutcome">
                <header>
                    <div><h3>Resultado de la oferta</h3><p>{{ selected?.numero }} · {{ selected?.institucion }}</p></div>
                    <button type="button" class="modal-close" @click="outcomeOpen = false">×</button>
                </header>
                <div class="quote-form-grid">
                    <label>Resultado
                        <select v-model="outcomeForm.outcome">
                            <option value="Ganada">🏆 Ganada</option>
                            <option value="Perdida">❌ Perdida</option>
                        </select>
                    </label>
                    <label>Monto adjudicado<input v-model.number="outcomeForm.awarded_amount" type="number" min="0" step=".01"></label>
                    <label>Fecha de adjudicación<input v-model="outcomeForm.awarded_at" type="date"></label>
                    <label>Ganador<input v-model="outcomeForm.winner_name" placeholder="Nombre del adjudicatario"></label>
                </div>
                <label class="quote-motivo">Motivo / notas<textarea v-model="outcomeForm.lost_reason" rows="3"></textarea></label>
                <p class="quote-muted" v-if="outcomeForm.outcome === 'Ganada'">Al guardar se creará o reutilizará el cliente y se abrirá el expediente post-adjudicación.</p>
                <p v-if="error" class="quote-error">{{ error }}</p>
                <footer>
                    <button type="submit" class="quote-primary">Registrar resultado</button>
                    <button type="button" class="quote-cancel" @click="outcomeOpen = false">Cerrar</button>
                </footer>
            </form>
        </div>

        <!-- Modal: participantes de la oferta -->
        <div v-if="participantsOpen" class="modal-overlay" @click.self="participantsOpen = false">
            <section class="quote-modal quote-form">
                <header>
                    <div><h3>Participantes de la oferta</h3><p>{{ selected?.numero }} · montos presentados por cada oferente</p></div>
                    <button class="modal-close" @click="participantsOpen = false">×</button>
                </header>

                <table class="quote-table quote-participants">
                    <thead><tr><th>Participante</th><th>RTN</th><th>Monto</th><th>Incluye impuesto</th><th></th></tr></thead>
                    <tbody>
                        <tr v-if="!participants.length"><td colspan="5" class="quote-empty">Aún no hay participantes registrados.</td></tr>
                        <tr v-for="(participant, index) in participants" :key="index">
                            <td>{{ participant.name }}</td>
                            <td>{{ participant.tax_id || '—' }}</td>
                            <td>{{ money(participant.amount) }}</td>
                            <td>{{ participant.includes_tax ? 'Sí' : 'No' }}</td>
                            <td><button class="line-delete" @click="removeParticipant(index)">×</button></td>
                        </tr>
                    </tbody>
                </table>

                <div class="quote-form-grid quote-participant-form">
                    <label>Nombre<input v-model="participantDraft.name"></label>
                    <label>RTN<input v-model="participantDraft.tax_id"></label>
                    <label>Monto<input v-model.number="participantDraft.amount" type="number" min="0" step=".01"></label>
                    <label class="quote-checkbox">
                        <input v-model="participantDraft.includes_tax" type="checkbox"> Incluye impuesto
                    </label>
                    <label class="quote-checkbox">
                        <input v-model="participantDraft.is_ours" type="checkbox"> Nuestra oferta
                    </label>
                </div>

                <button class="quote-secondary" @click="addParticipant">+ Agregar participante</button>
                <p v-if="error" class="quote-error">{{ error }}</p>
                <footer>
                    <button class="quote-primary" @click="submitParticipants">Guardar participantes</button>
                    <button class="quote-cancel" @click="participantsOpen = false">Cerrar</button>
                </footer>
            </section>
        </div>
    </AppLayout>
</template>
