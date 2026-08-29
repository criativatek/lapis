import { createInertiaApp } from '@inertiajs/vue3';
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
 * This file is deliberately just the configuration call: in Inertia's auto
 * mode the @inertiajs/vite plugin wraps a top-level `createInertiaApp(...)`
 * with `createServer` and `renderToString` at build time, so `page`, `render`,
 * `resolve` and `setup` are not written here.
 *
 * Runs as `php artisan inertia:start-ssr` (a Node process on port 13714,
 * config/inertia.php). When that process is down Inertia falls back to
 * client rendering, so a stopped SSR server is a slower crawl, never a
 * broken page. Built by `npm run build:ssr` into bootstrap/ssr/, which is
 * gitignored and travels in the release package like public/build.
 */
createInertiaApp({
    title: formatTitle,
    layout: resolveLayout,
});
