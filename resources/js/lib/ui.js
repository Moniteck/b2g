// Clases del recetario (resources/design/DESIGN_GUIDE.md) usadas por varios componentes.
// Se copian tal cual de la guía; no se editan aquí sin que cambie la guía.

export const focusRing =
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-cap-card dark:focus-visible:ring-gray-100';

export const inputBase =
    'block w-full rounded-cap-control border bg-cap-card px-3 text-sm text-gray-900 placeholder:text-gray-500 transition-colors duration-cap-fast ease-cap focus:outline-none focus:ring-1 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-100 dark:placeholder:text-gray-400';

export const inputNormal = 'border-cap-input-line focus:border-cap-chrome focus:ring-cap-chrome dark:focus:border-gray-100 dark:focus:ring-gray-100';

export const inputInvalid = 'border-cap-status-error focus:border-cap-status-error focus:ring-cap-status-error dark:border-cap-status-dark-error';

export const inputReadonly = 'border-transparent bg-cap-card-2 focus:border-transparent focus:ring-0';

/** Clases del input según su estado. */
export function inputClass({ invalid = false, readonly = false } = {}) {
    if (readonly) return `${inputBase} ${inputReadonly}`.replace('bg-cap-card ', '');
    return `${inputBase} ${invalid ? inputInvalid : inputNormal}`;
}

export const iconButton =
    'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-cap-text-soft transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-card disabled:cursor-not-allowed disabled:opacity-50 dark:hover:text-gray-100 dark:focus-visible:ring-gray-100';

export const card = 'rounded-cap-card border border-cap-divider bg-cap-card';

export const tableLink = 'font-medium text-cap-brand hover:underline dark:text-cap-brand-soft';

export const textLink = 'underline underline-offset-2 decoration-gray-400 hover:decoration-current';

// Elevación 1: dropdown, menú ⋮, popover, combo.
export const floatingPanel = 'rounded-cap-control border border-cap-divider bg-cap-card shadow-md dark:bg-cap-card-2';

export const menuItem =
    'flex h-10 w-full items-center gap-2 px-3 text-left text-sm text-gray-800 transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:bg-cap-card-2 dark:text-gray-100 dark:hover:bg-cap-card dark:focus-visible:bg-cap-card';

export const menuItemDanger =
    'flex h-10 w-full items-center gap-2 px-3 text-left text-sm text-cap-status-error-ink transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:bg-cap-card-2 dark:hover:bg-cap-card dark:focus-visible:bg-cap-card';
