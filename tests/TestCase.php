<?php

namespace Tests;

use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Plans and modules are reference data the app cannot function without —
     * a teacher with no Base plan is entitled to nothing — so every test that
     * refreshes the database gets the catalogue, the same as production.
     */
    protected bool $seed = true;

    protected string $seeder = EntitlementsSeeder::class;

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
