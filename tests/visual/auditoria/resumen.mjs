// Resume los JSON de recorrido de la auditoría: estados, eventos, scroll horizontal, objetivos
// táctiles, textos cortados, inglés, fechas nativas y violaciones de axe agregadas.
//
//   node tests/visual/auditoria/resumen.mjs [archivo.json ...]
import { readdirSync, readFileSync } from 'node:fs';

const DIR = 'storage/app/qa/auditoria';
const files = process.argv.slice(2).length ? process.argv.slice(2) : readdirSync(DIR).filter((f) => f.startsWith('recorrido-') && !f.includes('parcial')).map((f) => `${DIR}/${f}`);

for (const file of files) {
    const data = JSON.parse(readFileSync(file, 'utf8'));
    const pages = data.pantallas.filter((p) => p.ruta);
    console.log(`\n##### ${file} (${pages.length} pantallas)`);
    const landing = data.pantallas.filter((p) => p.nombre === 'login-destino').map((p) => `${p.vista}-${p.tema}:${p.destino}`);
    console.log('Destino tras login:', landing.join(', '));
    const bad = pages.filter((p) => p.estado >= 400 || p.estado === 0);
    if (bad.length) console.log('Estados ≠ 2xx:', bad.map((p) => `${p.vista}/${p.nombre}=${p.estado}`).join(', '));
    const redirected = pages.filter((p) => p.final && p.final !== p.ruta);
    if (redirected.length) console.log('Redirecciones:', redirected.map((p) => `${p.ruta}→${p.final}`).join(', '));
    for (const p of pages) {
        const ev = (p.eventos ?? []).filter((e) => !/http-403/.test(e.tipo) || p.estado < 400);
        if (ev.length) console.log(`  EVENTOS ${p.vista}-${p.tema}/${p.nombre}: ${[...new Set(ev.map((e) => `${e.tipo} ${e.detalle.slice(0, 160).replace(/\s+/g, ' ')}`))].join(' || ')}`);
    }
    for (const p of pages) {
        if (p.overflowX > 1) console.log(`  SCROLL-X ${p.vista}-${p.tema}/${p.nombre}: ${p.overflowX}px`);
        if (p.fueraDePantallaTotal) console.log(`  FUERA ${p.vista}-${p.tema}/${p.nombre}: ${p.fueraDePantallaTotal} ${p.fueraDePantalla.slice(0, 4).join(' ; ')}`);
    }
    const touch = pages.filter((p) => p.tactilesChicosTotal);
    if (touch.length) {
        console.log('  TÁCTILES <44px (total por pantalla):', touch.map((p) => `${p.nombre}:${p.tactilesChicosTotal}`).join(', '));
        const examples = new Map();
        touch.forEach((p) => p.tactilesChicos.forEach((t) => examples.set(t.replace(/«[^»]*»/, '«…»').replace(/ \d+x\d+$/, ''), t)));
        console.log('  ejemplos:', [...examples.values()].slice(0, 25).join(' | '));
    }
    const cut = pages.filter((p) => p.cortadosTotal);
    if (cut.length) console.log('  CORTADOS:', cut.map((p) => `${p.nombre}:${p.cortados.slice(0, 3).join(' ; ')}`).join(' || '));
    const english = new Map();
    pages.forEach((p) => (p.ingles ?? []).forEach((t) => english.set(t, (english.get(t) ?? []).concat(p.nombre))));
    if (english.size) console.log('  INGLÉS:', [...english.entries()].map(([t, n]) => `«${t}» (${[...new Set(n)].slice(0, 4).join(',')})`).join(' | '));
    const dates = pages.filter((p) => p.fechasNativas).map((p) => `${p.nombre}:${p.fechasNativas}`);
    if (dates.length) console.log('  FECHAS NATIVAS:', dates.join(', '));
    const unnamed = pages.filter((p) => p.sinNombreTotal);
    if (unnamed.length) console.log('  SIN NOMBRE ACCESIBLE:', unnamed.map((p) => `${p.nombre}:${p.sinNombre.slice(0, 2).join(' ; ')}`).join(' || ').slice(0, 1500));
    const axe = new Map();
    pages.forEach((p) => (p.axe ?? []).forEach((v) => {
        const entry = axe.get(v.id) ?? { impacto: v.impacto, ayuda: v.ayuda, pantallas: [], nodos: 0, ejemplos: v.ejemplos };
        entry.pantallas.push(`${p.nombre}`);
        entry.nodos += v.nodos ?? 0;
        axe.set(v.id, entry);
    }));
    for (const [id, v] of axe) console.log(`  AXE ${id} [${v.impacto}] ${v.ayuda} — ${v.nodos} nodos en ${new Set(v.pantallas).size} pantallas (${[...new Set(v.pantallas)].slice(0, 6).join(',')}) ej: ${(v.ejemplos ?? []).slice(0, 2).join(' / ').slice(0, 300)}`);
    const slow = pages.filter((p) => p.ms > 8000).map((p) => `${p.nombre}:${p.ms}`);
    if (slow.length) console.log('  LENTAS (>8 s, servidor de desarrollo cargado):', slow.join(', '));
}
