<?php

namespace Tests\Feature\Lessons;

use App\Models\AttendanceStatus;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Models\TeacherAbsenceReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.147.0 — fecho rápido de aulas a partir da semana.
 *
 * O fecho rápido não tem backend próprio: reutiliza `mark-taught`, `outcome` e
 * `batch-taught`. Estes testes fixam o contrato de que a página passa a
 * depender, e sobretudo as DUAS semânticas de assiduidade, que são diferentes
 * de propósito (decisão aprovada):
 *
 * - cartão (MarkLessonAsTaught): sem rascunho ⇒ «Sem faltas», consolidada;
 * - lote (MarkLessonsAsTaughtInBatch): sem rascunho ⇒ «Assiduidade por registar».
 *
 * Relógio congelado numa quinta-feira (08/10/2026, 11:00 em Lisboa).
 */
class QuickCloseLessonsTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 11:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_week_page_sends_feminine_labels_and_one_shared_list_of_absence_reasons(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'status' => LessonStatus::Prepared]);
        $this->makeLesson(['starts_at' => '2026-10-07 09:30:00', 'status' => LessonStatus::Taught]);

        $this->asTeacher()
            ->get('/lessons?week=2026-10-05')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('lessons/Index')
                ->where('lessons.0.status_label', 'Lecionada')
                ->where('lessons.1.status_label', 'Preparada')
                ->where('absenceReasons', array_map(
                    fn (TeacherAbsenceReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
                    TeacherAbsenceReason::cases(),
                )));
    }

    #[Test]
    public function the_card_action_without_a_draft_records_everyone_present(): void
    {
        $first = $this->enroll('Ana');
        $second = $this->enroll('Bruno');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'status' => LessonStatus::Prepared]);

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertSessionHasNoErrors()->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $first, $second): void {
            $lesson->refresh();
            $this->assertSame(LessonStatus::Taught, $lesson->status);
            $this->assertNotNull($lesson->attendance_recorded_at);
            foreach ([$first, $second] as $enrollment) {
                $this->assertSame(
                    AttendanceStatus::Present,
                    LessonAttendance::where('lesson_id', $lesson->id)->where('enrollment_id', $enrollment->id)->sole()->status,
                );
            }
        });
    }

    #[Test]
    public function the_card_action_with_a_draft_consolidates_its_absences(): void
    {
        $present = $this->enroll('Ana');
        $absent = $this->enroll('Bruno');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'status' => LessonStatus::Prepared]);

        $this->asTeacher()
            ->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => [$absent->student->ulid]])
            ->assertRedirect();

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertSessionHasNoErrors()->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $present, $absent): void {
            $lesson->refresh();
            $this->assertSame(LessonStatus::Taught, $lesson->status);
            $this->assertNotNull($lesson->attendance_recorded_at);
            $this->assertSame(
                AttendanceStatus::Present,
                LessonAttendance::where('lesson_id', $lesson->id)->where('enrollment_id', $present->id)->sole()->status,
            );
            $this->assertSame(
                AttendanceStatus::Absent,
                LessonAttendance::where('lesson_id', $lesson->id)->where('enrollment_id', $absent->id)->sole()->status,
            );
        });
    }

    #[Test]
    public function the_card_action_is_forbidden_to_a_teacher_of_another_class(): void
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $otherTeacher = User::factory()->create();
        $this->organization->members()->attach($otherTeacher, ['joined_at' => now()]);

        $this->actingAs($otherTeacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->post("/lessons/{$lesson->ulid}/mark-taught")
            ->assertForbidden();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
            $this->assertNull($lesson->attendance_recorded_at);
        });
    }

    #[Test]
    public function the_outcome_endpoints_used_by_the_card_menu_still_record_both_outcomes(): void
    {
        $absentTeacher = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $externalActivity = $this->makeLesson(['starts_at' => '2026-10-08 10:30:00']);

        $this->asTeacher()
            ->post("/lessons/{$absentTeacher->ulid}/outcome", ['outcome' => 'teacher_absent'])
            ->assertSessionHasErrors('reason');

        $this->asTeacher()
            ->post("/lessons/{$absentTeacher->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'official_duty'])
            ->assertSessionHasNoErrors();
        $this->asTeacher()
            ->post("/lessons/{$externalActivity->ulid}/outcome", ['outcome' => 'class_external_activity', 'note' => null])
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($absentTeacher, $externalActivity): void {
            $this->assertSame(LessonOutcome::TeacherAbsent, $absentTeacher->refresh()->outcome);
            $this->assertSame(TeacherAbsenceReason::OfficialDuty, $absentTeacher->outcome_reason);
            $this->assertSame(LessonOutcome::ClassExternalActivity, $externalActivity->refresh()->outcome);
            $this->assertSame('Turma em outras atividades letivas', $externalActivity->outcome->label());
        });
    }

    #[Test]
    public function the_selection_batch_closes_several_lessons_leaving_attendance_pending_without_a_draft(): void
    {
        $withDraft = $this->enroll('Com Rascunho');
        $first = $this->makeLesson(['starts_at' => '2026-10-08 08:30:00']);
        $second = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $third = $this->makeLesson(['starts_at' => '2026-10-07 09:30:00', 'status' => LessonStatus::Prepared]);

        $this->asTeacher()
            ->put("/lessons/{$third->ulid}/attendance/draft", ['absent' => [$withDraft->student->ulid]])
            ->assertRedirect();

        $preview = $this->asTeacher()->postJson('/lessons/batch-taught/preview', [
            'mode' => 'selection',
            'ulids' => [$first->ulid, $second->ulid, $third->ulid],
        ]);
        $preview->assertOk();
        $this->assertSame(3, $preview->json('eligible'));
        $this->assertSame(2, $preview->json('attendance_pending'));

        $this->asTeacher()
            ->post('/lessons/batch-taught', ['mode' => 'selection', 'ulids' => [$first->ulid, $second->ulid, $third->ulid]])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($first, $second, $third, $withDraft): void {
            foreach ([$first, $second] as $lesson) {
                $lesson->refresh();
                $this->assertSame(LessonStatus::Taught, $lesson->status);
                $this->assertNull($lesson->attendance_recorded_at);
                $this->assertSame(0, LessonAttendance::where('lesson_id', $lesson->id)->count());
            }

            $third->refresh();
            $this->assertSame(LessonStatus::Taught, $third->status);
            $this->assertNotNull($third->attendance_recorded_at);
            $this->assertSame(
                AttendanceStatus::Absent,
                LessonAttendance::where('lesson_id', $third->id)->where('enrollment_id', $withDraft->id)->sole()->status,
            );
        });
    }

    /**
     * O lote não fecha o que não pode: a aula de outro professor e a aula já
     * fechada ficam exatamente como estavam, e são reportadas como não elegíveis
     * na pré-visualização.
     */
    #[Test]
    public function the_selection_batch_never_touches_foreign_or_closed_lessons(): void
    {
        $mine = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $closed = $this->makeLesson([
            'starts_at' => '2026-10-08 08:30:00',
            'status' => LessonStatus::Taught,
        ]);
        $otherTeacher = User::factory()->create();
        $foreign = $this->inTenant($this->organization, function () use ($otherTeacher): Lesson {
            $class = SchoolClass::factory()
                ->recycle($this->organization)
                ->create(['academic_year_id' => $this->schoolClass->academic_year_id]);
            $class->teachers()->attach($otherTeacher, ['role' => 'owner']);

            return Lesson::create([
                'class_id' => $class->id,
                'starts_at' => '2026-10-08 09:30:00',
                'ends_at' => '2026-10-08 10:20:00',
                'status' => LessonStatus::Preparation,
                'created_by' => $otherTeacher->id,
            ]);
        });

        $preview = $this->asTeacher()->postJson('/lessons/batch-taught/preview', [
            'mode' => 'selection',
            'ulids' => [$mine->ulid, $closed->ulid, $foreign->ulid],
        ]);
        $preview->assertOk();
        $this->assertSame(1, $preview->json('eligible'));
        $this->assertSame([$mine->ulid], array_column($preview->json('lessons'), 'ulid'));
        $this->assertNotContains($foreign->ulid, array_column($preview->json('lessons'), 'ulid'));

        // Só inelegíveis: recusado e nada escrito.
        $this->asTeacher()
            ->from('/lessons')
            ->post('/lessons/batch-taught', ['mode' => 'selection', 'ulids' => [$closed->ulid, $foreign->ulid]])
            ->assertSessionHasErrors('batch');

        $this->inTenant($this->organization, function () use ($closed, $foreign, $mine): void {
            $this->assertSame(LessonStatus::Preparation, $foreign->refresh()->status);
            $this->assertNull($foreign->attendance_recorded_at);
            $this->assertSame(LessonStatus::Taught, $closed->refresh()->status);
            $this->assertSame(LessonStatus::Preparation, $mine->refresh()->status);
        });
    }
}
