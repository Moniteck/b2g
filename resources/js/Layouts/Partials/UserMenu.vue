<script setup>
// Menú de usuario de la cabecera de escritorio: quién soy, mi rol, lo personal (Mi cuenta, Mi firma y
// sello, Mis recordatorios, Mis conexiones MCP: `accountMenu` de config/navigation.js), tema,
// animaciones y cerrar sesión. (En móvil están en el bloque «Tu cuenta» de la hoja «Más».)
// Props: user ({ name, email, initials?, is_root?, company_role? }), company ({ name }).
// Eventos: logout.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CapAvatar from '@design/vue/CapAvatar.vue';
import { toggleTheme } from '@design/shell/js/theme.js';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import { accountMenu } from '@/config/navigation.js';
import { motionPreference, systemReducedMotion, toggleMotionPreference } from '@/lib/motion/index.js';
import { menuItem } from '@/lib/ui.js';

const props = defineProps({
    user: { type: Object, required: true },
    company: { type: Object, default: null },
});

const emit = defineEmits(['logout']);

const ROLE_LABELS = { admin: 'Administrador', operator: 'Operador', viewer: 'Solo lectura' };

// Animaciones: completas / reducidas (se guarda en el navegador; si el sistema pide menos movimiento, manda el sistema).
const motionLabel = computed(() => (systemReducedMotion.value || motionPreference.value === 'reduced' ? 'reducidas' : 'completas'));

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
            <Link v-for="(entry, index) in accountMenu" :key="entry.href" role="menuitem" :href="entry.href" :class="[menuItem, index === 0 ? 'mt-1' : '']" @click="close">
                <i class="mdi text-xl leading-none" :class="`mdi-${entry.icon}`" aria-hidden="true" /><span>{{ entry.label }}</span>
            </Link>
            <div class="my-1 border-t border-cap-divider" role="separator" />
            <button type="button" role="menuitem" :class="menuItem" @click="toggleTheme()">
                <i class="mdi mdi-weather-night text-xl leading-none" aria-hidden="true" /><span>Cambiar a modo <span class="dark:hidden">oscuro</span><span class="hidden dark:inline">claro</span></span>
            </button>
            <button
                type="button"
                role="menuitem"
                :disabled="systemReducedMotion"
                :title="systemReducedMotion ? 'Tu sistema pide menos movimiento' : undefined"
                :class="[menuItem, systemReducedMotion ? 'cursor-not-allowed opacity-50' : '']"
                @click="toggleMotionPreference()"
            >
                <i class="mdi text-xl leading-none" :class="motionLabel === 'completas' ? 'mdi-motion-play-outline' : 'mdi-motion-pause-outline'" aria-hidden="true" />
                <span class="min-w-0 flex-1">Animaciones: {{ motionLabel }}</span>
            </button>
            <div class="my-1 border-t border-cap-divider" role="separator" />
            <button type="button" role="menuitem" :class="menuItem" @click="close(); emit('logout')">
                <i class="mdi mdi-logout text-xl leading-none" aria-hidden="true" /><span>Cerrar sesión</span>
            </button>
        </template>
    </DropdownMenu>
</template>
