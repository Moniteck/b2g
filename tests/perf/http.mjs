// Cliente HTTP mínimo con cookies para medir sin navegador (Node 18+).
export class Session {
    constructor(baseUrl) {
        this.base = baseUrl;
        this.cookies = new Map();
        this.version = null;
    }

    store(response) {
        for (const line of response.headers.getSetCookie?.() ?? []) {
            const [pair] = line.split(';');
            const index = pair.indexOf('=');
            this.cookies.set(pair.slice(0, index).trim(), pair.slice(index + 1));
        }
    }

    header() {
        return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
    }

    xsrf() {
        return decodeURIComponent(this.cookies.get('XSRF-TOKEN') ?? '');
    }

    async request(path, { method = 'GET', headers = {}, body, redirect = 'manual' } = {}) {
        const started = performance.now();
        const response = await fetch(this.base + path, {
            method,
            redirect,
            headers: { cookie: this.header(), 'x-xsrf-token': this.xsrf(), 'accept-encoding': 'identity', ...headers },
            body,
        });
        this.store(response);
        const buffer = Buffer.from(await response.arrayBuffer());
        const ms = performance.now() - started;
        const h = (name) => response.headers.get(name);
        return {
            status: response.status,
            ms,
            bytes: buffer.length,
            body: buffer,
            headers: response.headers,
            perf: { queries: Number(h('x-perf-queries') ?? NaN), sqlMs: Number(h('x-perf-sql-ms') ?? NaN), serverMs: Number(h('x-perf-ms') ?? NaN), memMb: Number(h('x-perf-mem-mb') ?? NaN) },
        };
    }

    async login(email, password) {
        const page = await this.request('/login');
        const match = page.body.toString().match(/data-page="([^"]+)"|<script data-page="app" type="application\/json">([^<]+)</);
        if (match) {
            const json = JSON.parse((match[1] ?? match[2]).replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
            this.version = json.version;
        }
        const res = await this.request('/login', {
            method: 'POST',
            headers: { 'content-type': 'application/json', accept: 'text/html, application/xhtml+xml', 'x-requested-with': 'XMLHttpRequest', 'x-inertia': 'true', ...(this.version ? { 'x-inertia-version': this.version } : {}) },
            body: JSON.stringify({ email, password, remember: false }),
        });
        if (res.status !== 302 && res.status !== 409 && res.status !== 200) throw new Error(`login ${res.status}`);
        const location = res.headers.get('location') ?? '';
        if (location.includes('/login')) throw new Error('credenciales rechazadas');
        return res;
    }

    inertia(path, { only, component, headers = {} } = {}) {
        return this.request(path, {
            headers: {
                accept: 'text/html, application/xhtml+xml',
                'x-requested-with': 'XMLHttpRequest',
                'x-inertia': 'true',
                ...(this.version ? { 'x-inertia-version': this.version } : {}),
                ...(only ? { 'x-inertia-partial-data': only.join(','), 'x-inertia-partial-component': component } : {}),
                ...headers,
            },
        });
    }

    json(path, headers = {}) {
        return this.request(path, { headers: { accept: 'application/json', 'x-requested-with': 'XMLHttpRequest', ...headers } });
    }
}
