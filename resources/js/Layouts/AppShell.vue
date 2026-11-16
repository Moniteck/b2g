<script setup>
// Marco persistente de la aplicación: sidebar, cabecera, barra superior e inferior del móvil, hoja
// «Más», toasts de `flash`, confirmaciones, paleta ⌘K, asistente de empresa y franja de modo soporte.
// Inertia lo monta UNA vez como layout por defecto (`app.js`) y no se vuelve a montar al navegar;
// las páginas publican su título, migas, secciones, «Volver» y botón flotante con `<AppLayout>`
// (ver `shell.js`).
// Datos compartidos que consume: auth.user, auth.can, companies, activeCompany, flash, notifications.
import { router, usePage } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import CapToaster from '../../design/vue/CapToaster.vue';
import { useToasts } from '../../design/vue/composables/useToasts.js';
import { company as brand } from '../../design/company.js';
import ConfirmDialog from '../Components/ConfirmDialog.vue';
import { SYSTEM_NAME, accountMenu, currentEntry, endpoints, mobileTabs, visibleMenu, visibleTabs } from '../config/navigation.js';
import { daysUntil, relativeTime } from '../lib/format.js';
import { getJson, postJson } from '../lib/http.js';
import { captureIndicators, isPageVisit, slideIndicators, useShellMotion } from '../lib/motion/index.js';
import CompanySwitcher from './Partials/CompanySwitcher.vue';
import PageSkeleton from './Partials/PageSkeleton.vue';
import ShellHeader from './Partials/ShellHeader.vue';
import ShellMoreSheet from './Partials/ShellMoreSheet.vue';
import ShellSidebar from './Partials/ShellSidebar.vue';
import ShellTabBar from './Partials/ShellTabBar.vue';
import SupportBanner from './Partials/SupportBanner.vue';
import UserMenu from './Partials/UserMenu.vue';
import { SHELL_KEY, createShellState } from './shell.js';

defineOptions({ inheritAttrs: false });

const CommandPalette = defineAsyncComponent(() => import('./Partials/CommandPalette.vue'));
const CompanyWizard = defineAsyncComponent(() => import('./Partials/CompanyWizard.vue'));
const RequestErrorDialog = defineAsyncComponent(() => import('./Partials/RequestErrorDialog.vue'));

const shell = createShellState();
provide(SHELL_KEY, shell);

const page = usePage();
const toasts = useToasts();

const auth = computed(() => page.props.auth ?? {});
const user = computed(() => ({ name: 'Usuario', email: '', ...(auth.value.user ?? {}) }));
const companies = computed(() => (Array.isArray(page.props.companies) ? page.props.companies : []));
const activeCompany = computed(() => page.props.activeCompany ?? null);

const menu = computed(() => visibleMenu(auth.value, page.url));
const tabs = computed(() => visibleTabs(page.url));
const entry = computed(() => currentEntry(page.url));
const meta = computed(() => shell.meta);
const pageTitle = computed(() => meta.value.title || entry.value?.item.label || SYSTEM_NAME);
const crumbs = computed(() => meta.value.breadcrumbs ?? (entry.value ? [{ label: entry.value.group.label }] : []));
const moreActive = computed(() => !tabs.value.some((tab) => tab.active));

const ROLE_LABELS = { admin: 'Administrador', operator: 'Operador', viewer: 'Solo lectura' };
const roleLabel = computed(() => (user.value.is_root ? 'Usuario raíz' : (ROLE_LABELS[user.value.company_role] ?? '')));
const shortcut = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform ?? '') ? '⌘ K' : 'Ctrl K';

// ───────────── Notificaciones (props compartidas; el servidor las cachea) ─────────────
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

useShellMotion({ unread: () => notifications.value.filter((item) => item.unread).length });

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
    try {
        Object.keys(localStorage).filter((key) => key.startsWith('crm:search-recent')).forEach((key) => localStorage.removeItem(key));
    } catch {
        // Sin almacenamiento no hay recientes que borrar.
    }
    router.post(endpoints.logout);
}

// ───────────── Toasts de `flash` ─────────────
const FLASH_STATUS = { success: 'ok', error: 'error', warning: 'warning', info: 'info' };
let lastFlash = null;
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

// ───────────── Modo soporte (solo la raíz) ─────────────
const support = ref({ support: false, company: null, member_ids: null });
async function loadSupport() {
    if (!user.value.is_root) {
        support.value = { support: false, company: null, member_ids: null };
        return;
    }
    support.value = await getJson(endpoints.supportStatus).catch(() => support.value);
}
watch(() => [activeCompany.value?.id, user.value.is_root], loadSupport, { immediate: true });

// ───────────── Paleta ⌘K y asistente de empresa ─────────────
const paletteOpen = ref(false);
const paletteLoaded = ref(false);
const wizardOpen = ref(false);
const wizardLoaded = ref(false);
const moreOpen = ref(false);

function openPalette() {
    moreOpen.value = false;
    paletteLoaded.value = true;
    paletteOpen.value = true;
}

function openWizard() {
    wizardLoaded.value = true;
    wizardOpen.value = true;
}
// Las páginas (p. ej. Empresas) abren el asistente con `useShell().openCompanyWizard()`.
shell.openCompanyWizard = openWizard;
shell.openSearch = () => openPalette();

function onKeydown(event) {
    if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        paletteOpen.value ? (paletteOpen.value = false) : openPalette();
    }
}

// ───────────── Visitas: progreso, esqueleto e indicador ─────────────
const showSkeleton = ref(false);
let skeletonTimer = 0;
let indicatorsBefore = null;
const listeners = [];

watch(
    () => page.url,
    () => {
        indicatorsBefore = captureIndicators();
        moreOpen.value = false;
        nextTick(() => {
            slideIndicators(indicatorsBefore);
            indicatorsBefore = null;
        });
    },
    { flush: 'pre' },
);

// Error de una petición de Inertia que no es una página: modal propia en vez del iframe.
const requestError = ref(null);

onMounted(() => {
    if (import.meta.env.DEV) window.__crmShellMounts = (window.__crmShellMounts ?? 0) + 1;
    document.addEventListener('keydown', onKeydown);
    listeners.push(
        router.on('start', (event) => {
            if (!isPageVisit(event.detail.visit)) return;
            clearTimeout(skeletonTimer);
            skeletonTimer = window.setTimeout(() => (showSkeleton.value = true), 300);
        }),
        router.on('finish', () => {
            clearTimeout(skeletonTimer);
            showSkeleton.value = false;
        }),
        router.on('httpException', (event) => {
            const response = event.detail.response;
            if (response?.headers?.['x-inertia']) return true;
            event.preventDefault();
            requestError.value = { status: response?.status ?? 500 };
            return false;
        }),
        router.on('networkError', (event) => {
            event.preventDefault();
            requestError.value = { status: 0 };
        }),
    );
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    listeners.forEach((off) => off?.());
    clearTimeout(skeletonTimer);
});
</script>

<template>
    <a href="#contenido" class="app-skip-link">Saltar al contenido</a>
    <div class="cap-shell app-shell" :data-support="support.support ? 'true' : undefined">
        <ShellSidebar :system-name="SYSTEM_NAME" :owner="brand.name" :mark="brand.mark" :menu="menu" :user="user" @logout="logout" />

        <div class="cap-shell__main">
            <ShellHeader
                :title="pageTitle"
                :mark="brand.mark"
                :breadcrumbs="crumbs"
                :sections="meta.sections"
                :notifications="notifications"
                :notifications-href="endpoints.notifications"
                :back-href="meta.backHref"
                :shortcut="shortcut"
                @search="openPalette"
                @mark-all-read="markAllNotificationsRead"
                @notification="markNotificationRead"
            >
                <template #actions>
                    <CompanySwitcher :companies="companies" :active="activeCompany" :member-ids="support.member_ids" :is-root="Boolean(user.is_root)" @create="openWizard" />
                </template>
                <template #avatar>
                    <UserMenu :user="user" :company="activeCompany" @logout="logout" />
                </template>
                <template #topbar-actions>
                    <CompanySwitcher :companies="companies" :active="activeCompany" :member-ids="support.member_ids" :is-root="Boolean(user.is_root)" compact @create="openWizard" />
                </template>
            </ShellHeader>

            <SupportBanner v-if="support.support && support.company" :company="support.company" />

            <main id="contenido" class="cap-shell__content" tabindex="-1" :aria-busy="showSkeleton ? 'true' : undefined">
                <PageSkeleton v-if="showSkeleton" />
                <div v-show="!showSkeleton" class="app-shell__page">
                    <slot />
                </div>
            </main>
        </div>

        <ShellTabBar :tabs="tabs" :more-open="moreOpen" :more-active="moreActive" @more="moreOpen = !moreOpen" />

        <button v-if="meta.fabLabel" class="cap-shell-fab" type="button" :aria-label="meta.fabLabel" :title="meta.fabLabel" @click="shell.onFab?.()">
            <i class="mdi mdi-plus" aria-hidden="true" />
        </button>

        <ShellMoreSheet
            :open="moreOpen"
            :menu="menu"
            :account="accountMenu"
            :hidden-hrefs="mobileTabs.map((tab) => tab.href)"
            :user="user"
            :role-label="roleLabel"
            @close="moreOpen = false"
            @logout="logout"
            @search="openPalette"
        />

        <CapToaster />
    </div>
    <ConfirmDialog />
    <CommandPalette v-if="paletteLoaded" v-model:open="paletteOpen" :auth="auth" @create-company="openWizard" />
    <CompanyWizard v-if="wizardLoaded" v-model:open="wizardOpen" />
    <RequestErrorDialog v-if="requestError" :status="requestError.status" @close="requestError = null" />
</template>
