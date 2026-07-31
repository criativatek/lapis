<?php

namespace Tests\Feature\Evidence;

use App\Models\EvidenceRecord;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The logbook (§14): qualitative entries tied to a class and optionally a student,
 * never a grade. Targeting must stay inside the class.
 */
class EvidenceRecordTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{string, string} class ulid, an enrollment id of that class */
    private function seedClass(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$class->ulid, (string) $class->enrollments()->orderBy('class_number')->first()->id];
        });
    }

    #[Test]
    public function a_teacher_records_an_entry_for_a_student(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'participation',
            'description' => 'Participou de forma sustentada na discussão do texto.',
            'occurred_at' => '2026-10-20',
            'enrollment_id' => (int) $enrollmentId,
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($enrollmentId): void {
            $record = EvidenceRecord::firstOrFail();
            $this->assertSame('participation', $record->kind->value);
            $this->assertSame((int) $enrollmentId, $record->enrollment_id);
        });
    }

    #[Test]
    public function a_disciplinary_occurrence_requires_a_severity_grade(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'incident',
            'description' => 'Perturbou a aula.',
            'occurred_at' => '2026-10-20',
            'enrollment_id' => (int) $enrollmentId,
        ])->assertSessionHasErrors('disciplinary_severity');

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'incident',
            'disciplinary_severity' => 'g3',
            'description' => 'Perturbou a aula.',
            'occurred_at' => '2026-10-20',
            'enrollment_id' => (int) $enrollmentId,
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $record = EvidenceRecord::firstOrFail();
            $this->assertSame('incident', $record->kind->value);
            $this->assertSame('g3', $record->disciplinary_severity->value);
        });
    }

    #[Test]
    public function a_severity_grade_is_rejected_for_a_non_disciplinary_kind(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'participation',
            'disciplinary_severity' => 'g3',
            'description' => 'Participou bem.',
            'occurred_at' => '2026-10-20',
            'enrollment_id' => (int) $enrollmentId,
        ])->assertSessionHasErrors('disciplinary_severity');
    }

    #[Test]
    public function a_class_level_note_needs_no_student(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'activity',
            'description' => 'Visita de estudo à biblioteca municipal.',
            'occurred_at' => '2026-11-05',
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertNull(EvidenceRecord::firstOrFail()->enrollment_id);
        });
    }

    #[Test]
    public function a_student_from_another_class_is_rejected(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        // Enrollment id 999999 does not belong to this class.
        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'note',
            'description' => 'Tentativa inválida.',
            'occurred_at' => '2026-10-20',
            'enrollment_id' => 999999,
        ])->assertSessionHasErrors('enrollment_id');
    }

    #[Test]
    public function removing_a_record_soft_deletes_it(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'note', 'description' => 'A remover.', 'occurred_at' => '2026-10-20',
        ])->assertRedirect();

        $ulid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => EvidenceRecord::firstOrFail()->ulid);

        $this->actingAs($teacher)->delete("/records/{$ulid}")->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(0, EvidenceRecord::count());
            $this->assertSame(1, EvidenceRecord::withTrashed()->count());
        });
    }

    #[Test]
    public function the_logbook_of_another_organization_is_not_found(): void
    {
        [$classUlid] = $this->seedClass();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/classes/{$classUlid}/records")->assertNotFound();
    }
}
