<script setup>
import { nextTick, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

const quickQueries = [['💰', 'El Estado no me ha pagado'], ['⚖️', 'Quiero impugnar una adjudicación'], ['📋', '¿Qué documentos necesito?'], ['🏦', 'Garantías requeridas'], ['📅', 'Plazos después de adjudicación'], ['📝', 'Modificar un contrato vigente']];
const messages = ref([{ role: 'assistant', content: 'Bienvenido al Agente Legal B2G Honduras.\n\nSoy una herramienta de apoyo para analizar la Ley de Contratación del Estado (Decreto 74-2001), el Reglamento, el pliego y el expediente.\n\nPuedo ayudarte con matrices de cumplimiento, gestión documentada de cobros, garantías, plazos contractuales y borradores para revisión.' }]);
const input = ref('');
const chat = ref(null);
const view = ref('chat'); const documentType = ref(''); const generated = ref(''); const documentForm = ref({ empresa: '', institucion: '', detalles: '' });
const conversationId = ref(null); const loading = ref(false); const chatError = ref('');
const legalDocuments = ['Borrador de Impugnación', 'Recurso Administrativo', 'Gestión de Cobro Documentada', 'Solicitud de Aclaración', 'Carta de Adjudicación', 'Solicitud de Modificación de Plazo'];
async function reply(query) { if (loading.value) return; messages.value.push({ role: 'user', content: query }); loading.value = true; chatError.value = ''; input.value = ''; try { const response = await fetch('/agente-legal/chat', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content }, body: JSON.stringify({ conversation_id: conversationId.value, message: query }) }); const data = await response.json(); if (!response.ok) throw new Error(data.message || 'No fue posible procesar la consulta.'); conversationId.value = data.conversation_id; messages.value.push(data.message); } catch (error) { chatError.value = error.message; } finally { loading.value = false; nextTick(() => chat.value?.scrollTo({ top: chat.value.scrollHeight, behavior: 'smooth' })); } }
function send() { if (!input.value.trim()) return; reply(input.value.trim()); }
async function generateDocument() { if (!documentType.value || !documentForm.value.empresa || !documentForm.value.institucion || !documentForm.value.detalles) return; chatError.value = ''; try { const response = await fetch('/agente-legal/escritos', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content }, body: JSON.stringify({ type: documentType.value, recipient: documentForm.value.institucion, context: `Empresa: ${documentForm.value.empresa}\n${documentForm.value.detalles}` }) }); const data = await response.json(); if (!response.ok) throw new Error(data.message || 'No fue posible generar el escrito.'); generated.value = data.content; } catch (error) { chatError.value = error.message; } }
function copyGenerated() { navigator.clipboard?.writeText(generated.value); }
function printGenerated() { const popup = window.open('', '_blank'); if (!popup) return; popup.document.write(`<pre style="white-space:pre-wrap;font-family:Arial;padding:32px">${generated.value.replaceAll('&', '&amp;').replaceAll('<', '&lt;')}</pre>`); popup.document.close(); popup.print(); }
</script>

<template>
    <Head title="Agente Legal B2G" />
    <AppLayout>
        <div class="legal-page">
            <aside class="legal-panel">
                <div class="legal-panel-head">
                    <div class="legal-brand"><span class="legal-brand-icon">⚖️</span><div><strong>Agente Legal B2G</strong><span>Decreto 74-2001</span></div></div>
                    <div class="legal-tabs"><button :class="{ active: view === 'chat' }" @click="view = 'chat'">💬 Chat</button><button :class="{ active: view === 'documents' }" @click="view = 'documents'; generated = ''">📄 Escritos</button></div>
                </div>
                <section v-if="view === 'chat'" class="legal-section"><h3>CONSULTAS RÁPIDAS</h3><button v-for="[icon, label] in quickQueries" :key="label" class="legal-quick" @click="reply(label)"><span>{{ icon }}</span>{{ label }}</button></section>
                <section v-if="view === 'chat'" class="legal-section"><h3>ARTÍCULOS CLAVE</h3><button class="legal-article" @click="reply('Explícame el Art. 28 sobre intereses por mora.')">Art. 28 · Intereses por mora</button><button class="legal-article" @click="reply('Explícame las garantías de los Arts. 99 a 106.')">Arts. 99-106 · Garantías</button><button class="legal-article" @click="reply('Explícame la impugnación según Art. 142.')">Art. 142 · Impugnación</button></section>
                <section v-if="view === 'documents'" class="legal-section"><h3>TIPO DE ESCRITO</h3><button v-for="item in legalDocuments" :key="item" class="legal-quick" :class="{ selected: documentType === item }" @click="documentType = item">📄 {{ item }}</button></section>
            </aside>
            <section class="legal-main">
                <template v-if="view === 'chat'">
                    <div ref="chat" class="messages"><div v-for="(message, index) in messages" :key="index" class="message" :class="message.role"><span v-if="message.role === 'assistant'" class="message-avatar">⚖️</span><div class="bubble">{{ message.content }}</div></div><div v-if="loading" class="message"><span class="message-avatar">⚖️</span><div class="bubble">Consultando la normativa...</div></div><p v-if="chatError" class="quote-error">{{ chatError }}</p></div>
                    <form class="legal-input" @submit.prevent="send"><textarea v-model="input" placeholder="Escribe tu consulta legal... (Enter para enviar)" @keydown.enter.exact.prevent="send" /><button type="submit" :disabled="!input.trim()">➤</button></form>
                </template>
                <section v-else class="legal-document">
                    <div v-if="!documentType" class="legal-empty"><b>📄</b><h2>Generador de Escritos Legales</h2><p>Selecciona el tipo de escrito en el panel izquierdo para comenzar.</p></div>
                    <template v-else-if="!generated"><h2>{{ documentType }}</h2><p>Completa los datos para preparar un borrador sujeto a revisión.</p><label>Empresa proveedora *<input v-model="documentForm.empresa" placeholder="CAP Honduras S.A."></label><label>Institución destinataria *<input v-model="documentForm.institucion" placeholder="Secretaría de Salud"></label><label>Detalles del caso *<textarea v-model="documentForm.detalles" rows="7" placeholder="Número de proceso, fechas, hechos y solicitud..."></textarea></label><p v-if="chatError" class="quote-error">{{ chatError }}</p><button class="legal-generate" :disabled="!documentForm.empresa || !documentForm.institucion || !documentForm.detalles" @click="generateDocument">✍️ Generar borrador</button></template>
                    <template v-else><div class="legal-document-actions"><button class="legal-generate" @click="copyGenerated">📋 Copiar</button><button @click="printGenerated">Imprimir / PDF</button><button @click="generated = ''">✏️ Editar datos</button></div><pre>{{ generated }}</pre></template>
                </section>
            </section>
        </div>
    </AppLayout>
</template>
