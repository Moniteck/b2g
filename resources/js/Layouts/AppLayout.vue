<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { navigation, findModule } from '../config/navigation';

const collapsed = ref(false);
const page = usePage();
const active = computed(() => page.url === '/' ? 'dashboard' : page.url.slice(1));
const title = computed(() => findModule(active.value)[1]);
const user = computed(() => page.props.auth?.user);
const alertsCount = computed(() => page.props.topbar?.alertsCount || 0);
</script>

<template>
    <div class="app-shell">
        <aside class="sidebar" :class="{ 'is-collapsed': collapsed }">
            <div class="brand">
                <div class="logo">B2G</div>
                <div v-if="!collapsed" class="brand-copy"><div class="brand-title">CRM B2G Honduras</div><div class="brand-subtitle">Gestión Gubernamental</div></div>
            </div>
            <nav class="nav">
                <section v-for="group in navigation" :key="group.title" class="nav-group">
                    <p v-if="!collapsed" class="nav-title">{{ group.title }}</p>
                    <Link v-for="[id, label, icon, badge] in group.items" :key="id" :href="id === 'dashboard' ? '/' : `/${id}`" class="nav-link" :class="{ active: active === id }" :title="collapsed ? label : undefined">
                        <span class="nav-icon">{{ icon }}</span><span v-if="!collapsed" class="nav-label">{{ label }}</span><span v-if="!collapsed && badge" class="nav-badge" :class="{ new: badge === 'NUEVO' }">{{ badge }}</span>
                    </Link>
                </section>
            </nav>
            <div class="sidebar-footer"><p v-if="!collapsed">Ley de Contratación del Estado<br>Decreto 74-2001</p><button class="collapse-button" @click="collapsed = !collapsed">{{ collapsed ? '▶' : '◀' }}</button></div>
        </aside>
        <div class="workspace">
            <header class="topbar"><h1>{{ title }}</h1><div class="topbar-user"><span>{{ user?.name }}</span><div class="notice">⚠️ {{ alertsCount }} alertas</div><button class="logout" @click="router.post('/logout')">Salir</button></div></header>
            <main class="content"><slot /></main>
        </div>
    </div>
</template>
