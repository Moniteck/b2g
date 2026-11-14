// Preferencia de animaciones: «completas» (por defecto) o «reducidas». Se guarda en el navegador
// (`localStorage.motion`) y se suma a la del sistema (`prefers-reduced-motion`): si el sistema pide
// menos movimiento, manda el sistema. El resultado queda en `<html data-motion="full|reduced">`,
// que es lo que leen `motion.css` y las animaciones en JS.
import { computed, ref } from 'vue';

const STORAGE_KEY = 'motion';
const media = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

function readStored() {
    try {
        return localStorage.getItem(STORAGE_KEY) === 'reduced' ? 'reduced' : 'full';
    } catch {
        return 'full';
    }
}

/** 'full' | 'reduced': lo que eligió el usuario en su menú. */
export const motionPreference = ref(readStored());

/** true si el sistema operativo pide menos movimiento (no se puede anular desde la app). */
export const systemReducedMotion = ref(Boolean(media?.matches));

/** true si hay que reducir: por el sistema o por la preferencia del usuario. */
export const reducedMotion = computed(() => systemReducedMotion.value || motionPreference.value === 'reduced');

/** Lectura sincrónica para código que no es reactivo. */
export const isReducedMotion = () => reducedMotion.value;

export function applyMotionPreference() {
    if (typeof document === 'undefined') return;
    document.documentElement.dataset.motion = reducedMotion.value ? 'reduced' : 'full';
}

export function setMotionPreference(value) {
    motionPreference.value = value === 'reduced' ? 'reduced' : 'full';
    try {
        localStorage.setItem(STORAGE_KEY, motionPreference.value);
    } catch {
        // Sin almacenamiento la preferencia dura lo que la pestaña.
    }
    applyMotionPreference();
}

export function toggleMotionPreference() {
    setMotionPreference(motionPreference.value === 'reduced' ? 'full' : 'reduced');
}

media?.addEventListener('change', (event) => {
    systemReducedMotion.value = event.matches;
    applyMotionPreference();
});

// Otra pestaña cambió la preferencia.
if (typeof window !== 'undefined') {
    window.addEventListener('storage', (event) => {
        if (event.key !== STORAGE_KEY) return;
        motionPreference.value = readStored();
        applyMotionPreference();
    });
}

// Lo decorativo (confeti, brillos) se pausa con la pestaña oculta.
if (typeof document !== 'undefined') {
    const syncVisibility = () => document.documentElement.toggleAttribute('data-tab-hidden', document.hidden);
    document.addEventListener('visibilitychange', syncVisibility);
    syncVisibility();
}
