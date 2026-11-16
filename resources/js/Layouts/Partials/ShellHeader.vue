<script setup>
// Cabecera del marco persistente: la de escritorio (migas, título, secciones, buscador ⌘K, empresa,
// campana y avatar) y la barra superior móvil (Volver o logo, TÍTULO de la pantalla, buscar, empresa
// y campana). Usa las clases del sistema de diseño (`cap-shell-header*`, `cap-shell-topbar*`).
// Huecos para las páginas (<Teleport defer> desde AppLayout): #app-shell-header-actions,
// #app-shell-topbar-actions y #app-shell-search.
import { Link, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import CapBellButton from '../../../design/vue/CapBellButton.vue';
import CapNotifications from '../../../design/vue/CapNotifications.vue';
import CapSectionNav from '../../../design/vue/CapSectionNav.vue';
import { usePopover } from '../../../design/vue/composables/usePopover.js';

const props = defineProps({
    title: { type: String, required: true },
    mark: { type: String, default: '' },
    breadcrumbs: { type: Array, default: () => [] },
    sections: { type: Array, default: () => [] },
    notifications: { type: Array, default: () => [] },
    notificationsHref: { type: String, default: '' },
    backHref: { type: String, default: '' },
    shortcut: { type: String, default: 'Ctrl K' },
});

const emit = defineEmits(['mark-all-read', 'notification', 'search']);

const { isOpen: notificationsOpen, toggle: toggleNotifications, close: closeNotifications } = usePopover('cap-notifications');

const unreadCount = computed(() => props.notifications.filter((notification) => notification.unread).length);
const showSections = computed(() => props.sections.length >= 2);

// El marco no se vuelve a montar: al cambiar de página, el panel de avisos se cierra.
const page = usePage();
watch(() => page.url, () => closeNotifications());

function onNotification(id) {
    closeNotifications();
    emit('notification', id);
}
</script>

<template>
    <header class="cap-shell-header app-shell-header cap-glass" :class="{ 'cap-shell-header--with-nav': showSections }">
        <div class="app-shell-header__heading">
            <nav v-if="breadcrumbs.length" class="cap-shell-header__crumbs" aria-label="Migas de pan">
                <template v-for="(crumb, index) in breadcrumbs" :key="`${index}-${crumb.label}`">
                    <span v-if="index > 0" aria-hidden="true"> › </span>
                    <Link v-if="crumb.href" :href="crumb.href">{{ crumb.label }}</Link>
                    <template v-else>{{ crumb.label }}</template>
                </template>
            </nav>
            <h1 class="cap-shell-header__title">{{ title }}</h1>
        </div>
        <CapSectionNav v-if="showSections" :key="sections.map((section) => section.href).join()" :sections="sections" :link-component="Link" />
        <div id="app-shell-search" class="app-shell-header__search-slot" />
        <button type="button" class="cap-shell-header__search app-shell-search-trigger" aria-label="Buscar en el CRM" aria-haspopup="dialog" @click="emit('search')">
            <i class="mdi mdi-magnify" aria-hidden="true" />
            <span class="app-shell-search-trigger__text">Buscar…</span>
            <kbd class="cap-shell-header__kbd">{{ shortcut }}</kbd>
        </button>
        <div class="cap-shell-header__actions">
            <div id="app-shell-header-actions" class="app-shell-slot" />
            <slot name="actions" />
            <CapBellButton button-class="cap-shell-header__icon-button" :expanded="notificationsOpen" :unread-count="unreadCount" @click="toggleNotifications" />
            <slot name="avatar" />
        </div>
    </header>

    <header class="cap-shell-topbar app-shell-topbar cap-glass">
        <Link v-if="backHref" class="cap-shell-topbar__icon-button" :href="backHref" aria-label="Volver">
            <i class="mdi mdi-arrow-left" aria-hidden="true" />
        </Link>
        <Link v-else href="/" class="app-shell-topbar__logo" aria-label="Ir al Dashboard">
            <span class="cap-shell-logo" aria-hidden="true">{{ mark }}</span>
        </Link>
        <h1 class="cap-shell-topbar__title" :title="title">{{ title }}</h1>
        <div id="app-shell-topbar-actions" class="app-shell-slot" />
        <button class="cap-shell-topbar__icon-button" type="button" aria-label="Buscar" aria-haspopup="dialog" @click="emit('search')">
            <i class="mdi mdi-magnify" aria-hidden="true" />
        </button>
        <slot name="topbar-actions" />
        <CapBellButton button-class="cap-shell-topbar__icon-button" :expanded="notificationsOpen" :unread-count="unreadCount" @click="toggleNotifications" />
    </header>

    <CapSectionNav v-if="showSections" :key="`m-${sections.map((section) => section.href).join()}`" :sections="sections" mobile :link-component="Link" />

    <CapNotifications
        :hidden="!notificationsOpen"
        :notifications="notifications"
        :view-all-href="notificationsHref"
        :link-component="Link"
        @mark-all-read="emit('mark-all-read')"
        @notification="onNotification"
        @view-all="closeNotifications"
    />
</template>
