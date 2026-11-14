// Errores de validación de Inertia/Laravel llevados a los campos.
// `errors` es el objeto de `form.errors`, `usePage().props.errors` o el `errors` de una respuesta 422
// (cada clave puede traer un texto o una lista de textos).

/** Mensaje del campo, o '' si no tiene. Acepta claves anidadas: fieldError(errors, 'items.0.price'). */
export function fieldError(errors, key) {
    const value = errors?.[key];
    if (!value) return '';
    return Array.isArray(value) ? String(value[0] ?? '') : String(value);
}

/** Errores de un grupo sin su prefijo: errorsFor(errors, 'items.0') → { price: '…', quantity: '…' }. */
export function errorsFor(errors, prefix) {
    const start = `${prefix}.`;
    return Object.fromEntries(
        Object.keys(errors ?? {})
            .filter((key) => key.startsWith(start))
            .map((key) => [key.slice(start.length), fieldError(errors, key)]),
    );
}

/** Lista de mensajes sin repetir, para el resumen de arriba (solo si hay más de tres). */
export function errorList(errors) {
    return [...new Set(Object.keys(errors ?? {}).map((key) => fieldError(errors, key)).filter(Boolean))];
}

export function hasErrors(errors) {
    return errorList(errors).length > 0;
}

/** Lleva el scroll y el foco al primer campo marcado con error dentro de `root`. Llamar en `onError`. */
export function focusFirstError(root = document) {
    requestAnimationFrame(() => {
        const field = root.querySelector('[aria-invalid="true"]');
        if (!field) return;
        field.scrollIntoView({ block: 'center', behavior: 'smooth' });
        field.focus({ preventScroll: true });
    });
}

const HTTP_MESSAGES = {
    401: 'Tu sesión terminó. Vuelve a ingresar para continuar.',
    403: 'No tienes permiso para hacer esto.',
    404: 'El registro ya no existe o no pertenece a la empresa activa.',
    409: 'Otra persona cambió este registro. Recarga la página y vuelve a intentarlo.',
    413: 'El archivo supera el tamaño que acepta el servidor.',
    419: 'La página estuvo abierta mucho tiempo. Recárgala y vuelve a intentarlo.',
    422: 'Revisa los campos marcados.',
    429: 'Demasiados intentos seguidos. Espera un momento.',
    500: 'El servidor no pudo completar la acción. Vuelve a intentarlo.',
    503: 'El sistema está en mantenimiento. Vuelve a intentarlo en unos minutos.',
};

/** Qué pasó y cómo seguir, según el código HTTP. */
export function httpErrorMessage(status) {
    return HTTP_MESSAGES[status] ?? 'No se pudo completar la acción. Vuelve a intentarlo.';
}
