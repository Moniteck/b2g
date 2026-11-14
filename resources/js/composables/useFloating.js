// Posiciona un panel flotante (menú, combo, tooltip) pegado a su ancla, con `position: fixed`.
// El panel se teletransporta a la modal que lo contiene (o a <body>) para que ningún contenedor
// con scroll lo recorte, y se voltea hacia arriba si no cabe debajo.
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const MARGIN = 8;

export function useFloating(anchorRef, floatingRef, open, { placement = 'bottom-start', offset = 4, matchWidth = false } = {}) {
    const style = ref({ position: 'fixed', top: '0px', left: '0px', visibility: 'hidden' });
    const teleportTarget = ref('body');

    function update() {
        const anchor = anchorRef.value;
        const floating = floatingRef.value;
        if (!anchor || !floating) return;
        const box = anchor.getBoundingClientRect();
        const width = matchWidth ? box.width : floating.offsetWidth;
        const height = floating.offsetHeight;
        const [side, align] = placement.split('-');

        let top = side === 'top' ? box.top - height - offset : box.bottom + offset;
        if (side !== 'top' && top + height > window.innerHeight - MARGIN && box.top - height - offset >= MARGIN) top = box.top - height - offset;
        if (side === 'top' && top < MARGIN) top = box.bottom + offset;

        let left = box.left;
        if (align === 'end') left = box.right - width;
        if (!align) left = box.left + box.width / 2 - width / 2;
        left = Math.max(MARGIN, Math.min(left, window.innerWidth - width - MARGIN));
        top = Math.max(MARGIN, Math.min(top, window.innerHeight - height - MARGIN));

        style.value = {
            position: 'fixed',
            top: `${Math.round(top)}px`,
            left: `${Math.round(left)}px`,
            ...(matchWidth ? { width: `${Math.round(width)}px` } : {}),
        };
    }

    function listen(add) {
        const method = add ? 'addEventListener' : 'removeEventListener';
        window[method]('scroll', update, true);
        window[method]('resize', update);
    }

    watch(
        () => open.value,
        async (isOpen) => {
            listen(false);
            if (!isOpen) return;
            style.value = { ...style.value, visibility: 'hidden' };
            await nextTick();
            update();
            listen(true);
        },
        { flush: 'post' },
    );

    // Dentro de una modal el panel vive en ella: así queda por encima de su contenido.
    onMounted(() => {
        teleportTarget.value = anchorRef.value?.closest('[data-floating-root]') ?? 'body';
    });

    onBeforeUnmount(() => listen(false));

    return { style, teleportTarget, update };
}

/** Cierra al pulsar fuera del ancla y del panel. Devuelve el liberador. */
export function onPointerOutside(elements, handler) {
    const onPointerDown = (event) => {
        if (elements().some((element) => element?.contains(event.target))) return;
        handler(event);
    };
    document.addEventListener('pointerdown', onPointerDown, true);
    return () => document.removeEventListener('pointerdown', onPointerDown, true);
}
