// Subconjunto de Material Design Icons: solo los íconos que usa el sistema.
//
//   node scripts/mdi-subset.mjs           genera resources/fonts/mdi/mdi-subset.woff y resources/css/mdi.css
//   node scripts/mdi-subset.mjs --check   solo verifica que todo ícono usado esté en el subconjunto (sale 1 si falta)
//
// La hoja completa de @mdi/font pesa ~58 KB de CSS y 394 KB de fuente (7.448 íconos); el sistema usa
// unos cientos. Este script, sin dependencias:
//   1. Escanea resources/, app/ y config/ buscando `mdi-nombre` y cualquier cadena entre comillas que
//      sea un nombre de ícono (cubre los nombres dinámicos: `icon: 'gavel'` + `mdi-${icon}`), más la
//      variante rellena de cada `*-outline` (el menú la usa en el ítem activo).
//   2. Recorta la fuente TrueType (glyf/loca/hmtx/cmap 12) a esos glifos y la empaqueta como WOFF.
//   3. Escribe el CSS con la @font-face, las reglas base de `.mdi` y una regla por ícono usado.
// Después de agregar un ícono nuevo al código, vuelve a ejecutarlo (la prueba
// tests/Feature/Shell/IconSubsetTest.php falla si falta alguno).
import { readFileSync, readdirSync, statSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, extname } from 'node:path';
import { deflateSync } from 'node:zlib';

const ROOT = new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const MDI = join(ROOT, 'node_modules/@mdi/font');
const OUT_FONT = join(ROOT, 'resources/fonts/mdi/mdi-subset.woff');
const OUT_CSS = join(ROOT, 'resources/css/mdi.css');
const SCAN = ['resources/js', 'resources/design', 'resources/views', 'resources/css', 'app', 'config'];
const EXTENSIONS = new Set(['.vue', '.js', '.mjs', '.php', '.css', '.json', '.html']);
const CHECK = process.argv.includes('--check');

// ───────── 1. Íconos de la hoja completa y los que usa el código ─────────
const fullCss = readFileSync(join(MDI, 'css/materialdesignicons.css'), 'utf8');
const icons = new Map();
for (const match of fullCss.matchAll(/\.mdi-([a-z0-9-]+)::before\s*\{\s*content:\s*"\\([0-9A-F]+)";\s*\}/g)) icons.set(match[1], parseInt(match[2], 16));

function* files(dir) {
    let entries = [];
    try {
        entries = readdirSync(dir);
    } catch {
        return;
    }
    for (const entry of entries) {
        const path = join(dir, entry);
        if (entry === 'node_modules' || entry === 'mdi.css') continue;
        if (statSync(path).isDirectory()) yield* files(path);
        else if (EXTENSIONS.has(extname(entry))) yield path;
    }
}

const used = new Set();
for (const dir of SCAN) {
    for (const path of files(join(ROOT, dir))) {
        const text = readFileSync(path, 'utf8');
        for (const match of text.matchAll(/mdi-([a-z0-9]+(?:-[a-z0-9]+)*)/g)) if (icons.has(match[1])) used.add(match[1]);
        for (const match of text.matchAll(/['"`]([a-z0-9]+(?:-[a-z0-9]+)*)['"`]/g)) {
            if (!icons.has(match[1])) continue;
            // Valor de un atributo enlazado o de evento (`@click="restart"`, `:key="row"`, `v-if="open"`):
            // es una expresión, no un nombre de ícono. `:icon="'gavel'"` sí cuenta (comillas internas).
            const before = text.slice(Math.max(0, match.index - 60), match.index);
            if (match[0][0] === '"' && /(?:^|\s)(?:@|:|v-|#)[\w.:\[\]-]*=$/.test(before)) continue;
            used.add(match[1]);
        }
    }
}
// Glifos referidos por su código en el CSS (p. ej. el check de `markSuccess` en motion.css).
const byCode = new Map([...icons].map(([name, code]) => [code, name]));
for (const dir of ['resources/css', 'resources/design', 'resources/js']) {
    for (const path of files(join(ROOT, dir))) {
        for (const match of readFileSync(path, 'utf8').matchAll(/content:\s*["']\\(F[0-9A-F]{4})["']/g)) {
            const name = byCode.get(parseInt(match[1], 16));
            if (name) used.add(name);
        }
    }
}
for (const name of [...used]) {
    if (name.endsWith('-outline') && icons.has(name.slice(0, -8))) used.add(name.slice(0, -8));
}
const names = [...used].sort();

if (CHECK) {
    const subset = readFileSync(OUT_CSS, 'utf8');
    const missing = names.filter((name) => !subset.includes(`.mdi-${name}::before`));
    if (missing.length) {
        console.error(`Faltan ${missing.length} íconos en resources/css/mdi.css: ${missing.join(', ')}\nEjecuta: node scripts/mdi-subset.mjs`);
        process.exit(1);
    }
    console.log(`OK: ${names.length} íconos usados, todos en el subconjunto.`);
    process.exit(0);
}

// ───────── 2. Recorte de la fuente TrueType ─────────
const ttf = readFileSync(join(MDI, 'fonts/materialdesignicons-webfont.ttf'));
const tables = {};
for (let i = 0; i < ttf.readUInt16BE(4); i++) {
    const offset = 12 + i * 16;
    tables[ttf.toString('latin1', offset, offset + 4)] = { offset: ttf.readUInt32BE(offset + 8), length: ttf.readUInt32BE(offset + 12) };
}
const table = (tag) => ttf.subarray(tables[tag].offset, tables[tag].offset + tables[tag].length);

// cmap formato 12 (3, 10): código → glifo.
const cmap = table('cmap');
let format12 = null;
for (let i = 0; i < cmap.readUInt16BE(2); i++) {
    const record = 4 + i * 8;
    const sub = cmap.readUInt32BE(record + 4);
    if (cmap.readUInt16BE(sub) === 12) format12 = sub;
}
const codeToGlyph = new Map();
for (let i = 0, groups = cmap.readUInt32BE(format12 + 12); i < groups; i++) {
    const group = format12 + 16 + i * 12;
    const start = cmap.readUInt32BE(group);
    const end = cmap.readUInt32BE(group + 4);
    const glyph = cmap.readUInt32BE(group + 8);
    for (let code = start; code <= end; code++) codeToGlyph.set(code, glyph + code - start);
}

const head = Buffer.from(table('head'));
const longLoca = head.readInt16BE(50) === 1;
const loca = table('loca');
const glyf = table('glyf');
const glyphCount = ttf.readUInt16BE(tables.maxp.offset + 4);
const glyphRange = (id) => (longLoca ? [loca.readUInt32BE(id * 4), loca.readUInt32BE(id * 4 + 4)] : [loca.readUInt16BE(id * 2) * 2, loca.readUInt16BE(id * 2 + 2) * 2]);

const wanted = new Set([0]);
const codes = names.map((name) => icons.get(name));
codes.forEach((code) => codeToGlyph.has(code) && wanted.add(codeToGlyph.get(code)));
// Componentes de glifos compuestos.
const queue = [...wanted];
while (queue.length) {
    const id = queue.pop();
    const [start, end] = glyphRange(id);
    if (end - start < 10 || glyf.readInt16BE(start) >= 0) continue;
    let offset = start + 10;
    for (;;) {
        const flags = glyf.readUInt16BE(offset);
        const component = glyf.readUInt16BE(offset + 2);
        if (!wanted.has(component)) {
            wanted.add(component);
            queue.push(component);
        }
        offset += 4 + (flags & 1 ? 4 : 2) + (flags & 8 ? 2 : flags & 0x40 ? 4 : flags & 0x80 ? 8 : 0);
        if (!(flags & 0x20)) break;
    }
}
const order = [...wanted].sort((a, b) => a - b);
const newId = new Map(order.map((id, index) => [id, index]));

const glyphBuffers = order.map((id) => {
    const [start, end] = glyphRange(id);
    const data = Buffer.from(glyf.subarray(start, end));
    if (data.length >= 10 && data.readInt16BE(0) < 0) {
        let offset = 10;
        for (;;) {
            const flags = data.readUInt16BE(offset);
            data.writeUInt16BE(newId.get(data.readUInt16BE(offset + 2)), offset + 2);
            offset += 4 + (flags & 1 ? 4 : 2) + (flags & 8 ? 2 : flags & 0x40 ? 4 : flags & 0x80 ? 8 : 0);
            if (!(flags & 0x20)) break;
        }
    }
    const padded = Buffer.alloc(Math.ceil(data.length / 4) * 4);
    data.copy(padded);
    return padded;
});
const newGlyf = Buffer.concat(glyphBuffers);
const newLoca = Buffer.alloc((order.length + 1) * 4);
let cursor = 0;
glyphBuffers.forEach((buffer, index) => {
    newLoca.writeUInt32BE(cursor, index * 4);
    cursor += buffer.length;
});
newLoca.writeUInt32BE(cursor, order.length * 4);
head.writeInt16BE(1, 50);
head.writeUInt32BE(0, 8);

const hhea = Buffer.from(table('hhea'));
const metricsCount = hhea.readUInt16BE(34);
const hmtx = table('hmtx');
const newHmtx = Buffer.alloc(order.length * 4);
order.forEach((id, index) => {
    const metric = Math.min(id, metricsCount - 1);
    newHmtx.writeUInt16BE(hmtx.readUInt16BE(metric * 4), index * 4);
    newHmtx.writeInt16BE(id < metricsCount ? hmtx.readInt16BE(id * 4 + 2) : hmtx.readInt16BE(metricsCount * 4 + (id - metricsCount) * 2), index * 4 + 2);
});
hhea.writeUInt16BE(order.length, 34);

const maxp = Buffer.from(table('maxp'));
maxp.writeUInt16BE(order.length, 4);

const post = Buffer.from(table('post').subarray(0, 32));
post.writeUInt32BE(0x00030000, 0);

// cmap nuevo: un subtable formato 12 (3, 10) con grupos de códigos consecutivos.
const pairs = codes.filter((code) => codeToGlyph.has(code)).map((code) => [code, newId.get(codeToGlyph.get(code))]).sort((a, b) => a[0] - b[0]);
const groups = [];
for (const [code, glyph] of pairs) {
    const last = groups.at(-1);
    if (last && code === last[1] + 1 && glyph === last[2] + (code - last[0])) last[1] = code;
    else groups.push([code, code, glyph]);
}
const newCmap = Buffer.alloc(4 + 8 + 16 + groups.length * 12);
newCmap.writeUInt16BE(0, 0);
newCmap.writeUInt16BE(1, 2);
newCmap.writeUInt16BE(3, 4);
newCmap.writeUInt16BE(10, 6);
newCmap.writeUInt32BE(12, 8);
newCmap.writeUInt16BE(12, 12);
newCmap.writeUInt32BE(16 + groups.length * 12, 16);
newCmap.writeUInt32BE(groups.length, 24);
groups.forEach(([start, end, glyph], index) => {
    newCmap.writeUInt32BE(start, 28 + index * 12);
    newCmap.writeUInt32BE(end, 32 + index * 12);
    newCmap.writeUInt32BE(glyph, 36 + index * 12);
});

const output = {
    'OS/2': Buffer.from(table('OS/2')),
    cmap: newCmap,
    glyf: newGlyf,
    head,
    hhea,
    hmtx: newHmtx,
    loca: newLoca,
    maxp,
    name: Buffer.from(table('name')),
    post,
};

function checksum(buffer) {
    const padded = Buffer.alloc(Math.ceil(buffer.length / 4) * 4);
    buffer.copy(padded);
    let sum = 0;
    for (let i = 0; i < padded.length; i += 4) sum = (sum + padded.readUInt32BE(i)) >>> 0;
    return sum;
}

// checkSumAdjustment de `head` sobre la fuente sfnt equivalente.
function sfnt(tags) {
    const headerLength = 12 + tags.length * 16;
    const parts = [];
    let offset = headerLength;
    const header = Buffer.alloc(headerLength);
    header.writeUInt32BE(0x00010000, 0);
    header.writeUInt16BE(tags.length, 4);
    tags.forEach((tag, index) => {
        const data = output[tag];
        const record = 12 + index * 16;
        header.write(tag, record, 'latin1');
        header.writeUInt32BE(checksum(data), record + 4);
        header.writeUInt32BE(offset, record + 8);
        header.writeUInt32BE(data.length, record + 12);
        const padded = Buffer.alloc(Math.ceil(data.length / 4) * 4);
        data.copy(padded);
        parts.push(padded);
        offset += padded.length;
    });
    return Buffer.concat([header, ...parts]);
}
const tags = Object.keys(output).sort();
head.writeUInt32BE((0xb1b0afba - checksum(sfnt(tags))) >>> 0, 8);
const sfntSize = sfnt(tags).length;

// ───────── WOFF 1.0 (zlib) ─────────
const directory = Buffer.alloc(tags.length * 20);
const bodies = [];
let woffOffset = 44 + tags.length * 20;
tags.forEach((tag, index) => {
    const data = output[tag];
    const compressed = deflateSync(data, { level: 9 });
    const stored = compressed.length < data.length ? compressed : data;
    const record = index * 20;
    directory.write(tag, record, 'latin1');
    directory.writeUInt32BE(woffOffset, record + 4);
    directory.writeUInt32BE(stored.length, record + 8);
    directory.writeUInt32BE(data.length, record + 12);
    directory.writeUInt32BE(checksum(data), record + 16);
    const padded = Buffer.alloc(Math.ceil(stored.length / 4) * 4);
    stored.copy(padded);
    bodies.push(padded);
    woffOffset += padded.length;
});
const woffHeader = Buffer.alloc(44);
woffHeader.write('wOFF', 0, 'latin1');
woffHeader.writeUInt32BE(0x00010000, 4);
woffHeader.writeUInt32BE(woffOffset, 8);
woffHeader.writeUInt16BE(tags.length, 12);
woffHeader.writeUInt32BE(sfntSize, 16);
woffHeader.writeUInt16BE(7, 20);
woffHeader.writeUInt16BE(4, 22);
const woff = Buffer.concat([woffHeader, directory, ...bodies]);
mkdirSync(join(ROOT, 'resources/fonts/mdi'), { recursive: true });
writeFileSync(OUT_FONT, woff);

// ───────── 3. CSS ─────────
const base = fullCss
    .replace(/@font-face\s*\{[^}]*\}/, '')
    .replace(/\.mdi-[a-z0-9-]+::before\s*\{\s*content:\s*"\\[0-9A-F]+";\s*\}\s*/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/\n{2,}/g, '\n');
const rules = names.map((name) => `.mdi-${name}::before { content: "\\${icons.get(name).toString(16).toUpperCase()}"; }`).join('\n');
const version = JSON.parse(readFileSync(join(MDI, 'package.json'), 'utf8')).version;
writeFileSync(
    OUT_CSS,
    `/* Material Design Icons ${version}: subconjunto con los ${names.length} íconos que usa el sistema.
   ARCHIVO GENERADO por scripts/mdi-subset.mjs: no se edita a mano; tras usar un ícono nuevo, vuelve a ejecutarlo. */
@font-face {
  font-family: "Material Design Icons";
  src: url("../fonts/mdi/mdi-subset.woff?v=${version}-${names.length}") format("woff");
  font-weight: normal;
  font-style: normal;
  font-display: block;
}
${base.trim()}
${rules}
`,
);

console.log(`${names.length} íconos · fuente ${(woff.length / 1024).toFixed(1)} KB (WOFF) · CSS ${(readFileSync(OUT_CSS).length / 1024).toFixed(1)} KB`);
