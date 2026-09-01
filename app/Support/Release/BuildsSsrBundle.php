<?php

namespace App\Support\Release;

/**
 * Rebuilds `bootstrap/ssr` before `lapis:build-package` reads it.
 *
 * The command used to treat "the directory exists" as "the directory matches
 * the current source" — it never rebuilt anything, only inventoried whatever
 * `npm run build:ssr` had last written there, on whatever machine, whenever
 * that was. Three releases in a row (0.105.4, 0.105.5, 0.105.6) shipped a
 * committed SSR fix while production kept running a bundle built on 30
 * August, because the documented pre-package step was `npm run build` — the
 * client-only script, which never touches `bootstrap/ssr` at all.
 *
 * Bound to {@see NpmSsrBundleBuilder} in production, so packaging a release
 * always rebuilds the bundle from the tree being packaged; bound to a
 * deterministic test double in `Tests\TestCase` so the ordinary test suite
 * never has to shell out to Node.
 */
interface BuildsSsrBundle
{
    /**
     * @return bool whether the bundle was rebuilt successfully. `false` must
     *              stop the package from being produced — it must never fall
     *              back to whatever was already on disk.
     */
    public function build(): bool;
}
