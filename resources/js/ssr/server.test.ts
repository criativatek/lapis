// @vitest-environment node

/**
 * The SSR server, exercised over real HTTP on an ephemeral port.
 *
 * Each case is one of the ways production's ssr.log showed the stock
 * @inertiajs/core server failing: an empty or truncated body that used to
 * kill the process, a component that throws, Portuguese text split across
 * TCP chunks. What is asserted every time: the process answers (it does not
 * die), the answer is well-formed JSON, and the log line says where — with
 * a timestamp and the release — and never what.
 */
import { request as httpRequest } from 'node:http';
import type { AddressInfo } from 'node:net';
import { Readable } from 'node:stream';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createRenderErrorCollector, createSsrServer, readBody, routeOnly, sourceLocation } from './server';
import type { InertiaPage, SsrLogEntry } from './server';

type Reply = { status: number; body: string };

const lines: string[] = [];
const rendered: InertiaPage[] = [];
let server: ReturnType<typeof createSsrServer>;
let port: number;

const renderPage = vi.fn(async (page: InertiaPage) => {
    rendered.push(page);

    if (page.component === 'reports/Show') {
        throw new TypeError("Cannot read properties of undefined (reading 'footer')");
    }

    if (page.component === 'Dashboard') {
        // What the collector produces for a shell rendered without `nav`:
        // two template branches, two errors, one render.
        throw new AggregateError(
            [
                new TypeError("Cannot read properties of undefined (reading 'sections')"),
                new TypeError("Cannot read properties of undefined (reading 'footer')"),
            ],
            '2 component error(s) while rendering Dashboard',
        );
    }

    return { head: ['<title>ok</title>'], body: `<div>${page.component}</div>` };
});

function post(path: string, chunks: (Buffer | string)[], headers: Record<string, string> = {}): Promise<Reply> {
    return new Promise((resolve, reject) => {
        const req = httpRequest(
            { host: '127.0.0.1', port, path, method: 'POST', headers: { 'Content-Type': 'application/json', ...headers } },
            (res) => {
                let body = '';
                res.setEncoding('utf8');
                res.on('data', (chunk: string) => (body += chunk));
                res.on('end', () => resolve({ status: res.statusCode ?? 0, body }));
            },
        );
        req.on('error', reject);

        for (const chunk of chunks) {
            req.write(chunk);
        }

        req.end();
    });
}

function get(path: string): Promise<Reply> {
    return new Promise((resolve, reject) => {
        httpRequest({ host: '127.0.0.1', port, path, method: 'GET' }, (res) => {
            let body = '';
            res.setEncoding('utf8');
            res.on('data', (chunk: string) => (body += chunk));
            res.on('end', () => resolve({ status: res.statusCode ?? 0, body }));
        })
            .on('error', reject)
            .end();
    });
}

function lastLog(): SsrLogEntry {
    expect(lines.length).toBeGreaterThan(0);

    return JSON.parse(lines[lines.length - 1]) as SsrLogEntry;
}

const fictitiousPage: InertiaPage = {
    component: 'reports/Show',
    url: '/reports/01JFICTICIO?rascunho=Ana%20Fict%C3%ADcia',
    version: 'abc',
    props: {
        report: { title: 'Relatório da aluna Ana Fictícia' },
        sections: [{ body: 'Observação pedagógica confidencial.' }],
        auth: { user: { name: 'Docente Fictícia', email: 'docente@exemplo.test' } },
    },
};

beforeEach(async () => {
    lines.length = 0;
    rendered.length = 0;
    renderPage.mockClear();
    server = createSsrServer({
        renderPage,
        release: '0.153.0',
        log: (line) => lines.push(line),
        now: () => new Date('2026-09-21T10:00:00.000Z'),
    });
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    port = (server.address() as AddressInfo).port;
});

afterEach(async () => {
    await new Promise<void>((resolve) => server.close(() => resolve()));
});

describe('the SSR server', () => {
    it('renders a page and answers with head and body', async () => {
        const reply = await post('/render', [JSON.stringify({ ...fictitiousPage, component: 'Welcome' })]);

        expect(reply.status).toBe(200);
        expect(JSON.parse(reply.body)).toEqual({ head: ['<title>ok</title>'], body: '<div>Welcome</div>' });
        expect(lines).toEqual([]);
    });

    it('answers 400 to an empty body instead of dying, and says so with a timestamp and the release', async () => {
        const reply = await post('/render', []);

        expect(reply.status).toBe(400);
        expect(JSON.parse(reply.body)).toEqual({ error: 'Empty request body' });
        expect(renderPage).not.toHaveBeenCalled();
        expect(lastLog()).toEqual({
            timestamp: '2026-09-21T10:00:00.000Z',
            level: 'error',
            release: '0.153.0',
            message: 'Empty SSR render request body',
            component: null,
            route: null,
            type: 'empty-body',
        });
    });

    it('answers 400 to truncated JSON — the SyntaxError that used to be an unhandled rejection', async () => {
        const reply = await post('/render', ['{"component":"reports/Show","props":{"report":{"title":"Relatório da Ana']);

        expect(reply.status).toBe(400);
        expect(JSON.parse(reply.body)).toEqual({ error: 'Invalid JSON in request body' });
        expect(renderPage).not.toHaveBeenCalled();

        const entry = lastLog();
        expect(entry.type).toBe('invalid-json');
        expect(entry.message).toMatch(/JSON/);
        expect(entry.timestamp).toBe('2026-09-21T10:00:00.000Z');
        expect(entry.release).toBe('0.153.0');
        // Not even the fragment that was received.
        expect(lines[lines.length - 1]).not.toContain('Relatório');
    });

    it('answers 500 to a component that throws, naming the component and the route but nothing on the page', async () => {
        const reply = await post('/render', [JSON.stringify(fictitiousPage)]);

        expect(reply.status).toBe(500);
        expect(JSON.parse(reply.body)).toEqual({
            error: "Cannot read properties of undefined (reading 'footer')",
            type: 'render',
            component: 'reports/Show',
        });

        const entry = lastLog();
        expect(entry).toMatchObject({
            timestamp: '2026-09-21T10:00:00.000Z',
            level: 'error',
            release: '0.153.0',
            message: "Cannot read properties of undefined (reading 'footer')",
            component: 'reports/Show',
            route: '/reports/01JFICTICIO',
            type: 'render-error',
        });
        // The first frame of our own: here, the test that threw.
        expect(entry.source).toMatch(/server\.test\.ts:\d+:\d+$/);

        const line = lines[lines.length - 1];

        for (const secret of ['Fictícia', 'Docente', 'docente@exemplo.test', 'confidencial', 'Observa', 'rascunho', 'props']) {
            expect(line).not.toContain(secret);
        }
    });

    it('logs one line per component error when a render collected several — one missing prop, two messages', async () => {
        const reply = await post('/render', [JSON.stringify({ ...fictitiousPage, component: 'Dashboard', url: '/dashboard' })]);

        expect(reply.status).toBe(500);
        expect(JSON.parse(reply.body)).toEqual({
            error: "Cannot read properties of undefined (reading 'sections')",
            type: 'render',
            component: 'Dashboard',
        });

        expect(lines.map((line) => JSON.parse(line) as SsrLogEntry).map((entry) => [entry.message, entry.component, entry.route, entry.type])).toEqual([
            ["Cannot read properties of undefined (reading 'sections')", 'Dashboard', '/dashboard', 'render-error'],
            ["Cannot read properties of undefined (reading 'footer')", 'Dashboard', '/dashboard', 'render-error'],
        ]);
    });

    it('is still alive for the next request after every kind of failure', async () => {
        await post('/render', []);
        await post('/render', ['{"component":']);
        await post('/render', [JSON.stringify(fictitiousPage)]);

        const reply = await post('/render', [JSON.stringify({ ...fictitiousPage, component: 'marketing/Plans' })]);

        expect(reply.status).toBe(200);
        expect(JSON.parse(reply.body).body).toBe('<div>marketing/Plans</div>');
        expect(lines).toHaveLength(3);
    });

    it('keeps Portuguese text intact when a multi-byte character is split across chunks', async () => {
        const json = JSON.stringify({ ...fictitiousPage, component: 'Welcome', props: { title: 'Avaliação — 5.º ano, Ciências' } });
        const bytes = Buffer.from(json, 'utf8');
        // Cut inside «ç» (two bytes: 0xC3 0xA7).
        const cut = bytes.indexOf(Buffer.from('ç', 'utf8')) + 1;

        const reply = await post('/render', [bytes.subarray(0, cut), bytes.subarray(cut)], { 'Content-Length': String(bytes.length) });

        expect(reply.status).toBe(200);
        expect(rendered[0].props).toEqual({ title: 'Avaliação — 5.º ano, Ciências' });
    });

    it('answers the health check and a 404 for anything else', async () => {
        expect((await get('/health')).status).toBe(200);
        expect(JSON.parse((await get('/health')).body).status).toBe('OK');
        expect((await get('/nope')).status).toBe(404);
        expect(lines).toEqual([]);
    });
});

describe('readBody', () => {
    it('decodes once, after concatenating — a character split across two chunks survives', async () => {
        const stream = Readable.from([Buffer.from([0xc3]), Buffer.from([0xa3])]);

        expect(await readBody(stream)).toBe('ã');
    });

    it('would have been corrupted by chunk-wise string coercion (the stock server)', () => {
        let corrupted = '';

        for (const chunk of [Buffer.from([0xc3]), Buffer.from([0xa3])]) {
            corrupted += chunk;
        }

        expect(corrupted).not.toBe('ã');
    });
});

describe('the render error collector', () => {
    function fakeApp(): App {
        return { config: { errorHandler: undefined } } as unknown as App;
    }

    it('turns the errors Vue would only console.error into a failed render, all of them', async () => {
        const collector = createRenderErrorCollector();
        const page: InertiaPage = { ...fictitiousPage, component: 'Dashboard' };
        const app = fakeApp();

        const render = collector.wrap(async (rendering) => {
            // What the Inertia adapter does per render, then what Vue does
            // when two template branches throw in production.
            collector.withApp(app, { ssr: true, page: rendering });
            app.config.errorHandler?.(new TypeError("Cannot read properties of undefined (reading 'sections')"), null, 'render function');
            app.config.errorHandler?.(new TypeError("Cannot read properties of undefined (reading 'footer')"), null, 'render function');

            return { head: [], body: '<div id="app"></div>' };
        });

        const failure = await render(page).then(
            () => null,
            (error: unknown) => error,
        );

        expect(failure).toBeInstanceOf(AggregateError);
        expect((failure as AggregateError).errors.map((error) => (error as Error).message)).toEqual([
            "Cannot read properties of undefined (reading 'sections')",
            "Cannot read properties of undefined (reading 'footer')",
        ]);
    });

    it('leaves a clean render alone, and forgets a page once it is answered', async () => {
        const collector = createRenderErrorCollector();
        const page: InertiaPage = { ...fictitiousPage, component: 'Welcome' };
        const app = fakeApp();

        const render = collector.wrap(async (rendering) => {
            collector.withApp(app, { ssr: true, page: rendering });

            return { head: [], body: '<h1>Lapispro</h1>' };
        });

        expect(await render(page)).toEqual({ head: [], body: '<h1>Lapispro</h1>' });
        expect(app.config.errorHandler).toBeTypeOf('function');
    });

    it('keeps concurrent renders apart — an error belongs to the page it happened on', async () => {
        const collector = createRenderErrorCollector();
        const good: InertiaPage = { ...fictitiousPage, component: 'Welcome' };
        const bad: InertiaPage = { ...fictitiousPage, component: 'Dashboard' };
        const apps = new Map<object, App>();

        const render = collector.wrap(async (rendering) => {
            const app = fakeApp();
            apps.set(rendering, app);
            collector.withApp(app, { ssr: true, page: rendering });
            // Let both renders be in flight before either reports.
            await new Promise((resolve) => setTimeout(resolve, 0));

            if (rendering.component === 'Dashboard') {
                app.config.errorHandler?.(new TypeError("Cannot read properties of undefined (reading 'length')"), null, 'render function');
            }

            return { head: [], body: '' };
        });

        const [goodResult, badResult] = await Promise.allSettled([render(good), render(bad)]);

        expect(goodResult.status).toBe('fulfilled');
        expect(badResult.status).toBe('rejected');
    });

    it('does nothing for a browser app', () => {
        const collector = createRenderErrorCollector();
        const app = fakeApp();

        collector.withApp(app, { ssr: false });

        expect(app.config.errorHandler).toBeUndefined();
    });
});

describe('sourceLocation', () => {
    it("names the application's own frame, not the renderer's", () => {
        const stack = [
            "TypeError: Cannot read properties of undefined (reading 'sections')",
            '    at file:///home/lapis/htdocs/app/bootstrap/ssr/assets/server-renderer-BTxsnJ54.js:22993:11',
            '    at file:///home/lapis/htdocs/app/bootstrap/ssr/ssr.js:6455:63',
            '    at renderComponentSubTree (file:///home/lapis/htdocs/app/bootstrap/ssr/assets/server-renderer-BTxsnJ54.js:29723:6)',
        ].join('\n');

        expect(sourceLocation(stack)).toBe('/home/lapis/htdocs/app/bootstrap/ssr/ssr.js:6455:63');
    });

    it('is undefined when there is no stack or no frame of our own', () => {
        expect(sourceLocation(undefined)).toBeUndefined();
        expect(sourceLocation('TypeError: x\n    at node:internal/process/task_queues:104:5')).toBeUndefined();
    });
});

describe('routeOnly', () => {
    it('keeps the path and drops the query string', () => {
        expect(routeOnly('/reports/01JFICTICIO?rascunho=x')).toBe('/reports/01JFICTICIO');
        expect(routeOnly('/planos')).toBe('/planos');
    });

    it('is null for anything that is not a url', () => {
        expect(routeOnly('')).toBeNull();
        expect(routeOnly(undefined)).toBeNull();
        expect(routeOnly(42)).toBeNull();
    });
});
