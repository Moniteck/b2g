<script setup>
// Diagnóstico del servidor: una tarjeta por comprobación con su estado y qué hacer si falla.
// Datos: { checks: [{ key, label, status: ok|warning|error, detail, meta }], summary, generated_at }.
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import AppButton from '../../Components/AppButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { dateTime } from '../../lib/format.js';
import { card } from '../../lib/ui.js';

const props = defineProps({
    checks: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({ ok: 0, warning: 0, error: 0 }) },
    generated_at: { type: String, default: '' },
});

const STATUS_LABELS = { ok: 'Correcto', warning: 'Revisar', error: 'Falla' };
const ICONS = {
    tesseract: 'text-recognition',
    poppler: 'file-pdf-box',
    php_limits: 'upload-outline',
    queue: 'tray-full',
    scheduler: 'clock-outline',
    openai: 'creation-outline',
    mailer: 'email-outline',
    https: 'lock-outline',
};
const IMPACT = {
    tesseract: 'Lectura de capturas de HonduCompras.',
    poppler: 'Lectura de pliegos y firma de PDF.',
    php_limits: 'Subida de pliegos y documentos grandes.',
    queue: 'Análisis de pliegos y envío de correos en segundo plano.',
    scheduler: 'Recordatorios y resumen diario.',
    openai: 'Análisis de pliegos y agente legal.',
    mailer: 'Recordatorios y envío de cotizaciones por correo.',
    https: 'Conexión con OpenAI, Resend y Blessing.',
};

const refreshing = ref(false);
function refresh() {
    router.reload({ onStart: () => (refreshing.value = true), onFinish: () => (refreshing.value = false) });
}

const ordered = computed(() => {
    const weight = { error: 0, warning: 1, ok: 2 };
    return [...props.checks].sort((a, b) => weight[a.status] - weight[b.status]);
});

function metaLines(check) {
    const meta = check.meta ?? {};
    const lines = [];
    if (meta.version) lines.push(`Versión: ${meta.version}`);
    if (meta.path) lines.push(`Ruta: ${meta.path}`);
    if (meta.ini_file) lines.push(`php.ini: ${meta.ini_file}`);
    if (meta.limits) Object.entries(meta.limits).forEach(([key, limit]) => lines.push(`${key}: ${limit.current} (mínimo ${limit.required})`));
    if (meta.binaries) Object.entries(meta.binaries).forEach(([key, binary]) => lines.push(`${key}: ${binary.installed ? binary.version || 'instalado' : 'no encontrado'}`));
    if (meta.connection) lines.push(`Conexión: ${meta.connection} · ${meta.pending} pendientes · ${meta.failed} fallidos`);
    if (meta.default_cert_file) lines.push(`Almacén por defecto de OpenSSL: ${meta.default_cert_file}`);
    if (meta.mailer) lines.push(`Transporte: ${meta.mailer}`);
    return lines;
}
</script>

<template>
    <AppLayout title="Diagnóstico">
        <PageHeader :description="generated_at ? `Comprobado el ${dateTime(generated_at)}` : 'Estado del servidor'">
            <template #actions>
                <AppButton icon="refresh" :loading="refreshing" @click="refresh">Volver a comprobar</AppButton>
            </template>
        </PageHeader>

        <CapAlert v-if="summary.error" status="error" :title="`${summary.error} comprobación(es) con falla`" detail="Las funciones afectadas no estarán disponibles hasta corregirlas. Cada tarjeta dice qué hacer." />
        <CapAlert v-else-if="summary.warning" status="warning" :title="`${summary.warning} comprobación(es) para revisar`" detail="El sistema funciona, pero algunas funciones pueden fallar." />
        <CapAlert v-else status="ok" title="Todo en orden" detail="El servidor tiene lo necesario para el flujo completo." />

        <div class="cap-stagger grid grid-cols-3 gap-3 sm:gap-4">
            <StatCard label="Correctas" :count="summary.ok" />
            <StatCard label="Para revisar" :count="summary.warning" :hint="summary.warning ? 'Funcionan con riesgo' : 'Nada pendiente'" />
            <StatCard :variant="summary.error ? 'alert' : 'normal'" label="Con falla" :count="summary.error" />
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2" :class="{ 'opacity-50': refreshing }">
            <article v-for="check in ordered" :key="check.key" :class="[card, 'flex flex-col gap-3 p-4 sm:p-5']" :data-qa="`check-${check.key}`">
                <header class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-cap-card-2 text-cap-text-soft" aria-hidden="true">
                            <i class="mdi text-xl leading-none" :class="`mdi-${ICONS[check.key] ?? 'cog-outline'}`" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ check.label }}</h2>
                            <p v-if="IMPACT[check.key]" class="text-xs text-cap-text-soft">{{ IMPACT[check.key] }}</p>
                        </div>
                    </div>
                    <StatusBadge :status="check.status" :label="STATUS_LABELS[check.status] ?? check.status" />
                </header>
                <p class="text-sm text-gray-800 dark:text-gray-200">
                    <strong v-if="check.status !== 'ok'" class="font-semibold">Qué hacer: </strong>{{ check.detail }}
                </p>
                <ul v-if="metaLines(check).length" class="flex flex-col gap-0.5 break-all border-t border-cap-divider pt-3 text-xs text-cap-text-soft">
                    <li v-for="line in metaLines(check)" :key="line">{{ line }}</li>
                </ul>
            </article>
        </div>
    </AppLayout>
</template>
