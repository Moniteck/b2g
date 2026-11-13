<script setup>
// Marco de la aplicación sobre `CapAppLayout` (sidebar, cabecera, barra móvil, hoja «Más», toasts).
// Uso mínimo:  <AppLayout title="Licitaciones"> …contenido… </AppLayout>
// Props (todas opcionales):
//   title        título de la cabecera y de la pestaña del navegador; por defecto, el del menú.
//   breadcrumbs  [{ label, href? }]; por defecto, el grupo del menú.
//   sections     [{ label, href, active? }] de 2 a 5 vistas hermanas del módulo (píldoras en la cabecera).
//   backHref     en móvil, cambia el logo por la flecha «Volver» (pantallas de detalle).
//   fabLabel     botón flotante de crear en móvil (emite `fab`).
// Slots: default (contenido; ya va envuelto en `flex flex-col gap-6`), header-actions (cabecera de
//        escritorio), topbar-actions (barra superior móvil), search (buscador global del header).
// Datos compartidos que consume (con valores por defecto si faltan): auth.user, auth.can, companies,
// activeCompany, flash, notifications.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import CapAppLayout from '../../design/vue/CapAppLayout.vue';
import { useToasts } from '../../design/vue/composables/useToasts.js';
import ConfirmDialog from '../Components/ConfirmDialog.vue';
import { SYSTEM_NAME, currentEntry, endpoints, visibleMenu, visibleTabs } from '../config/navigation.js';
import { daysUntil, relativeTime } from '../lib/format.js';
import { postJson } from '../lib/http.js';
import CompanySwitcher from './Partials/CompanySwitcher.vue';
import UserMenu from './Partials/UserMenu.vue';

const props = defineProps({
    title: { type: String, default: '' },
    breadcrumbs: { type: Array, default: null },
    sections: { type: Array, default: () => [] },
    backHref: { type: String, default: '' },
    fabLabel: { type: String, default: '' },
});

const emit = defineEmits(['fab']);

const page = usePage();
const toasts = useToasts();

const auth = computed(() => page.props.auth ?? {});
const user = computed(() => ({ name: 'Usuario', email: '', ...(auth.value.user ?? {}) }));
const companies = computed(() => page.props.companies ?? []);
const activeCompany = computed(() => page.props.activeCompany ?? null);

const menu = computed(() => visibleMenu(auth.value, page.url));
const tabs = computed(() => visibleTabs(page.url));
const entry = computed(() => currentEntry(page.url));
const pageTitle = computed(() => props.title || entry.value?.item.label || SYSTEM_NAME);
const crumbs = computed(() => props.breadcrumbs ?? (entry.value ? [{ label: entry.value.group.label }] : []));

// Notificaciones del servidor ({ title, body, href, read_at, created_at, severity }) al formato del marco.
const SEVERITY_ICONS = { error: 'alert-circle-outline', warning: 'clock-alert-outline', ok: 'check-circle-outline', info: 'bell-outline' };

function groupOf(createdAt) {
    const days = daysUntil(createdAt);
    if (days === 0) return 'Hoy';
    if (days === -1) return 'Ayer';
    return 'Anteriores';
}

const notifications = computed(() =>
    (page.props.notifications?.items ?? []).map((item) => ({
        id: item.id,
        title: item.title,
        detail: item.body ?? '',
        time: relativeTime(item.created_at),
        group: groupOf(item.created_at),
        unread: !item.read_at,
        alert: item.severity === 'error' || item.severity === 'warning',
        icon: SEVERITY_ICONS[item.severity] ?? SEVERITY_ICONS.info,
        href: item.href || undefined,
    })),
);

async function markNotificationRead(id) {
    const item = notifications.value.find((notification) => notification.id === id);
    if (!item?.unread) return;
    // Petición aparte: el clic ya navega al destino y dos visitas de Inertia se cancelarían.
    await postJson(endpoints.notificationRead(id)).catch(() => {});
    if (!item.href) router.reload({ only: ['notifications'] });
}

function markAllNotificationsRead() {
    router.post(endpoints.notificationsReadAll, {}, { preserveScroll: true, preserveState: true, only: ['notifications', 'flash'] });
}

function logout() {
    router.post(endpoints.logout);
}

// Toasts de `flash` en cada respuesta del servidor.
const FLASH_STATUS = { success: 'ok', error: 'error', warning: 'warning', info: 'info' };
watch(
    () => page.props.flash,
    (flash) => {
        if (!flash || flash === lastFlash) return;
        lastFlash = flash;
        Object.entries(FLASH_STATUS).forEach(([key, status]) => {
            if (flash[key]) toasts.show({ status, title: String(flash[key]) });
        });
    },
    { immediate: true },
);
</script>

<script>
// El marco se vuelve a montar al cambiar de página: el último `flash` mostrado se recuerda aquí,
// a nivel de módulo, para no repetir su toast.
let lastFlash = null;
</script>

<template>
    <Head v-if="title" :title="title" />
    <CapAppLayout
        :system-name="SYSTEM_NAME"
        :menu="menu"
        :tabs="tabs"
        :user="user"
        :title="pageTitle"
        :breadcrumbs="crumbs"
        :sections="sections"
        :notifications="notifications"
        :notifications-href="endpoints.notifications"
        :link-component="Link"
        :back-href="backHref"
        :topbar-search="Boolean($slots.search)"
        :fab-label="fabLabel"
        @logout="logout"
        @mark-all-read="markAllNotificationsRead"
        @notification="markNotificationRead"
        @fab="emit('fab')"
    >
        <template v-if="$slots.search" #search><slot name="search" /></template>
        <template #header-actions>
            <slot name="header-actions" />
            <CompanySwitcher :companies="companies" :active="activeCompany" />
            <UserMenu :user="user" :company="activeCompany" @logout="logout" />
        </template>
        <template #topbar-actions>
            <slot name="topbar-actions" />
            <CompanySwitcher :companies="companies" :active="activeCompany" compact />
        </template>

        <div class="flex min-w-0 flex-col gap-6 [&>*]:min-w-0">
            <slot />
        </div>
    </CapAppLayout>
    <ConfirmDialog />
</template>
