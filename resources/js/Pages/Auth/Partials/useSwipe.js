// Gesto de deslizar horizontal con eventos de puntero (toque o lápiz). El arrastre sigue al dedo;
// al soltar, cambia de cara si pasó el umbral de distancia o de velocidad y, si no, vuelve.
//
//   const swipe = useSwipe(trackRef, { canGo: (dir) => …, onSwipe: (dir) => … });
//   swipe.offset.value   // px que se desplazó el dedo (con resistencia en los bordes)
//   swipe.dragging.value // true mientras se arrastra
//
// `dir` es 'next' (de derecha a izquierda) o 'prev'. El elemento debe llevar `touch-action: pan-y`
// para que el navegador siga haciendo el desplazamiento vertical.
import { onBeforeUnmount, onMounted, ref, unref } from 'vue';

const DECIDE_PX = 8;
const DISTANCE_RATIO = 0.28;
const VELOCITY = 0.45; // px por ms

export function useSwipe(target, { canGo = () => true, onSwipe = () => {}, enabled = () => true } = {}) {
    const offset = ref(0);
    const dragging = ref(false);
    let start = null;
    let samples = [];

    function reset() {
        start = null;
        samples = [];
        dragging.value = false;
        offset.value = 0;
    }

    function down(event) {
        if (!enabled() || event.pointerType === 'mouse' || !event.isPrimary) return;
        start = { x: event.clientX, y: event.clientY, id: event.pointerId, decided: false };
        samples = [{ x: event.clientX, t: event.timeStamp }];
    }

    function move(event) {
        if (!start || event.pointerId !== start.id) return;
        const dx = event.clientX - start.x;
        const dy = event.clientY - start.y;

        if (!start.decided) {
            if (Math.abs(dy) > DECIDE_PX && Math.abs(dy) > Math.abs(dx)) {
                start = null; // es desplazamiento vertical: lo maneja el navegador
                return;
            }
            if (Math.abs(dx) < DECIDE_PX || Math.abs(dx) < Math.abs(dy) * 1.2) return;
            start.decided = true;
            dragging.value = true;
            unref(target)?.setPointerCapture?.(event.pointerId);
        }

        const direction = dx < 0 ? 'next' : 'prev';
        offset.value = canGo(direction) ? dx : dx * 0.22;
        samples.push({ x: event.clientX, t: event.timeStamp });
        if (samples.length > 6) samples.shift();
    }

    function up(event) {
        if (!start || event.pointerId !== start.id) return;
        if (!start.decided) {
            start = null;
            return;
        }

        const width = unref(target)?.getBoundingClientRect().width || window.innerWidth;
        const first = samples[0];
        const last = samples[samples.length - 1];
        const velocity = last && first && last.t > first.t ? (last.x - first.x) / (last.t - first.t) : 0;
        const dx = event.clientX - start.x;
        const direction = dx < 0 ? 'next' : 'prev';
        const passed = Math.abs(dx) > width * DISTANCE_RATIO || (Math.abs(velocity) > VELOCITY && Math.sign(velocity) === Math.sign(dx));

        reset();
        if (passed && canGo(direction)) onSwipe(direction);
    }

    onMounted(() => {
        const element = unref(target);
        if (!element) return;
        element.addEventListener('pointerdown', down);
        element.addEventListener('pointermove', move);
        element.addEventListener('pointerup', up);
        element.addEventListener('pointercancel', reset);
    });

    onBeforeUnmount(() => {
        const element = unref(target);
        if (!element) return;
        element.removeEventListener('pointerdown', down);
        element.removeEventListener('pointermove', move);
        element.removeEventListener('pointerup', up);
        element.removeEventListener('pointercancel', reset);
    });

    return { offset, dragging };
}
