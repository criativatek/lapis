import { createHash } from 'node:crypto';
import { copyFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import type { Plugin } from 'vite';

/**
 * Self-hosts the tesseract.js runtime assets used by the in-browser OCR seam
 * (resources/js/pages/classes/partials/characterisation-image-extraction.ts)
 * — worker, WASM core and the Portuguese traineddata — under
 * public/vendor/tesseract/, so nothing is fetched from tesseract.js's own
 * CDN default at runtime (the images this feature handles may contain the
 * names of minors; we do not take a runtime dependency on someone else's
 * server for them).
 *
 * The assets are NOT committed: they already ship inside npm packages we
 * install anyway (`tesseract.js`, `tesseract.js-core`, and
 * `@tesseract.js-data/por` for the traineddata), so committing a second copy
 * would just be 7+ MB of binaries duplicated forever in git history. Instead
 * this plugin copies the handful of files actually used — one WASM core
 * variant (not all of tesseract.js-core's ~44 MB of variants), its loader,
 * the worker, and the Portuguese model — from node_modules into public/ at
 * build time. It runs on both `vite build` and `vite dev` (via `buildStart`,
 * which fires for both) and is idempotent: it only writes a file when it is
 * missing or its content differs, so repeated dev-server restarts don't
 * thrash the filesystem.
 *
 * Mirrors the precedent set by @laravel/vite-plugin-wayfinder in
 * vite.config.ts: generated, environment-specific output lives under
 * public/vendor/tesseract (gitignored, like resources/js/routes and
 * resources/js/actions), never in git.
 */

const require = createRequire(import.meta.url);

const TARGET_DIR = 'public/vendor/tesseract';

/**
 * The exact core variant characterisation-image-extraction.ts requests via
 * `corePath`. tesseract.js-core ships many variants (plain, SIMD,
 * relaxed-SIMD, each with/without a baked-in LSTM model); we only copy this
 * one plus its `.wasm` binary.
 */
const CORE_VARIANT = 'tesseract-core-simd-lstm';

/** Traineddata variant from @tesseract.js-data/por: the size-optimised,
 * int8-quantised model (~1.4 MB gzipped) rather than the package's default
 * `4.0.0` float model (~6.8 MB gzipped) — the previously committed asset was
 * ~1 MB, so `best_int` keeps the same footprint instead of ~7x-ing it. */
const TRAINEDDATA_VARIANT = '4.0.0_best_int';

interface CopyJob {
    from: string;
    to: string;
}

function resolveCopyJobs(): CopyJob[] {
    const tesseractCoreDir = path.dirname(require.resolve('tesseract.js-core/package.json'));
    const tesseractDistDir = path.join(path.dirname(require.resolve('tesseract.js/package.json')), 'dist');
    const porDataDir = path.dirname(require.resolve('@tesseract.js-data/por/package.json'));

    return [
        {
            from: path.join(tesseractDistDir, 'worker.min.js'),
            to: path.join(TARGET_DIR, 'worker.min.js'),
        },
        {
            from: path.join(tesseractCoreDir, `${CORE_VARIANT}.wasm.js`),
            to: path.join(TARGET_DIR, `${CORE_VARIANT}.wasm.js`),
        },
        {
            from: path.join(tesseractCoreDir, `${CORE_VARIANT}.wasm`),
            to: path.join(TARGET_DIR, `${CORE_VARIANT}.wasm`),
        },
        {
            from: path.join(porDataDir, TRAINEDDATA_VARIANT, 'por.traineddata.gz'),
            to: path.join(TARGET_DIR, 'por.traineddata.gz'),
        },
    ];
}

function sha256(filePath: string): string {
    return createHash('sha256').update(readFileSync(filePath)).digest('hex');
}

function copyIfChanged(job: CopyJob): void {
    if (existsSync(job.to) && sha256(job.to) === sha256(job.from)) {
        return;
    }

    mkdirSync(path.dirname(job.to), { recursive: true });
    copyFileSync(job.from, job.to);
}

export function tesseractAssets(): Plugin {
    return {
        name: 'lapis:tesseract-assets',
        // buildStart runs for both `vite build` and `vite dev`.
        buildStart() {
            for (const job of resolveCopyJobs()) {
                copyIfChanged(job);
            }
        },
    };
}
