<script setup>
// Selector de empresa activa de la cabecera (escritorio: píldora; móvil: botón de ícono).
// - Lista las empresas a las que el usuario puede cambiarse; la activa lleva el check.
// - Para la raíz separa «Mis empresas» de «Otras empresas del grupo»: entrar en una de estas es el
//   «modo soporte» (no crea membresía, queda auditado y el marco muestra una franja).
// - Al pie: «Administrar empresas» (/empresas) y, para la raíz, «Nueva empresa» (asistente).
// Props: companies ([{ id, name }]), active ({ id, name }), memberIds (ids donde la raíz es
// miembro; null si no aplica), isRoot, compact (botón de ícono para la barra móvil).
// Eventos: create (abrir el asistente de alta).
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import { endpoints } from '../../config/navigation.js';
import { menuItem } from '../../lib/ui.js';

const props = defineProps({
    companies: { type: Array, default: () => [] },
    active: { type: Object, default: null },
    memberIds: { type: Array, default: null },
    isRoot: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['create']);

const switching = ref(false);
const activeName = computed(() => props.active?.name ?? 'Elegir empresa');

const splitBySupport = computed(() => props.isRoot && Array.isArray(props.memberIds) && props.memberIds.length > 0);
const mine = computed(() => (splitBySupport.value ? props.companies.filter((company) => props.memberIds.includes(company.id)) : props.companies));
const others = computed(() => (splitBySupport.value ? props.companies.filter((company) => !props.memberIds.includes(company.id)) : []));

function choose(company, close, support = false) {
    close();
    if (company.id === props.active?.id || switching.value) return;
    switching.value = true;
    router.post(support ? endpoints.supportEnter : endpoints.activeCompany, { company_id: company.id }, { preserveScroll: true, onFinish: () => (switching.value = false) });
}

function create(close) {
    close();
    emit('create');
}
</script>

<template>
    <DropdownMenu v-if="companies.length" label="Cambiar de empresa" width="w-80" align="end">
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
        <template #default="{ close }">
            <p class="px-3 pb-1 pt-2 text-xs font-medium text-cap-text-soft">{{ splitBySupport ? 'Mis empresas' : 'Empresa activa' }}</p>
            <button v-for="company in mine" :key="company.id" type="button" role="menuitem" :aria-current="company.id === active?.id ? 'true' : undefined" :class="menuItem" @click="choose(company, close)">
                <i class="mdi text-xl leading-none" :class="company.id === active?.id ? 'mdi-check text-cap-brand dark:text-cap-brand-soft' : 'mdi-domain text-cap-text-soft'" aria-hidden="true" />
                <span class="min-w-0 truncate" :class="company.id === active?.id ? 'font-semibold' : ''">{{ company.name }}</span>
            </button>
            <template v-if="others.length">
                <div class="my-1 border-t border-cap-divider" role="separator" />
                <p class="px-3 pb-1 pt-1 text-xs font-medium text-cap-text-soft">Otras empresas del grupo · modo soporte</p>
                <button
                    v-for="company in others"
                    :key="company.id"
                    type="button"
                    role="menuitem"
                    :aria-current="company.id === active?.id ? 'true' : undefined"
                    :class="menuItem"
                    :title="`Entrar en modo soporte en ${company.name}`"
                    @click="choose(company, close, true)"
                >
                    <i class="mdi text-xl leading-none" :class="company.id === active?.id ? 'mdi-check text-cap-brand dark:text-cap-brand-soft' : 'mdi-lifebuoy text-cap-text-soft'" aria-hidden="true" />
                    <span class="min-w-0 flex-1 truncate" :class="company.id === active?.id ? 'font-semibold' : ''">{{ company.name }}</span>
                    <span v-if="company.id !== active?.id" class="shrink-0 text-xs text-cap-text-soft">Entrar</span>
                </button>
            </template>
            <div class="my-1 border-t border-cap-divider" role="separator" />
            <Link role="menuitem" :href="endpoints.companies" :class="menuItem" @click="close">
                <i class="mdi mdi-domain-switch text-xl leading-none text-cap-text-soft" aria-hidden="true" /><span>{{ isRoot ? 'Administrar empresas' : 'Ver mis empresas' }}</span>
            </Link>
            <button v-if="isRoot" type="button" role="menuitem" :class="menuItem" @click="create(close)">
                <i class="mdi mdi-domain-plus text-xl leading-none text-cap-text-soft" aria-hidden="true" /><span>Nueva empresa</span>
            </button>
        </template>
    </DropdownMenu>
</template>
