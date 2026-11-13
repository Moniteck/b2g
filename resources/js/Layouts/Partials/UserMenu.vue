<script setup>
// Menú de usuario de la cabecera de escritorio: quién soy, mi rol, mi firma, tema y cerrar sesión.
// (En móvil esas mismas acciones están al pie de la hoja «Más».)
// Props: user ({ name, email, initials?, is_root?, company_role? }), company ({ name }).
// Eventos: logout.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CapAvatar from '../../../design/vue/CapAvatar.vue';
import { toggleTheme } from '../../../design/shell/js/theme.js';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import { endpoints } from '../../config/navigation.js';
import { menuItem } from '../../lib/ui.js';

const props = defineProps({
    user: { type: Object, required: true },
    company: { type: Object, default: null },
});

const emit = defineEmits(['logout']);

const ROLE_LABELS = { admin: 'Administrador', operator: 'Operador', viewer: 'Solo lectura' };

const role = computed(() => (props.user.is_root ? 'Usuario raíz' : (ROLE_LABELS[props.user.company_role] ?? '')));
</script>

<template>
    <DropdownMenu class="order-last" label="Menú de usuario" width="w-64" align="end">
        <template #trigger="{ toggle, attrs }">
            <button type="button" class="app-header-avatar" v-bind="attrs" :aria-label="`Menú de usuario: ${user.name}`" @click="toggle">
                <CapAvatar :user="user" />
            </button>
        </template>
        <template #header>
            <div class="border-b border-cap-divider px-3 pb-3 pt-2">
                <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ user.name }}</p>
                <p v-if="user.email" class="truncate text-xs text-cap-text-soft">{{ user.email }}</p>
                <p v-if="role || company" class="mt-1 truncate text-xs text-cap-text-soft">{{ [role, company?.name].filter(Boolean).join(' · ') }}</p>
            </div>
        </template>
        <template #default="{ close }">
            <Link role="menuitem" href="/mi-cuenta" :class="[menuItem, 'mt-1']" @click="close">
                <i class="mdi mdi-account-circle-outline text-xl leading-none" aria-hidden="true" /><span>Mi cuenta</span>
            </Link>
            <Link role="menuitem" :href="endpoints.signature" :class="menuItem" @click="close">
                <i class="mdi mdi-draw-pen text-xl leading-none" aria-hidden="true" /><span>Mi firma y sello</span>
            </Link>
            <button type="button" role="menuitem" :class="menuItem" @click="toggleTheme()">
                <i class="mdi mdi-weather-night text-xl leading-none" aria-hidden="true" /><span>Cambiar a modo <span class="dark:hidden">oscuro</span><span class="hidden dark:inline">claro</span></span>
            </button>
            <div class="my-1 border-t border-cap-divider" role="separator" />
            <button type="button" role="menuitem" :class="menuItem" @click="close(); emit('logout')">
                <i class="mdi mdi-logout text-xl leading-none" aria-hidden="true" /><span>Cerrar sesión</span>
            </button>
        </template>
    </DropdownMenu>
</template>
