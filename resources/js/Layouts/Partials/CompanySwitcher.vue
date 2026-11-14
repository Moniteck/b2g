<script setup>
// Selector de empresa activa de la cabecera. Solo se pinta si el usuario tiene más de una empresa.
// Props: companies ([{ id, name }]), active ({ id, name }), compact (botón de ícono para la barra móvil).
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import { endpoints } from '../../config/navigation.js';
import { menuItem } from '../../lib/ui.js';

const props = defineProps({
    companies: { type: Array, default: () => [] },
    active: { type: Object, default: null },
    compact: { type: Boolean, default: false },
});

const switching = ref(false);
const activeName = computed(() => props.active?.name ?? 'Elegir empresa');

function choose(company, close) {
    close();
    if (company.id === props.active?.id || switching.value) return;
    switching.value = true;
    router.post(endpoints.activeCompany, { company_id: company.id }, { preserveScroll: true, onFinish: () => (switching.value = false) });
}
</script>

<template>
    <DropdownMenu v-if="companies.length > 1" label="Cambiar de empresa" width="w-72" align="end">
        <template #trigger="{ toggle, attrs }">
            <button v-if="compact" type="button" class="cap-shell-topbar__icon-button" v-bind="attrs" :aria-label="`Empresa activa: ${activeName}. Cambiar de empresa`" :aria-busy="switching ? 'true' : undefined" @click="toggle">
                <i class="mdi mdi-domain" aria-hidden="true" />
            </button>
            <button v-else type="button" class="app-header-chip" v-bind="attrs" :aria-label="`Empresa activa: ${activeName}. Cambiar de empresa`" :aria-busy="switching ? 'true' : undefined" @click="toggle">
                <i class="mdi mdi-domain" aria-hidden="true" />
                <span class="min-w-0 truncate">{{ activeName }}</span>
                <i class="mdi mdi-chevron-down" aria-hidden="true" />
            </button>
        </template>
        <template #header>
            <p class="px-3 pb-1 pt-2 text-xs font-medium text-cap-text-soft">Empresa activa</p>
        </template>
        <template #default="{ close }">
            <button v-for="company in companies" :key="company.id" type="button" role="menuitem" :aria-current="company.id === active?.id ? 'true' : undefined" :class="menuItem" @click="choose(company, close)">
                <i class="mdi text-xl leading-none" :class="company.id === active?.id ? 'mdi-check text-cap-brand dark:text-cap-brand-soft' : 'mdi-domain text-cap-text-soft'" aria-hidden="true" />
                <span class="min-w-0 truncate" :class="company.id === active?.id ? 'font-semibold' : ''">{{ company.name }}</span>
            </button>
        </template>
    </DropdownMenu>
</template>
