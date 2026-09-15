<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MarkLessonAsTaught;
use App\Actions\Lessons\MarkLessonsAsTaughtInBatch;
use App\Actions\Lessons\RecordLessonOutcome;
use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\TeacherAbsenceReason;
use App\Models\User;
use App\Services\Lessons\ClassAttendanceSummary;
use App\Services\Lessons\LessonNumbering;
use App\Services\Lessons\StudentAttendanceHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.146.0 — o resultado real da aula. Calendário de outubro de 2026: as
 * quintas-feiras são 08, 15, 22 e 29; as terças 06, 13, 20 e 27.
 */
class LessonOutcomeTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    #[Test]
    public function a_teacher_absence_is_not_numbered_and_keeps_its_status(): void
    {
        $slot = $this->makeSlot();
        [$first, $second, $third] = $this->thursdays($slot, ['08', '15', '22']);
        $this->resequence();

        $this->asTeacher()
            ->post("/lessons/{$second->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($first, $second, $third): void {
            $second->refresh();
            $this->assertSame(LessonOutcome::TeacherAbsent, $second->outcome);
            $this->assertSame(TeacherAbsenceReason::Training, $second->outcome_reason);
            $this->assertSame(LessonStatus::Preparation, $second->status);
            $this->assertNull($second->lesson_number);
            $this->assertSame($this->teacher->id, $second->outcome_recorded_by);
            $this->assertSame(1, $first->refresh()->lesson_number);
            $this->assertSame(2, $third->refresh()->lesson_number);
        });
    }

    #[Test]
    public function a_class_external_activity_is_numbered_and_keeps_only_a_short_note(): void
    {
        $slot = $this->makeSlot();
        [$first, $second, $third] = $this->thursdays($slot, ['08', '15', '22']);
        $this->resequence();

        $this->asTeacher()
            ->post("/lessons/{$second->ulid}/outcome", ['outcome' => 'class_external_activity', 'note' => 'Visita de estudo'])
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($first, $second, $third): void {
            $second->refresh();
            $this->assertSame(LessonOutcome::ClassExternalActivity, $second->outcome);
            $this->assertNull($second->outcome_reason);
            $this->assertSame('Visita de estudo', $second->outcome_note);
            $this->assertSame([1, 2, 3], [$first->refresh()->lesson_number, $second->lesson_number, $third->refresh()->lesson_number]);
        });
    }

    #[Test]
    public function the_reason_is_a_category_and_never_free_text(): void
    {
        $lesson = $this->thursdays($this->makeSlot(), ['08'])[0];

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/outcome", ['outcome' => 'teacher_absent'])
            ->assertSessionHasErrors('reason');
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'Estive doente'])
            ->assertSessionHasErrors('reason');
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'other', 'note' => 'consulta médica'])
            ->assertSessionHasErrors('note');
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/outcome", ['outcome' => 'class_external_activity', 'reason' => 'other'])
            ->assertSessionHasErrors('reason');
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/outcome", ['outcome' => 'taught'])
            ->assertSessionHasErrors('outcome');

        $this->inTenant($this->organization, fn () => $this->assertNull($lesson->refresh()->outcome));
    }

    #[Test]
    public function the_audit_event_carries_the_category_but_no_text(): void
    {
        $lesson = $this->thursdays($this->makeSlot(), ['08'])[0];

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/outcome", ['outcome' => 'class_external_activity', 'note' => 'Visita ao museu']);

        $this->inTenant($this->organization, function () use ($lesson): void {
            $event = AuditEvent::query()->where('event', 'lesson.outcome_recorded')->where('subject_id', $lesson->id)->sole();
            $properties = json_encode($event->properties);
            $this->assertStringNotContainsString('museu', (string) $properties);
            $this->assertSame('class_external_activity', $event->properties['outcome']);
            $this->assertTrue($event->properties['has_note']);
        });
    }

    #[Test]
    public function planning_shifts_one_position_and_stops_at_the_first_empty_lesson(): void
    {
        $slot = $this->makeSlot();
        [$a, $b, $c, $d] = $this->thursdays($slot, ['08', '15', '22', '29']);
        $this->summary($a, 'Plano A', ['homework' => 'TPC A']);
        $this->summary($b, 'Plano B');
        // C sem planeamento: a cadeia pára aqui.
        $this->summary($d, 'Plano D');

        $this->record($a, LessonOutcome::TeacherAbsent, TeacherAbsenceReason::OfficialDuty);

        $this->inTenant($this->organization, function () use ($a, $b, $c, $d): void {
            $this->assertNull($a->summary()->first());
            $this->assertSame('Plano A', $b->summary()->first()?->content);
            $this->assertSame('TPC A', $b->summary()->first()?->homework);
            $this->assertSame('Plano B', $c->summary()->first()?->content);
            $this->assertSame(LessonStatus::Prepared, $c->refresh()->status);
            $this->assertSame('Plano D', $d->summary()->first()?->content);
            $this->assertSame(1, LessonSummary::query()->where('content', 'Plano A')->count());
            $this->assertSame(1, LessonSummary::query()->where('content', 'Plano B')->count());
        });
    }

    #[Test]
    public function without_a_next_lesson_the_next_schedule_occurrence_is_materialized(): void
    {
        $slot = $this->makeSlot();
        [$a] = $this->thursdays($slot, ['08']);
        $this->summary($a, 'Plano A');

        $this->record($a, LessonOutcome::ClassExternalActivity);

        $this->inTenant($this->organization, function () use ($slot): void {
            $next = Lesson::query()->where('recurring_lesson_slot_id', $slot->id)->where('starts_at', '>', '2026-10-09')->sole();
            $this->assertSame('2026-10-15', $next->starts_at->setTimezone('Europe/Lisbon')->toDateString());
            $this->assertSame('Plano A', $next->summary()->first()?->content);
            $this->assertSame(0, LessonPlan::query()->count());
        });
    }

    #[Test]
    public function without_any_occurrence_until_the_end_of_the_year_the_plan_stays_pending(): void
    {
        $slot = $this->makeSlot(['ends_on' => '2026-10-08']);
        [$a] = $this->thursdays($slot, ['08']);
        $this->summary($a, 'Plano A', ['resources' => 'Manual p. 12']);

        $this->record($a, LessonOutcome::TeacherAbsent, TeacherAbsenceReason::Other);

        $this->inTenant($this->organization, function () use ($a): void {
            $plan = LessonPlan::query()->where('lesson_id', $a->id)->sole();
            $this->assertStringContainsString('Plano A', $plan->planned_summary);
            $this->assertStringContainsString('Manual p. 12', $plan->planned_summary);
            $this->assertSame(1, Lesson::query()->count());
        });

        $this->asTeacher()->get("/lessons/{$a->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('lesson.pending_plan', fn ($value) => str_contains((string) $value, 'Plano A')));
    }

    #[Test]
    public function t2_absent_hands_its_lesson_to_the_next_t2_and_stays_behind_t1(): void
    {
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $key = (string) Str::ulid();
        $t1Slot = $this->makeSlot(['class_group_id' => $t1->id, 'day_of_week' => 1, 'split_lesson_key' => $key]);
        $t2Slot = $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 2, 'split_lesson_key' => $key]);

        $t1First = $this->onSlot($t1Slot, '2026-10-05');
        $t2First = $this->onSlot($t2Slot, '2026-10-06');
        $t1Second = $this->onSlot($t1Slot, '2026-10-12');
        $t2Second = $this->onSlot($t2Slot, '2026-10-13');
        $t2Third = $this->onSlot($t2Slot, '2026-10-20');
        $this->resequence();

        $this->summary($t2First, 'Lição 1 T2');
        $this->summary($t2Second, 'Lição 2 T2');

        $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($t1First, $this->teacher, consolidateAttendance: false));

        $units = $this->inTenant($this->organization, fn (): array => [
            $t1First->refresh()->lesson_unit_key,
            $t1Second->refresh()->lesson_unit_key,
        ]);

        $this->record($t2First, LessonOutcome::TeacherAbsent, TeacherAbsenceReason::Training);

        $this->inTenant($this->organization, function () use ($t1First, $t1Second, $t2First, $t2Second, $t2Third, $units): void {
            $this->assertNull($t2First->refresh()->lesson_unit_key);
            $this->assertNull($t2First->lesson_number);
            // T1 mantém a sua lição; T2 faz a MESMA lição uma semana depois.
            $this->assertSame($units[0], $t1First->refresh()->lesson_unit_key);
            $this->assertSame($units[0], $t2Second->refresh()->lesson_unit_key);
            $this->assertSame($units[1], $t2Third->refresh()->lesson_unit_key);
            $this->assertSame(1, $t1First->lesson_number);
            $this->assertSame(1, $t2Second->lesson_number);
            $this->assertSame(2, $t1Second->refresh()->lesson_number);
            $this->assertSame(2, $t2Third->lesson_number);
            // O conteúdo de T2 não se perde: desce com a lição.
            $this->assertSame('Lição 1 T2', $t2Second->summary()->first()?->content);
            $this->assertSame('Lição 2 T2', $t2Third->summary()->first()?->content);
        });
    }

    #[Test]
    public function t2_absent_with_no_later_t2_lesson_materializes_it_behind_an_already_taught_t1(): void
    {
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $key = (string) Str::ulid();
        $t1Slot = $this->makeSlot(['class_group_id' => $t1->id, 'day_of_week' => 1, 'split_lesson_key' => $key]);
        $t2Slot = $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 2, 'split_lesson_key' => $key]);

        $t1First = $this->onSlot($t1Slot, '2026-10-05');
        $t2First = $this->onSlot($t2Slot, '2026-10-06');
        $t1Second = $this->onSlot($t1Slot, '2026-10-12');
        $this->resequence();
        $this->summary($t2First, 'Lição 1 T2');

        foreach ([$t1First, $t1Second] as $taught) {
            $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($taught, $this->teacher, consolidateAttendance: false));
        }

        $this->record($t2First, LessonOutcome::TeacherAbsent, TeacherAbsenceReason::Training);

        $this->inTenant($this->organization, function () use ($t1First, $t1Second, $t2Slot): void {
            $next = Lesson::query()->where('recurring_lesson_slot_id', $t2Slot->id)->whereNull('outcome')->sole();
            $this->assertSame('2026-10-13', $next->starts_at->setTimezone('Europe/Lisbon')->toDateString());
            $this->assertSame($t1First->refresh()->lesson_unit_key, $next->lesson_unit_key);
            $this->assertSame(1, $next->lesson_number);
            $this->assertSame(1, $t1First->lesson_number);
            $this->assertSame(2, $t1Second->refresh()->lesson_number);
            $this->assertSame('Lição 1 T2', $next->summary()->first()?->content);
        });
    }

    #[Test]
    public function the_lesson_page_does_not_offer_an_outcome_that_the_server_would_refuse(): void
    {
        $slot = $this->makeSlot();
        [$a, $b] = $this->thursdays($slot, ['08', '15']);
        $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($b, $this->teacher, consolidateAttendance: false));

        $this->asTeacher()->get("/lessons/{$a->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('lesson.can_record_outcome', false));
    }

    #[Test]
    public function attendance_drafts_are_dropped_and_attendance_is_not_applicable(): void
    {
        $student = $this->enroll('Alice');
        $lesson = $this->thursdays($this->makeSlot(), ['08'])[0];

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => [$student->student->ulid]])->assertSessionHasNoErrors();
        $this->record($lesson, LessonOutcome::TeacherAbsent, TeacherAbsenceReason::Training);

        $this->inTenant($this->organization, fn () => $this->assertSame(0, LessonAttendance::query()->count()));

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => [$student->student->ulid]])->assertSessionHasErrors('absent');
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/attendance")->assertSessionHasErrors('absent');
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertSessionHasErrors('outcome');
    }

    #[Test]
    public function refusals_happen_before_any_write(): void
    {
        $slot = $this->makeSlot();
        [$a, $b] = $this->thursdays($slot, ['08', '15']);
        $this->summary($a, 'Plano A');

        // Uma aula fechada depois desta.
        $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($b, $this->teacher, consolidateAttendance: false));
        $this->asTeacher()->post("/lessons/{$a->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasErrors('outcome');

        // A própria aula já fechada.
        $this->asTeacher()->post("/lessons/{$b->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasErrors('outcome');

        $this->inTenant($this->organization, function () use ($a): void {
            $this->assertNull($a->refresh()->outcome);
            $this->assertSame('Plano A', $a->summary()->first()?->content);
        });
    }

    #[Test]
    public function a_lesson_with_consolidated_attendance_cannot_become_teacher_absent(): void
    {
        $this->enroll('Alice');
        $lesson = $this->thursdays($this->makeSlot(), ['08'])[0];
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/attendance", ['absent' => []])->assertSessionHasNoErrors();

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasErrors('outcome');
    }

    #[Test]
    public function closed_special_lessons_cannot_be_deleted_or_batch_taught(): void
    {
        $lesson = $this->thursdays($this->makeSlot(), ['08'])[0];
        $this->record($lesson, LessonOutcome::TeacherAbsent, TeacherAbsenceReason::Training);

        $this->asTeacher()->delete("/lessons/{$lesson->ulid}")->assertSessionHasErrors('lesson');

        $candidates = $this->inTenant($this->organization, fn (): array => app(MarkLessonsAsTaughtInBatch::class)->candidates(
            $this->teacher,
            AcademicYear::query()->findOrFail($this->schoolClass->academic_year_id),
            ulids: [$lesson->ulid],
        ));
        $this->assertCount(0, $candidates['eligible']);
        $this->assertCount(1, $candidates['ineligible']);
    }

    #[Test]
    public function another_organization_cannot_record_an_outcome(): void
    {
        $lesson = $this->thursdays($this->makeSlot(), ['08'])[0];
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->withSession(['organization_id' => $stranger->personalOrganization()->id])
            ->post("/lessons/{$lesson->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertStatus(404);

        $this->inTenant($this->organization, fn () => $this->assertNull($lesson->refresh()->outcome));
    }

    #[Test]
    public function attendance_drafts_of_open_lessons_never_enter_the_totals(): void
    {
        $alice = $this->enroll('Alice');
        $slot = $this->makeSlot();
        [$open, $taught, $absent] = $this->thursdays($slot, ['08', '15', '22']);

        // Rascunho numa aula ainda aberta.
        $this->asTeacher()->put("/lessons/{$open->ulid}/attendance/draft", ['absent' => [$alice->student->ulid]])->assertSessionHasNoErrors();
        // Lecionada com falta consolidada.
        $this->asTeacher()->post("/lessons/{$taught->ulid}/mark-taught", ['absent' => [$alice->student->ulid]])->assertSessionHasNoErrors();
        $this->record($absent, LessonOutcome::TeacherAbsent, TeacherAbsenceReason::Training);

        $this->inTenant($this->organization, function () use ($alice): void {
            $row = app(ClassAttendanceSummary::class)->for($this->schoolClass)['rows'][0];
            $this->assertSame(['present' => 0, 'absent' => 1, 'not_recorded' => 0], array_intersect_key($row, array_flip(['present', 'absent', 'not_recorded'])));

            $history = app(StudentAttendanceHistory::class)->for($alice);
            $this->assertSame(['recorded' => 1, 'present' => 0, 'absent' => 1, 'not_recorded' => 0], $history['totals']);
            $this->assertSame(1, LessonAttendance::query()->where('status', AttendanceStatus::Absent)->whereHas('lesson', fn ($query) => $query->whereNull('attendance_recorded_at'))->count());
        });
    }

    #[Test]
    public function the_action_refuses_taught_as_a_special_outcome(): void
    {
        $lesson = $this->thursdays($this->makeSlot(), ['08'])[0];

        $this->expectException(\InvalidArgumentException::class);
        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute($lesson, LessonOutcome::Taught, $this->teacher));
    }

    #[Test]
    public function renumbering_that_would_change_a_taught_number_rolls_everything_back(): void
    {
        $slot = $this->makeSlot();
        $other = $this->makeSlot(['day_of_week' => 5]);
        [$a] = $this->thursdays($slot, ['08']);
        $friday = $this->makeLesson(['recurring_lesson_slot_id' => $other->id, 'starts_at' => '2026-10-09 09:30:00']);
        $this->resequence();
        $this->summary($a, 'Plano A');
        // A sexta, de outro tempo mas do mesmo público, já foi lecionada.
        $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($friday, $this->teacher, consolidateAttendance: false));

        try {
            $this->record($a, LessonOutcome::TeacherAbsent, TeacherAbsenceReason::Training);
            $this->fail('Esperava recusa.');
        } catch (ValidationException) {
            // recusado
        }

        $this->inTenant($this->organization, function () use ($a, $friday): void {
            $this->assertNull($a->refresh()->outcome);
            $this->assertSame(1, $a->lesson_number);
            $this->assertSame(2, $friday->refresh()->lesson_number);
            $this->assertSame('Plano A', $a->summary()->first()?->content);
        });
    }

    /**
     * 0.146.1 — uma aula PREPARADA que fecha como «turma em outras atividades
     * letivas» fica fechada numa só operação: sem rascunhos, sem assiduidade
     * simulada, o planeamento desce sem apagar o da seguinte, e o lote não a
     * transforma em lecionada.
     */
    #[Test]
    public function a_prepared_lesson_closed_as_external_activity_is_final_in_one_step(): void
    {
        $student = $this->enroll('Alice');
        $slot = $this->makeSlot();
        [$a, $b, $c] = $this->thursdays($slot, ['08', '15', '22']);
        $this->summary($a, 'Plano A');
        $this->summary($b, 'Plano B');
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($a->id)->update(['status' => LessonStatus::Prepared->value]));

        $this->asTeacher()->put("/lessons/{$a->ulid}/attendance/draft", ['absent' => [$student->student->ulid]])->assertSessionHasNoErrors();
        $this->asTeacher()
            ->post("/lessons/{$a->ulid}/outcome", ['outcome' => 'class_external_activity'])
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($a, $b, $c): void {
            $a->refresh();
            $this->assertTrue($a->isClosed());
            $this->assertSame(LessonOutcome::ClassExternalActivity, $a->outcome);
            $this->assertNull($a->attendance_recorded_at);
            $this->assertSame(0, LessonAttendance::query()->count());
            $this->assertNull($a->summary()->first());
            $this->assertSame('Plano A', $b->summary()->first()?->content);
            $this->assertSame('Plano B', $c->summary()->first()?->content);
        });

        // Não há segundo passo, e nenhum caminho de «lecionada» escreve por cima.
        $this->asTeacher()->post("/lessons/{$a->ulid}/mark-taught")->assertSessionHasErrors('outcome');
        $candidates = $this->inTenant($this->organization, fn (): array => app(MarkLessonsAsTaughtInBatch::class)->candidates(
            $this->teacher,
            AcademicYear::query()->findOrFail($this->schoolClass->academic_year_id),
            ulids: [$a->ulid],
        ));
        $this->assertCount(0, $candidates['eligible']);
        $this->inTenant($this->organization, fn () => $this->assertSame(LessonOutcome::ClassExternalActivity, $a->refresh()->outcome));

        // O payload semanal leva o resultado: é dele que a Lista e o Horário
        // derivam o único estado do cartão.
        $this->asTeacher()
            ->get('/lessons?week=2026-10-05')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lessons.0.ulid', $a->ulid)
                ->where('lessons.0.outcome', 'class_external_activity')
                ->where('lessons.0.outcome_label', 'Turma em outras atividades letivas')
                ->where('lessons.0.attendance_recorded', false)
                ->etc());
    }

    /**
     * @param  list<string>  $days
     * @return list<Lesson>
     */
    private function thursdays(RecurringLessonSlot $slot, array $days): array
    {
        return array_map(fn (string $day): Lesson => $this->onSlot($slot, "2026-10-{$day}"), $days);
    }

    private function onSlot(RecurringLessonSlot $slot, string $date): Lesson
    {
        return $this->makeLesson([
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => "{$date} {$slot->starts_at}",
        ]);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function summary(Lesson $lesson, string $content, array $extra = []): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => $content] + $extra));
    }

    private function record(Lesson $lesson, LessonOutcome $outcome, ?TeacherAbsenceReason $reason = null): void
    {
        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute($lesson, $outcome, $this->teacher, $reason));
    }

    private function resequence(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));
    }
}
