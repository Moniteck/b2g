<script setup>
// Fondo vivo de la pantalla de acceso: manchones de marca que derivan (CSS) y, encima, un lienzo
// con partículas y formas (anillos, rombos, cruces) que flotan con parallax al puntero. Un solo
// requestAnimationFrame, se detiene con la pestaña oculta y, con movimiento reducido, se pinta una
// sola vez quieto. Colores desde las variables --cap-* (cambian solos con el tema).
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { cssVar, prefersReducedMotion } from '@/Modules/Auth/motion.js';

const props = defineProps({
    density: { type: Number, default: 1 },
});

const canvas = ref(null);
let context = null;
let frame = 0;
let items = [];
let colors = [];
let width = 0;
let height = 0;
let pointer = { x: 0, y: 0 };
let eased = { x: 0, y: 0 };
let running = false;
let themeObserver = null;

function readColors() {
    colors = ['--cap-red-600', '--cap-red-400', '--cap-wine', '--cap-taupe', '--cap-red-200'].map((name) => cssVar(name)).filter(Boolean);
}

function seed() {
    const area = (width * height) / (1440 * 900);
    const count = Math.round(Math.max(16, 54 * area) * props.density);
    items = Array.from({ length: count }, (_, index) => ({
        x: Math.random() * width,
        y: Math.random() * height,
        r: index % 7 === 0 ? 10 + Math.random() * 16 : 1.4 + Math.random() * 3.2,
        kind: index % 7 === 0 ? ['ring', 'diamond', 'cross'][index % 3] : 'dot',
        vx: (Math.random() - 0.5) * 0.18,
        vy: -0.08 - Math.random() * 0.22,
        spin: (Math.random() - 0.5) * 0.006,
        angle: Math.random() * Math.PI,
        depth: 0.3 + Math.random() * 1.4,
        alpha: (index % 7 === 0 ? 0.1 : 0.18) + Math.random() * (index % 7 === 0 ? 0.18 : 0.4),
        color: Math.floor(Math.random() * 5),
        phase: Math.random() * Math.PI * 2,
    }));
}

function resize() {
    const element = canvas.value;
    if (!element) return;
    const ratio = Math.min(window.devicePixelRatio || 1, 1.25);
    width = window.innerWidth;
    height = window.innerHeight;
    element.width = Math.round(width * ratio);
    element.height = Math.round(height * ratio);
    context = element.getContext('2d');
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    seed();
    draw(0);
}

function draw(time) {
    if (!context) return;
    context.clearRect(0, 0, width, height);
    eased.x += (pointer.x - eased.x) * 0.06;
    eased.y += (pointer.y - eased.y) * 0.06;

    for (const item of items) {
        const px = item.x + eased.x * 26 * item.depth;
        const py = item.y + eased.y * 18 * item.depth + Math.sin(time / 1600 + item.phase) * 6 * item.depth;
        context.globalAlpha = item.alpha * (0.75 + 0.25 * Math.sin(time / 900 + item.phase));
        context.fillStyle = context.strokeStyle = colors[item.color % colors.length] || 'currentColor';
        context.lineWidth = 1.6;

        if (item.kind === 'dot') {
            context.beginPath();
            context.arc(px, py, item.r, 0, Math.PI * 2);
            context.fill();
            continue;
        }

        context.save();
        context.translate(px, py);
        context.rotate(item.angle);
        context.beginPath();
        if (item.kind === 'ring') {
            context.arc(0, 0, item.r, 0, Math.PI * 2);
        } else if (item.kind === 'diamond') {
            context.moveTo(0, -item.r);
            context.lineTo(item.r * 0.7, 0);
            context.lineTo(0, item.r);
            context.lineTo(-item.r * 0.7, 0);
            context.closePath();
        } else {
            context.moveTo(-item.r * 0.6, 0);
            context.lineTo(item.r * 0.6, 0);
            context.moveTo(0, -item.r * 0.6);
            context.lineTo(0, item.r * 0.6);
        }
        context.stroke();
        context.restore();
    }
    context.globalAlpha = 1;
}

function step(time) {
    for (const item of items) {
        item.x += item.vx;
        item.y += item.vy;
        item.angle += item.spin;
        if (item.y < -40) {
            item.y = height + 40;
            item.x = Math.random() * width;
        }
        if (item.x < -40) item.x = width + 40;
        if (item.x > width + 40) item.x = -40;
    }
    draw(time);
    frame = window.requestAnimationFrame(step);
}

function start() {
    if (running || prefersReducedMotion() || document.hidden) return;
    running = true;
    frame = window.requestAnimationFrame(step);
}

function stop() {
    running = false;
    window.cancelAnimationFrame(frame);
}

function onVisibility() {
    if (document.hidden) stop();
    else start();
}

function onPointer(event) {
    if (event.pointerType !== 'mouse') return;
    pointer = { x: event.clientX / width - 0.5, y: event.clientY / height - 0.5 };
}

onMounted(() => {
    readColors();
    resize();
    start();
    window.addEventListener('resize', resize);
    window.addEventListener('pointermove', onPointer, { passive: true });
    document.addEventListener('visibilitychange', onVisibility);
    themeObserver = new MutationObserver(() => {
        readColors();
        draw(performance.now());
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

onBeforeUnmount(() => {
    stop();
    window.removeEventListener('resize', resize);
    window.removeEventListener('pointermove', onPointer);
    document.removeEventListener('visibilitychange', onVisibility);
    themeObserver?.disconnect();
});
</script>

<template>
    <div class="auth__canvas" aria-hidden="true">
        <span class="auth__blob auth__blob--red" data-depth="0.6" />
        <span class="auth__blob auth__blob--wine" data-depth="1" />
        <span class="auth__blob auth__blob--taupe" data-depth="1.4" />
        <canvas ref="canvas" class="auth__particles" />
    </div>
</template>
