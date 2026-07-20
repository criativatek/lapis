<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function validation_errors_are_in_portuguese_not_raw_keys(): void
    {
        $user = User::factory()->create();

        // The bug this guards against: with no lang/pt_PT file, Laravel echoes the
        // key itself ("validation.required") straight to the teacher.
        $this->actingAs($user)
            ->post('/subjects', ['name' => '', 'code' => ''])
            ->assertInvalid(['name' => 'obrigatório']);
    }

    #[Test]
    public function field_names_are_translated_in_messages(): void
    {
        $user = User::factory()->create();

        // "código", not "code" — the teacher reads the field name they saw.
        $this->actingAs($user)
            ->post('/subjects', ['name' => 'Português', 'code' => ''])
            ->assertInvalid(['code' => 'código']);
    }

    #[Test]
    public function the_application_runs_in_portuguese(): void
    {
        $this->assertSame('pt_PT', config('app.locale'));
        $this->assertSame('Europe/Lisbon', config('app.timezone'));
    }
}
