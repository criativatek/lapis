<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InstrumentType;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;
use ZipArchive;

/**
 * Scenario 10 (JANELA AG round 2): an old-style backup — written before R2
 * existed — can carry a diagnostic instrument with
 * counts_toward_classification stored true, or a regular instrument with the
 * field missing entirely. Restoring it must still succeed: the diagnostic
 * instrument comes back forced to false (Instrument::booted()'s `saving`
 * hook, the same guarantee that covers every write path), the regular one
 * defaults to true (ValidateBackupPayload's documented default), and neither
 * instrument's items or scores are affected either way.
 *
 * Built on a REAL export → mutate the zip's JSON → REAL import confirm, the
 * same round-trip shape as PedagogicalRoundTripTest/LessonAttendanceRoundTripTest,
 * so this exercises ValidateBackupPayload + WriteAssessmentData exactly as
 * production does, not a hand-built payload.
 */
class InstrumentEligibilityRestoreTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = User::factory()->create();
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    #[Test]
    public function restoring_an_old_style_payload_restores_diagnostic_as_false_and_missing_field_as_true(): void
    {
        ['class' => $class, 'diagnostic' => $diagnosticUlid, 'regular' => $regularUlid] = $this->scenario();
        $classUlid = $class->ulid;

        $backup = $this->backupUpload();

        $mutated = $this->rewriteBackup($backup, function (array $json) use ($diagnosticUlid, $regularUlid): array {
            $json['instruments'] = collect($json['instruments'])->map(function (array $row) use ($diagnosticUlid, $regularUlid): array {
                if ($row['ulid'] === $diagnosticUlid) {
                    // Old-style payload: a diagnostic instrument that was
                    // (incorrectly, by today's rule) stored as counting.
                    $row['counts_toward_classification'] = true;
                }

                if ($row['ulid'] === $regularUlid) {
                    // Old-style payload: the field simply did not exist yet.
                    unset($row['counts_toward_classification']);
                }

                return $row;
            })->values()->all();

            return $json;
        });

        $this->deletePedagogicalData($class);

        $import = $this->uploadInto($mutated);
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->teacher->personalOrganization()->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($classUlid, $diagnosticUlid, $regularUlid): void {
            $restoredClass = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            $diagnostic = Instrument::where('ulid', $diagnosticUlid)->firstOrFail();
            $this->assertSame($restoredClass->id, $diagnostic->class_id);
            $this->assertSame('diagnostic', $diagnostic->purpose);
            $this->assertFalse($diagnostic->counts_toward_classification, 'R2: a diagnostic instrument is always restored as not counting.');
            $this->assertSame(1, $diagnostic->items()->count());
            $this->assertSame(1, StudentItemScore::where('instrument_id', $diagnostic->id)->count());

            $regular = Instrument::where('ulid', $regularUlid)->firstOrFail();
            $this->assertSame($restoredClass->id, $regular->class_id);
            $this->assertSame('formative', $regular->purpose);
            $this->assertTrue($regular->counts_toward_classification, 'ValidateBackupPayload defaults a missing field to true.');
            $this->assertSame(1, $regular->items()->count());
            $this->assertSame(1, StudentItemScore::where('instrument_id', $regular->id)->count());
        });
    }

    /**
     * @return array{class: SchoolClass, diagnostic: string, regular: string}
     */
    private function scenario(): array
    {
        return $this->inTenant(function (): array {
            $organization = $this->teacher->personalOrganization();
            $year = AcademicYear::factory()->recycle($organization)->create([
                'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
            ]);
            $period = AcademicPeriod::factory()->recycle($organization)->for($year)->create([
                'label' => '1.º Período', 'sequence' => 1, 'starts_on' => '2025-09-01', 'ends_on' => '2025-12-31',
            ]);
            $subject = Subject::factory()->recycle($organization)->create(['name' => 'Matemática']);
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '7.º A restauro',
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            $student = Student::factory()->recycle($organization)->create();
            StudentIdentity::create(['student_id' => $student->id, 'organization_id' => $organization->id, 'display_name' => 'Aluno Restauro']);
            $enrollment = Enrollment::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'student_id' => $student->id, 'enrolled_on' => '2025-09-01',
            ]);

            $type = InstrumentType::where('code', 'TEST')->firstOrFail();

            $diagnostic = Instrument::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'academic_period_id' => $period->id, 'instrument_type_id' => $type->id,
                'title' => 'Diagnóstico inicial', 'applied_on' => '2025-09-10', 'status' => 'completed',
                'purpose' => 'diagnostic', 'counts_toward_classification' => false, 'total_points' => 10,
            ]);
            $diagnosticItem = InstrumentItem::factory()->recycle($organization)->create([
                'instrument_id' => $diagnostic->id, 'code' => 'Q1', 'sequence' => 1, 'points_possible' => 10,
            ]);
            StudentItemScore::create([
                'instrument_id' => $diagnostic->id, 'instrument_item_id' => $diagnosticItem->id,
                'enrollment_id' => $enrollment->id, 'result_state' => ResultState::Assessed,
                'points_earned' => 7, 'assessed_at' => '2025-09-11 10:00:00', 'assessed_by' => $this->teacher->id,
            ]);

            $regular = Instrument::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'academic_period_id' => $period->id, 'instrument_type_id' => $type->id,
                'title' => 'Ficha regular', 'applied_on' => '2025-10-15', 'status' => 'completed',
                'purpose' => 'formative', 'counts_toward_classification' => true, 'total_points' => 10,
            ]);
            $regularItem = InstrumentItem::factory()->recycle($organization)->create([
                'instrument_id' => $regular->id, 'code' => 'Q1', 'sequence' => 1, 'points_possible' => 10,
            ]);
            StudentItemScore::create([
                'instrument_id' => $regular->id, 'instrument_item_id' => $regularItem->id,
                'enrollment_id' => $enrollment->id, 'result_state' => ResultState::Assessed,
                'points_earned' => 8, 'assessed_at' => '2025-10-16 10:00:00', 'assessed_by' => $this->teacher->id,
            ]);

            return ['class' => $class->fresh(), 'diagnostic' => $diagnostic->ulid, 'regular' => $regular->ulid];
        });
    }

    private function backupUpload(): UploadedFile
    {
        $organization = $this->teacher->personalOrganization();
        $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->teacher->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function rewriteBackup(UploadedFile $file, callable $mutate): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'lapis-instrument-eligibility-backup-').'.zip';
        copy($file->getPathname(), $copy);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($copy));
        $json = $mutate(json_decode((string) $zip->getFromName('backup-lapis.json'), true));
        $zip->addFromString('backup-lapis.json', (string) json_encode($json));
        $zip->close();

        return new UploadedFile($copy, 'backup.zip', 'application/zip', null, true);
    }

    private function uploadInto(UploadedFile $file): DataImport
    {
        $organization = $this->teacher->personalOrganization();

        if (! app(Entitlements::class)->allowsFor($organization, 'data_backup_restore')) {
            $this->subscribeOrganizationTo($organization, 'pro');
        }

        $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])
            ->post('/data-imports', ['file' => $file])
            ->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('requested_by', $this->teacher->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function deletePedagogicalData(SchoolClass $class): void
    {
        $this->inTenant(function () use ($class): void {
            $enrollments = Enrollment::where('class_id', $class->id)->get();
            $studentIds = $enrollments->pluck('student_id');

            DB::table('student_item_scores')->whereIn('enrollment_id', $enrollments->pluck('id'))->delete();
            DB::table('enrollment_instrument_applicability')->whereIn('enrollment_id', $enrollments->pluck('id'))->delete();
            DB::table('item_domain_allocations')->delete();
            InstrumentItem::query()->delete();
            DB::table('instrument_groups')->delete();
            Instrument::where('class_id', $class->id)->forceDelete();

            $class->teachers()->detach();
            Enrollment::where('class_id', $class->id)->delete();
            StudentIdentity::whereIn('student_id', $studentIds)->delete();
            Student::whereIn('id', $studentIds)->delete();
            DB::table('classes')->where('id', $class->id)->delete();
        });
    }
}
