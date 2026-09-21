import { createServer as createHttpServer } from 'node:http';
import type { IncomingMessage, ServerResponse } from 'node:http';
import { createInertiaApp } from '@inertiajs/vue3';
import { renderToString } from 'vue/server-renderer';
import type * as VueServerRenderer from 'vue/server-renderer';
import { formatTitle, resolveLayout } from '@/inertia';

/**
 * Server-side rendering, for the pages a crawler has to be able to read.
 *
 * Without it the response for the landing page was a 14 KB shell with no
 * <h1>, no copy and no links — everything arrived with the JavaScript, which
 * Google renders late and with a budget, and Bing, LinkedIn, WhatsApp and the
 * LLM crawlers do not render at all. The SEO tags were already server-side
 * (app.blade.php); this puts the page itself there too.
 *
 * Runs as `php artisan inertia:start-ssr` (`node bootstrap/ssr/ssr.js`, a
 * production build with `import.meta.env.PROD` true), on port 13714
 * (config/inertia.php). When that process is down Inertia falls back to
 * client rendering, so a stopped SSR server is a slower crawl, never a
 * broken page. Built by `npm run build:ssr` into bootstrap/ssr/, which is
 * gitignored and travels in the release package like public/build.
 *
 * This file deliberately does NOT let the @inertiajs/vite plugin auto-wrap a
 * bare `createInertiaApp(...)` call with `@inertiajs/core`'s own
 * `createServer` (dist/server.js). That helper has two bugs that matched the
 * exact pattern in ssr.log (a run of errors followed by a clean restart,
 * with no timestamp on the console line):
 *
 *   - `handleRender` does `JSON.parse(await readableToString(request))`
 *     OUTSIDE any try/catch. An empty or truncated POST /render body throws
 *     a bare `SyntaxError: Unexpected end of JSON input` inside the async
 *     request handler, which Node treats as an unhandled promise rejection —
 *     fatal since Node 15. The process dies mid-response (no `res.end()`)
 *     and the process manager restarts it, which is exactly "erros seguidos
 *     de arranques limpos, sem timestamps": the timestamp core DOES compute
 *     for other errors (`classifySSRError`) is real, but its own
 *     `formatConsoleError` never prints it, and this failure mode never
 *     reaches that formatter at all — it kills the process before logging
 *     anything.
 *   - `readableToString` concatenates Buffer chunks onto a string
 *     (`data += chunk`) instead of concatenating buffers and decoding once.
 *     A multi-byte UTF-8 character (ã, ç, é, º — all over Portuguese report
 *     text) split across a chunk boundary is silently corrupted.
 *
 * The server below reads the body as Buffers and decodes once, parses JSON
 * inside a try/catch, and always answers with a well-formed response and
 * `response.end()` — a malformed request becomes a 400, not a dead process.
 * It also logs one structured, timestamped, PII-free line per request that
 * fails, naming the release, the route/component and the error type, which
 * `formatConsoleError` did not.
 */

type InertiaPage = { component: string; url: string; version: string | null; props: Record<string, unknown> };
type SsrRenderFunction = (page: InertiaPage, renderToString: typeof VueServerRenderer.renderToString) => Promise<{ head: string[]; body: string }>;

// createInertiaApp()'s return type is `void | RenderFunction<...>` because
// the same function also covers the browser bootstrap path, which returns
// nothing. Under Node (no `window`) and without `page`/`render` options —
// exactly how it is called above — it always resolves to the SSR render
// function; the cast below only narrows what TypeScript already can't see
// from the overload.
const render = (await createInertiaApp({
    title: formatTitle,
    layout: resolveLayout,
})) as unknown as SsrRenderFunction;

const renderPage = (page: InertiaPage) => render(page, renderToString);

type SsrLogEntry = {
    timestamp: string;
    level: 'error';
    message: string;
    /** config('app.version'), baked in at build time — see vite.config.ts. */
    release: string;
    /** The Inertia component name, e.g. "reports/Show" — never page content. */
    component: string | null;
    /** The request path only; query strings can carry real data and are dropped. */
    route: string | null;
    type: 'invalid-json' | 'empty-body' | 'render-error';
};

/** The Inertia component/url are metadata about WHERE rendering failed, never
 * what was being rendered — no props, no report text, no names, no tokens. */
function logSsrError(entry: SsrLogEntry): void {
    // One line, machine-parseable, so `ssr.log` can finally be grepped by
    // release or by route instead of scanning multi-line stack dumps.
    console.error(JSON.stringify(entry));
}

/** The path only — a report ulid in it is fine (opaque, not PII); a query
 * string is not trusted, since nothing stops a caller from putting real data
 * there, so it is stripped even though Inertia itself never populates one. */
function routeOnly(url: string | undefined): string | null {
    if (!url) {
        return null;
    }

    const queryIndex = url.indexOf('?');

    return queryIndex === -1 ? url : url.slice(0, queryIndex);
}

async function readBody(request: IncomingMessage): Promise<string> {
    const chunks: Buffer[] = [];

    for await (const chunk of request) {
        chunks.push(chunk as Buffer);
    }

    // Concatenating Buffers and decoding once (as opposed to `data += chunk`,
    // which coerces each chunk to a string as it arrives) is what keeps a
    // multi-byte UTF-8 character intact when it lands on a chunk boundary.
    return Buffer.concat(chunks).toString('utf8');
}

function sendJson(response: ServerResponse, status: number, body: unknown): void {
    // A failure that happens after headers are already sent (e.g. the
    // connection dropped mid-response) must not try to write again — Node
    // throws "ERR_HTTP_HEADERS_SENT" and that throw, from inside a rejected
    // promise's catch handler, becomes the very unhandled-rejection crash
    // this file exists to prevent.
    if (response.headersSent) {
        return;
    }

    response.writeHead(status, { 'Content-Type': 'application/json', Server: 'Inertia.js SSR' });
    response.end(JSON.stringify(body));
}

async function handleRender(request: IncomingMessage, response: ServerResponse, release: string): Promise<void> {
    let raw: string;

    try {
        raw = await readBody(request);
    } catch (error) {
        // The client can drop the connection mid-upload (exactly the
        // "truncated body" scenario this file was written for) — that
        // surfaces as a stream error, not a JSON error, so it needs its own
        // branch rather than falling into the JSON.parse try/catch below.
        logSsrError({
            timestamp: new Date().toISOString(),
            level: 'error',
            message: error instanceof Error ? error.message : String(error),
            release,
            component: null,
            route: null,
            type: 'empty-body',
        });
        sendJson(response, 400, { error: 'Failed to read request body' });

        return;
    }

    if (raw.trim() === '') {
        logSsrError({
            timestamp: new Date().toISOString(),
            level: 'error',
            message: 'Empty SSR render request body',
            release,
            component: null,
            route: null,
            type: 'empty-body',
        });
        sendJson(response, 400, { error: 'Empty request body' });

        return;
    }

    let page: { component: string; url: string };

    try {
        page = JSON.parse(raw);
    } catch (error) {
        logSsrError({
            timestamp: new Date().toISOString(),
            level: 'error',
            message: error instanceof Error ? error.message : 'Invalid JSON',
            release,
            component: null,
            route: null,
            type: 'invalid-json',
        });
        sendJson(response, 400, { error: 'Invalid JSON in request body' });

        return;
    }

    try {
        const result = await renderPage(page as InertiaPage);
        sendJson(response, 200, result);
    } catch (error) {
        logSsrError({
            timestamp: new Date().toISOString(),
            level: 'error',
            message: error instanceof Error ? error.message : String(error),
            release,
            component: page.component ?? null,
            route: routeOnly(page.url),
            type: 'render-error',
        });
        sendJson(response, 500, {
            error: error instanceof Error ? error.message : 'SSR render error',
        });
    }
}

if (import.meta.env.PROD) {
    // config('app.version') at build time — see the `__APP_VERSION__` define
    // in vite.config.ts. Falls back to "unknown" rather than throwing if the
    // define is ever missing, since a stopped SSR server is worse than an
    // unlabelled log line.
    const release = typeof __APP_VERSION__ === 'string' ? __APP_VERSION__ : 'unknown';
    const port = 13714;
    const host = '0.0.0.0';

    createHttpServer((request, response) => {
        if (request.url === '/render') {
            // handleRender has its own try/catch around every step that can
            // throw, but this `.catch()` is the backstop: without it, any
            // rejection this function does not itself account for would be
            // an unhandled rejection — the exact failure mode (Node >=15
            // kills the process) that made ssr.log look like restarts.
            handleRender(request, response, release).catch((error: unknown) => {
                logSsrError({
                    timestamp: new Date().toISOString(),
                    level: 'error',
                    message: error instanceof Error ? error.message : String(error),
                    release,
                    component: null,
                    route: null,
                    type: 'render-error',
                });
                sendJson(response, 500, { error: 'SSR render error' });
            });

            return;
        }

        if (request.url === '/health') {
            sendJson(response, 200, { status: 'OK', timestamp: Date.now() });

            return;
        }

        if (request.url === '/shutdown') {
            // Exit only once the response has actually been flushed to the
            // socket — calling `process.exit()` right after `.end()` can cut
            // the write off before the client sees it.
            response.writeHead(200, { 'Content-Type': 'application/json', Server: 'Inertia.js SSR' });
            response.end(JSON.stringify({ status: 'OK', timestamp: Date.now() }), () => process.exit());

            return;
        }

        sendJson(response, 404, { status: 'NOT_FOUND', timestamp: Date.now() });
    }).listen({ port, host }, () => {
        console.log(`Inertia SSR server started on port ${port}.`);
    });
}

export default renderPage;
