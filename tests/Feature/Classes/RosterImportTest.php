<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RosterImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    /**
     * @return array{year: int, subject: int}
     */
    protected function context(): array
    {
        return app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), fn () => [
            'year' => AcademicYear::factory()->recycle($this->user->personalOrganization())->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($this->user->personalOrganization())->create()->id,
        ]);
    }

    protected function createClass(): SchoolClass
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);

        return SchoolClass::withoutGlobalScope('organization')->firstOrFail();
    }

    #[Test]
    public function a_students_photo_streams_for_the_teacher_who_teaches_them(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();

        Storage::disk('local')->put('student-photos/'.$student->ulid.'.jpg', 'fake-bytes');
        StudentIdentity::where('student_id', $student->id)->update(['photo_path' => 'student-photos/'.$student->ulid.'.jpg']);

        $response = $this->actingAs($this->user)->get("/students/{$student->ulid}/photo");

        $response->assertOk();
        $this->assertSame('fake-bytes', $response->streamedContent());
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_student_cannot_see_the_photo(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();
        StudentIdentity::where('student_id', $student->id)->update(['photo_path' => 'student-photos/x.jpg']);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/students/{$student->ulid}/photo")->assertNotFound();
    }

    #[Test]
    public function a_student_with_no_photo_returns_not_found(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($this->user)->get("/students/{$student->ulid}/photo")->assertNotFound();
    }
}
