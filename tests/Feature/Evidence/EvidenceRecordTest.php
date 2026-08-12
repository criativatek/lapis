<?php

namespace Tests\Feature\Evidence;

use App\Models\AcademicPeriod;
use App\Models\Domain;
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

    /** @return array{string, string, string} class ulid, an enrollment id, a domain id of the class's subject */
    private function seedClassWithDomain(): array
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $domainId = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return (string) Domain::where('subject_id', $class->subject_id)->firstOrFail()->id;
        });

        return [$classUlid, $enrollmentId, $domainId];
    }

    #[Test]
    public function a_teacher_records_an_entry_for_a_student(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'participation',
            'participation_level' => 'positive',
            'description' => 'Participou de forma sustentada na discussão do texto.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
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
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertSessionHasErrors('disciplinary_severity');

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'incident',
            'disciplinary_severity' => 'g3',
            'description' => 'Perturbou a aula.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
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
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertSessionHasErrors('disciplinary_severity');
    }

    #[Test]
    public function a_class_level_note_needs_no_student(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'note',
            'description' => 'Visita de estudo à biblioteca municipal marcada para a turma.',
            'occurred_at' => '2026-11-05',
            'enrollment_ids' => [],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(1, EvidenceRecord::count());
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
            'enrollment_ids' => [999999],
        ])->assertSessionHasErrors('enrollment_ids');

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(0, EvidenceRecord::count());
        });
    }

    #[Test]
    public function selecting_several_students_creates_one_independent_record_each(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $enrollmentIds = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return $class->enrollments()->orderBy('class_number')->limit(3)->pluck('id')->all();
        });

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'homework',
            'homework_status' => 'not_done',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => $enrollmentIds,
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($enrollmentIds): void {
            $this->assertSame(3, EvidenceRecord::count());
            $recordedEnrollmentIds = EvidenceRecord::pluck('enrollment_id')->sort()->values()->all();
            $this->assertSame(collect($enrollmentIds)->sort()->values()->all(), $recordedEnrollmentIds);
            // Each is its own row: deleting one must not touch the others.
            $first = EvidenceRecord::whereKey(EvidenceRecord::first()->id)->firstOrFail();
            $first->delete();
            $this->assertSame(2, EvidenceRecord::count());
        });
    }

    #[Test]
    public function removing_a_record_soft_deletes_it(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'note', 'description' => 'A remover.', 'occurred_at' => '2026-10-20',
            'enrollment_ids' => [],
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

    #[Test]
    public function homework_requires_a_status_but_description_is_optional(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'homework',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertSessionHasErrors('homework_status');

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'homework',
            'homework_status' => 'not_done',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $record = EvidenceRecord::firstOrFail();
            $this->assertSame('not_done', $record->homework_status->value);
            $this->assertSame('', $record->description);
        });
    }

    #[Test]
    public function participation_requires_a_level(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'participation',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertSessionHasErrors('participation_level');
    }

    #[Test]
    public function participation_description_is_optional(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'participation',
            'participation_level' => 'adequate',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $record = EvidenceRecord::firstOrFail();
            $this->assertSame('adequate', $record->participation_level->value);
            $this->assertSame('', $record->description);
        });
    }

    #[Test]
    public function a_disciplinary_occurrence_description_is_optional(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'incident',
            'disciplinary_severity' => 'g2',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame('', EvidenceRecord::firstOrFail()->description);
        });
    }

    #[Test]
    public function activity_requires_evaluation_include_in_report_and_description(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'activity',
            'occurred_at' => '2026-11-05',
            'enrollment_ids' => [],
        ])->assertSessionHasErrors(['description', 'activity_evaluation', 'activity_include_in_report']);

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'activity',
            'description' => 'Visionamento da peça Leandro, Rei da Helíria.',
            'activity_evaluation' => 'very_positive',
            'activity_include_in_report' => true,
            'occurred_at' => '2026-11-05',
            'enrollment_ids' => [],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $record = EvidenceRecord::firstOrFail();
            $this->assertSame('very_positive', $record->activity_evaluation->value);
            $this->assertTrue($record->activity_include_in_report);
        });
    }

    #[Test]
    public function progress_requires_description_and_accepts_an_optional_domain(): void
    {
        [$classUlid, $enrollmentId, $domainId] = $this->seedClassWithDomain();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'progress',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertSessionHasErrors('description');

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'progress',
            'description' => 'Demonstrou maior autonomia na produção escrita.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'progress',
            'description' => 'Progrediu na leitura em voz alta.',
            'domain_id' => (int) $domainId,
            'occurred_at' => '2026-10-21',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($domainId): void {
            $this->assertSame(2, EvidenceRecord::count());
            $withDomain = EvidenceRecord::whereNotNull('domain_id')->firstOrFail();
            $this->assertSame((int) $domainId, $withDomain->domain_id);
        });
    }

    #[Test]
    public function difficulty_requires_description_and_accepts_an_optional_domain(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'difficulty',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertSessionHasErrors('description');

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'difficulty',
            'description' => 'Revela dificuldade na organização das ideias.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertNull(EvidenceRecord::firstOrFail()->domain_id);
        });
    }

    #[Test]
    public function updating_a_record_persists_the_new_values(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'note',
            'description' => 'Observação inicial.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        $ulid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => EvidenceRecord::firstOrFail()->ulid);

        $this->actingAs($teacher)->put("/records/{$ulid}", [
            'kind' => 'homework',
            'homework_status' => 'done',
            'occurred_at' => '2026-10-21',
            'enrollment_id' => (int) $enrollmentId,
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($ulid): void {
            $record = EvidenceRecord::where('ulid', $ulid)->firstOrFail();
            $this->assertSame('homework', $record->kind->value);
            $this->assertSame('done', $record->homework_status->value);
            $this->assertSame(1, EvidenceRecord::count());
        });
    }

    #[Test]
    public function a_teacher_from_another_organization_cannot_edit_the_record(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'note',
            'description' => 'Observação.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        $ulid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => EvidenceRecord::firstOrFail()->ulid);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->put("/records/{$ulid}", [
            'kind' => 'note',
            'description' => 'Tentativa de outra organização.',
            'occurred_at' => '2026-10-21',
        ])->assertNotFound();
    }

    #[Test]
    public function the_listing_can_be_filtered_by_kind_student_and_period(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'homework', 'homework_status' => 'done',
            'occurred_at' => '2026-10-20', 'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();
        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'note', 'description' => 'Nota de turma.', 'occurred_at' => '2026-11-01',
            'enrollment_ids' => [],
        ])->assertRedirect();
        $this->actingAs($teacher)->post("/classes/{$classUlid}/records", [
            'kind' => 'note', 'description' => 'Nota do 2.º semestre.', 'occurred_at' => '2027-03-01',
            'enrollment_ids' => [],
        ])->assertRedirect();

        $periodId = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return AcademicPeriod::where('academic_year_id', $class->academic_year_id)->where('sequence', 1)->firstOrFail()->id;
        });

        $this->actingAs($teacher)->get("/classes/{$classUlid}/records?kind=homework")
            ->assertInertia(fn ($page) => $page->component('records/Show')->has('records', 1));

        $this->actingAs($teacher)->get("/classes/{$classUlid}/records?enrollment_id={$enrollmentId}")
            ->assertInertia(fn ($page) => $page->component('records/Show')->has('records', 1));

        $this->actingAs($teacher)->get("/classes/{$classUlid}/records?period_id={$periodId}")
            ->assertInertia(fn ($page) => $page->component('records/Show')->has('records', 2));
    }
}
