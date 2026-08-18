<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Only the reference data the app cannot run without — no demo users. This is
 * what tests seed, so their database mirrors production's baseline without the
 * local demo accounts DatabaseSeeder adds.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            EntitlementsSeeder::class,
            SystemScalesSeeder::class,
            InstrumentTypesSeeder::class,
            ReportLibrarySeeder::class,
        ]);
    }
}
