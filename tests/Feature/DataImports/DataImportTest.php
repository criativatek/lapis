<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataImportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Organization, User} */
    private function institutionalOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);

        return [$organization, $owner];
    }

    private function classWithEnrollment(Organization $organization, User $teacher, string $yearLabel = '2026/2027', string $subjectName = 'Matemática', string $classLabel = '7.º A'): SchoolClass
    {
        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $teacher, $yearLabel, $subjectName, $classLabel): SchoolClass {
            $year = AcademicYear::factory()->recycle($organization)->create(['label' => $yearLabel]);
            $subject = Subject::factory()->recycle($organization)->create(['name' => $subjectName]);
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'label' => $classLabel,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);
            Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id]);

            return $class->fresh();
        });
    }

    private function seedMatchingStructure(Organization $destination, string $yearLabel = '2026/2027', string $subjectName = 'Matemática'): void
    {
        app(CurrentOrganization::class)->runFor($destination, function () use ($destination, $yearLabel, $subjectName) {
            AcademicYear::factory()->recycle($destination)->create(['label' => $yearLabel]);
            Subject::factory()->recycle($destination)->create(['name' => $subjectName]);
        });
    }

    /**
     * Generates a real export and returns the ZIP as an upload — a
     * self-contained call that internally switches the test's "acting as"
     * user/session to the SOURCE. Always resolve this into a variable
     * BEFORE opening the destination's own actingAs()/withSession() chain:
     * PHP evaluates a call's arguments before invoking it, so inlining this
     * inside post('/data-imports', ['file' => $this->backupUpload(...)])
     * would run the source's actingAs() last, silently uploading as the
     * wrong user into the wrong organization.
     */
    private function backupUpload(Organization $sourceOrganization, User $sourceUser): UploadedFile
    {
        $this->actingAs($sourceUser)->withSession(['organization_id' => $sourceOrganization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $sourceUser->id)->latest('id')->firstOrFail();
        $zipPath = Storage::disk('local')->path($export->disk_path);

        return new UploadedFile($zipPath, 'backup.zip', 'application/zip', null, true);
    }

    /**
     * `classes.ulid` (and students', and enrollments') is unique across the
     * WHOLE table, not per-organization — restoring a class while its
     * source row still exists ANYWHERE would always collide. A realistic
     * "creates new records" scenario is therefore restore-after-loss: the
     * class is exported, then removed (the way it would be if it were
     * genuinely gone), only then imported back — into the same
     * organization it came from, or into a different one, either way now
     * legitimately free of that ulid.
     */
    private function deletePedagogicalData(Organization $organization, SchoolClass $class): void
    {
        app(CurrentOrganization::class)->runFor($organization, function () use ($class) {
            $enrollments = Enrollment::where('class_id', $class->id)->get();
            $studentIds = $enrollments->pluck('student_id');
            Enrollment::where('class_id', $class->id)->delete();

            foreach (Student::whereIn('id', $studentIds)->get() as $student) {
                $student->identity?->delete();
                $student->delete();
            }

            $class->teachers()->detach();
            $class->delete();
        });
    }

    private function uploadInto(Organization $destination, User $actor, UploadedFile $file): DataImport
    {
        $this->actingAs($actor)->withSession(['organization_id' => $destination->id])
            ->post('/data-imports', ['file' => $file])
            ->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')
            ->where('organization_id', $destination->id)
            ->where('requested_by', $actor->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * The `organization` global scope ANDs every query with whichever tenant
     * is currently bound in the container — which, in a feature test, is
     * whatever the LAST simulated HTTP request resolved, not necessarily the
     * organization an assertion cares about. Counting through it as-is would
     * silently intersect two different organizations and read as zero
     * either way, hiding a real leak as easily as it would fabricate one.
     * Bypassing it here is the same legitimate cross-tenant pattern the
     * production code itself uses for admin/report reads.
     */
    private function classCount(Organization $organization): int
    {
        return SchoolClass::withoutGlobalScope('organization')->where('organization_id', $organization->id)->count();
    }

    private function studentCount(Organization $organization): int
    {
        return Student::withoutGlobalScope('organization')->where('organization_id', $organization->id)->count();
    }

    private function enrollmentCount(Organization $organization): int
    {
        return Enrollment::withoutGlobalScope('organization')->where('organization_id', $organization->id)->count();
    }

    private function onlyClassIn(Organization $organization): SchoolClass
    {
        return SchoolClass::withoutGlobalScope('organization')->where('organization_id', $organization->id)->firstOrFail();
    }

    #[Test]
    public function uploading_a_backup_builds_a_preview_and_writes_nothing_pedagogical(): void
    {
        Storage::fake('local');
        [$sourceOrg, $sourceOwner] = $this->institutionalOrganization();
        $class = $this->classWithEnrollment($sourceOrg, $sourceOwner);
        $file = $this->backupUpload($sourceOrg, $sourceOwner);
        $this->deletePedagogicalData($sourceOrg, $class);

        $import = $this->uploadInto($sourceOrg, $sourceOwner, $file);

        $this->assertSame('validated', $import->status->value);
        $this->assertNotNull($import->canonical_snapshot);
        $this->assertSame(BackupSchemaCompatibility::CURRENT, $import->source_schema_version);
        $this->assertSame(0, $this->classCount($sourceOrg));
        $this->assertSame(0, $this->studentCount($sourceOrg));
    }

    #[Test]
    public function confirming_restores_deleted_records_and_makes_the_importer_the_teacher_in_a_personal_organization(): void
    {
        Storage::fake('local');
        $sourceUser = User::factory()->create();
        $sourceOrg = $sourceUser->personalOrganization();
        $class = $this->classWithEnrollment($sourceOrg, $sourceUser);
        $file = $this->backupUpload($sourceOrg, $sourceUser);
        $this->deletePedagogicalData($sourceOrg, $class);

        $import = $this->uploadInto($sourceOrg, $sourceUser, $file);

        $response = $this->actingAs($sourceUser)->withSession(['organization_id' => $sourceOrg->id])
            ->post("/data-imports/{$import->ulid}/confirm");

        $response->assertRedirect();
        $imported = $import->fresh();
        $this->assertSame('imported', $imported->status->value);
        $this->assertNull($imported->stored_path);
        $this->assertSame(1, $this->classCount($sourceOrg));
        $this->assertSame(1, $this->studentCount($sourceOrg));
        $this->assertSame(1, $this->enrollmentCount($sourceOrg));

        $restoredClass = $this->onlyClassIn($sourceOrg);
        $this->assertSame($class->ulid, $restoredClass->ulid);
        $this->assertTrue($restoredClass->teachers()->whereKey($sourceUser->id)->exists());

        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $sourceOrg,
            fn () => AuditEvent::where('event', 'data_import.completed')->exists(),
        ));
    }

    #[Test]
    public function institutional_import_leaves_restored_classes_unassigned_for_reassignment(): void
    {
        Storage::fake('local');
        [$sourceOrg, $sourceOwner] = $this->institutionalOrganization();
        $class = $this->classWithEnrollment($sourceOrg, $sourceOwner);
        $file = $this->backupUpload($sourceOrg, $sourceOwner);
        $this->deletePedagogicalData($sourceOrg, $class);

        $import = $this->uploadInto($sourceOrg, $sourceOwner, $file);

        $this->actingAs($sourceOwner)->withSession(['organization_id' => $sourceOrg->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertRedirect();

        $restoredClass = $this->onlyClassIn($sourceOrg);
        $this->assertFalse($restoredClass->teachers()->exists());
        $this->assertTrue(SchoolClass::withoutGlobalScope('organization')->needingReassignment()->whereKey($restoredClass->id)->exists());
        $this->assertSame(1, $import->fresh()->summary['classes_needing_reassignment']);
    }

    #[Test]
    public function importing_the_same_backup_twice_creates_no_duplicates(): void
    {
        Storage::fake('local');
        [$sourceOrg, $sourceOwner] = $this->institutionalOrganization();
        $class = $this->classWithEnrollment($sourceOrg, $sourceOwner);
        $file = $this->backupUpload($sourceOrg, $sourceOwner);
        $this->deletePedagogicalData($sourceOrg, $class);

        $firstImport = $this->uploadInto($sourceOrg, $sourceOwner, $file);
        $this->actingAs($sourceOwner)->withSession(['organization_id' => $sourceOrg->id])
            ->post("/data-imports/{$firstImport->ulid}/confirm")->assertRedirect();

        $this->assertSame(1, $this->classCount($sourceOrg));
        $this->assertSame(1, $this->studentCount($sourceOrg));
        $this->assertSame(1, $this->enrollmentCount($sourceOrg));

        // The very same file, uploaded and previewed again — nothing was
        // deleted this time, so every row now matches something that exists.
        $secondImport = $this->uploadInto($sourceOrg, $sourceOwner, $file);

        $this->actingAs($sourceOwner)->withSession(['organization_id' => $sourceOrg->id])
            ->get("/data-imports/{$secondImport->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('plan.counts.classes.new', 0)
                ->where('plan.counts.classes.existing', 1)
                ->where('plan.can_confirm', false));

        $this->assertSame(1, $this->classCount($sourceOrg));
        $this->assertSame(1, $this->studentCount($sourceOrg));
        $this->assertSame(1, $this->enrollmentCount($sourceOrg));
    }

    #[Test]
    public function a_class_edited_locally_after_a_first_import_is_a_conflict_on_reimport_and_is_never_overwritten(): void
    {
        Storage::fake('local');
        [$sourceOrg, $sourceOwner] = $this->institutionalOrganization();
        $class = $this->classWithEnrollment($sourceOrg, $sourceOwner);
        $file = $this->backupUpload($sourceOrg, $sourceOwner);
        $this->deletePedagogicalData($sourceOrg, $class);

        $firstImport = $this->uploadInto($sourceOrg, $sourceOwner, $file);
        $this->actingAs($sourceOwner)->withSession(['organization_id' => $sourceOrg->id])
            ->post("/data-imports/{$firstImport->ulid}/confirm")->assertRedirect();

        // Diverged locally after the restore.
        $this->onlyClassIn($sourceOrg)->update(['label' => 'Turma Diferente']);

        $secondImport = $this->uploadInto($sourceOrg, $sourceOwner, $file);

        $this->actingAs($sourceOwner)->withSession(['organization_id' => $sourceOrg->id])
            ->get("/data-imports/{$secondImport->ulid}")
            ->assertInertia(fn ($page) => $page->where('plan.counts.classes.conflict', 1)->where('plan.counts.classes.new', 0));

        $this->actingAs($sourceOwner)->withSession(['organization_id' => $sourceOrg->id])
            ->post("/data-imports/{$secondImport->ulid}/confirm")->assertRedirect();

        $this->assertSame('Turma Diferente', $this->onlyClassIn($sourceOrg)->label);
        $this->assertSame(1, $this->classCount($sourceOrg));
    }

    #[Test]
    public function a_class_referencing_an_academic_year_absent_from_the_destination_is_invalid_and_skipped(): void
    {
        Storage::fake('local');
        [$sourceOrg, $sourceOwner] = $this->institutionalOrganization();
        $class = $this->classWithEnrollment($sourceOrg, $sourceOwner, yearLabel: '2030/2031');
        $file = $this->backupUpload($sourceOrg, $sourceOwner);
        $this->deletePedagogicalData($sourceOrg, $class);

        // A different, unrelated destination — never had 2030/2031 to begin
        // with, so this exercises "the year is genuinely missing" cleanly,
        // rather than "the year happened to survive the delete above".
        $otherUser = User::factory()->create();
        $otherOrg = $otherUser->personalOrganization();

        $import = $this->uploadInto($otherOrg, $otherUser, $file);

        // The student itself has nothing to do with academic years, so it
        // classifies as `new` on its own — the class stays `invalid` and
        // blocks only itself, not the whole import. `can_confirm` is
        // therefore true: there is a real, independent new row to write.
        $this->actingAs($otherUser)->withSession(['organization_id' => $otherOrg->id])
            ->get("/data-imports/{$import->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('plan.counts.academic_years.invalid', 1)
                ->where('plan.counts.classes.invalid', 1)
                ->where('plan.counts.students.new', 1)
                ->where('plan.can_confirm', true));

        $this->actingAs($otherUser)->withSession(['organization_id' => $otherOrg->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertRedirect();

        $this->assertSame(0, $this->classCount($otherOrg));
        $this->assertSame(1, $this->studentCount($otherOrg));
    }

    /**
     * A class/student ulid that still belongs to a DIFFERENT organization
     * (the export was never followed by a delete) must never reach the
     * database as an insert attempt under the SOURCE ulid — `classes.ulid`
     * is globally unique, so that insert would fail as a raw constraint
     * violation. Fatia 6.1's cross-organization clone correction (see
     * docs/data-import.md) resolves this instead of blocking it: the class
     * is classified `new`, restored under a freshly generated ulid, and the
     * source organization is left completely untouched — a genuine clone,
     * not a move.
     */
    #[Test]
    public function a_backup_restored_into_a_different_organization_while_the_source_still_exists_clones_with_a_fresh_ulid(): void
    {
        Storage::fake('local');
        [$sourceOrg, $sourceOwner] = $this->institutionalOrganization();
        $sourceClass = $this->classWithEnrollment($sourceOrg, $sourceOwner);
        $file = $this->backupUpload($sourceOrg, $sourceOwner);
        // Source data deliberately left in place.

        $otherUser = User::factory()->create();
        $otherOrg = $otherUser->personalOrganization();
        $this->seedMatchingStructure($otherOrg);

        $import = $this->uploadInto($otherOrg, $otherUser, $file);

        $this->actingAs($otherUser)->withSession(['organization_id' => $otherOrg->id])
            ->get("/data-imports/{$import->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('plan.counts.classes.invalid', 0)
                ->where('plan.counts.classes.new', 1)
                ->where('plan.can_confirm', true));

        $this->actingAs($otherUser)->withSession(['organization_id' => $otherOrg->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertRedirect();

        $this->assertSame(1, $this->classCount($otherOrg));
        $this->assertSame(1, $this->classCount($sourceOrg));

        $clonedClass = $this->onlyClassIn($otherOrg);
        $this->assertNotSame($sourceClass->ulid, $clonedClass->ulid);
    }

    #[Test]
    public function a_corrupted_backup_that_slips_past_row_validation_rolls_back_completely(): void
    {
        Storage::fake('local');
        [$sourceOrg, $sourceOwner] = $this->institutionalOrganization();
        $class = $this->classWithEnrollment($sourceOrg, $sourceOwner);
        $file = $this->backupUpload($sourceOrg, $sourceOwner);
        $this->deletePedagogicalData($sourceOrg, $class);

        $import = $this->uploadInto($sourceOrg, $sourceOwner, $file);

        // Duplicate the one student row under a different ulid but the SAME
        // pseudonym_code — both classify as "new" (neither ulid exists
        // anywhere), so the second INSERT collides with the destination's
        // own [organization_id, pseudonym_code] unique constraint
        // mid-transaction. This can never come out of GenerateDataExport
        // itself; it stands in for a corrupted/crafted file reaching the
        // write step.
        $snapshot = $import->canonical_snapshot;
        $duplicateStudent = $snapshot['students'][0];
        $duplicateStudent['ulid'] = (string) Str::ulid();
        $snapshot['students'][] = $duplicateStudent;
        $import->forceFill(['canonical_snapshot' => $snapshot])->save();

        $this->actingAs($sourceOwner)->withSession(['organization_id' => $sourceOrg->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertSessionHasErrors('import');

        $this->assertSame('failed', $import->fresh()->status->value);
        $this->assertSame(0, $this->classCount($sourceOrg));
        $this->assertSame(0, $this->studentCount($sourceOrg));
        $this->assertSame(0, $this->enrollmentCount($sourceOrg));
    }

    #[Test]
    public function restoring_into_one_organization_does_not_affect_the_users_personal_organization_or_another_institution(): void
    {
        Storage::fake('local');
        [$throwawaySource, $throwawayOwner] = $this->institutionalOrganization();
        $class = $this->classWithEnrollment($throwawaySource, $throwawayOwner);
        $file = $this->backupUpload($throwawaySource, $throwawayOwner);
        $this->deletePedagogicalData($throwawaySource, $class);

        $owner = User::factory()->create();
        $personal = $owner->personalOrganization();
        [$institutionA] = $this->institutionalOrganization();
        $institutionA->members()->attach($owner, ['joined_at' => now()]);
        $this->seedMatchingStructure($institutionA);

        $import = $this->uploadInto($institutionA, $owner, $file);

        $this->actingAs($owner)->withSession(['organization_id' => $institutionA->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertRedirect();

        $this->assertSame(1, $this->classCount($institutionA));
        $this->assertSame(0, $this->classCount($personal));
    }

    #[Test]
    public function cancelling_deletes_the_stored_file_and_marks_the_import_cancelled(): void
    {
        Storage::fake('local');
        [$sourceOrg, $sourceOwner] = $this->institutionalOrganization();
        $class = $this->classWithEnrollment($sourceOrg, $sourceOwner);
        $file = $this->backupUpload($sourceOrg, $sourceOwner);
        $this->deletePedagogicalData($sourceOrg, $class);

        $import = $this->uploadInto($sourceOrg, $sourceOwner, $file);
        $this->assertTrue(Storage::disk('local')->exists((string) $import->stored_path));

        $this->actingAs($sourceOwner)->withSession(['organization_id' => $sourceOrg->id])
            ->delete("/data-imports/{$import->ulid}")->assertRedirect();

        $fresh = $import->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertNull($fresh->stored_path);
    }
}
