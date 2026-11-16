// Generadores de imagen para «Crear firma» (escrita con una fuente manuscrita) y «Crear sello»
// (circular o rectangular). Devuelven un <canvas> transparente y recortado, listo para exportar a PNG.
import { HANDWRITING_FONTS, trimCanvas } from '@/Modules/Signatures/ink.js';

const STAMP_FONT = 'Poppins, Inter, "Segoe UI", Arial, sans-serif';

/**
 * Firma escrita: `text` con la fuente manuscrita `font` (valor de HANDWRITING_FONTS), en `color`,
 * a `size` % (70–130) y con `slant` grados de inclinación (−10 a 25).
 */
export function renderTyped({ text, font, color, size = 100, slant = 8 }) {
    const value = String(text ?? '').trim();
    if (!value) return null;
    const meta = HANDWRITING_FONTS.find((item) => item.value === font) ?? HANDWRITING_FONTS[0];
    const px = Math.round(170 * meta.scale * (size / 100));
    const shear = Math.tan((slant * Math.PI) / 180);
    const probe = document.createElement('canvas').getContext('2d');
    probe.font = `${px}px "${meta.family}"`;
    const width = Math.ceil(probe.measureText(value).width);
    const canvas = document.createElement('canvas');
    canvas.width = Math.min(5800, width + Math.ceil(px * 1.2) + Math.ceil(Math.abs(shear) * px * 1.6));
    canvas.height = Math.ceil(px * 2.1);
    const ctx = canvas.getContext('2d');
    ctx.font = probe.font;
    ctx.fillStyle = color;
    ctx.textBaseline = 'alphabetic';
    const baseline = Math.round(px * 1.35);
    // Inclinación: se corta el eje x en función de y, alrededor de la línea base.
    ctx.setTransform(1, 0, -shear, 1, shear * baseline, 0);
    ctx.fillText(value, Math.round(px * 0.6), baseline);
    return trimCanvas(canvas, 16);
}

function fitFont(ctx, text, weight, start, min, maxWidth) {
    let size = start;
    ctx.font = `${weight} ${size}px ${STAMP_FONT}`;
    while (size > min && ctx.measureText(text).width > maxWidth) {
        size -= 2;
        ctx.font = `${weight} ${size}px ${STAMP_FONT}`;
    }
    return size;
}

/** Texto a lo largo de un arco: arriba se lee en sentido horario y abajo en antihorario. */
function arcText(ctx, text, radius, { bottom = false, maxAngle = Math.PI * 1.15, weight = 700, start = 64, min = 30, spacing = 0.12 } = {}) {
    let size = start;
    const measure = () => {
        ctx.font = `${weight} ${size}px ${STAMP_FONT}`;
        const widths = [...text].map((ch) => ctx.measureText(ch).width + size * spacing);
        return { widths, total: widths.reduce((sum, w) => sum + w, 0) / radius };
    };
    let { widths, total } = measure();
    while (total > maxAngle && size > min) {
        size -= 2;
        ({ widths, total } = measure());
    }
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    let angle = bottom ? Math.PI / 2 + total / 2 : -Math.PI / 2 - total / 2;
    [...text].forEach((ch, index) => {
        const step = widths[index] / radius;
        angle += bottom ? -step / 2 : step / 2;
        ctx.save();
        ctx.translate(radius * Math.cos(angle), radius * Math.sin(angle));
        ctx.rotate(bottom ? angle - Math.PI / 2 : angle + Math.PI / 2);
        ctx.fillText(ch, 0, 0);
        ctx.restore();
        angle += bottom ? -step / 2 : step / 2;
    });
    return total;
}

function star(ctx, x, y, r) {
    ctx.beginPath();
    for (let i = 0; i < 10; i += 1) {
        const radius = i % 2 === 0 ? r : r * 0.45;
        const angle = -Math.PI / 2 + (i * Math.PI) / 5;
        ctx.lineTo(x + radius * Math.cos(angle), y + radius * Math.sin(angle));
    }
    ctx.closePath();
    ctx.fill();
}

/** Parte un texto en líneas que caben en `maxWidth` con la fuente actual. */
function wrap(ctx, text, maxWidth, maxLines = 3) {
    const words = String(text ?? '').trim().split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';
    words.forEach((word) => {
        const next = line ? `${line} ${word}` : word;
        if (ctx.measureText(next).width > maxWidth && line) {
            lines.push(line);
            line = word;
        } else {
            line = next;
        }
    });
    if (line) lines.push(line);
    return lines.slice(0, maxLines);
}

/**
 * Sello: `shape` 'circle' | 'rect', `company` (nombre de la empresa), `taxId` (RTN), `text` (cargo o
 * leyenda, p. ej. «Gerencia General») y `color` de la tinta.
 */
export function renderStamp({ shape = 'circle', company = '', taxId = '', text = '', color }) {
    const name = String(company ?? '').trim().toUpperCase();
    const rtn = String(taxId ?? '').trim();
    const legend = String(text ?? '').trim().toUpperCase();
    if (!name && !legend) return null;
    return shape === 'rect' ? rectStamp(name, rtn, legend, color) : circleStamp(name, rtn, legend, color);
}

function circleStamp(name, rtn, legend, color) {
    const size = 1000;
    const c = size / 2;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = color;
    ctx.strokeStyle = color;
    ctx.translate(c, c);

    ctx.lineWidth = 16;
    ctx.beginPath();
    ctx.arc(0, 0, 470, 0, Math.PI * 2);
    ctx.stroke();
    ctx.lineWidth = 6;
    ctx.beginPath();
    ctx.arc(0, 0, 440, 0, Math.PI * 2);
    ctx.stroke();
    ctx.lineWidth = 6;
    ctx.beginPath();
    ctx.arc(0, 0, 300, 0, Math.PI * 2);
    ctx.stroke();

    const top = name ? arcText(ctx, name, 372, { maxAngle: rtn ? Math.PI * 1.2 : Math.PI * 1.75, start: 70, min: 30 }) : 0;
    const bottom = rtn ? arcText(ctx, `RTN ${rtn}`, 372, { bottom: true, maxAngle: Math.PI * 0.7, weight: 600, start: 54, min: 28, spacing: 0.08 }) : 0;
    if (name && rtn) {
        // Estrellas en el hueco entre los dos arcos de texto.
        const gap = (Math.PI * 2 - top - bottom) / 2;
        const right = -Math.PI / 2 + top / 2 + gap / 2;
        const left = -Math.PI / 2 - top / 2 - gap / 2;
        [right, left].forEach((angle) => star(ctx, 372 * Math.cos(angle), 372 * Math.sin(angle), 20));
    }

    if (legend) {
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = `700 64px ${STAMP_FONT}`;
        let lines = wrap(ctx, legend, 470);
        const sizeFit = Math.min(...lines.map((line) => fitFont(ctx, line, 700, 64, 30, 480)));
        ctx.font = `700 ${sizeFit}px ${STAMP_FONT}`;
        lines = wrap(ctx, legend, 480);
        const lineHeight = sizeFit * 1.18;
        lines.forEach((line, index) => ctx.fillText(line, 0, (index - (lines.length - 1) / 2) * lineHeight));
        ctx.lineWidth = 5;
        const edge = ((lines.length * lineHeight) / 2) + 22;
        [-edge, edge].forEach((y) => {
            ctx.beginPath();
            ctx.moveTo(-170, y);
            ctx.lineTo(170, y);
            ctx.stroke();
        });
    } else {
        star(ctx, 0, 0, 70);
    }
    return trimCanvas(canvas, 14);
}

function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

function rectStamp(name, rtn, legend, color) {
    const width = 1300;
    const height = 560;
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = color;
    ctx.strokeStyle = color;
    ctx.lineWidth = 14;
    roundRect(ctx, 20, 20, width - 40, height - 40, 36);
    ctx.stroke();
    ctx.lineWidth = 5;
    roundRect(ctx, 46, 46, width - 92, height - 92, 22);
    ctx.stroke();

    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    const inner = width - 180;
    const rows = [];
    if (name) rows.push({ text: name, size: fitFont(ctx, name, 800, 92, 36, inner), weight: 800 });
    if (legend) rows.push({ text: legend, size: fitFont(ctx, legend, 700, 64, 30, inner), weight: 700, rule: true });
    if (rtn) rows.push({ text: `RTN ${rtn}`, size: fitFont(ctx, `RTN ${rtn}`, 600, 54, 28, inner), weight: 600 });
    const gaps = 34;
    const total = rows.reduce((sum, row) => sum + row.size * 1.15, 0) + gaps * (rows.length - 1);
    let y = height / 2 - total / 2;
    rows.forEach((row, index) => {
        const lineHeight = row.size * 1.15;
        if (row.rule && index > 0) {
            ctx.lineWidth = 4;
            ctx.beginPath();
            ctx.moveTo(width / 2 - 220, y - gaps / 2);
            ctx.lineTo(width / 2 + 220, y - gaps / 2);
            ctx.stroke();
        }
        ctx.font = `${row.weight} ${row.size}px ${STAMP_FONT}`;
        ctx.fillText(row.text, width / 2, y + lineHeight / 2);
        y += lineHeight + gaps;
    });
    return trimCanvas(canvas, 14);
}
