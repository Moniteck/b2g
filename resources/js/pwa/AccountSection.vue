<script setup>
// «Aplicación y avisos» en Mi cuenta: instalar la app y «Recibir avisos en este dispositivo».
//
//   <PwaAccountSection />   (resources/js/Pages/Account/Index.vue)
import { computed, onMounted, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Toggle from '@/Components/Toggle.vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import { card } from '@/lib/ui.js';
import IosInstallSteps from '@/pwa/IosInstallSteps.vue';
import { disablePush, enablePush, pushStatus, sendTestPush } from '@/pwa/push.js';
import { detectStandalone, promptInstall, pwa } from '@/pwa/state.js';

// Ruta pública (no un import de Vite): el ícono vive en public/icons.
const APP_ICON = '/icons/icon-192.png';

const toasts = useToasts();
const status = ref('loading');
const busy = ref(false);
const testing = ref(false);
const error = ref('');

const PUSH_HELP = {
    loading: 'Comprobando este dispositivo…',
    unsupported: 'Este navegador no admite notificaciones push. Use Chrome, Edge, Firefox o Safari actualizados.',
    'ios-install': 'En iPhone y iPad los avisos solo funcionan con la app instalada (iOS 16.4 o posterior). Instálela con los pasos de arriba y active los avisos desde la app.',
    'server-off': 'El administrador aún no configuró las notificaciones push en el servidor.',
    'no-worker': 'Disponible en la versión publicada del CRM (con HTTPS). En este entorno de desarrollo no se activa.',
    denied: 'El navegador bloqueó las notificaciones de este sitio. Permítalas en la configuración del sitio y vuelva aquí.',
    off: 'Le avisaremos de los plazos de aclaraciones, presentación y vencimientos aunque tenga el CRM cerrado.',
    on: 'Este dispositivo recibe los avisos de plazos. Tóquelos para abrir la pantalla del plazo.',
};

const pushOn = computed(() => status.value === 'on');
const toggleable = computed(() => ['on', 'off'].includes(status.value));
const installState = computed(() => {
    if (pwa.standalone) return 'standalone';
    if (pwa.installed) return 'installed';
    if (pwa.installEvent) return 'prompt';
    if (pwa.isIos) return 'ios';
    return 'browser';
});

async function refresh() {
    detectStandalone();
    status.value = await pushStatus().catch(() => 'unsupported');
}

async function togglePush(on) {
    busy.value = true;
    error.value = '';
    try {
        const result = on ? await enablePush() : await disablePush();
        toasts.show({ status: 'ok', title: result.message });
    } catch (exception) {
        error.value = exception.message;
    } finally {
        busy.value = false;
        await refresh();
    }
}

async function test() {
    testing.value = true;
    error.value = '';
    try {
        toasts.show({ status: 'ok', title: (await sendTestPush()).message });
    } catch (exception) {
        error.value = exception.message;
    } finally {
        testing.value = false;
    }
}

onMounted(refresh);
</script>

<template>
    <section :class="[card, 'flex flex-col gap-4 p-4 sm:p-6']" aria-labelledby="account-pwa" data-pwa="account">
        <h2 id="account-pwa" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Aplicación y avisos</h2>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <img :src="APP_ICON" alt="" width="40" height="40" class="h-10 w-10 shrink-0 rounded-xl" />
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Instalar aplicación</p>
                    <p v-if="installState === 'standalone'" class="text-sm text-cap-text-soft">Está usando CRM B2G como aplicación instalada.</p>
                    <p v-else-if="installState === 'installed'" class="text-sm text-cap-text-soft">Instalada. Ábrala desde el escritorio o la pantalla de inicio.</p>
                    <p v-else-if="installState === 'prompt'" class="text-sm text-cap-text-soft">Ábrala como una app, a pantalla completa y con acceso directo.</p>
                    <p v-else-if="installState === 'ios'" class="text-sm text-cap-text-soft">En iPhone o iPad se instala desde Safari:</p>
                    <p v-else class="text-sm text-cap-text-soft">Use la opción «Instalar» del menú del navegador (Chrome o Edge). Si no aparece, ya está instalada o el navegador no lo admite.</p>
                </div>
            </div>
            <StatusBadge v-if="installState === 'standalone' || installState === 'installed'" status="ok" label="Instalada" class="self-start" />
            <AppButton v-else-if="installState === 'prompt'" icon="download-outline" class="self-start" @click="promptInstall">Instalar aplicación</AppButton>
        </div>
        <IosInstallSteps v-if="installState === 'ios'" class="sm:pl-[3.25rem]" />

        <div class="border-t border-cap-divider pt-4">
            <Toggle
                :model-value="pushOn"
                :disabled="!toggleable || busy"
                label="Recibir avisos en este dispositivo"
                :description="PUSH_HELP[status]"
                @update:model-value="togglePush"
            />
            <p v-if="error" class="mt-2 text-sm text-cap-status-error-ink" role="alert">{{ error }}</p>
            <div v-if="pushOn" class="mt-3 flex justify-end">
                <AppButton icon="bell-ring-outline" :loading="testing" @click="test">Enviar aviso de prueba</AppButton>
            </div>
        </div>
    </section>
</template>
