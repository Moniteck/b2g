// Salida animada al iniciar sesión. En orden: los campos salen volando escalonados, la tarjeta se
// recoge hacia Lici, que salta y saluda; una onda expansiva de la marca sale de Lici y un disco con el
// degradado cubre la pantalla; dentro, «Hola, {nombre}» letra por letra con confeti. Tras el cambio de
// página el disco se cierra como un iris hacia Lici y deja ver el Dashboard.
// La cortina vive en <body> (fuera de Vue) para seguir visible mientras Inertia cambia de página.
//
//   const exit = playLoginExit({ card, mascot, name: 'Ana' });
//   await exit.ready;   // momento de cambiar de página (~0,95 s; antes si el usuario interrumpe)
//   exit.finish();      // tras el cambio: la cortina se abre y se retira
import { confetti, prefersReducedMotion } from './motion.js';

const FULL = { fields: 260, cardDelay: 110, card: 420, ring: 700, discDelay: 240, disc: 600, helloDelay: 560, ready: 950, out: 620 };
const REDUCED = { fields: 0, cardDelay: 0, card: 0, ring: 0, discDelay: 0, disc: 200, helloDelay: 0, ready: 420, out: 220 };

export function playLoginExit({ card, mascot, name }) {
    const reduced = prefersReducedMotion();
    const timing = reduced ? REDUCED : FULL;
    const animations = [];
    const run = (element, keyframes, options) => {
        if (!element?.animate) return null;
        const animation = element.animate(keyframes, { fill: 'both', ...options });
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

    const place = (element, size) => {
        element.style.width = `${size}px`;
        element.style.height = `${size}px`;
        element.style.left = `${originX - size / 2}px`;
        element.style.top = `${originY - size / 2}px`;
    };

    const disc = document.createElement('div');
    disc.className = 'auth-curtain__disc';
    place(disc, radius * 2);

    const ring = document.createElement('div');
    ring.className = 'auth-curtain__ring';
    place(ring, 120);

    const hello = document.createElement('div');
    hello.className = 'auth-curtain__hello';
    const avatar = document.createElement('div');
    avatar.className = 'auth-curtain__avatar';
    if (mascot) {
        const clone = mascot.cloneNode(true);
        clone.setAttribute('class', 'lici lici--wave');
        clone.setAttribute('aria-hidden', 'true');
        clone.setAttribute('width', '112');
        clone.setAttribute('height', '105');
        avatar.appendChild(clone);
    }
    const title = document.createElement('p');
    title.className = 'auth-curtain__title';
    const text = name ? `Hola, ${name}` : 'Hola';
    const letters = [...text].map((character) => {
        const span = document.createElement('span');
        span.className = 'auth-curtain__letter';
        span.textContent = character === ' ' ? ' ' : character;
        title.appendChild(span);
        return span;
    });
    title.setAttribute('aria-label', text);
    const subtitle = document.createElement('p');
    subtitle.className = 'auth-curtain__subtitle';
    subtitle.textContent = 'Preparando tu tablero…';
    hello.append(avatar, title, subtitle);
    curtain.append(ring, disc, hello);
    document.body.appendChild(curtain);

    if (reduced) {
        run(disc, [{ opacity: 0 }, { opacity: 1 }], { duration: timing.disc, easing: 'linear' });
        hello.style.opacity = '1';
    } else {
        const face = card?.querySelector('.auth-card__face:not([inert])');
        [...(face?.querySelectorAll('.auth-stagger > *') ?? [])].forEach((element, index) =>
            run(element, [{ transform: 'none', opacity: 1 }, { transform: 'translateY(-18px) scale(0.96)', opacity: 0 }], { duration: timing.fields, delay: index * 28, easing: 'cubic-bezier(0.4, 0, 1, 1)' }),
        );
        const cardRect = card?.getBoundingClientRect();
        const dx = cardRect ? originX - (cardRect.left + cardRect.width / 2) : 0;
        const dy = cardRect ? originY - (cardRect.top + cardRect.height / 2) : 0;
        run(card, [{ transform: 'none', opacity: 1 }, { transform: `translate(${dx}px, ${dy}px) scale(0.12) rotate(-8deg)`, opacity: 0 }], {
            duration: timing.card,
            delay: timing.cardDelay,
            easing: 'cubic-bezier(0.55, 0, 0.75, 0.2)',
        });
        run(ring, [{ transform: 'scale(0.2)', opacity: 0.9 }, { transform: 'scale(9)', opacity: 0 }], { duration: timing.ring, delay: timing.discDelay - 80, easing: 'cubic-bezier(0.1, 0.7, 0.3, 1)' });
        run(disc, [{ transform: 'scale(0.01)' }, { transform: 'scale(1)' }], { duration: timing.disc, delay: timing.discDelay, easing: 'cubic-bezier(0.7, 0, 0.3, 1)' });
        run(hello, [{ opacity: 0 }, { opacity: 1 }], { duration: 160, delay: timing.helloDelay, easing: 'linear' });
        run(avatar, [{ transform: 'scale(0.4) translateY(30px)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: 420, delay: timing.helloDelay, easing: 'cubic-bezier(0.3, 1.6, 0.5, 1)' });
        letters.forEach((letter, index) =>
            run(letter, [{ transform: 'translateY(0.7em) rotate(10deg)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: 380, delay: timing.helloDelay + 60 + index * 32, easing: 'cubic-bezier(0.3, 1.5, 0.5, 1)' }),
        );
        run(subtitle, [{ opacity: 0 }, { opacity: 0.85 }], { duration: 300, delay: timing.helloDelay + 260, easing: 'linear' });
        window.setTimeout(() => confetti(avatar, { count: 44, layer: curtain }), timing.helloDelay + 120);
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
        let out;
        if (reduced) {
            out = curtain.animate([{ opacity: 1 }, { opacity: 0 }], { duration: timing.out, easing: 'linear', fill: 'forwards' });
        } else {
            hello.animate([{ transform: 'none', opacity: 1 }, { transform: 'translateY(-24px) scale(1.05)', opacity: 0 }], { duration: 260, easing: 'cubic-bezier(0.4, 0, 1, 1)', fill: 'forwards' });
            out = disc.animate([{ transform: 'scale(1)' }, { transform: 'scale(0)' }], { duration: timing.out, delay: 120, easing: 'cubic-bezier(0.7, 0, 0.84, 0)', fill: 'both' });
        }
        out.finished.catch(() => {}).finally(() => curtain.remove());
    }

    // Seguro: si el cambio de página no llega a avisar, la cortina no se queda puesta.
    window.setTimeout(finish, 6000);

    return { ready, finish };
}
