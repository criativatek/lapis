<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonSequence;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
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

class ApplyLessonSequenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $teacher;

    private Subject $subject;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);
        $this->subject = $this->subjectFor();
        $this->year = $this->academicYearFor();

        Carbon::setTestNow('2026-10-01 08:00:00');
    }

    #[Test]
    public function applying_with_all_options_creates_independent_summaries_derives_prepared_and_skips_taught_lessons(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $taughtLesson = $this->lessonFor($schoolClass, '2026-10-05 09:00:00', LessonStatus::Taught);
        $lessonOne = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $lessonTwo = $this->lessonFor($schoolClass, '2026-10-07 09:00:00');

        $sequence = $this->sequenceFor([
            ['summary' => 'Sumário 1.', 'private_notes' => 'Nota 1.', 'resources' => 'Recurso 1.', 'homework' => 'TPC 1.'],
            ['summary' => 'Sumário 2.', 'private_notes' => 'Nota 2.', 'resources' => 'Recurso 2.', 'homework' => 'TPC 2.'],
        ]);

        $response = $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass));
        $response->assertRedirect();

        // A fully successful apply — nothing preserved, nothing unavailable —
        // reads as one clean sentence, with no trailing clauses.
        $response->assertSessionHas(
            'inertia.flash_data',
            fn ($flash) => ($flash['toast'] ?? null) === ['type' => 'success', 'message' => 'Sequência aplicada a 2 aulas.'],
        );

        $this->inTenant($this->organization, function () use ($schoolClass, $taughtLesson, $lessonOne, $lessonTwo): void {
            $this->assertSame(LessonStatus::Taught, $taughtLesson->refresh()->status);
            $this->assertNull($taughtLesson->summary()->first());

            $summaryOne = $lessonOne->refresh()->summary()->sole();
            $this->assertSame('Sumário 1.', $summaryOne->content);
            $this->assertSame('Nota 1.', $summaryOne->private_notes);
            $this->assertSame('Recurso 1.', $summaryOne->resources);
            $this->assertSame('TPC 1.', $summaryOne->homework);
            $this->assertSame(LessonStatus::Prepared, $lessonOne->status);

            $summaryTwo = $lessonTwo->refresh()->summary()->sole();
            $this->assertSame('Sumário 2.', $summaryTwo->content);
            $this->assertSame(LessonStatus::Prepared, $lessonTwo->status);

            $event = AuditEvent::query()->where('event', 'lesson_sequence.applied')->sole();
            $this->assertSameJsonPayload([
                'class_id' => $schoolClass->id,
                'items_applied' => 2,
                'items_skipped' => 0,
                'copy_options' => ['summary' => true, 'resources' => true, 'homework' => true, 'private_notes' => true],
            ], $event->properties);
        });
    }

    #[Test]
    public function selected_fields_only_fill_in_what_is_blank_at_the_destination_and_leave_the_rest_untouched(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => 'Conteúdo original.',
            'private_notes' => null,
            'resources' => null,
            'homework' => 'TPC original.',
        ]));

        $sequence = $this->sequenceFor([
            ['summary' => 'Conteúdo novo.', 'private_notes' => 'Nota nova.', 'resources' => 'Recurso novo.', 'homework' => 'TPC novo.'],
        ]);

        // All four options selected — but content and homework are already
        // filled at the destination, while private_notes and resources are
        // blank there.
        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            // Already filled — untouched, despite being selected.
            $this->assertSame('Conteúdo original.', $summary->content);
            $this->assertSame('TPC original.', $summary->homework);
            // Blank at the destination — filled in from the sequence item.
            $this->assertSame('Recurso novo.', $summary->resources);
            $this->assertSame('Nota nova.', $summary->private_notes);
            $this->assertDatabaseCount('lesson_summaries', 1);
        });
    }

    #[Test]
    public function a_lesson_with_no_existing_summary_skips_content_when_the_summary_option_is_off_but_still_gets_other_selected_fields(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([[
            'summary' => 'Nunca deve aparecer — a opção Sumário está desligada.',
            'resources' => 'Recurso do item.',
        ]]);

        // "summary" explicitly off, "resources" on. There is no existing row,
        // but resources still has something genuine to write, so the row is
        // created — content falls back to '' as a structural placeholder,
        // never to the sequence item's actual summary text.
        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass, [
            'summary' => false,
            'resources' => true,
            'homework' => false,
            'private_notes' => false,
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('', $summary->content);
            $this->assertSame('Recurso do item.', $summary->resources);
            $this->assertNull($summary->homework);
            $this->assertNull($summary->private_notes);
        });
    }

    #[Test]
    public function a_lesson_with_no_existing_summary_and_nothing_applicable_is_skipped_entirely_without_creating_a_row(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([[
            'summary' => 'Texto que nunca deve ser aplicado.',
            'private_notes' => 'Nota que nunca deve ser aplicada.',
            'resources' => 'Recurso que nunca deve ser aplicado.',
            'homework' => 'TPC que nunca deve ser aplicado.',
        ]]);

        // Every option off — there is nothing at all to apply, so the lesson
        // is skipped entirely rather than getting an empty placeholder row.
        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass, [
            'summary' => false,
            'resources' => false,
            'homework' => false,
            'private_notes' => false,
        ]))->assertRedirect();

        $this->assertDatabaseMissing('lesson_summaries', ['lesson_id' => $lesson->id]);
        $this->assertDatabaseCount('lesson_summaries', 0);

        $this->inTenant($this->organization, function (): void {
            $event = AuditEvent::query()->where('event', 'lesson_sequence.applied')->sole();
            $this->assertSame(0, $event->properties['items_applied']);
            $this->assertSame(0, $event->properties['items_skipped']);
        });
    }

    #[Test]
    public function summary_option_off_leaves_a_blank_existing_content_field_unwritten(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        // A LessonSummary row can already exist with an empty content field
        // (e.g. left that way by a previous sequence application) — a
        // legitimate, anticipated state, not a broken one.
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => '',
        ]));

        $sequence = $this->sequenceFor([['summary' => 'Nunca deve substituir o vazio.']]);

        // The option being off blocks the write on its own — independent of
        // whether the destination happens to already be blank.
        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass, [
            'summary' => false,
            'resources' => false,
            'homework' => false,
            'private_notes' => false,
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('', $summary->content);
            // Nothing was actually written, so Preparation never derives to
            // Prepared — SaveLessonSummary was never called for this lesson.
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
            $this->assertDatabaseCount('lesson_summaries', 1);
        });
    }

    #[Test]
    public function selecting_summary_when_the_destination_already_has_content_leaves_it_unchanged(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => 'Conteúdo já escrito à mão.',
        ]));

        $sequence = $this->sequenceFor([['summary' => 'Novo conteúdo que nunca deve aparecer.']]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass, [
            'summary' => true,
            'resources' => false,
            'homework' => false,
            'private_notes' => false,
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('Conteúdo já escrito à mão.', $summary->content);
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
        });
    }

    #[Test]
    public function selecting_resources_when_the_destination_already_has_them_leaves_them_unchanged(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => '',
            'resources' => 'Recurso já definido.',
        ]));

        $sequence = $this->sequenceFor([['summary' => '', 'resources' => 'Recurso novo — nunca deve aparecer.']]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass, [
            'summary' => false,
            'resources' => true,
            'homework' => false,
            'private_notes' => false,
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('Recurso já definido.', $summary->resources);
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
        });
    }

    #[Test]
    public function selecting_homework_when_the_destination_already_has_it_leaves_it_unchanged(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => '',
            'homework' => 'TPC já definido.',
        ]));

        $sequence = $this->sequenceFor([['summary' => '', 'homework' => 'TPC novo — nunca deve aparecer.']]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass, [
            'summary' => false,
            'resources' => false,
            'homework' => true,
            'private_notes' => false,
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('TPC já definido.', $summary->homework);
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
        });
    }

    #[Test]
    public function selecting_private_notes_when_the_destination_already_has_them_leaves_them_unchanged(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => '',
            'private_notes' => 'Nota já definida.',
        ]));

        $sequence = $this->sequenceFor([['summary' => '', 'private_notes' => 'Nota nova — nunca deve aparecer.']]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass, [
            'summary' => false,
            'resources' => false,
            'homework' => false,
            'private_notes' => true,
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('Nota já definida.', $summary->private_notes);
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
        });
    }

    #[Test]
    public function unselected_fields_stay_untouched_regardless_of_existing_content_or_what_the_sequence_offered(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => 'Conteúdo existente que não deve mudar.',
            'resources' => null,
            'homework' => 'TPC existente que não deve mudar.',
            'private_notes' => 'Nota existente que não deve mudar.',
        ]));

        $sequence = $this->sequenceFor([[
            'summary' => 'Sumário novo — nunca deve aparecer.',
            'resources' => 'Recurso novo.',
            'homework' => 'TPC novo — nunca deve aparecer.',
            'private_notes' => 'Nota nova — nunca deve aparecer.',
        ]]);

        // Only resources is selected. content/homework/private_notes are
        // never even considered, no matter that the sequence offers
        // different, non-blank text for them.
        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass, [
            'summary' => false,
            'resources' => true,
            'homework' => false,
            'private_notes' => false,
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('Recurso novo.', $summary->resources);
            $this->assertSame('Conteúdo existente que não deve mudar.', $summary->content);
            $this->assertSame('TPC existente que não deve mudar.', $summary->homework);
            $this->assertSame('Nota existente que não deve mudar.', $summary->private_notes);
        });
    }

    #[Test]
    public function blank_values_in_the_sequence_item_never_overwrite_or_clear_an_existing_but_blank_destination_and_the_lesson_is_preserved(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => '',
            'resources' => null,
            'homework' => null,
            'private_notes' => null,
        ]));

        // Every field blank at the source too — even with every option
        // selected, there is nothing genuine to copy.
        $sequence = $this->sequenceFor([['summary' => '']]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('', $summary->content);
            $this->assertNull($summary->resources);
            $this->assertNull($summary->homework);
            $this->assertNull($summary->private_notes);
            // Nothing changed, so SaveLessonSummary was never called — no
            // false "prepared" transition for a lesson that was not touched.
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);

            $event = AuditEvent::query()->where('event', 'lesson_sequence.applied')->sole();
            $this->assertSame(0, $event->properties['items_applied']);
            $this->assertSame(0, $event->properties['items_skipped']);
        });
    }

    #[Test]
    public function private_notes_is_never_copied_unless_explicitly_requested(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $existingLesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $existingLesson->id,
            'content' => 'Conteúdo.',
            'private_notes' => 'Nota que tem de sobreviver.',
        ]));

        $sequence = $this->sequenceFor([
            ['summary' => 'Novo conteúdo.', 'private_notes' => 'Nota do item — nunca deve ser copiada.'],
        ]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass, [
            'summary' => true,
            'resources' => true,
            'homework' => true,
            'private_notes' => false,
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($existingLesson): void {
            $summary = $existingLesson->summary()->sole();
            $this->assertSame('Nota que tem de sobreviver.', $summary->private_notes);
        });
    }

    #[Test]
    public function fewer_eligible_lessons_than_items_applies_to_the_available_ones_and_reports_counts(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([
            ['summary' => 'Item 1.'],
            ['summary' => 'Item 2.'],
            ['summary' => 'Item 3.'],
        ]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame('Item 1.', $lesson->summary()->sole()->content);
            $this->assertDatabaseCount('lesson_summaries', 1);

            $event = AuditEvent::query()->where('event', 'lesson_sequence.applied')->sole();
            $this->assertSame(1, $event->properties['items_applied']);
            $this->assertSame(2, $event->properties['items_skipped']);
        });
    }

    #[Test]
    public function zero_eligible_lessons_reports_zero_applied_without_erroring(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        // Only a past lesson exists — nothing eligible.
        $this->lessonFor($schoolClass, '2026-09-01 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item único.']]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('lesson_summaries', 0);
        $this->inTenant($this->organization, function (): void {
            $event = AuditEvent::query()->where('event', 'lesson_sequence.applied')->sole();
            $this->assertSame(0, $event->properties['items_applied']);
            $this->assertSame(1, $event->properties['items_skipped']);
        });
    }

    #[Test]
    public function apply_reports_preserved_and_unavailable_counts_distinctly_in_the_flash_message(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        // Only one eligible lesson for a two-item sequence.
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([
            ['summary' => 'Item 1.'],
            ['summary' => 'Item 2.'],
        ]);

        // Nothing selected — the one matched lesson is preserved (nothing to
        // write), and the second item has no lesson left to pair with.
        $response = $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass, [
            'summary' => false,
            'resources' => false,
            'homework' => false,
            'private_notes' => false,
        ]));
        $response->assertRedirect();

        $response->assertSessionHas('inertia.flash_data', fn ($flash) => ($flash['toast'] ?? null) === [
            'type' => 'warning',
            'message' => 'Sequência aplicada a 0 aulas. 1 aula já tinha conteúdo próprio e foi preservada. 1 item sem aula disponível.',
        ]);

        $this->assertDatabaseMissing('lesson_summaries', ['lesson_id' => $lesson->id]);
    }

    #[Test]
    public function a_class_of_a_different_subject_is_rejected_before_touching_any_lesson(): void
    {
        $otherSubject = $this->subjectFor();
        $schoolClass = $this->schoolClassFor($this->teacher, $otherSubject);
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item.']]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertRedirect()
            ->assertSessionHasErrors('class_id');

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function a_class_of_a_different_grade_level_is_rejected_when_the_sequence_declares_one(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, gradeLevel: '8.º');
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item.']], gradeLevel: '7.º');

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertRedirect()
            ->assertSessionHasErrors('class_id');

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function a_sequence_with_no_grade_level_matches_a_class_of_any_grade_level(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, gradeLevel: 'Licenciatura');
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item universal.']], gradeLevel: null);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('lesson_summaries', 1);
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_target_class_is_forbidden(): void
    {
        $schoolClass = $this->inTenant($this->organization, function (): SchoolClass {
            // No teachers()->attach() — the sequence owner does not teach it.
            return SchoolClass::factory()->recycle($this->organization)->create([
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->year->id,
                'grade_level' => '7.º',
            ]);
        });
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item.']]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function impersonation_blocks_applying_a_sequence(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item.']]);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => 999])
            ->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_summaries', 0);
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

    private function schoolClassFor(User $teacher, ?Subject $subject = null, ?string $gradeLevel = '7.º'): SchoolClass
    {
        $subject ??= $this->subject;

        return $this->inTenant($this->organization, function () use ($teacher, $subject, $gradeLevel): SchoolClass {
            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create([
                'subject_id' => $subject->id,
                'academic_year_id' => $this->year->id,
                'grade_level' => $gradeLevel,
            ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    private function lessonFor(SchoolClass $schoolClass, string $startsAt, LessonStatus $status = LessonStatus::Preparation): Lesson
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
     * @param  list<array{summary: string, private_notes?: ?string, resources?: ?string, homework?: ?string}>  $items
     */
    private function sequenceFor(array $items, ?string $gradeLevel = null): LessonSequence
    {
        return $this->inTenant($this->organization, function () use ($items, $gradeLevel): LessonSequence {
            $sequence = LessonSequence::create([
                'user_id' => $this->teacher->id,
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->year->id,
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
     * @param  array<string, mixed>  $overrides
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
