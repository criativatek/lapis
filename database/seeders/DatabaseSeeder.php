<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reference data, not demo data — needed in every environment. Plans gate
        // access; system scales are the shared 1–5 / 0–20 / 0–100 every org can use.
        $this->call([
            EntitlementsSeeder::class,
            SystemScalesSeeder::class,
            InstrumentTypesSeeder::class,
        ]);

        if (app()->environment('local', 'testing')) {
            User::factory()->create([
                'name' => 'Professora Ana Martins',
                'email' => 'ana.martins@lapis.test',
            ]);
        }
    }
}
