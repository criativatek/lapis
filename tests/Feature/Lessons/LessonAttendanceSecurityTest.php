<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * §28 do briefing de assiduidade — a mesma disciplina de isolamento e
 * autorização que o resto das aulas já tem, aplicada às três rotas novas.
 */
class LessonAttendanceSecurityTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden_on_every_attendance_route(): void
    {
        $enrollment = $this->enroll('Aluno');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $otherTeacher = User::factory()->create();
        $this->organization->members()->attach($otherTeacher, ['joined_at' => now()]);
        $actingAsOther = $this->actingAs($otherTeacher)->withSession(['organization_id' => $this->organization->id]);

        $actingAsOther->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => []])->assertForbidden();
        $actingAsOther->post("/lessons/{$lesson->ulid}/attendance", ['absent' => []])->assertForbidden();
        $actingAsOther->patch("/lessons/{$lesson->ulid}/attendance/{$enrollment->student->ulid}", ['status' => 'absent'])->assertForbidden();
    }

    #[Test]
    public function a_lesson_from_another_organization_is_not_resolved(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $this->subscribeToPro($otherOrganization);

        $otherLesson = $this->inTenant($otherOrganization, function () use ($otherTeacher, $otherOrganization) {
            $class = SchoolClass::factory()->recycle($otherOrganization)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($otherOrganization)
                    ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])->id,
            ]);
            $class->teachers()->attach($otherTeacher, ['role' => 'owner']);

            return Lesson::create([
                'class_id' => $class->id,
                'starts_at' => '2026-10-08 09:30:00',
                'ends_at' => '2026-10-08 10:20:00',
                'status' => LessonStatus::Preparation,
                'created_by' => $otherTeacher->id,
            ]);
        });

        $this->asTeacher()
            ->put("/lessons/{$otherLesson->ulid}/attendance/draft", ['absent' => []])
            ->assertNotFound();
    }

    #[Test]
    public function a_tampered_student_ulid_from_another_class_is_rejected_without_writing_anything(): void
    {
        $this->enroll('Da Turma');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $otherClass = $this->inTenant($this->organization, function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });
        $outsider = $this->enroll('De Outra Turma', schoolClass: $otherClass);

        $this->asTeacher()
            ->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => [$outsider->student->ulid]])
            ->assertSessionHasErrors('absent');

        $this->assertDatabaseCount('lesson_attendances', 0);
    }

    #[Test]
    public function correcting_a_student_outside_the_consolidated_snapshot_is_rejected(): void
    {
        $this->enroll('Consolidado');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();

        $outsider = $this->enroll('Fora Do Instantâneo');

        $this->asTeacher()
            ->patch("/lessons/{$lesson->ulid}/attendance/{$outsider->student->ulid}", ['status' => 'absent'])
            ->assertNotFound();
    }

    #[Test]
    public function mass_assignment_of_status_and_organization_is_ignored(): void
    {
        $enrollment = $this->enroll('Aluno');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $otherOrganization = Organization::factory()->create();

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/attendance/draft", [
            'absent' => [$enrollment->student->ulid],
            'organization_id' => $otherOrganization->id,
            'status' => 'present',
        ])->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $row = LessonAttendance::where('lesson_id', $lesson->id)->sole();
            $this->assertSame($this->organization->id, $row->organization_id);
            $this->assertSame('absent', $row->status->value);
        });
    }

    #[Test]
    public function impersonation_blocks_every_attendance_write(): void
    {
        $enrollment = $this->enroll('Aluno');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $impersonating = $this->actingAs($this->teacher)->withSession([
            'organization_id' => $this->organization->id,
            'impersonator_id' => 999,
        ]);

        $impersonating->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => []])->assertForbidden();
        $impersonating->post("/lessons/{$lesson->ulid}/attendance", ['absent' => []])->assertForbidden();
        $impersonating->patch("/lessons/{$lesson->ulid}/attendance/{$enrollment->student->ulid}", ['status' => 'absent'])->assertForbidden();

        $this->assertDatabaseCount('lesson_attendances', 0);
    }

    #[Test]
    public function a_ulid_that_is_not_a_student_at_all_is_rejected(): void
    {
        $this->enroll('Aluno');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $this->asTeacher()
            ->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => [(string) Str::ulid()]])
            ->assertSessionHasErrors('absent');

        $this->assertDatabaseCount('lesson_attendances', 0);
    }
}
