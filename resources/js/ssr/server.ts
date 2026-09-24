import { createServer } from 'node:http';
import type { IncomingMessage, Server, ServerResponse } from 'node:http';
import type { App } from 'vue';

/**
 * The HTTP server `php artisan inertia:start-ssr` runs — ours, not the one
 * `@inertiajs/core` ships — and the error collector that makes a component
 * crash visible.
 *
 * Production's ssr.log showed two things at once: raw `TypeError` stacks with
 * no timestamp, component or route, and runs of those followed by clean
 * restarts. They have different causes.
 *
 * THE RAW STACKS are Vue's production error handling. When a component throws
 * during `renderToString`, Vue hands the error to `app.config.errorHandler`
 * if one is set — and otherwise `console.error`s it and carries on, so the
 * render "succeeds" with a hole where the component was and the adapter
 * answers 200. Nothing in that path knows the release, the page or the
 * route. `createRenderErrorCollector()` below sets the handler, through the
 * adapter's own `withApp(app, { ssr, page })` hook, and turns any collected
 * error into a failed render: a 500 the Laravel gateway treats as «no SSR,
 * render in the browser» (the same outcome the user got before) and logs on
 * its side too (`SsrRenderFailed` → `LogSsrRenderFailure`). In dev Vue throws
 * instead of logging, which is why a Vitest render rejects on its own.
 *
 * THE RESTARTS are the stock server (`@inertiajs/core/dist/server.js`):
 *
 *   - `handleRender` does `JSON.parse(await readableToString(request))`
 *     OUTSIDE any try/catch. An empty or truncated POST /render body throws
 *     `SyntaxError: Unexpected end of JSON input` inside the async request
 *     handler; Node treats that as an unhandled promise rejection, fatal
 *     since Node 15. The process dies mid-response (no `res.end()`), systemd
 *     restarts it, and nothing is logged.
 *   - `readableToString` concatenates Buffer chunks onto a string
 *     (`data += chunk`). A multi-byte UTF-8 character (ã, ç, é, º — all over
 *     Portuguese report text) split across a chunk boundary is silently
 *     corrupted.
 *
 * Here the body is read as Buffers and decoded once; JSON is parsed inside
 * a try/catch; every path answers a well-formed response and calls
 * `end()`; nothing is left as a rejected promise. And every failure is one
 * structured, timestamped line naming the release, the component and the
 * route — never the page.
 *
 * Kept apart from ssr.ts so it can be tested without a Vite build: ssr.ts
 * only wires the Inertia render function into this.
 */

/**
 * Where the SSR server listens, by default: the loopback interface only.
 *
 * The only client is Laravel on the same machine (`config/inertia.php`,
 * `http://127.0.0.1:13714`), so binding `0.0.0.0` — as this did until
 * 0.154.1 — published an unauthenticated render endpoint on every interface
 * of the VPS and left the firewall as the ONLY thing between it and the
 * internet. `/render` takes a JSON body and runs the page components on it;
 * `/shutdown` kills the process. Neither asks who is calling. A firewall
 * rule is a second layer, not the first one: it lives outside the repo, it
 * is not asserted by any test here, and a provider-level rule or a rebuilt
 * ufw table can drop it without anything in the application noticing.
 *
 * `INERTIA_SSR_HOST` and `INERTIA_SSR_PORT` exist for the case where the
 * Node process ever has to run somewhere other than the web server (a
 * container, a second host). Setting the host to anything but a loopback
 * address is a deliberate act that has to be paired with a network boundary
 * of its own — it is never the default, and production sets neither.
 */
export const SSR_DEFAULT_HOST = '127.0.0.1';
export const SSR_DEFAULT_PORT = 13714;

export type SsrListenAddress = { host: string; port: number };

/**
 * Reads the listen address from the environment, falling back to loopback.
 *
 * A blank or unparseable value is the fallback, not an error: a typo in a
 * systemd unit must not leave the server listening somewhere unintended, and
 * a stopped SSR server would itself be a (smaller) regression — Inertia
 * falls back to client rendering, the page still opens.
 */
export function resolveListenAddress(env: Record<string, string | undefined> = {}): SsrListenAddress {
    const host = typeof env.INERTIA_SSR_HOST === 'string' && env.INERTIA_SSR_HOST.trim() !== '' ? env.INERTIA_SSR_HOST.trim() : SSR_DEFAULT_HOST;

    const port = Number.parseInt(env.INERTIA_SSR_PORT ?? '', 10);

    return { host, port: Number.isInteger(port) && port > 0 && port < 65536 ? port : SSR_DEFAULT_PORT };
}

export type InertiaPage = { component: string; url: string; version: string | null; props: Record<string, unknown> };

export type RenderPage = (page: InertiaPage) => Promise<{ head: string[]; body: string }>;

export type SsrLogEntry = {
    timestamp: string;
    level: 'error';
    message: string;
    /** config('app.version'), baked in at build time — see vite.config.ts. */
    release: string;
    /** The Inertia component name, e.g. "reports/Show" — never page content. */
    component: string | null;
    /** The request path only; a query string could carry real data and is dropped. */
    route: string | null;
    type: 'invalid-json' | 'empty-body' | 'render-error';
    /** The first stack frame outside Vue's renderer — a file:line:column in the bundle, when there is one. */
    source?: string;
};

export type SsrServerOptions = {
    renderPage: RenderPage;
    release: string;
    /** Where a log line goes. One JSON line per failure; stderr in production. */
    log?: (line: string) => void;
    now?: () => Date;
};

/** The path only — a report ulid in it is opaque, not PII; a query string is
 * not trusted, since nothing stops a caller from putting real data there, so
 * it is stripped even though Inertia itself never populates one. */
export function routeOnly(url: unknown): string | null {
    if (typeof url !== 'string' || url === '') {
        return null;
    }

    const queryIndex = url.indexOf('?');

    return queryIndex === -1 ? url : url.slice(0, queryIndex);
}

/** The first frame of a stack that is the application's own code, not the
 * renderer's — enough to find the component, and nothing from the page. */
export function sourceLocation(stack: string | undefined): string | undefined {
    if (!stack) {
        return undefined;
    }

    for (const line of stack.split('\n')) {
        if (!line.includes(' at ') || line.includes('node_modules') || line.includes('server-renderer') || line.includes('node:')) {
            continue;
        }

        const match = line.match(/\(?((?:file:\/\/)?[^\s()]+):(\d+):(\d+)\)?\s*$/);

        if (match) {
            return `${match[1].replace(/^file:\/\//, '')}:${match[2]}:${match[3]}`;
        }
    }

    return undefined;
}

export async function readBody(request: AsyncIterable<Buffer | string>): Promise<string> {
    const chunks: Buffer[] = [];

    for await (const chunk of request) {
        chunks.push(typeof chunk === 'string' ? Buffer.from(chunk, 'utf8') : chunk);
    }

    // Concatenating Buffers and decoding once (as opposed to `data += chunk`,
    // which coerces each chunk to a string as it arrives) is what keeps a
    // multi-byte UTF-8 character intact when it lands on a chunk boundary.
    return Buffer.concat(chunks).toString('utf8');
}

/**
 * Collects the component errors Vue would otherwise only `console.error`.
 *
 * `withApp` goes to `createInertiaApp({ withApp })`: the adapter calls it with
 * the Vue app it just created for ONE page, and that page — so the handler
 * set here is scoped to a single render, and concurrent renders never mix.
 * `wrap` turns a render that collected errors into a rejection carrying all
 * of them (`AggregateError`), because one missing prop can fail two template
 * branches — production logged 'sections' and 'footer' for the same page —
 * and both belong in the log.
 */
export function createRenderErrorCollector(): {
    withApp: (app: App, context: { ssr: boolean; page?: object }) => void;
    wrap: (render: RenderPage) => RenderPage;
} {
    const failures = new WeakMap<object, unknown[]>();

    return {
        withApp(app, context) {
            if (!context.ssr || context.page === undefined) {
                return;
            }

            const page = context.page;

            app.config.errorHandler = (error) => {
                const collected = failures.get(page) ?? [];
                collected.push(error);
                failures.set(page, collected);
            };
        },

        wrap(render) {
            return async (page) => {
                const result = await render(page);
                const errors = failures.get(page);
                failures.delete(page);

                if (errors !== undefined && errors.length > 0) {
                    throw new AggregateError(errors, `${errors.length} component error(s) while rendering ${page.component}`);
                }

                return result;
            };
        },
    };
}

function sendJson(response: ServerResponse, status: number, body: unknown): void {
    // A failure after the headers are out (the connection dropped
    // mid-response) must not write again: Node throws ERR_HTTP_HEADERS_SENT,
    // and a throw from inside a catch handler is the very unhandled
    // rejection this file exists to prevent.
    if (response.headersSent) {
        return;
    }

    response.writeHead(status, { 'Content-Type': 'application/json', Server: 'Inertia.js SSR' });
    response.end(JSON.stringify(body));
}

function messageOf(error: unknown): string {
    return error instanceof Error ? error.message : String(error);
}

export function createSsrServer(options: SsrServerOptions): Server {
    const { renderPage, release } = options;
    const log = options.log ?? ((line: string) => console.error(line));
    const now = options.now ?? (() => new Date());

    function fail(entry: Omit<SsrLogEntry, 'timestamp' | 'level' | 'release'>): void {
        // One line, machine-parseable, so ssr.log can be grepped by release
        // or by route instead of scanning multi-line stack dumps. The
        // component and route say WHERE it failed, never what was rendered:
        // no props, no report text, no names, no tokens.
        log(JSON.stringify({ timestamp: now().toISOString(), level: 'error', release, ...entry } satisfies SsrLogEntry));
    }

    async function handleRender(request: IncomingMessage, response: ServerResponse): Promise<void> {
        let raw: string;

        try {
            raw = await readBody(request);
        } catch (error) {
            // The client dropped the connection mid-upload — the truncated
            // body this file was written for. It surfaces as a stream error,
            // not a JSON error, so it gets its own branch.
            fail({ message: messageOf(error), component: null, route: null, type: 'empty-body' });
            sendJson(response, 400, { error: 'Failed to read request body' });

            return;
        }

        if (raw.trim() === '') {
            fail({ message: 'Empty SSR render request body', component: null, route: null, type: 'empty-body' });
            sendJson(response, 400, { error: 'Empty request body' });

            return;
        }

        let page: InertiaPage;

        try {
            page = JSON.parse(raw) as InertiaPage;
        } catch (error) {
            fail({ message: error instanceof Error ? error.message : 'Invalid JSON', component: null, route: null, type: 'invalid-json' });
            sendJson(response, 400, { error: 'Invalid JSON in request body' });

            return;
        }

        const component = typeof page?.component === 'string' ? page.component : null;
        const route = routeOnly(page?.url);

        try {
            const result = await renderPage(page);
            sendJson(response, 200, result);
        } catch (error) {
            // One line per error: a single missing prop can fail more than
            // one template branch, and each message is a clue.
            const errors = error instanceof AggregateError ? error.errors : [error];

            for (const each of errors) {
                fail({
                    message: messageOf(each),
                    component,
                    route,
                    type: 'render-error',
                    source: sourceLocation(each instanceof Error ? each.stack : undefined),
                });
            }

            // What the Laravel gateway reads into SsrRenderFailed — the same
            // shape @inertiajs/core answers with, minus its stack.
            sendJson(response, 500, { error: messageOf(errors[0] ?? error), type: 'render', component });
        }
    }

    return createServer((request, response) => {
        if (request.url === '/render') {
            // handleRender catches every step that can throw; this is the
            // backstop that keeps any rejection it did not foresee from
            // becoming an unhandled one — the exact failure mode (Node >=15
            // kills the process) that made ssr.log look like restarts.
            handleRender(request, response).catch((error: unknown) => {
                fail({ message: messageOf(error), component: null, route: null, type: 'render-error' });
                sendJson(response, 500, { error: 'SSR render error', type: 'render' });
            });

            return;
        }

        if (request.url === '/health') {
            sendJson(response, 200, { status: 'OK', timestamp: Date.now() });

            return;
        }

        if (request.url === '/shutdown') {
            // Exit only once the response has actually been flushed to the
            // socket — `process.exit()` right after `.end()` can cut the
            // write off before `inertia:stop-ssr` sees it.
            response.writeHead(200, { 'Content-Type': 'application/json', Server: 'Inertia.js SSR' });
            response.end(JSON.stringify({ status: 'OK', timestamp: Date.now() }), () => process.exit());

            return;
        }

        sendJson(response, 404, { status: 'NOT_FOUND', timestamp: Date.now() });
    });
}
