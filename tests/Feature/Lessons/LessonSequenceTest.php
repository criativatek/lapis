<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonSequence;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LessonSequenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        // The apply test below depends on the lesson being "in the future" —
        // frozen so the suite never depends on the real wall clock.
        Carbon::setTestNow('2026-10-01 08:00:00');
    }

    #[Test]
    public function creating_a_sequence_persists_ordered_items_in_position_order(): void
    {
        $subject = $this->subjectFor();
        $year = $this->academicYearFor();

        $this->asTeacher()->post('/lessons/sequences', $this->sequencePayload($subject, $year, [
            ['summary' => 'Primeira aula.'],
            ['summary' => 'Segunda aula.'],
            ['summary' => 'Terceira aula.'],
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($subject, $year): void {
            $sequence = LessonSequence::query()->sole();
            $this->assertSame('Sequência de teste', $sequence->name);
            $this->assertSame($subject->id, $sequence->subject_id);
            $this->assertSame($year->id, $sequence->academic_year_id);
            $this->assertSame($this->teacher->id, $sequence->user_id);
            $this->assertSame(
                ['Primeira aula.', 'Segunda aula.', 'Terceira aula.'],
                $sequence->items()->orderBy('position')->pluck('summary')->all(),
            );
            $this->assertSame([1, 2, 3], $sequence->items()->orderBy('position')->pluck('position')->all());
        });
    }

    #[Test]
    public function editing_a_sequence_adds_updates_and_removes_items_without_touching_applied_summaries(): void
    {
        $subject = $this->subjectFor();
        $year = $this->academicYearFor();
        $schoolClass = $this->schoolClassFor($this->teacher, $subject, $year);
        $lesson = $this->lessonFor($schoolClass);

        $sequence = $this->sequenceFor($this->teacher, $subject, $year, [
            ['summary' => 'Manter e editar.'],
            ['summary' => 'Remover.'],
        ]);

        // Applied once — the item that survives the edit below must never be
        // retroactively rewritten in the lesson it was already copied into.
        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertRedirect();

        $keptItem = $this->inTenant($this->organization, fn () => $sequence->items()->orderBy('position')->first());

        $this->asTeacher()->put("/lessons/sequences/{$sequence->ulid}", $this->sequencePayload($subject, $year, [
            ['ulid' => $keptItem->ulid, 'summary' => 'Texto editado.'],
            ['summary' => 'Nova aula adicionada.'],
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($sequence, $keptItem, $lesson): void {
            $sequence->refresh();
            $this->assertCount(2, $sequence->items);
            $this->assertSame(
                ['Texto editado.', 'Nova aula adicionada.'],
                $sequence->items()->orderBy('position')->pluck('summary')->all(),
            );
            // The removed item is gone, and the kept one changed identity of
            // content but not of row (still the same ulid/id).
            $this->assertSame($keptItem->id, $sequence->items()->orderBy('position')->first()->id);
            $this->assertDatabaseCount('lesson_sequence_items', 2);

            // The already-applied lesson still has its original copy — never
            // rewritten by editing the source sequence afterwards.
            $summary = $lesson->summary()->sole();
            $this->assertSame('Manter e editar.', $summary->content);
        });
    }

    #[Test]
    public function a_non_owning_teacher_is_forbidden_from_viewing_editing_or_deleting_but_may_create_their_own(): void
    {
        $subject = $this->subjectFor();
        $year = $this->academicYearFor();
        $sequence = $this->sequenceFor($this->teacher, $subject, $year, [['summary' => 'Original.']]);
        $schoolClass = $this->schoolClassFor($this->teacher, $subject, $year);

        $otherTeacher = User::factory()->create();
        $this->organization->members()->attach($otherTeacher, ['joined_at' => now()]);
        $otherSession = $this->actingAs($otherTeacher)->withSession(['organization_id' => $this->organization->id]);

        // "View" has no dedicated route of its own — apply is the one action
        // that reads a sequence's items, so it is what exercises the ability.
        $otherSession->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertForbidden();

        $this->actingAs($otherTeacher)->withSession(['organization_id' => $this->organization->id])
            ->put("/lessons/sequences/{$sequence->ulid}", $this->sequencePayload($subject, $year, [['summary' => 'Roubado.']]))
            ->assertForbidden();

        $this->actingAs($otherTeacher)->withSession(['organization_id' => $this->organization->id])
            ->delete("/lessons/sequences/{$sequence->ulid}")
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_sequences', 1);

        // But creating their own sequence is unrestricted.
        $this->actingAs($otherTeacher)->withSession(['organization_id' => $this->organization->id])
            ->post('/lessons/sequences', $this->sequencePayload($subject, $year, [['summary' => 'Sequência do outro professor.']]))
            ->assertRedirect();

        $this->assertDatabaseCount('lesson_sequences', 2);
    }

    #[Test]
    public function impersonation_blocks_create_update_and_delete(): void
    {
        $subject = $this->subjectFor();
        $year = $this->academicYearFor();
        $sequence = $this->sequenceFor($this->teacher, $subject, $year, [['summary' => 'Original.']]);
        $session = ['organization_id' => $this->organization->id, 'impersonator_id' => 999];

        $this->actingAs($this->teacher)->withSession($session)
            ->post('/lessons/sequences', $this->sequencePayload($subject, $year, [['summary' => 'Bloqueado.']]))
            ->assertForbidden();

        $this->actingAs($this->teacher)->withSession($session)
            ->put("/lessons/sequences/{$sequence->ulid}", $this->sequencePayload($subject, $year, [['summary' => 'Bloqueado.']]))
            ->assertForbidden();

        $this->actingAs($this->teacher)->withSession($session)
            ->delete("/lessons/sequences/{$sequence->ulid}")
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_sequences', 1);
        $this->inTenant($this->organization, fn () => $this->assertSame('Original.', $sequence->items()->sole()->summary));
    }

    private function asTeacher(): self
    {
        return $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id]);
    }

    private function subjectFor(): Subject
    {
        return $this->inTenant($this->organization, fn () => Subject::factory()->recycle($this->organization)->create());
    }

    private function academicYearFor(): AcademicYear
    {
        return $this->inTenant($this->organization, fn () => AcademicYear::factory()
            ->recycle($this->organization)
            ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']));
    }

    private function schoolClassFor(User $teacher, Subject $subject, AcademicYear $year, ?string $gradeLevel = '7.º'): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($teacher, $subject, $year, $gradeLevel): SchoolClass {
            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create([
                'subject_id' => $subject->id,
                'academic_year_id' => $year->id,
                'grade_level' => $gradeLevel,
            ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    private function lessonFor(SchoolClass $schoolClass, string $startsAt = '2026-10-08 09:30:00', LessonStatus $status = LessonStatus::Preparation): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::create([
            'class_id' => $schoolClass->id,
            'starts_at' => $startsAt,
            'ends_at' => null,
            'status' => $status,
            'created_by' => $this->teacher->id,
        ]));
    }

    /**
     * @param  list<array{summary: string}>  $items
     */
    private function sequenceFor(User $teacher, Subject $subject, AcademicYear $year, array $items, ?string $gradeLevel = null): LessonSequence
    {
        return $this->inTenant($this->organization, function () use ($teacher, $subject, $year, $items, $gradeLevel): LessonSequence {
            $sequence = LessonSequence::create([
                'user_id' => $teacher->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $year->id,
                'grade_level' => $gradeLevel,
                'name' => 'Sequência de teste',
            ]);

            foreach ($items as $index => $item) {
                $sequence->items()->create([
                    'position' => $index + 1,
                    'summary' => $item['summary'],
                    'private_notes' => $item['private_notes'] ?? null,
                    'resources' => $item['resources'] ?? null,
                    'homework' => $item['homework'] ?? null,
                ]);
            }

            return $sequence;
        });
    }

    /**
     * @param  list<array{ulid?: string, summary: string}>  $items
     * @return array<string, mixed>
     */
    private function sequencePayload(Subject $subject, AcademicYear $year, array $items, ?string $gradeLevel = null): array
    {
        return [
            'name' => 'Sequência de teste',
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'grade_level' => $gradeLevel,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applyPayload(SchoolClass $schoolClass, array $overrides = []): array
    {
        return array_merge([
            'class_id' => $schoolClass->id,
            'summary' => true,
            'resources' => true,
            'homework' => true,
            'private_notes' => true,
        ], $overrides);
    }

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
