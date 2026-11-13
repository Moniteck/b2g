// Formato ligero y seguro para las respuestas del Agente Legal: primero escapa TODO el HTML (el texto
// es dato, venga del usuario o de la IA) y después reconoce negritas, títulos y listas simples.
const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ESCAPES[character]);
}

function inline(text) {
    return text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/(^|[\s(])\*(?!\s)(.+?)\*(?=[\s).,;:]|$)/g, '$1<em>$2</em>');
}

/** Devuelve HTML con <p>, <ul>, <ol>, <strong> y <em>; nunca etiquetas del texto original. */
export function formatMessage(source) {
    const lines = escapeHtml(source).replace(/\r\n/g, '\n').split('\n');
    const blocks = [];
    let list = null;
    let paragraph = [];

    const flushParagraph = () => {
        if (paragraph.length) blocks.push(`<p>${paragraph.map(inline).join('<br>')}</p>`);
        paragraph = [];
    };
    const flushList = () => {
        if (list) blocks.push(`<${list.tag}>${list.items.map((item) => `<li>${inline(item)}</li>`).join('')}</${list.tag}>`);
        list = null;
    };

    for (const raw of lines) {
        const line = raw.trim();
        const bullet = line.match(/^[-*•]\s+(.*)$/);
        const numbered = line.match(/^\d+[.)]\s+(.*)$/);
        const heading = line.match(/^#{1,6}\s+(.*)$/);

        if (!line) {
            flushParagraph();
            flushList();
        } else if (heading) {
            flushParagraph();
            flushList();
            blocks.push(`<p><strong>${inline(heading[1].replace(/\*\*/g, ''))}</strong></p>`);
        } else if (bullet || numbered) {
            flushParagraph();
            const tag = bullet ? 'ul' : 'ol';
            if (list?.tag !== tag) {
                flushList();
                list = { tag, items: [] };
            }
            list.items.push((bullet ?? numbered)[1]);
        } else {
            flushList();
            paragraph.push(line);
        }
    }

    flushParagraph();
    flushList();
    return blocks.join('');
}

/** Texto plano sin marcas de formato, para copiar al portapapeles. */
export function plainText(source) {
    return String(source ?? '').replace(/\*\*(.+?)\*\*/g, '$1').replace(/^#{1,6}\s+/gm, '').trim();
}
