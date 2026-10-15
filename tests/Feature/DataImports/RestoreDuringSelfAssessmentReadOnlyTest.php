<?php

namespace Tests\Feature\DataImports;

use App\Actions\DataImports\ExecuteDataImport;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Assessment\SelfAssessmentReadOnlyException;
use App\Support\Assessment\SelfAssessmentWrites;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;

/**
 * Recovery containment (docs/self-assessment-recovery.md): while the
 * self-assessment is read-only, NO backup is restored. A backup carries
 * self-assessments, templates and answers, and restoring one would write
 * exactly what the mode exists to stop.
 *
 * Refused, not failed: the import stays confirmable and its upload stays on
 * the private disk, so it can be confirmed once the mode is off.
 */
class RestoreDuringSelfAssessmentReadOnlyTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->organization = $this->user->personalOrganization();
    }

    /**
     * A real export of a class, the class then removed, and the backup
     * uploaded back — validated and waiting for confirmation.
     */
    private function validatedImport(): DataImport
    {
        $class = app(CurrentOrganization::class)->runFor($this->organization, function (): SchoolClass {
            $year = AcademicYear::factory()->recycle($this->organization)->create(['label' => '2026/2027']);
            $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Matemática']);
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '7.º A']);
            $class->teachers()->attach($this->user, ['role' => 'owner']);
            Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id]);

            return $class->fresh();
        });

        $this->actingAs($this->user)->withSession(['organization_id' => $this->organization->id])->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->user->id)->latest('id')->firstOrFail();
        $file = new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($class): void {
            $enrollments = Enrollment::where('class_id', $class->id)->get();
            Enrollment::where('class_id', $class->id)->delete();

            foreach (Student::whereIn('id', $enrollments->pluck('student_id'))->get() as $student) {
                $student->identity?->delete();
                $student->delete();
            }

            $class->teachers()->detach();
            $class->delete();
        });

        if (! app(Entitlements::class)->allowsFor($this->organization, 'data_backup_restore')) {
            $this->subscribeOrganizationTo($this->organization, 'pro');
        }

        $this->actingAs($this->user)->withSession(['organization_id' => $this->organization->id])
            ->post('/data-imports', ['file' => $file])
            ->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')->where('organization_id', $this->organization->id)->latest('id')->firstOrFail();
    }

    private function classCount(): int
    {
        return SchoolClass::withoutGlobalScope('organization')->where('organization_id', $this->organization->id)->count();
    }

    private function restoreEvents(): int
    {
        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): int => AuditEvent::whereIn('event', ['data_import.completed', 'data_import.failed'])->count(),
        );
    }

    private function confirm(DataImport $import): TestResponse
    {
        return $this->actingAs($this->user)->withSession(['organization_id' => $this->organization->id])
            ->from("/data-imports/{$import->ulid}")
            ->post("/data-imports/{$import->ulid}/confirm");
    }

    #[Test]
    public function a_restore_is_refused_while_the_self_assessment_is_read_only_and_the_import_survives_it(): void
    {
        $import = $this->validatedImport();
        $status = $import->status->value;
        $storedPath = $import->stored_path;

        config(['lapis.self_assessment.read_only' => true]);

        $this->confirm($import)
            ->assertRedirect("/data-imports/{$import->ulid}")
            ->assertSessionHasErrors(['import' => SelfAssessmentWrites::restoreRefusal()]);

        // Nothing written, and the import exactly as it was: not failed, its
        // upload still there to confirm later.
        $this->assertSame(0, $this->classCount());
        $this->assertSame(0, $this->restoreEvents());

        $after = $import->fresh();
        $this->assertSame($status, $after->status->value);
        $this->assertTrue($after->status->canBeConfirmed());
        $this->assertSame($storedPath, $after->stored_path);
        $this->assertNotNull($storedPath);
        Storage::disk('local')->assertExists($storedPath);
    }

    #[Test]
    public function the_executor_itself_refuses_whoever_calls_it(): void
    {
        $import = $this->validatedImport();

        config(['lapis.self_assessment.read_only' => true]);

        try {
            app(ExecuteDataImport::class)->execute($import, $this->user);
            $this->fail('O restauro devia ter sido recusado.');
        } catch (SelfAssessmentReadOnlyException $exception) {
            $this->assertSame(SelfAssessmentWrites::restoreRefusal(), $exception->getMessage());
        }

        $this->assertSame(0, $this->classCount());
        $this->assertTrue($import->fresh()->status->canBeConfirmed());
    }

    #[Test]
    public function the_same_import_restores_once_the_mode_is_off(): void
    {
        $import = $this->validatedImport();

        config(['lapis.self_assessment.read_only' => true]);
        $this->confirm($import)->assertSessionHasErrors('import');

        config(['lapis.self_assessment.read_only' => false]);
        $this->confirm($import)->assertSessionHasNoErrors();

        $this->assertSame(1, $this->classCount());
        $this->assertSame('imported', $import->fresh()->status->value);
    }
}
