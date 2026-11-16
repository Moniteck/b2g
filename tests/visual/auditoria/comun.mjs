// Utilidades compartidas de la auditoría (Playwright): sesión, contextos por vista y tema, medición
// de problemas de interfaz (scroll horizontal, objetivos táctiles, textos cortados, inglés) y axe.
//
// Variables: QA_URL (http://127.0.0.1:8000), QA_AXE (ruta local de axe.min.js; sin ella se usa el CDN).
import { mkdirSync } from 'node:fs';

export const BASE_URL = process.env.QA_URL ?? 'http://127.0.0.1:8000';
export const OUTPUT = 'storage/app/qa/auditoria';
export const AXE_URL = 'https://cdn.jsdelivr.net/npm/axe-core@4.10.2/axe.min.js';

export const USERS = {
    admin: { email: 'qa.admin@crm-b2g.test', password: 'QaB2g#2026' },
    lector: { email: 'qa.lector@crm-b2g.test', password: 'QaB2g#2026' },
    operador: { email: 'qa.operador@crm-b2g.test', password: 'QaB2g#2026' },
    raiz: { email: 'root@crm-b2g.test', password: 'Crm#3db83782' },
    qaroot: { email: 'qa.root@crm-b2g.test', password: 'QaB2g#2026' },
};

export const VIEWPORTS = {
    d1440: { width: 1440, height: 900 },
    d1280: { width: 1280, height: 720 },
    t1024: { width: 1024, height: 768, touch: true },
    t768: { width: 768, height: 1024, touch: true },
    m390: { width: 390, height: 844, touch: true },
    m360: { width: 360, height: 740, touch: true },
};

export function dir(...parts) {
    const path = [OUTPUT, ...parts].join('/');
    mkdirSync(path, { recursive: true });
    return path;
}

export async function openContext(browser, viewport, theme) {
    const size = VIEWPORTS[viewport];
    const context = await browser.newContext({
        viewport: { width: size.width, height: size.height },
        deviceScaleFactor: 1,
        isMobile: Boolean(size.touch) && size.width < 800,
        hasTouch: Boolean(size.touch),
        locale: 'es-HN',
        timezoneId: 'America/Tegucigalpa',
    });
    await context.addInitScript((value) => {
        try {
            localStorage.setItem('theme', value);
        } catch {
            // Sin almacenamiento, el tema sale del sistema.
        }
    }, theme === 'oscuro' ? 'dark' : 'light');
    return context;
}

export async function login(page, user) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', user.email);
    await page.fill('#login-password', user.password);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 25000 }), page.click('button[type="submit"]')]);
}

export async function settle(page, extra = 900) {
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    await page.evaluate(() => document.fonts.ready).catch(() => {});
    await page.waitForTimeout(extra);
}

/** Escucha consola, excepciones y respuestas 4xx/5xx del mismo origen. */
export function watch(page, sink, label) {
    page.on('console', (message) => {
        if (message.type() === 'error' || message.type() === 'warning') {
            const text = message.text();
            if (/Download the Vue Devtools|\[vite\]/.test(text)) return;
            sink.push({ label: label(), tipo: `consola-${message.type()}`, detalle: text.slice(0, 400) });
        }
    });
    page.on('pageerror', (error) => sink.push({ label: label(), tipo: 'excepcion', detalle: error.message.slice(0, 400) }));
    page.on('response', (response) => {
        const url = response.url();
        if (!url.startsWith(BASE_URL) || response.status() < 400) return;
        sink.push({ label: label(), tipo: `http-${response.status()}`, detalle: `${response.request().method()} ${url.replace(BASE_URL, '')}` });
    });
}

/** Mide problemas visibles de la pantalla actual. */
export async function measure(page, { touch }) {
    return page.evaluate(({ touch }) => {
        const vw = window.innerWidth;
        const doc = document.documentElement;
        const visible = (el) => {
            const r = el.getBoundingClientRect();
            const s = getComputedStyle(el);
            return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) > 0.05;
        };
        const describe = (el) => {
            const text = (el.getAttribute('aria-label') || el.innerText || el.getAttribute('title') || el.value || '').trim().replace(/\s+/g, ' ').slice(0, 40);
            const cls = (el.className && typeof el.className === 'string' ? el.className.split(' ').slice(0, 2).join('.') : '');
            return `${el.tagName.toLowerCase()}${cls ? '.' + cls : ''}«${text}»`;
        };
        const result = { overflowX: doc.scrollWidth - vw, fueraDePantalla: [], tactilesChicos: [], cortados: [], ingles: [], fechasNativas: 0, sinNombre: [] };

        const interactive = [...document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button], [role=tab], [role=menuitem], summary')].filter(visible);
        for (const el of interactive) {
            const r = el.getBoundingClientRect();
            if (r.right > vw + 1 && !el.closest('[data-scroll-x], .overflow-x-auto, .overflow-auto, table')) result.fueraDePantalla.push(describe(el));
            if (touch && (r.width < 44 || r.height < 44)) {
                // Inputs de casilla dentro de label se miden por la etiqueta.
                const host = el.closest('label');
                const hr = host ? host.getBoundingClientRect() : r;
                if (hr.width < 44 || hr.height < 44) result.tactilesChicos.push(`${describe(el)} ${Math.round(r.width)}x${Math.round(r.height)}`);
            }
            const name = (el.getAttribute('aria-label') || el.innerText || el.getAttribute('title') || el.getAttribute('placeholder') || (el.labels && el.labels[0]?.innerText) || el.getAttribute('aria-labelledby') || '').trim();
            if (!name && el.tagName !== 'INPUT') result.sinNombre.push(describe(el) + ' ' + el.outerHTML.slice(0, 120));
        }
        result.fechasNativas = document.querySelectorAll('input[type=date], input[type=datetime-local], input[type=month], input[type=time]').length;

        // Texto cortado sin puntos suspensivos (clip con overflow hidden).
        for (const el of document.querySelectorAll('main *, header *, nav *')) {
            if (!el.children.length && visible(el) && el.innerText && el.innerText.trim().length > 2) {
                const s = getComputedStyle(el);
                if ((el.scrollWidth > el.clientWidth + 2) && (s.overflow === 'hidden' || s.overflowX === 'hidden') && s.textOverflow !== 'ellipsis') result.cortados.push(describe(el));
                const r = el.getBoundingClientRect();
                if (r.right > vw + 2 && !el.closest('.overflow-x-auto, .overflow-auto, table, [data-scroll-x]')) result.fueraDePantalla.push('texto ' + describe(el));
            }
        }

        // Textos visibles en inglés (palabras de interfaz típicas).
        const english = /\b(Previous|Next|Showing|Search|No results|Loading|Submit|Cancel|Save|Delete|Edit|Close|Results|of \d+|per page|Select|Upload|Download|Required|Invalid|Error|Success|Warning|Settings|Sign in|Log out|Logout|Back|Filter|Clear|Apply|Actions?)\b/;
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const seen = new Set();
        while (walker.nextNode()) {
            const node = walker.currentNode;
            const text = node.textContent.trim();
            if (!text || text.length > 80 || !node.parentElement || !visible(node.parentElement)) continue;
            if (node.parentElement.closest('script, style, code, pre, .mdi')) continue;
            const match = text.match(english);
            if (match && !seen.has(text)) {
                seen.add(text);
                result.ingles.push(text.slice(0, 60));
            }
        }
        for (const key of ['fueraDePantalla', 'tactilesChicos', 'cortados', 'ingles', 'sinNombre']) {
            result[`${key}Total`] = result[key].length;
            result[key] = [...new Set(result[key])].slice(0, 12);
        }
        return result;
    }, { touch });
}

/** Ejecuta axe (WCAG 2.1 AA) y devuelve las violaciones resumidas. */
export async function axe(page) {
    if (!(await page.evaluate(() => Boolean(window.axe)))) {
        await page.addScriptTag(process.env.QA_AXE ? { path: process.env.QA_AXE } : { url: AXE_URL });
    }
    const violations = await page.evaluate(async () => {
        const result = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] }, resultTypes: ['violations'] });
        return result.violations.map((v) => ({ id: v.id, impacto: v.impact, ayuda: v.help, nodos: v.nodes.length, ejemplos: v.nodes.slice(0, 3).map((n) => `${n.target.join(' ')} ${(n.any[0]?.message ?? '').slice(0, 140)}`) }));
    });
    return violations;
}
