// Utilidades de lienzo para crear la firma y el sello en el navegador: tintas, fuentes manuscritas,
// recorte del espacio vacío y exportación a PNG transparente.
//
// Las tintas son datos que se pintan en un <canvas> (la imagen exportada lleva su color real), no
// estilos de la interfaz: por eso aquí sí hay valores de color.

export const INKS = [
    { value: 'azul', label: 'Azul tinta', color: '#1f3a93' },
    { value: 'negro', label: 'Negro', color: '#16181d' },
];

export const STAMP_INKS = [
    { value: 'azul', label: 'Azul', color: '#1f3a93' },
    { value: 'morado', label: 'Morado', color: '#5b2a86' },
    { value: 'rojo', label: 'Rojo', color: '#b3202a' },
    { value: 'verde', label: 'Verde', color: '#1d6b45' },
    { value: 'negro', label: 'Negro', color: '#16181d' },
];

export function inkColor(value, list = INKS) {
    return (list.find((ink) => ink.value === value) ?? list[0]).color;
}

// Fuentes manuscritas con licencia OFL alojadas en ./fonts (ver ./fonts/OFL.txt). `scale` iguala el
// tamaño aparente entre fuentes de proporciones muy distintas.
export const HANDWRITING_FONTS = [
    { value: 'great-vibes', label: 'Clásica', family: 'Firma Great Vibes', file: 'great-vibes', scale: 1 },
    { value: 'allura', label: 'Elegante', family: 'Firma Allura', file: 'allura', scale: 1.02 },
    { value: 'sacramento', label: 'Fina', family: 'Firma Sacramento', file: 'sacramento', scale: 1.08 },
    { value: 'herr-von-muellerhoff', label: 'Rápida', family: 'Firma Herr Von Muellerhoff', file: 'herr-von-muellerhoff', scale: 1.12 },
    { value: 'dancing-script', label: 'Redonda', family: 'Firma Dancing Script', file: 'dancing-script', scale: 0.82 },
    { value: 'caveat', label: 'Natural', family: 'Firma Caveat', file: 'caveat', scale: 0.86 },
];

const FONT_URLS = {
    'great-vibes': () => import('@/Pages/Signatures/Partials/fonts/great-vibes.woff2?url'),
    allura: () => import('@/Pages/Signatures/Partials/fonts/allura.woff2?url'),
    sacramento: () => import('@/Pages/Signatures/Partials/fonts/sacramento.woff2?url'),
    'herr-von-muellerhoff': () => import('@/Pages/Signatures/Partials/fonts/herr-von-muellerhoff.woff2?url'),
    'dancing-script': () => import('@/Pages/Signatures/Partials/fonts/dancing-script.woff2?url'),
    caveat: () => import('@/Pages/Signatures/Partials/fonts/caveat.woff2?url'),
};

let fontsPromise = null;

/** Carga las fuentes manuscritas una sola vez, al abrir la modal (no con la página). */
export function loadHandwritingFonts() {
    if (fontsPromise) return fontsPromise;
    fontsPromise = Promise.all(
        HANDWRITING_FONTS.map(async (font) => {
            const { default: url } = await FONT_URLS[font.file]();
            const face = new FontFace(font.family, `url(${url}) format('woff2')`, { display: 'block' });
            await face.load();
            document.fonts.add(face);
            return font.value;
        }),
    ).catch((error) => {
        fontsPromise = null;
        throw error;
    });
    return fontsPromise;
}

/**
 * Recorta el espacio transparente alrededor del dibujo y deja `padding` px de margen.
 * Devuelve un lienzo nuevo, o null si el lienzo está vacío.
 */
export function trimCanvas(source, padding = 12) {
    const { width, height } = source;
    const data = source.getContext('2d').getImageData(0, 0, width, height).data;
    let top = height;
    let left = width;
    let right = -1;
    let bottom = -1;
    for (let y = 0; y < height; y += 1) {
        const row = y * width * 4;
        for (let x = 0; x < width; x += 1) {
            if (data[row + x * 4 + 3] > 8) {
                if (x < left) left = x;
                if (x > right) right = x;
                if (y < top) top = y;
                bottom = y;
            }
        }
    }
    if (right < 0) return null;
    const x = Math.max(0, left - padding);
    const y = Math.max(0, top - padding);
    const w = Math.min(width, right + padding + 1) - x;
    const h = Math.min(height, bottom + padding + 1) - y;
    const out = document.createElement('canvas');
    out.width = Math.max(w, 20);
    out.height = Math.max(h, 20);
    out.getContext('2d').drawImage(source, x, y, w, h, Math.round((out.width - w) / 2), Math.round((out.height - h) / 2), w, h);
    return out;
}

/** Reduce el lienzo si algún lado supera `max` px (el servidor acepta hasta 6000 y 2 MB). */
export function fitCanvas(source, max = 2400) {
    const ratio = Math.min(1, max / Math.max(source.width, source.height));
    if (ratio === 1) return source;
    const out = document.createElement('canvas');
    out.width = Math.round(source.width * ratio);
    out.height = Math.round(source.height * ratio);
    const ctx = out.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(source, 0, 0, out.width, out.height);
    return out;
}

/** Convierte el lienzo en un archivo PNG listo para el mismo endpoint de subida. */
export function canvasToFile(canvas, name) {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => (blob ? resolve(new File([blob], name, { type: 'image/png' })) : reject(new Error('No se pudo generar la imagen.'))), 'image/png');
    });
}

/**
 * Quita el fondo claro de una foto de firma (papel blanco): los píxeles claros se vuelven
 * transparentes y el trazo conserva su color. Devuelve un lienzo nuevo.
 */
export function removeLightBackground(image, { threshold = 200, softness = 45 } = {}) {
    const canvas = document.createElement('canvas');
    canvas.width = image.naturalWidth || image.width;
    canvas.height = image.naturalHeight || image.height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(image, 0, 0);
    const frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const px = frame.data;
    for (let i = 0; i < px.length; i += 4) {
        const luminance = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
        const keep = Math.max(0, Math.min(1, (threshold + softness - luminance) / softness));
        px[i + 3] = Math.round(px[i + 3] * keep);
    }
    ctx.putImageData(frame, 0, 0);
    return canvas;
}

/** Carga una imagen desde una URL o un File. */
export function loadImage(source) {
    return new Promise((resolve, reject) => {
        const url = source instanceof Blob ? URL.createObjectURL(source) : source;
        const image = new Image();
        image.onload = () => {
            if (source instanceof Blob) URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => reject(new Error('No se pudo leer la imagen.'));
        image.src = url;
    });
}
