<?php

namespace App\Support\Release;

use Illuminate\Support\Facades\Process;

/**
 * The real rebuild: `npm run build:ssr` in the project root.
 *
 * That script is `vite build && vite build --ssr` (package.json) — it always
 * rebuilds the client bundle first, so a run of this never leaves the client
 * and SSR bundles produced from two different local states. Its own exit
 * code is trusted directly: a non-zero exit fails the build, full stop,
 * rather than being inspected for a partial success to salvage.
 */
class NpmSsrBundleBuilder implements BuildsSsrBundle
{
    public function build(): bool
    {
        $result = Process::path(base_path())
            ->timeout(600)
            ->run('npm run build:ssr');

        return $result->successful();
    }
}
