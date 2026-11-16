// Formato ligero y seguro para las respuestas del Agente Legal: primero escapa TODO el HTML (el texto
// es dato, venga del usuario o de la IA) y después reconoce negritas, cursivas, títulos, listas,
// citas («> texto») y menciones de artículos de ley («Artículo 131», «Art. 45»), que se resaltan.
const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ESCAPES[character]);
}

// «Artículo 131», «Arto. 12», «Art. 45-A», «artículos 3 y 4» (solo el número que sigue).
const ARTICLE = /\b(Art[íi]culos?|Arts?\.|Arto\.)\s*(\d+(?:\s*[-–]\s*[A-Z])?(?:\s*(?:,|y)\s*\d+)*)/gi;

function inline(text) {
    return text
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[\s(])\*(?!\s)(.+?)\*(?=[\s).,;:]|$)/g, '$1<em>$2</em>')
        .replace(ARTICLE, '<mark class="legal-cite">$1 $2</mark>');
}

/** Devuelve HTML con <p>, <ul>, <ol>, <blockquote>, <strong>, <em> y <mark>; nunca etiquetas del texto original. */
export function formatMessage(source) {
    const lines = escapeHtml(source).replace(/\r\n/g, '\n').split('\n');
    const blocks = [];
    let list = null;
    let paragraph = [];
    let quote = [];

    const flushParagraph = () => {
        if (paragraph.length) blocks.push(`<p>${paragraph.map(inline).join('<br>')}</p>`);
        paragraph = [];
    };
    const flushList = () => {
        if (list) blocks.push(`<${list.tag}>${list.items.map((item) => `<li>${inline(item)}</li>`).join('')}</${list.tag}>`);
        list = null;
    };
    const flushQuote = () => {
        if (quote.length) blocks.push(`<blockquote>${quote.map(inline).join('<br>')}</blockquote>`);
        quote = [];
    };
    const flushAll = () => {
        flushParagraph();
        flushList();
        flushQuote();
    };

    for (const raw of lines) {
        const line = raw.trim();
        // `>` ya llega escapado como `&gt;`.
        const quoted = line.match(/^&gt;\s?(.*)$/);
        const bullet = line.match(/^[-*•]\s+(.*)$/);
        const numbered = line.match(/^\d+[.)]\s+(.*)$/);
        const heading = line.match(/^#{1,6}\s+(.*)$/);

        if (!line) {
            flushAll();
        } else if (quoted) {
            flushParagraph();
            flushList();
            if (quoted[1]) quote.push(quoted[1]);
        } else if (heading) {
            flushAll();
            blocks.push(`<p class="legal-heading"><strong>${inline(heading[1].replace(/\*\*/g, ''))}</strong></p>`);
        } else if (bullet || numbered) {
            flushParagraph();
            flushQuote();
            const tag = bullet ? 'ul' : 'ol';
            if (list?.tag !== tag) {
                flushList();
                list = { tag, items: [] };
            }
            list.items.push((bullet ?? numbered)[1]);
        } else {
            flushList();
            flushQuote();
            paragraph.push(line);
        }
    }

    flushAll();
    return blocks.join('');
}

/** Texto plano sin marcas de formato, para copiar al portapapeles. */
export function plainText(source) {
    return String(source ?? '')
        .replace(/\*\*(.+?)\*\*/g, '$1')
        .replace(/^#{1,6}\s+/gm, '')
        .replace(/^>\s?/gm, '')
        .trim();
}
