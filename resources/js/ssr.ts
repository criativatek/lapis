import { createInertiaApp } from '@inertiajs/vue3';
import { renderToString } from 'vue/server-renderer';
import type * as VueServerRenderer from 'vue/server-renderer';
import { formatTitle, resolveLayout } from '@/inertia';
import { createRenderErrorCollector, createSsrServer } from '@/ssr/server';
import type { InertiaPage } from '@/ssr/server';

/**
 * Server-side rendering, for the pages a crawler has to be able to read.
 *
 * Without it the response for the landing page was a 14 KB shell with no
 * <h1>, no copy and no links — everything arrived with the JavaScript, which
 * Google renders late and with a budget, and Bing, LinkedIn, WhatsApp and the
 * LLM crawlers do not render at all. The SEO tags were already server-side
 * (app.blade.php); this puts the page itself there too. WHICH pages are sent
 * here is decided on the Laravel side (App\Support\Ssr\ServerRenderedPages):
 * only those, and never the authenticated shell.
 *
 * Runs as `php artisan inertia:start-ssr` (`node bootstrap/ssr/ssr.js`, a
 * production build with `import.meta.env.PROD` true), on port 13714
 * (config/inertia.php). When that process is down Inertia falls back to
 * client rendering, so a stopped SSR server is a slower crawl, never a
 * broken page. Built by `npm run build:ssr` into bootstrap/ssr/, which is
 * gitignored and travels in the release package like public/build.
 *
 * This file deliberately does NOT leave a bare `createInertiaApp(...)` for the
 * @inertiajs/vite plugin to wrap with `@inertiajs/core`'s own `createServer`:
 * that server dies on a malformed request body and logs without timestamps.
 * And it sets a Vue error handler for every render (`withApp`), because in a
 * production build Vue only `console.error`s a component that throws and
 * carries on — which is what filled ssr.log with bare stacks. See
 * resources/js/ssr/server.ts for both. The plugin still serves this entry in
 * dev through the default export below.
 */

type SsrRenderFunction = (page: InertiaPage, renderToString: typeof VueServerRenderer.renderToString) => Promise<{ head: string[]; body: string }>;

const collector = createRenderErrorCollector();

// createInertiaApp()'s return type is `void | RenderFunction<...>` because
// the same function also covers the browser bootstrap path, which returns
// nothing. Under Node (no `window`) and without `page`/`render` options —
// exactly how it is called here — it always resolves to the SSR render
// function; the cast only narrows what TypeScript cannot see from the
// overload.
const render = (await createInertiaApp({
    title: formatTitle,
    layout: resolveLayout,
    withApp: collector.withApp,
})) as unknown as SsrRenderFunction;

const renderPage = collector.wrap((page: InertiaPage) => render(page, renderToString));

if (import.meta.env.PROD) {
    // config('app.version') at build time — see the `__APP_VERSION__` define
    // in vite.config.ts. Falls back to "unknown" rather than throwing if the
    // define is ever missing: a stopped SSR server is worse than an
    // unlabelled log line.
    const release = typeof __APP_VERSION__ === 'string' ? __APP_VERSION__ : 'unknown';
    const port = 13714;

    createSsrServer({ renderPage, release }).listen({ port, host: '0.0.0.0' }, () => {
        console.log(`Inertia SSR server started on port ${port}.`);
    });
}

export default renderPage;
