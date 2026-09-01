<?php

namespace Tests\Support;

use App\Support\Release\BuildsSsrBundle;
use Tests\TestCase;

/**
 * The default `BuildsSsrBundle` for the ordinary test suite.
 *
 * Reports success without touching Node or the filesystem, leaving
 * `bootstrap/ssr` exactly as the checkout has it — the pre-fix behaviour,
 * kept here on purpose so the hundreds of tests that never asked about SSR
 * packaging do not start requiring npm just because `BuildPackageCommand`
 * now depends on {@see BuildsSsrBundle}. Bound in {@see TestCase}.
 *
 * The tests that actually exercise SSR-freshness enforcement
 * (`SsrBundleFreshnessTest`) replace this binding per-test with doubles that
 * simulate a real build succeeding, failing, or replacing stale content.
 */
class PassthroughSsrBundleBuilder implements BuildsSsrBundle
{
    public function build(): bool
    {
        return true;
    }
}
