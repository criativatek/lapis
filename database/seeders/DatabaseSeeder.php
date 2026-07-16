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
        // Plans and modules are reference data, not demo data: without them no
        // organization is entitled to anything. This must run in every environment.
        $this->call(EntitlementsSeeder::class);

        if (app()->environment('local', 'testing')) {
            User::factory()->create([
                'name' => 'Professora Ana Martins',
                'email' => 'ana.martins@lapis.test',
            ]);
        }
    }
}
