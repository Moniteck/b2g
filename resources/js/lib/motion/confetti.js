// Celebración con confeti en los colores de la marca (rojo, vino, taupe y el verde de «ok»).
//   celebrate();                          desde arriba al centro
//   celebrate({ origin: buttonElement }); sale del elemento
// Un lienzo fijo encima de todo que no recibe clics y se retira solo. Con movimiento reducido no
// hay partículas: devuelve false y quien llama ya muestra el resultado de forma estática.
import { isReducedMotion } from './preference.js';
import { DURATION } from './tokens.js';

const COLOR_VARS = ['--cap-brand', '--cap-red', '--cap-wine', '--cap-taupe', '--cap-red-200', '--cap-status-ok-solid', '--cap-status-warning-solid'];

function brandColors() {
    const style = getComputedStyle(document.documentElement);
    const colors = COLOR_VARS.map((name) => style.getPropertyValue(name).trim()).filter(Boolean);
    return colors.length ? colors : ['currentColor'];
}

function originPoint(origin) {
    const element = origin?.$el ?? origin;
    if (element?.getBoundingClientRect) {
        const rect = element.getBoundingClientRect();
        return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
    }
    return { x: window.innerWidth / 2, y: window.innerHeight * 0.32 };
}

export function celebrate({ origin = null, count = 180, duration = DURATION.celebrate } = {}) {
    if (typeof document === 'undefined' || isReducedMotion() || document.hidden) return false;

    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    const canvas = document.createElement('canvas');
    canvas.className = 'm-confetti';
    canvas.setAttribute('aria-hidden', 'true');
    canvas.width = window.innerWidth * ratio;
    canvas.height = window.innerHeight * ratio;
    document.body.appendChild(canvas);
    const context = canvas.getContext('2d');
    context.scale(ratio, ratio);

    const colors = brandColors();
    const { x, y } = originPoint(origin);
    const particles = Array.from({ length: count }, (_, index) => {
        const angle = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.3;
        const speed = 10 + Math.random() * 15;
        return {
            x: x + (Math.random() - 0.5) * 120,
            y: y + (Math.random() - 0.5) * 30,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed - 2,
            size: 5 + Math.random() * 6,
            ratio: 0.4 + Math.random() * 0.5,
            rotation: Math.random() * Math.PI,
            spin: (Math.random() - 0.5) * 0.35,
            tilt: Math.random() * Math.PI,
            color: colors[index % colors.length],
            round: index % 5 === 0,
        };
    });

    let start = 0;
    let frame = 0;
    const draw = (now) => {
        if (!start) start = now;
        const elapsed = now - start;
        const fade = Math.max(0, 1 - Math.max(0, elapsed - duration * 0.6) / (duration * 0.4));
        context.clearRect(0, 0, window.innerWidth, window.innerHeight);
        for (const particle of particles) {
            particle.vx *= 0.985;
            particle.vy = particle.vy * 0.985 + 0.32;
            particle.x += particle.vx;
            particle.y += particle.vy;
            particle.rotation += particle.spin;
            particle.tilt += 0.12;
            context.save();
            context.globalAlpha = fade;
            context.fillStyle = particle.color;
            context.translate(particle.x, particle.y);
            context.rotate(particle.rotation);
            if (particle.round) {
                context.beginPath();
                context.arc(0, 0, particle.size * 0.4, 0, Math.PI * 2);
                context.fill();
            } else {
                context.scale(1, Math.cos(particle.tilt));
                context.fillRect(-particle.size / 2, (-particle.size * particle.ratio) / 2, particle.size, particle.size * particle.ratio);
            }
            context.restore();
        }
        if (elapsed < duration) frame = requestAnimationFrame(draw);
        else canvas.remove();
    };
    frame = requestAnimationFrame(draw);
    // Seguro: si la pestaña se oculta, rAF se detiene; el lienzo se retira igual.
    setTimeout(() => {
        cancelAnimationFrame(frame);
        canvas.remove();
    }, duration + 1500);
    return true;
}
