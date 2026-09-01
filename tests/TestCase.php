<?php

namespace Tests;

use App\Support\Release\BuildsSsrBundle;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\PassthroughSsrBundleBuilder;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reference data the app cannot function without — plans/modules and the
     * system scales — seeded for every test that refreshes the database, the
     * same baseline production has, minus the demo accounts.
     */
    protected bool $seed = true;

    protected string $seeder = ReferenceDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // lapis:build-package rebuilds bootstrap/ssr for real before reading it
        // (BuildsSsrBundle). Bound here, globally, so the ordinary suite never
        // shells out to npm — only SsrBundleFreshnessTest replaces this
        // per-test to exercise the rebuild-succeeds/fails/replaces-stale paths.
        $this->app->bind(BuildsSsrBundle::class, PassthroughSsrBundleBuilder::class);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
