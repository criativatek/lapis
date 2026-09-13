<?php

namespace Tests\Feature\Lessons;

use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\LessonStatus;
use App\Services\Lessons\StudentAttendanceHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * `StudentAttendanceHistory` — o histórico de assiduidade de um aluno através
 * de uma inscrição, que alimenta o cartão de Evolução do Aluno e a secção
 * homónima dos relatórios.
 */
class StudentAttendanceHistoryTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    private function history(Enrollment $enrollment, ?string $from = null, ?string $to = null): array
    {
        return $this->inTenant(
            $this->organization,
            fn () => app(StudentAttendanceHistory::class)->for($enrollment->fresh(), $from, $to),
        );
    }

    #[Test]
    public function present_and_absent_lessons_are_counted_apart_from_not_recorded(): void
    {
        $enrollment = $this->enroll('Ana');
        $present = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'lesson_number' => 1]);
        $absent = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00', 'lesson_number' => 2]);

        $this->asTeacher()->post("/lessons/{$present->ulid}/mark-taught")->assertRedirect();
        $this->asTeacher()->post("/lessons/{$absent->ulid}/mark-taught", [
            'absent' => [$enrollment->student->ulid],
        ])->assertRedirect();

        // Uma terceira aula lecionada em lote, sem oportunidade de registar
        // faltas: fica «não registada» (§83 do briefing).
        $notRecorded = $this->makeLesson(['starts_at' => '2026-10-22 09:30:00', 'lesson_number' => 3]);
        $this->inTenant($this->organization, function () use ($notRecorded): void {
            $notRecorded->update(['status' => LessonStatus::Taught]);
        });

        $history = $this->history($enrollment);

        $this->assertSame(['recorded' => 2, 'present' => 1, 'absent' => 1, 'not_recorded' => 1], $history['totals']);
        $this->assertCount(3, $history['rows']);

        // Cronológico: outubro 8, 15, 22.
        $this->assertSame('2026-10-08', $history['rows'][0]['date']);
        $this->assertSame('present', $history['rows'][0]['status']);
        $this->assertSame('2026-10-15', $history['rows'][1]['date']);
        $this->assertSame('absent', $history['rows'][1]['status']);
        $this->assertSame('2026-10-22', $history['rows'][2]['date']);
        $this->assertSame('not_recorded', $history['rows'][2]['status']);
    }

    #[Test]
    public function an_ended_enrollment_without_left_on_gains_no_not_recorded_lessons(): void
    {
        $enrollment = $this->enroll('Saiu sem data', ['status' => EnrollmentStatus::TransferredOut, 'left_on' => null]);
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $this->inTenant($this->organization, function () use ($lesson): void {
            $lesson->update(['status' => LessonStatus::Taught]);
        });

        $history = $this->history($enrollment);

        $this->assertSame(0, $history['totals']['not_recorded']);
        $this->assertSame([], $history['rows']);
    }

    #[Test]
    public function not_recorded_is_never_counted_as_present(): void
    {
        $enrollment = $this->enroll('Bruno');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $this->inTenant($this->organization, function () use ($lesson): void {
            $lesson->update(['status' => LessonStatus::Taught]);
        });

        $history = $this->history($enrollment);

        $this->assertSame(0, $history['totals']['recorded']);
        $this->assertSame(0, $history['totals']['present']);
        $this->assertSame(1, $history['totals']['not_recorded']);
    }

    #[Test]
    public function a_group_lesson_only_counts_for_its_own_group_members(): void
    {
        $groupOne = $this->makeGroup('T1');
        $groupTwo = $this->makeGroup('T2');
        $inGroupOne = $this->enroll('Do T1');
        $inGroupTwo = $this->enroll('Do T2');
        $this->addToGroup($inGroupOne, $groupOne);
        $this->addToGroup($inGroupTwo, $groupTwo);

        $lessonOne = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'class_group_id' => $groupOne->id]);
        $this->inTenant($this->organization, function () use ($lessonOne): void {
            $lessonOne->update(['status' => LessonStatus::Taught]);
        });

        $historyOne = $this->history($inGroupOne);
        $historyTwo = $this->history($inGroupTwo);

        $this->assertSame(1, $historyOne['totals']['not_recorded']);
        $this->assertSame(0, $historyTwo['totals']['not_recorded']);
    }

    #[Test]
    public function late_enrollment_does_not_gain_a_not_recorded_lesson_before_it_existed(): void
    {
        $late = $this->enroll('Tardio', ['enrolled_on' => '2026-10-20', 'is_late_entry' => true]);
        $before = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $this->inTenant($this->organization, function () use ($before): void {
            $before->update(['status' => LessonStatus::Taught]);
        });

        $history = $this->history($late);

        $this->assertSame(0, $history['totals']['not_recorded']);
        $this->assertSame([], $history['rows']);
    }

    #[Test]
    public function a_date_range_narrows_both_consolidated_and_not_recorded_lessons(): void
    {
        $enrollment = $this->enroll('Carla');

        $inRange = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'lesson_number' => 1]);
        $this->asTeacher()->post("/lessons/{$inRange->ulid}/mark-taught")->assertRedirect();

        $outOfRange = $this->makeLesson(['starts_at' => '2026-11-10 09:30:00', 'lesson_number' => 2]);
        $this->asTeacher()->post("/lessons/{$outOfRange->ulid}/mark-taught")->assertRedirect();

        $history = $this->history($enrollment, '2026-10-01', '2026-10-31');

        $this->assertSame(1, $history['totals']['recorded']);
        $this->assertCount(1, $history['rows']);
        $this->assertSame('2026-10-08', $history['rows'][0]['date']);
    }
}
