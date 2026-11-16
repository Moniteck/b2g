// Balanceador local para medir en Windows con varios procesos PHP.
//
// En Windows `php -S` no admite PHP_CLI_SERVER_WORKERS (usa fork). Para simular un pool tipo
// PHP-FPM se levantan N procesos `php -S` en puertos consecutivos y este proxy reparte las
// peticiones: cada proceso atiende una a la vez y las demás esperan en una cola común
// (como la cola `listen` de FPM). Opcionalmente añade lo que haría nginx en producción:
// compresión gzip/brotli y caché larga para /build/assets.
//
//   node tests/perf/lb.mjs --port=8089 --backends=8090-8097 [--compress] [--static-cache]
//
// Expone /__lb para ver cola y ocupación.
import http from 'node:http';
import zlib from 'node:zlib';

const args = Object.fromEntries(process.argv.slice(2).map((a) => {
    const [k, v] = a.replace(/^--/, '').split('=');
    return [k, v ?? true];
}));

const port = Number(args.port ?? 8089);
const [from, to] = String(args.backends ?? '8090-8097').split('-').map(Number);
const compress = Boolean(args.compress);
const staticCache = Boolean(args['static-cache']);

const backends = [];
for (let p = from; p <= (to ?? from); p++) backends.push({ port: p, busy: false, served: 0 });

const queue = [];
let maxQueue = 0;
let total = 0;

const COMPRESSIBLE = /^(text\/|application\/(json|javascript|xml)|image\/svg)/;

function dispatch() {
    while (queue.length) {
        const backend = backends.find((b) => !b.busy);
        if (!backend) return;
        backend.busy = true;
        proxy(queue.shift(), backend);
    }
}

function release(backend) {
    backend.busy = false;
    backend.served++;
    dispatch();
}

function proxy({ req, res, body }, backend) {
    let released = false;
    const done = () => { if (!released) { released = true; release(backend); } };

    const upstream = http.request({
        host: '127.0.0.1',
        port: backend.port,
        method: req.method,
        path: req.url,
        headers: { ...req.headers, 'x-forwarded-for': req.socket.remoteAddress, connection: 'close' },
        agent: false,
    }, (up) => {
        const headers = { ...up.headers };
        delete headers.connection;
        const type = String(headers['content-type'] ?? '');
        const isAsset = req.url.startsWith('/build/assets/');

        if (staticCache && isAsset && up.statusCode === 200) {
            headers['cache-control'] = 'public, max-age=31536000, immutable';
        }

        const accept = String(req.headers['accept-encoding'] ?? '');
        const encoding = compress && COMPRESSIBLE.test(type) && !headers['content-encoding']
            ? (accept.includes('br') ? 'br' : accept.includes('gzip') ? 'gzip' : null)
            : null;

        if (encoding) {
            delete headers['content-length'];
            headers['content-encoding'] = encoding;
            headers.vary = headers.vary ? `${headers.vary}, Accept-Encoding` : 'Accept-Encoding';
            res.writeHead(up.statusCode, headers);
            const z = encoding === 'br'
                ? zlib.createBrotliCompress({ params: { [zlib.constants.BROTLI_PARAM_QUALITY]: isAsset ? 9 : 4 } })
                : zlib.createGzip({ level: 6 });
            up.pipe(z).pipe(res);
        } else {
            res.writeHead(up.statusCode, headers);
            up.pipe(res);
        }
        up.on('end', done);
        up.on('error', done);
    });

    upstream.on('error', (error) => {
        done();
        if (!res.headersSent) res.writeHead(502, { 'content-type': 'text/plain' });
        res.end(`Bad gateway: ${error.message}`);
    });
    upstream.end(body);
}

http.createServer((req, res) => {
    if (req.url === '/__lb') {
        res.writeHead(200, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ queue: queue.length, maxQueue, total, backends }));
        return;
    }
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
        total++;
        queue.push({ req, res, body: Buffer.concat(chunks) });
        maxQueue = Math.max(maxQueue, queue.length);
        dispatch();
    });
}).listen(port, '127.0.0.1', () => {
    console.log(`LB :${port} -> ${backends.map((b) => b.port).join(',')} compress=${compress} staticCache=${staticCache}`);
});
