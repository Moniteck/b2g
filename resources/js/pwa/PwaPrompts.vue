<script setup>
// Avisos flotantes de la aplicación instalable (los monta resources/js/pwa/index.js fuera del marco):
//  - «Hay una actualización · Actualizar»: activa el service worker nuevo y recarga.
//  - Oferta de instalación de una sola vez (Android y escritorio con Chromium; en iOS, la guía
//    «Compartir → Añadir a pantalla de inicio»). Después queda disponible en Mi cuenta.
// Van sobre la barra inferior móvil y respetan el área segura (notch).
import { computed } from 'vue';
import AppButton from '../Components/AppButton.vue';
import IconButton from '../Components/IconButton.vue';
import IosInstallSteps from './IosInstallSteps.vue';
import { applyUpdate, promptInstall, pwa, rememberOffer } from './state.js';

// Ruta pública (no un import de Vite): el ícono vive en public/icons.
const APP_ICON = '/icons/icon-192.png';

const updating = computed(() => pwa.updateReady);
const panel = 'pointer-events-auto w-full rounded-cap-card border border-cap-divider bg-cap-card p-4 shadow-lg dark:bg-cap-card-2';

function dismissUpdate() {
    pwa.updateReady = false;
}

async function install() {
    await promptInstall();
}
</script>

<template>
    <div class="pwa-prompts pointer-events-none fixed inset-x-0 z-cap-toast flex flex-col items-center gap-3 px-3 sm:items-end sm:px-6" aria-live="polite">
        <Transition name="pwa-prompt">
            <section v-if="updating" :class="panel" class="flex max-w-sm items-center gap-3" role="status" data-pwa="update">
                <span class="mdi mdi-update text-xl text-cap-brand dark:text-cap-brand-soft" aria-hidden="true" />
                <p class="min-w-0 flex-1 text-sm font-medium text-gray-900 dark:text-gray-100">Hay una actualización</p>
                <AppButton variant="primary" icon="refresh" @click="applyUpdate">Actualizar</AppButton>
                <IconButton icon="close" label="Más tarde" @click="dismissUpdate" />
            </section>
        </Transition>

        <Transition name="pwa-prompt">
            <section v-if="pwa.offerVisible && !updating" :class="panel" class="flex max-w-sm flex-col gap-3" aria-labelledby="pwa-offer-title" data-pwa="install-offer">
                <div class="flex items-start gap-3">
                    <img :src="APP_ICON" alt="" class="h-11 w-11 shrink-0 rounded-xl" width="44" height="44" />
                    <div class="min-w-0 flex-1">
                        <h2 id="pwa-offer-title" class="font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Instale CRM B2G en este equipo</h2>
                        <p class="text-sm text-cap-text-soft">Ábralo como una app, a pantalla completa, y reciba los avisos de plazos.</p>
                    </div>
                </div>
                <IosInstallSteps v-if="!pwa.installEvent && pwa.isIosSafari" />
                <div class="flex justify-end gap-2">
                    <AppButton @click="rememberOffer('later')">Ahora no</AppButton>
                    <AppButton v-if="pwa.installEvent" variant="primary" icon="download-outline" @click="install">Instalar</AppButton>
                    <AppButton v-else variant="primary" @click="rememberOffer('ios-guide')">Entendido</AppButton>
                </div>
            </section>
        </Transition>
    </div>
</template>

<style scoped>
.pwa-prompts {
    bottom: calc(24px + env(safe-area-inset-bottom));
}

@media (max-width: 1023.98px) {
    /* Sobre la barra inferior del marco móvil. */
    .pwa-prompts {
        bottom: calc(92px + env(safe-area-inset-bottom));
    }
}

.pwa-prompt-enter-active,
.pwa-prompt-leave-active {
    transition: opacity var(--cap-duration-base) var(--cap-ease-standard), transform var(--cap-duration-base) var(--cap-ease-standard);
}

.pwa-prompt-enter-from,
.pwa-prompt-leave-to {
    opacity: 0;
    transform: translateY(8px);
}

@media (prefers-reduced-motion: reduce) {
    .pwa-prompt-enter-active,
    .pwa-prompt-leave-active {
        transition: opacity var(--cap-duration-fast) linear;
    }

    .pwa-prompt-enter-from,
    .pwa-prompt-leave-to {
        transform: none;
    }
}
</style>
