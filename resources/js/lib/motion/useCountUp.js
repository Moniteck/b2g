// Cifra que cuenta desde 0 (o desde su valor anterior) hasta el valor nuevo.
//   const shown = useCountUp(() => props.total, { format: (v) => money(v) });   →   {{ shown }}
// Con movimiento reducido o la pestaña oculta muestra el valor final al instante.
import { onBeforeUnmount, ref, toValue, watch } from 'vue';
import { isReducedMotion } from './preference.js';
import { DURATION, easeOutExpo } from './tokens.js';

const plain = (value) => String(Math.round(value));

/** Interpola de `from` a `to` llamando a `onFrame(valor)` en cada cuadro. Devuelve la función que cancela. */
export function tween(from, to, onFrame, { duration = DURATION.count, delay = 0 } = {}) {
    if (isReducedMotion() || document.hidden || from === to || !Number.isFinite(from) || !Number.isFinite(to)) {
        onFrame(to);
        return () => {};
    }
    let frame = 0;
    let start = 0;
    const step = (now) => {
        if (!start) start = now + delay;
        const progress = Math.min(1, Math.max(0, (now - start) / duration));
        onFrame(progress >= 1 ? to : from + (to - from) * easeOutExpo(progress));
        if (progress < 1) frame = requestAnimationFrame(step);
    };
    onFrame(from);
    frame = requestAnimationFrame(step);
    return () => cancelAnimationFrame(frame);
}

/** Decimales que trae un número (para no mostrar 12.000000001 a mitad de camino). */
export function decimalsOf(value) {
    const text = String(value);
    const dot = text.indexOf('.');
    return dot === -1 ? 0 : Math.min(4, text.length - dot - 1);
}

export function useCountUp(source, { format = plain, duration = DURATION.count, delay = 0, from = 0 } = {}) {
    const target = () => Number(toValue(source)) || 0;
    const shown = ref(format(target()));
    let current = from;
    let cancel = () => {};

    const run = (to) => {
        cancel();
        const decimals = decimalsOf(to);
        cancel = tween(current, to, (value) => {
            current = value;
            shown.value = format(value >= to - 1e-9 && value <= to + 1e-9 ? to : Number(value.toFixed(decimals)));
        }, { duration, delay });
    };

    watch(target, run, { immediate: true });
    onBeforeUnmount(() => cancel());
    return shown;
}

const NUMBER_TOKEN = /\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?/;

/**
 * Cuenta la primera cifra de un texto ya formateado («L 12,345.67», «1,240», «45 %») sin cambiar el
 * resto. Trabaja sobre el nodo de texto que pinta Vue: si Vue lo cambia a mitad, se detiene.
 * Fechas y horas no se tocan.
 */
export function countUpText(element, { duration = DURATION.count, delay = 0 } = {}) {
    const node = [...element.childNodes].find((child) => child.nodeType === 3 && NUMBER_TOKEN.test(child.nodeValue));
    if (!node) return () => {};
    const original = node.nodeValue;
    if (/\d[/:]\d/.test(original)) return () => {};
    const match = original.match(NUMBER_TOKEN);
    const token = match[0];
    const target = Number(token.replace(/,/g, ''));
    if (!Number.isFinite(target) || target === 0) return () => {};
    const decimals = token.includes('.') ? token.split('.')[1].length : 0;
    const grouped = token.includes(',');
    const formatter = new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals, useGrouping: grouped });
    const before = original.slice(0, match.index);
    const after = original.slice(match.index + token.length);
    let written = original;
    let stop = () => {};
    stop = tween(0, target, (value) => {
        if (node.nodeValue !== written) return stop();
        written = value === target ? original : `${before}${formatter.format(value)}${after}`;
        node.nodeValue = written;
    }, { duration, delay });
    return () => {
        stop();
        if (node.nodeValue === written) node.nodeValue = original;
    };
}
