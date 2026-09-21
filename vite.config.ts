import { readFileSync } from 'node:fs';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';
import { tesseractAssets } from './resources/build/vite-plugin-tesseract-assets';

/**
 * config('app.version'), read straight from config/app.php rather than
 * duplicated in package.json (which has no "version" field) or an env var
 * (APP_VERSION does not exist — the backend hardcodes it, bumped by hand
 * with every release per that file's own comment). Only resources/js/ssr.ts
 * reads this, to label its structured error log; a missing/unparsable
 * version never breaks the build, since a stopped SSR server is worse than
 * an unlabelled log line.
 */
function readAppVersion(): string {
    try {
        const contents = readFileSync(new URL('./config/app.php', import.meta.url), 'utf8');
        const match = contents.match(/'version'\s*=>\s*'([^']+)'/);

        return match?.[1] ?? 'unknown';
    } catch {
        return 'unknown';
    }
}

export default defineConfig({
    /*
     * The SSR bundle carries its own dependencies. Vite externalises them by
     * default, which means the bundle expects a node_modules beside it — and
     * production has none: what travels there is the release package, not the
     * build machine's tree. `noExternal` inlines Vue, Inertia and the rest
     * into bootstrap/ssr/ssr.js, so Node needs the one file.
     */
    ssr: {
        noExternal: true,
    },
    define: {
        __APP_VERSION__: JSON.stringify(readAppVersion()),
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // A voz humana do DESIGN.md — só saudação, horas do
                // «Arrumado» e frase de fecho. Dose pequena por regra.
                bunny('Newsreader', {
                    weights: [400, 500, 600],
                    styles: ['normal', 'italic'],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        tesseractAssets(),
    ],
});
