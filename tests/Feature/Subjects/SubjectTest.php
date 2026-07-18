<?php

namespace Tests\Feature\Subjects;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
