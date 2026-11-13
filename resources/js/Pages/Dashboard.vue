<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

const props = defineProps({ dashboard: { type: Object, default: () => ({}) }, alerts: { type: Array, default: () => [] } });
const amount = (value) => `L ${new Intl.NumberFormat('es-HN').format(value || 0)}`;
const stats = computed(() => [
    ['🎯', amount(props.dashboard.pipeline), 'Pipeline Estimado', `${props.dashboard.opportunities || 0} oportunidades activas`, 'pipeline'],
    ['🏆', `${props.dashboard.awardRate || 0}%`, 'Tasa de Adjudicación', `${props.dashboard.wonTenders || 0} ganadas / ${props.dashboard.submittedTenders || 0} presentadas`, 'licitaciones'],
    ['⚠️', amount(props.dashboard.overdueReceivables), 'Cartera Vencida', 'Cobros pendientes de pago', 'cobros'],
    ['📁', `${props.dashboard.expiringDocuments || 0}`, 'Docs. por Vencer', `${props.dashboard.expiredDocuments || 0} ya vencidos`, 'documentos'],
]);
const actions = [['🎯', 'Nueva Oportunidad', 'pipeline'], ['📋', 'Nueva Licitación', 'licitaciones'], ['📄', 'Nueva Cotización', 'cotizaciones'], ['💰', 'Registrar Cobro', 'cobros'], ['🛒', 'Compra Menor', 'compras-menores'], ['🏛️', 'Ver Directorio', 'directorio']];
</script>

<template>
    <Head title="Dashboard General" />
    <AppLayout>
        <div class="page">
            <div class="page-heading"><h2>Dashboard General</h2><p>Resumen ejecutivo · {{ new Intl.DateTimeFormat('es-HN', { dateStyle: 'full' }).format(new Date()) }}</p></div>
            <div class="ai-insight"><span>🤖</span><div><div class="eyebrow">SUGERENCIA OPERATIVA</div><template v-if="!dashboard.submittedTenders">Aún no hay ofertas presentadas para calcular la tasa de adjudicación.</template><template v-else-if="dashboard.awardRate < 25">Tu tasa de adjudicación es {{ dashboard.awardRate }}%, por debajo de la meta del 25%. Revisa la calidad documental de tus ofertas y aplica la Matriz de Cumplimiento.</template><template v-else-if="dashboard.overdueReceivables">Tienes {{ amount(dashboard.overdueReceivables) }} en cartera vencida. Revisa tus cobros pendientes.</template><template v-else-if="dashboard.expiredDocuments">Tienes {{ dashboard.expiredDocuments }} documento(s) vencido(s). Actualiza tu documentación.</template><template v-else>Gestión en orden. Revisa los procesos disponibles y las próximas fechas de cierre.</template></div></div>
            <div class="stats"><Link v-for="[icon, value, label, sub, href] in stats" :key="label" :href="`/${href}`" class="stat-card"><div class="stat-top"><span class="stat-icon">{{ icon }}</span><span>→</span></div><div class="stat-value">{{ value }}</div><div class="stat-label">{{ label }}</div><div class="stat-sub">{{ sub }}</div></Link></div>
            <div class="grid-two"><section class="card white"><div class="card-header"><span>🔔 Alertas Activas</span><span class="count">{{ alerts.length }}</span></div><p v-if="!alerts.length" class="stat-sub">No hay alertas activas.</p><Link v-for="alert in alerts" :key="`${alert.type}-${alert.message}`" :href="`/${alert.type}`" class="alert-item" :class="alert.severity">{{ alert.message }}</Link></section><section class="card white"><div class="card-header">⚡ Acceso Rápido</div><div class="quick-actions"><Link v-for="[icon, label, href] in actions" :key="label" :href="`/${href}`" class="quick-action">{{ icon }}&nbsp; {{ label }}</Link></div></section></div>
            <div class="grid-two"><section class="card white"><div class="card-header">🏛️ Directorio por Potencial <Link href="/directorio">Ver todo →</Link></div><div class="potential"><div><strong>{{ dashboard.institutions?.high || 0 }}</strong><span>Potencial ALTO</span></div><div style="color:#b45309;background:#fffbeb"><strong>{{ dashboard.institutions?.medium || 0 }}</strong><span>Potencial MEDIO</span></div><div style="color:#6b7280;background:#f3f4f6"><strong>{{ dashboard.institutions?.low || 0 }}</strong><span>Potencial BAJO</span></div></div></section><section class="card white"><div class="card-header">🏛️ HonduCompras - Hoy <Link href="/licitaciones">Ver procesos →</Link></div><div class="list-row danger"><span>Cierran HOY (URGENTE)</span><strong>{{ dashboard.tenders?.closingToday || 0 }}</strong></div><div class="list-row warning"><span>Cierran en 1-30 días</span><strong>{{ dashboard.tenders?.closingSoon || 0 }}</strong></div><div class="list-row"><span>Compras Menores activas</span><strong>{{ dashboard.tenders?.minorActive || 0 }}</strong></div><div class="list-row"><span>Total procesos vigentes</span><strong>{{ dashboard.tenders?.active || 0 }}</strong></div></section></div>
        </div>
    </AppLayout>
</template>
