// Tokens de movimiento del CRM. Amplían los de CAP (`--cap-duration-*`, `--cap-ease-*` en tokens.css)
// y son los mismos valores que `resources/css/motion.css` publica como `--m-*`. Cualquier animación
// en JS (Web Animations API) los toma de aquí; en CSS se usan las variables.

/** Duraciones en milisegundos. */
export const DURATION = {
    fast: 120, // hover, foco, salidas
    base: 180, // aparecer: menús, toasts, chips
    slow: 260, // paneles y cajones
    page: 260, // entrada del contenido de una página
    spring: 420, // ítems que se acomodan con resorte (kanban, indicadores)
    count: 900, // cifras que cuentan
    draw: 1100, // trazos de gráficas
    celebrate: 2600, // confeti
};

/** Curvas. `spring` pasa un poco del destino y vuelve: solo para cosas pequeñas que «llegan». */
export const EASE = {
    standard: 'cubic-bezier(0.2, 0, 0, 1)',
    enter: 'cubic-bezier(0, 0, 0.2, 1)',
    exit: 'cubic-bezier(0.4, 0, 1, 1)',
    expo: 'cubic-bezier(0.16, 1, 0.3, 1)',
    spring: 'cubic-bezier(0.34, 1.4, 0.64, 1)',
};

/** Distancias en píxeles. */
export const DISTANCE = { lift: 2, sm: 8, md: 14, lg: 24 };

/** Escalonado: paso entre hijos y cuántos se animan como máximo (el resto entra con el último). */
export const STAGGER = { step: 40, max: 12 };

/** easeOutExpo para interpolar cifras en JS. */
export const easeOutExpo = (t) => (t >= 1 ? 1 : 1 - 2 ** (-10 * t));
