// Salida animada al iniciar sesión: la tarjeta se recoge, Lici saluda y una cortina circular con el
// degradado de la marca cubre la pantalla con «Hola, {nombre}». La cortina vive en <body> (fuera de
// Vue) para seguir visible mientras Inertia cambia a la página siguiente y se retira sobre ella.
//
//   const exit = playLoginExit({ card, mascot, name: 'Ana' });
//   await exit.ready;   // momento de cambiar de página (como mucho ~0,9 s; antes si el usuario interrumpe)
//   exit.finish();      // tras el cambio: la cortina se desvanece y se retira
import { prefersReducedMotion } from './motion.js';

const FULL = { cardOut: 360, discDelay: 160, disc: 560, helloDelay: 470, hello: 280, ready: 880, out: 420 };
const REDUCED = { cardOut: 0, discDelay: 0, disc: 200, helloDelay: 0, hello: 0, ready: 420, out: 220 };

export function playLoginExit({ card, mascot, name }) {
    const reduced = prefersReducedMotion();
    const timing = reduced ? REDUCED : FULL;
    const animations = [];
    const run = (element, keyframes, options) => {
        if (!element?.animate) return null;
        const animation = element.animate(keyframes, { fill: 'forwards', ...options });
        animations.push(animation);
        return animation;
    };

    const mascotRect = mascot?.getBoundingClientRect();
    const originX = mascotRect ? mascotRect.left + mascotRect.width / 2 : window.innerWidth / 2;
    const originY = mascotRect ? mascotRect.top + mascotRect.height / 2 : window.innerHeight / 2;
    const radius = Math.hypot(Math.max(originX, window.innerWidth - originX), Math.max(originY, window.innerHeight - originY));

    const curtain = document.createElement('div');
    curtain.className = `auth-curtain${reduced ? ' auth-curtain--reduced' : ''}`;
    curtain.setAttribute('role', 'status');
    curtain.setAttribute('aria-live', 'polite');

    const disc = document.createElement('div');
    disc.className = 'auth-curtain__disc';
    disc.style.width = `${radius * 2}px`;
    disc.style.height = `${radius * 2}px`;
    disc.style.left = `${originX - radius}px`;
    disc.style.top = `${originY - radius}px`;

    const hello = document.createElement('div');
    hello.className = 'auth-curtain__hello';
    if (mascot) {
        const clone = mascot.cloneNode(true);
        clone.setAttribute('class', 'lici lici--wave');
        clone.setAttribute('aria-hidden', 'true');
        clone.setAttribute('width', '120');
        clone.setAttribute('height', '113');
        hello.appendChild(clone);
    }
    const title = document.createElement('p');
    title.className = 'auth-curtain__title';
    title.textContent = name ? `Hola, ${name}` : 'Hola';
    const subtitle = document.createElement('p');
    subtitle.className = 'auth-curtain__subtitle';
    subtitle.textContent = 'Preparando tu tablero…';
    hello.append(title, subtitle);
    curtain.append(disc, hello);
    document.body.appendChild(curtain);

    run(card, [{ transform: 'none', opacity: 1 }, { transform: 'translateY(-14px) scale(0.86)', opacity: 0 }], { duration: timing.cardOut, easing: 'cubic-bezier(0.4, 0, 1, 1)' });
    if (reduced) {
        run(disc, [{ opacity: 0 }, { opacity: 1 }], { duration: timing.disc, easing: 'linear' });
        hello.style.opacity = '1';
    } else {
        run(disc, [{ transform: 'scale(0.01)' }, { transform: 'scale(1)' }], { duration: timing.disc, delay: timing.discDelay, easing: 'cubic-bezier(0.7, 0, 0.3, 1)' });
        run(hello, [{ opacity: 0, transform: 'translateY(12px) scale(0.96)' }, { opacity: 1, transform: 'none' }], { duration: timing.hello, delay: timing.helloDelay, easing: 'cubic-bezier(0, 0, 0.2, 1)' });
    }

    let release;
    const ready = new Promise((resolve) => (release = resolve));
    const timer = window.setTimeout(() => release(), timing.ready);

    // Interrumpir: una tecla o un toque salta al final al instante.
    const skip = () => {
        animations.forEach((animation) => animation.finish());
        hello.style.opacity = '1';
        release();
    };
    window.addEventListener('keydown', skip, { once: true });
    window.addEventListener('pointerdown', skip, { once: true });

    let finished = false;
    function finish() {
        if (finished) return;
        finished = true;
        window.clearTimeout(timer);
        window.removeEventListener('keydown', skip);
        window.removeEventListener('pointerdown', skip);
        const out = curtain.animate([{ opacity: 1 }, { opacity: 0 }], { duration: timing.out, delay: reduced ? 0 : 120, easing: 'cubic-bezier(0.4, 0, 1, 1)', fill: 'forwards' });
        out.finished.catch(() => {}).finally(() => curtain.remove());
    }

    // Seguro: si el cambio de página no llega a avisar, la cortina no se queda puesta.
    window.setTimeout(finish, 6000);

    /** Si el ingreso falla después de empezar, la escena vuelve a su sitio. */
    function cancel() {
        animations.forEach((animation) => animation.cancel());
        finished = true;
        window.clearTimeout(timer);
        curtain.remove();
        release();
    }

    return { ready, finish, cancel };
}
