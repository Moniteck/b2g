// Capa central de movimiento del CRM. Un único punto de entrada:
//
//   import motion from '@/lib/motion';        app.use(motion)  → directivas globales y transiciones de página
//   import { useCountUp, celebrate, play, reducedMotion } from '../lib/motion/index.js';
//
// Piezas:
//   tokens.js       duraciones, curvas, distancias y escalonado (los mismos que `--m-*` en motion.css)
//   preference.js   «Animaciones: completas / reducidas» + prefers-reduced-motion → <html data-motion>
//   animate.js      play() (WAAPI que respeta la preferencia), shake, pop, ring, highlight, flipFrom
//   directives.js   v-stagger, v-reveal, v-count, v-shake, v-draw, v-grow, v-magnetic, v-settle
//   useCountUp.js   cifra que cuenta (composable) y tween()
//   useMagnetic.js  atracción magnética hacia el puntero
//   confetti.js     celebrate(): confeti con los colores de la marca
//   navigation.js   salida/entrada entre páginas, indicador deslizante del menú, cabecera y campana
//
// Transiciones con nombre para <Transition name="…"> y <TransitionGroup name="…"> (en motion.css):
//   m-fade, m-rise, m-pop, m-slide-left, m-slide-right, m-slide-up, m-collapse, m-list
import { vCount, vDraw, vGrow, vMagnetic, vReveal, vSettle, vShake, vStagger } from './directives.js';
import { installPageTransitions } from './navigation.js';
import { applyMotionPreference } from './preference.js';

export * from './tokens.js';
export * from './preference.js';
export * from './animate.js';
export { celebrate } from './confetti.js';
export { useCountUp, tween } from './useCountUp.js';
export { useMagnetic, bindMagnetic } from './useMagnetic.js';
export { useShellMotion, ringBell, markSuccess, isPageVisit, captureIndicators, slideIndicators } from './navigation.js';
export { vCount, vDraw, vGrow, vMagnetic, vReveal, vSettle, vShake, vStagger };

export default {
    install(app) {
        applyMotionPreference();
        app.directive('stagger', vStagger);
        app.directive('reveal', vReveal);
        app.directive('count', vCount);
        app.directive('shake', vShake);
        app.directive('draw', vDraw);
        app.directive('grow', vGrow);
        app.directive('magnetic', vMagnetic);
        app.directive('settle', vSettle);
        installPageTransitions();
    },
};
