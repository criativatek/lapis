<?php

namespace Tests\Feature\Subjects;

use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubjectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_teacher_creates_a_subject(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/subjects', ['name' => 'Português', 'code' => 'PT7'])
            ->assertRedirect('/subjects');

        $subject = Subject::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('Português', $subject->name);
        $this->assertSame($user->personalOrganization()->getKey(), $subject->organization_id);
    }

    #[Test]
    public function the_code_is_unique_within_the_organization_but_not_across(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/subjects', ['name' => 'Português', 'code' => 'PT7']);

        $this->actingAs($user)->post('/subjects', ['name' => 'Outro', 'code' => 'PT7'])->assertSessionHasErrors('code');

        $other = User::factory()->create();
        $this->actingAs($other)->post('/subjects', ['name' => 'Português', 'code' => 'PT7'])->assertRedirect('/subjects');

        $this->assertSame(2, Subject::withoutGlobalScope('organization')->count());
    }

    #[Test]
    public function a_teacher_cannot_touch_another_organizations_subject(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/subjects', ['name' => 'Português', 'code' => 'PT7']);
        $subject = Subject::withoutGlobalScope('organization')->firstOrFail();

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->put("/subjects/{$subject->ulid}", ['name' => 'X', 'code' => 'X'])->assertNotFound();
        $this->actingAs($intruder)->delete("/subjects/{$subject->ulid}")->assertNotFound();
    }

    #[Test]
    public function a_teacher_updates_and_deletes_their_subject(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/subjects', ['name' => 'Português', 'code' => 'PT7']);
        $subject = Subject::firstOrFail();

        $this->actingAs($user)->put("/subjects/{$subject->ulid}", ['name' => 'Português A', 'code' => 'PTA7'])->assertRedirect();
        $this->assertSame('Português A', $subject->refresh()->name);

        $this->actingAs($user)->delete("/subjects/{$subject->ulid}")->assertRedirect();
        $this->assertDatabaseCount('subjects', 0);
    }

    #[Test]
    public function name_and_code_are_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/subjects', ['name' => '', 'code' => ''])
            ->assertSessionHasErrors(['name', 'code']);
    }

    #[Test]
    public function a_subject_in_use_is_never_deleted_and_the_teacher_reads_why(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        [$subject, $class] = app(CurrentOrganization::class)->runFor($organization, function () use ($organization): array {
            $subject = Subject::factory()->recycle($organization)->create(['name' => 'Português']);

            return [$subject, SchoolClass::factory()->recycle($organization)->create(['subject_id' => $subject->getKey()])];
        });

        $this->actingAs($user)->get('/subjects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('subjects.0.name', 'Português')
                ->where('subjects.0.in_use', true));

        // O DELETE direto, sem passar pelo botão desativado.
        $response = $this->actingAs($user)->from('/subjects')->delete("/subjects/{$subject->ulid}");

        $response->assertRedirect('/subjects');
        $this->assertNotSame(500, $response->getStatusCode());
        $toast = session('inertia.flash_data')['toast'];
        $this->assertSame('error', $toast['type']);
        $this->assertStringContainsString('Português', $toast['message']);
        $this->assertStringContainsString('turmas', $toast['message']);
        $this->assertStringNotContainsString('SQL', $toast['message']);
        $this->assertTrue(DB::table('subjects')->where('id', $subject->getKey())->exists());
        $this->assertTrue(DB::table('classes')->where('id', $class->getKey())->exists());
    }

    #[Test]
    public function every_foreign_key_pointing_at_subjects_is_accounted_for(): void
    {
        // Se uma nova FK para `subjects` aparecer sem entrar em SubjectUsage, o
        // 500 volta — e isto falha primeiro.
        $schema = DB::getSchemaBuilder();
        $tables = collect($schema->getTables())->pluck('name')
            ->filter(fn (string $table): bool => collect($schema->getForeignKeys($table))
                ->contains(fn (array $key): bool => $key['foreign_table'] === 'subjects'))
            ->sort()->values()->all();

        $this->assertSame(['assessment_profiles', 'classes', 'domains', 'lesson_sequences', 'report_library_entries'], $tables);
    }

    #[Test]
    public function an_unused_subject_is_deleted_and_marked_as_not_in_use(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/subjects', ['name' => 'Latim', 'code' => 'LAT']);

        $this->actingAs($user)->get('/subjects')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subjects.0.in_use', false));

        $subject = Subject::withoutGlobalScope('organization')->firstOrFail();
        $this->actingAs($user)->delete("/subjects/{$subject->ulid}")->assertRedirect('/subjects');
        $this->assertDatabaseCount('subjects', 0);
    }
}
