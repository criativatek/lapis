<?php

namespace Tests\Feature\Evidence;

use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Lessons\StudentAttendanceHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Evidence\Concerns\BuildsFollowupFixtures;
use Tests\TestCase;

/**
 * «Faltas de presença» in the Registos consultation (2026-10-13): counted from
 * the attendance registered in the lessons — consolidated only, never a draft,
 * never attendance still to fill in, never a lateness — one absence per lesson,
 * and the same number in the tile, the list, the detail and the student's
 * Evolução. Consultation and counts only: no alert, no Inovar message.
 */
class RecordAbsencesTest extends TestCase
{
    use BuildsFollowupFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFollowupFixtures();
    }

    /**
     * A lesson whose attendance was consolidated (marked as taught), with the
     * given students absent and every other listed one present.
     *
     * @param  list<Enrollment>  $absent
     * @param  list<Enrollment>  $present
     */
    private function taughtLesson(string $startsAt, array $absent, array $present = [], ?SchoolClass $class = null, bool $consolidated = true, ?string $outcome = 'taught'): Lesson
    {
        $lesson = $this->makeLesson([
            'class_id' => ($class ?? $this->schoolClass)->id,
            'starts_at' => $startsAt,
            'status' => $outcome === 'taught' ? LessonStatus::Taught : LessonStatus::Prepared,
            'outcome' => $outcome,
            'attendance_recorded_at' => $consolidated ? $startsAt : null,
        ]);

        $this->inTenant($this->organization, function () use ($lesson, $absent, $present): void {
            foreach ($absent as $enrollment) {
                LessonAttendance::create(['lesson_id' => $lesson->id, 'enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'status' => 'absent']);
            }
            foreach ($present as $enrollment) {
                LessonAttendance::create(['lesson_id' => $lesson->id, 'enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'status' => 'present']);
            }
        });

        return $lesson;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function consult(array $query = [], ?User $as = null): TestResponse
    {
        $request = $as === null ? $this->asTeacher() : $this->actingAs($as)->withSession(['organization_id' => $this->organization->id]);

        // The search tab (0.165.0): the cards and lists live there.
        return $request->get('/records?'.http_build_query(['tab' => 'search', ...$query]));
    }

    #[Test]
    public function only_consolidated_absences_count_never_drafts_presences_unapplicable_lessons_or_lateness(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $rui = $this->enroll('Rui Sousa', ['class_number' => 2]);

        $this->taughtLesson('2026-10-05 09:30:00', [$ana], [$rui]);
        // A draft absence in a lesson not yet closed.
        $this->taughtLesson('2026-10-06 09:30:00', [$ana], consolidated: false, outcome: null);
        // «Não houve aula — professor ausente»: attendance does not apply.
        $this->taughtLesson('2026-10-07 09:30:00', [$ana], outcome: 'teacher_absent');
        // A taught lesson whose attendance is still to fill in.
        $this->taughtLesson('2026-10-08 09:30:00', [], consolidated: false);
        // A lateness is a logbook entry, never an absence.
        $this->record($rui, EvidenceKind::Lateness, '2026-10-05');

        $this->consult()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tiles.3.category', 'absences')
            ->where('tiles.3.students', 1)
            ->where('tiles.3.occurrences', 1)
            ->where('tiles.3.pending', null)
            ->where('tiles.1.occurrences', 1));
    }

    #[Test]
    public function two_lessons_on_one_day_are_two_absences_on_one_day(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $this->taughtLesson('2026-10-05 09:30:00', [$ana]);
        $this->taughtLesson('2026-10-05 14:00:00', [$ana]);
        $this->taughtLesson('2026-10-09 09:30:00', [$ana]);

        $this->consult(['view' => 'absences'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tiles.3.occurrences', 3)
            ->where('followup.category', 'absences')
            ->where('followup.unit', 'absence')
            ->where('followup.rows.data.0.occurrences', 3)
            ->where('followup.rows.data.0.days', 2)
            ->where('followup.rows.data.0.status', null));

        $this->consult(['view' => 'absences', 'case_class' => $this->schoolClass->ulid, 'case_student' => $ana->ulid, 'case_category' => 'absences'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('openCase.kind', 'absences')
                ->where('openCase.count', 3)
                ->where('openCase.days', 2)
                ->has('openCase.absences', 3)
                ->where('openCase.absences.0.date', '2026-10-05')
                ->where('openCase.absences.0.time', '09:30')
                ->where('openCase.absences.1.time', '14:00')
                ->missing('openCase.message')
                ->missing('openCase.can_confirm'));
    }

    #[Test]
    public function a_correction_or_an_annulled_lesson_shows_at_once(): void
    {
        $ana = $this->enroll('Ana Costa');
        $first = $this->taughtLesson('2026-10-05 09:30:00', [$ana]);
        $second = $this->taughtLesson('2026-10-06 09:30:00', [$ana]);
        $this->taughtLesson('2026-10-07 09:30:00', [$ana]);

        $this->consult()->assertInertia(fn (AssertableInertia $page) => $page->where('tiles.3.occurrences', 3));

        // Corrected from Falta to Presente; the other lesson reclassified as
        // «turma noutras atividades», where attendance does not apply.
        $this->inTenant($this->organization, function () use ($first, $second): void {
            LessonAttendance::query()->where('lesson_id', $first->id)->update(['status' => 'present']);
            $second->forceFill(['outcome' => 'class_external_activity'])->save();
        });

        $this->consult()->assertInertia(fn (AssertableInertia $page) => $page->where('tiles.3.occurrences', 1));
    }

    #[Test]
    public function the_lesson_date_decides_the_period_whole_days_included(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->taughtLesson('2026-12-16 16:40:00', [$ana]);
        $this->taughtLesson('2026-12-21 09:30:00', [$ana]);
        $this->taughtLesson('2027-01-04 09:30:00', [$ana]);

        $this->consult()->assertInertia(fn (AssertableInertia $page) => $page->where('tiles.3.occurrences', 1));
        $this->consult(['period' => $this->secondPeriod->ulid])->assertInertia(fn (AssertableInertia $page) => $page->where('tiles.3.occurrences', 1));
        $this->consult(['mode' => 'range', 'from' => '2026-12-01', 'to' => '2026-12-31'])->assertInertia(fn (AssertableInertia $page) => $page->where('tiles.3.occurrences', 2));
    }

    #[Test]
    public function tile_list_detail_and_the_students_evolucao_agree(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $rui = $this->enroll('Rui Sousa', ['class_number' => 2]);
        $this->taughtLesson('2026-10-05 09:30:00', [$ana, $rui]);
        $this->taughtLesson('2026-10-06 09:30:00', [$ana], [$rui]);
        $this->taughtLesson('2026-10-07 09:30:00', [], [$ana, $rui]);
        // Taught, attendance still to fill in: said apart in the detail.
        $this->taughtLesson('2026-10-08 09:30:00', [], consolidated: false);

        $this->consult(['view' => 'absences'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tiles.3.students', 2)
            ->where('tiles.3.occurrences', 3)
            ->where('followup.rows.total', 2)
            ->where('followup.rows.data.0.name', 'Ana Costa')
            ->where('followup.rows.data.0.occurrences', 2)
            ->where('followup.rows.data.1.occurrences', 1));

        $this->consult(['view' => 'absences', 'case_class' => $this->schoolClass->ulid, 'case_student' => $ana->ulid, 'case_category' => 'absences'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('openCase.count', 2)
                ->where('openCase.not_recorded', 1));

        $history = $this->inTenant($this->organization, fn (): array => app(StudentAttendanceHistory::class)->for($ana, '2026-09-14', '2026-12-16'));
        $this->assertSame(2, $history['totals']['absent']);
    }

    #[Test]
    public function one_student_in_two_classes_is_one_student_and_two_cases(): void
    {
        $ana = $this->enroll('Ana Costa');
        $support = $this->secondClass('7.º A (apoio)', $this->inTenant($this->organization, fn () => $this->schoolClass->subject));
        // A support class reuses the same student (StudentEnrollmentService::enrollExisting).
        $anaInSupport = $this->inTenant($this->organization, fn (): Enrollment => Enrollment::create([
            'class_id' => $support->id, 'student_id' => $ana->student_id, 'enrolled_on' => '2026-09-01', 'status' => 'active', 'is_late_entry' => false,
        ]));

        $this->taughtLesson('2026-10-05 09:30:00', [$ana]);
        $this->taughtLesson('2026-10-06 11:00:00', [$anaInSupport], class: $support);

        $this->consult(['view' => 'absences'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tiles.3.students', 1)
            ->where('tiles.3.cases', 2)
            ->where('tiles.3.occurrences', 2)
            ->where('followup.rows.total', 2)
            ->where('followup.rows.data.0.student_cases', 2)
            ->where('followup.rows.data.1.student_cases', 2));
    }

    #[Test]
    public function a_colleagues_class_is_never_counted_nor_opened(): void
    {
        $colleague = $this->colleagueTeaching();
        $foreign = $this->inTenant($this->organization, function () use ($colleague): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id, 'label' => '9.º C']);
            $class->teachers()->attach($colleague, ['role' => 'owner']);

            return $class;
        });
        $stranger = $this->enroll('Aluno da colega', [], $foreign);
        $this->taughtLesson('2026-10-05 09:30:00', [$stranger], class: $foreign);

        $this->consult()->assertInertia(fn (AssertableInertia $page) => $page->where('tiles.3.occurrences', 0));
        $this->consult(['view' => 'absences', 'case_class' => $foreign->ulid, 'case_student' => $stranger->ulid, 'case_category' => 'absences'])->assertNotFound();
        $this->consult([], $colleague)->assertInertia(fn (AssertableInertia $page) => $page->where('tiles.3.occurrences', 1));
    }

    #[Test]
    public function absences_never_raise_an_alert(): void
    {
        $ana = $this->enroll('Ana Costa');

        foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'] as $day) {
            $this->taughtLesson("{$day} 09:30:00", [$ana]);
        }

        $this->consult()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tiles.3.occurrences', 4)
            ->has('alerts', 0));
        $this->assertSame(0, $this->alertCount());
    }
}
