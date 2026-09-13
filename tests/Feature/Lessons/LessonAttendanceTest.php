<?php

namespace Tests\Feature\Lessons;

use App\Models\AttendanceStatus;
use App\Models\AuditEvent;
use App\Models\LessonAttendance;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * §27 do briefing de assiduidade — o roster, a consolidação e a correção,
 * caso a caso.
 */
class LessonAttendanceTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    #[Test]
    public function whole_class_two_students_one_absence(): void
    {
        $enrollmentA = $this->enroll('Ana Presente');
        $enrollmentB = $this->enroll('Bruno Ausente');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $this->asTeacher()
            ->post("/lessons/{$lesson->ulid}/mark-taught", ['absent' => [$enrollmentB->student->ulid]])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $enrollmentA, $enrollmentB): void {
            $lesson->refresh();
            $this->assertSame(LessonStatus::Taught, $lesson->status);
            $this->assertNotNull($lesson->attendance_recorded_at);
            $this->assertSame(
                AttendanceStatus::Present,
                LessonAttendance::where('lesson_id', $lesson->id)->where('enrollment_id', $enrollmentA->id)->sole()->status,
            );
            $this->assertSame(
                AttendanceStatus::Absent,
                LessonAttendance::where('lesson_id', $lesson->id)->where('enrollment_id', $enrollmentB->id)->sole()->status,
            );
            $this->assertDatabaseCount('lesson_attendances', 2);

            $event = AuditEvent::where('event', 'lesson.attendance_recorded')->where('subject_id', $lesson->id)->sole();
            // A coluna JSON do MySQL reordena as chaves: compara-se o conteúdo.
            $this->assertEqualsCanonicalizing(['present' => 1, 'absent' => 1], $event->properties);
        });
    }

    #[Test]
    public function saving_the_summary_after_attendance_is_recorded_still_works(): void
    {
        $enrollment = $this->enroll('Ana');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught", ['absent' => [$enrollment->student->ulid]])->assertRedirect();

        // O ecrã reenvia a lista consolidada em cada «Guardar» — nunca pode
        // bloquear a edição do sumário nem alterar a assiduidade.
        $this->asTeacher()
            ->put("/lessons/{$lesson->ulid}/summary", ['content' => 'Revisto depois', 'absent' => []])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame('Revisto depois', $lesson->refresh()->summary?->content);
            $this->assertSame(1, LessonAttendance::where('lesson_id', $lesson->id)->where('status', 'absent')->count());
        });
    }

    #[Test]
    public function none_marked_means_everybody_present(): void
    {
        $this->enroll('Ana');
        $this->enroll('Bruno');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertNotNull($lesson->refresh()->attendance_recorded_at);
            $this->assertSame(2, LessonAttendance::where('lesson_id', $lesson->id)->where('status', 'present')->count());
            $this->assertSame(0, LessonAttendance::where('lesson_id', $lesson->id)->where('status', 'absent')->count());
        });
    }

    #[Test]
    public function a_student_outside_the_audience_gets_no_row(): void
    {
        $inAudience = $this->enroll('Dentro');
        // Deixou a turma antes do dia da aula: fora da audiência.
        $left = $this->enroll('Fora', ['enrolled_on' => '2026-09-01', 'left_on' => '2026-09-30', 'status' => 'left']);
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $inAudience, $left): void {
            $this->assertDatabaseCount('lesson_attendances', 1);
            $this->assertDatabaseHas('lesson_attendances', ['lesson_id' => $lesson->id, 'enrollment_id' => $inAudience->id]);
            $this->assertDatabaseMissing('lesson_attendances', ['lesson_id' => $lesson->id, 'enrollment_id' => $left->id]);
        });
    }

    #[Test]
    public function late_enrollment_after_the_lesson_date_is_excluded(): void
    {
        $onTime = $this->enroll('A Tempo');
        $late = $this->enroll('Tardio', ['enrolled_on' => '2026-10-20', 'is_late_entry' => true]);
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $onTime, $late): void {
            $this->assertDatabaseCount('lesson_attendances', 1);
            $this->assertDatabaseHas('lesson_attendances', ['lesson_id' => $lesson->id, 'enrollment_id' => $onTime->id]);
            $this->assertDatabaseMissing('lesson_attendances', ['lesson_id' => $lesson->id, 'enrollment_id' => $late->id]);
        });
    }

    #[Test]
    public function a_group_lesson_only_covers_its_own_group(): void
    {
        $groupOne = $this->makeGroup('T1');
        $groupTwo = $this->makeGroup('T2');
        $inGroupOne = $this->enroll('Do T1');
        $inGroupTwo = $this->enroll('Do T2');
        $this->addToGroup($inGroupOne, $groupOne);
        $this->addToGroup($inGroupTwo, $groupTwo);

        $lessonOne = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'class_group_id' => $groupOne->id]);

        $this->asTeacher()->post("/lessons/{$lessonOne->ulid}/mark-taught")->assertRedirect();

        $this->inTenant($this->organization, function () use ($lessonOne, $inGroupOne, $inGroupTwo): void {
            $this->assertDatabaseCount('lesson_attendances', 1);
            $this->assertDatabaseHas('lesson_attendances', ['lesson_id' => $lessonOne->id, 'enrollment_id' => $inGroupOne->id]);
            $this->assertDatabaseMissing('lesson_attendances', ['lesson_id' => $lessonOne->id, 'enrollment_id' => $inGroupTwo->id]);
        });
    }

    #[Test]
    public function the_other_group_lesson_only_covers_its_own_group(): void
    {
        $groupOne = $this->makeGroup('T1');
        $groupTwo = $this->makeGroup('T2');
        $inGroupOne = $this->enroll('Do T1');
        $inGroupTwo = $this->enroll('Do T2');
        $this->addToGroup($inGroupOne, $groupOne);
        $this->addToGroup($inGroupTwo, $groupTwo);

        $lessonTwo = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'class_group_id' => $groupTwo->id]);

        $this->asTeacher()->post("/lessons/{$lessonTwo->ulid}/mark-taught")->assertRedirect();

        $this->inTenant($this->organization, function () use ($lessonTwo, $inGroupOne, $inGroupTwo): void {
            $this->assertDatabaseCount('lesson_attendances', 1);
            $this->assertDatabaseHas('lesson_attendances', ['lesson_id' => $lessonTwo->id, 'enrollment_id' => $inGroupTwo->id]);
            $this->assertDatabaseMissing('lesson_attendances', ['lesson_id' => $lessonTwo->id, 'enrollment_id' => $inGroupOne->id]);
        });
    }

    #[Test]
    public function a_support_class_uses_the_same_enrollment_rule(): void
    {
        $supportClass = $this->inTenant($this->organization, function () {
            $class = SchoolClass::factory()
                ->recycle($this->organization)
                ->create([
                    'academic_year_id' => $this->schoolClass->academic_year_id,
                    'is_support_class' => true,
                ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });

        $enrollment = $this->enroll('Apoiado', schoolClass: $supportClass);
        $lesson = $this->makeLesson([
            'class_id' => $supportClass->id,
            'starts_at' => '2026-10-08 09:30:00',
        ]);

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $enrollment): void {
            $this->assertDatabaseCount('lesson_attendances', 1);
            $this->assertDatabaseHas('lesson_attendances', ['lesson_id' => $lesson->id, 'enrollment_id' => $enrollment->id]);
        });
    }

    #[Test]
    public function a_correction_flips_present_to_absent_and_back_with_audit(): void
    {
        $enrollment = $this->enroll('Aluno Único');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();

        $studentUlid = $enrollment->student->ulid;

        $this->asTeacher()
            ->patch("/lessons/{$lesson->ulid}/attendance/{$studentUlid}", ['status' => 'absent'])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $enrollment): void {
            $this->assertSame(
                AttendanceStatus::Absent,
                LessonAttendance::where('lesson_id', $lesson->id)->where('enrollment_id', $enrollment->id)->sole()->status,
            );
        });

        $this->asTeacher()
            ->patch("/lessons/{$lesson->ulid}/attendance/{$studentUlid}", ['status' => 'present'])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $enrollment): void {
            $this->assertSame(
                AttendanceStatus::Present,
                LessonAttendance::where('lesson_id', $lesson->id)->where('enrollment_id', $enrollment->id)->sole()->status,
            );
            $events = AuditEvent::where('event', 'lesson.attendance_corrected')->where('subject_id', $lesson->id)->get();
            $this->assertCount(2, $events);
            $this->assertSame('present', $events[0]->properties['from']);
            $this->assertSame('absent', $events[0]->properties['to']);
            $this->assertSame('absent', $events[1]->properties['from']);
            $this->assertSame('present', $events[1]->properties['to']);
        });
    }

    #[Test]
    public function a_historical_taught_lesson_without_attendance_stays_unrecorded(): void
    {
        $this->enroll('Aluno');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'status' => LessonStatus::Taught]);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('lessons/Show')
                ->where('attendance.recorded', false));

        $this->assertDatabaseCount('lesson_attendances', 0);
    }

    #[Test]
    public function a_taught_lesson_without_attendance_can_record_it_afterwards(): void
    {
        $enrollment = $this->enroll('Aluno');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'status' => LessonStatus::Taught]);

        $this->asTeacher()
            ->post("/lessons/{$lesson->ulid}/attendance", ['absent' => [$enrollment->student->ulid]])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $enrollment): void {
            $this->assertNotNull($lesson->refresh()->attendance_recorded_at);
            $this->assertSame(
                AttendanceStatus::Absent,
                LessonAttendance::where('lesson_id', $lesson->id)->where('enrollment_id', $enrollment->id)->sole()->status,
            );
        });
    }

    #[Test]
    public function a_failed_consolidation_rolls_back_the_whole_transaction(): void
    {
        $enrollment = $this->enroll('Vai Sair');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        // Grava um rascunho válido...
        $this->asTeacher()
            ->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => [$enrollment->student->ulid]])
            ->assertRedirect();

        // ...e a seguir a inscrição deixa de ser elegível nesse dia.
        $this->inTenant($this->organization, function () use ($enrollment): void {
            $enrollment->update(['left_on' => '2026-09-15', 'status' => 'left']);
        });

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertSessionHasErrors();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
            $this->assertNull($lesson->attendance_recorded_at);
        });
        // O rascunho continua lá — nada mudou.
        $this->assertDatabaseCount('lesson_attendances', 1);
    }

    #[Test]
    public function draft_is_consolidated_by_the_batch_only_when_it_exists(): void
    {
        $withDraft = $this->enroll('Com Rascunho');
        $lessonWithDraft = $this->makeLesson(['starts_at' => now('Europe/Lisbon')->toDateString().' 09:30:00']);
        $lessonWithoutDraft = $this->makeLesson(['starts_at' => now('Europe/Lisbon')->toDateString().' 11:30:00']);

        $this->asTeacher()
            ->put("/lessons/{$lessonWithDraft->ulid}/attendance/draft", ['absent' => [$withDraft->student->ulid]])
            ->assertRedirect();

        $this->asTeacher()->post('/lessons/batch-taught', ['mode' => 'today'])->assertRedirect();

        $this->inTenant($this->organization, function () use ($lessonWithDraft, $lessonWithoutDraft): void {
            $this->assertSame(LessonStatus::Taught, $lessonWithDraft->refresh()->status);
            $this->assertNotNull($lessonWithDraft->attendance_recorded_at);

            $this->assertSame(LessonStatus::Taught, $lessonWithoutDraft->refresh()->status);
            $this->assertNull($lessonWithoutDraft->attendance_recorded_at);
        });
    }

    #[Test]
    public function batch_preview_reports_pending_attendance(): void
    {
        $withDraft = $this->enroll('Com Rascunho');
        $lessonWithDraft = $this->makeLesson(['starts_at' => now('Europe/Lisbon')->toDateString().' 09:30:00']);
        $this->makeLesson(['starts_at' => now('Europe/Lisbon')->toDateString().' 11:30:00']);

        $this->asTeacher()
            ->put("/lessons/{$lessonWithDraft->ulid}/attendance/draft", ['absent' => [$withDraft->student->ulid]])
            ->assertRedirect();

        $response = $this->asTeacher()->postJson('/lessons/batch-taught/preview', ['mode' => 'today']);

        $response->assertOk();
        $this->assertSame(1, $response->json('attendance_pending'));
    }
}
