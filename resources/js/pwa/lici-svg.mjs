// Lici en SVG estático (sin animaciones) para los íconos de la app y la página sin conexión.
// Mismo dibujo que `Modules/Auth/Mascot.vue`, con los colores de `resources/design/tokens.json`
// escritos en el SVG (los PNG y el favicon no pueden leer variables CSS).
//
//   liciSvg()                          → solo el búho, fondo transparente (viewBox 160×150)
//   iconSvg({ size: 512, maskable })   → ícono cuadrado con fondo del marco
//   liciSvg({ state: 'offline' })      → con la mirada baja y una gota de sudor (sin conexión)
import tokens from '../../design/tokens.json' with { type: 'json' };

const red = tokens.brand.redScale;
const C = {
    tuft: red['800'],
    body: red['600'],
    belly: red['100'],
    feather: red['200'],
    tie: tokens.brand.wine,
    knot: red['800'],
    taupe: tokens.brand.taupe,
    disc: red['50'],
    white: tokens.chrome.onAccent,
    pupil: tokens.chrome.surface,
    glasses: tokens.brand.wine,
    wing: red['800'],
    wingFeather: red['600'],
    cheek: red['400'],
    sweat: tokens.status.light.info,
    chrome: tokens.chrome.surface,
};

/** Cuerpo del búho en coordenadas del viewBox 0 0 160 150. */
export function liciShapes({ state = 'idle', shadow = false } = {}) {
    const offline = state === 'offline';
    const pupilDy = offline ? 3.2 : 0;
    return `
  ${shadow ? '<ellipse cx="80" cy="145" rx="40" ry="4" fill="#1E1B1A" opacity=".12"/>' : ''}
  <path d="M42 58 L30 20 L64 46 Z" fill="${C.tuft}"/>
  <path d="M118 58 L130 20 L96 46 Z" fill="${C.tuft}"/>
  <ellipse cx="80" cy="92" rx="54" ry="51" fill="${C.body}"/>
  <ellipse cx="80" cy="112" rx="33" ry="30" fill="${C.belly}"/>
  <path d="M68 108 q4 4 8 0 M84 108 q4 4 8 0 M76 120 q4 4 8 0" fill="none" stroke="${C.feather}" stroke-width="2.2" stroke-linecap="round"/>
  <path d="M80 101 L68 94 L68 108 Z M80 101 L92 94 L92 108 Z" fill="${C.tie}"/>
  <circle cx="80" cy="101" r="3.2" fill="${C.knot}"/>
  <ellipse cx="64" cy="141" rx="9" ry="4.5" fill="${C.taupe}"/>
  <ellipse cx="96" cy="141" rx="9" ry="4.5" fill="${C.taupe}"/>
  <circle cx="60" cy="70" r="23" fill="${C.disc}"/>
  <circle cx="100" cy="70" r="23" fill="${C.disc}"/>
  <circle cx="60" cy="70" r="13.5" fill="${C.white}"/>
  <circle cx="100" cy="70" r="13.5" fill="${C.white}"/>
  <g transform="translate(0 ${pupilDy})">
    <circle cx="60" cy="71" r="6.5" fill="${C.pupil}"/>
    <circle cx="100" cy="71" r="6.5" fill="${C.pupil}"/>
    <circle cx="62.5" cy="68" r="2.1" fill="${C.white}"/>
    <circle cx="102.5" cy="68" r="2.1" fill="${C.white}"/>
  </g>
  ${offline ? `<path d="M46.5 60 h27 M86.5 60 h27" stroke="${C.body}" stroke-width="9" stroke-linecap="round"/>` : ''}
  <g fill="none" stroke="${C.glasses}" stroke-width="3.6" stroke-linecap="round">
    <circle cx="60" cy="70" r="17"/>
    <circle cx="100" cy="70" r="17"/>
    <path d="M77 69 q3 -5 6 0 M43 66 L36 62 M117 66 L124 62"/>
  </g>
  <path d="M50 60 q4 -4 9 -4 M90 60 q4 -4 9 -4" fill="none" stroke="${C.white}" stroke-width="2" stroke-linecap="round" opacity=".7"/>
  ${offline ? `<path d="M45 49 L67 55 M115 49 L93 55" fill="none" stroke="${C.tuft}" stroke-width="3.4" stroke-linecap="round"/>` : ''}
  <path d="M73 84 Q80 80 87 84 L80 97 Z" fill="${C.taupe}"/>
  ${offline ? `<path d="M128 46 q5 8 0 11 q-5 -3 0 -11 Z" fill="${C.sweat}" opacity=".85"/>` : ''}
  <g transform="translate(30 90) rotate(110) scale(.62 .55) translate(-30 -90)">
    <path d="M28 82 C29 58 48 42 72 41 C83 41 92 45 98 52 L91 57 L99 63 L91 68 L97 75 L88 78 L91 85 L80 86 C68 95 50 101 34 102 C26 101 24 92 28 82 Z" fill="${C.wing}"/>
    <path d="M46 58 C58 52 72 52 86 56 M42 72 C56 66 72 66 88 69 M42 86 C56 80 70 80 82 81" fill="none" stroke="${C.wingFeather}" stroke-width="2.4" stroke-linecap="round"/>
  </g>
  <g transform="translate(130 90) rotate(-110) scale(.62 .55) translate(-130 -90)">
    <path d="M132 82 C131 58 112 42 88 41 C77 41 68 45 62 52 L69 57 L61 63 L69 68 L63 75 L72 78 L69 85 L80 86 C92 95 110 101 126 102 C134 101 136 92 132 82 Z" fill="${C.wing}"/>
    <path d="M114 58 C102 52 88 52 74 56 M118 72 C104 66 88 66 72 69 M118 86 C104 80 90 80 78 81" fill="none" stroke="${C.wingFeather}" stroke-width="2.4" stroke-linecap="round"/>
  </g>`;
}

export function liciSvg({ state = 'idle', width = 160, label = 'Lici, el búho del CRM' } = {}) {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 150" width="${width}" height="${Math.round(width * 0.9375)}" role="img" aria-label="${label}">${liciShapes({ state, shadow: true })}
</svg>`;
}

/**
 * Ícono cuadrado: fondo del marco (chrome) y Lici centrado.
 * `maskable`: fondo a sangre y Lici dentro de la zona segura (círculo del 80 %).
 * `rounded`: esquinas redondeadas (favicon y ícono «any»).
 */
export function iconSvg({ size = 512, maskable = false, rounded = !maskable } = {}) {
    // Caja del búho: x 24…136, y 20…146 (centro 80, 83).
    const scale = (size / 160) * (maskable ? 0.74 : 0.92);
    const tx = size / 2 - 80 * scale;
    const ty = size / 2 - 84 * scale + size * 0.015;
    const radius = rounded ? Math.round(size * 0.22) : 0;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="${size}" height="${size}">
  <rect width="${size}" height="${size}" rx="${radius}" fill="${C.chrome}"/>
  <g transform="translate(${tx.toFixed(2)} ${ty.toFixed(2)}) scale(${scale.toFixed(4)})">${liciShapes()}</g>
</svg>`;
}

export const colors = C;
