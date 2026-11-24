<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentStudentComment;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Import\Backup\BuildImportPlan;
use App\Support\Entitlements\Entitlements;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;
use ZipArchive;

/**
 * Schema v23 — the teacher's private comments on a student in an element of
 * assessment, through a REAL export and a REAL import (the same way as
 * ResultsAnalysisNotesPortabilityTest): GenerateDataExport,
 * ValidateBackupPayload, BuildInstrumentStudentCommentsPlan and
 * WriteInstrumentStudentComments exactly as production runs them.
 */
class InstrumentStudentCommentsPortabilityTest extends TestCase
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

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    /**
     * @return array{instrument: Instrument, enrollment: Enrollment, comment: InstrumentStudentComment}
     */
    private function scenario(?User $teacher = null, ?Organization $organization = null): array
    {
        $teacher ??= $this->teacher;
        $organization ??= $teacher->personalOrganization();

        return $this->inTenant($organization, function () use ($teacher, $organization): array {
            $year = AcademicYear::factory()->recycle($organization)->create([
                'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
            ]);
            $period = AcademicPeriod::factory()->recycle($organization)->for($year)->create([
                'label' => '1.º Período', 'sequence' => 1, 'starts_on' => '2025-09-01', 'ends_on' => '2025-12-31',
            ]);
            $subject = Subject::factory()->recycle($organization)->create(['name' => 'Matemática Fictícia']);
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '7.º Z fictício',
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            $student = Student::factory()->recycle($organization)->create();
            StudentIdentity::create(['student_id' => $student->id, 'organization_id' => $organization->id, 'display_name' => 'Aluno Fictício']);
            $enrollment = Enrollment::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'student_id' => $student->id, 'enrolled_on' => '2025-09-01',
            ]);

            $instrument = Instrument::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Ficha fictícia', 'applied_on' => '2025-10-15', 'status' => 'completed',
            ]);

            $comment = InstrumentStudentComment::create([
                'instrument_id' => $instrument->id, 'enrollment_id' => $enrollment->id,
                'body' => 'Apanhado a copiar na Q2 (fictício).', 'lock_version' => 3,
                'created_by' => $teacher->id, 'updated_by' => $teacher->id,
            ]);

            return ['instrument' => $instrument->fresh(), 'enrollment' => $enrollment->fresh(), 'comment' => $comment->fresh()];
        });
    }

    private function backupUpload(User $teacher, Organization $organization): UploadedFile
    {
        $this->actingAs($teacher)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $teacher->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function rewriteBackup(UploadedFile $file, callable $mutate): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'lapis-student-comments-backup-').'.zip';
        copy($file->getPathname(), $copy);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($copy));
        $json = $mutate(json_decode((string) $zip->getFromName('backup-lapis.json'), true));
        $zip->addFromString('backup-lapis.json', (string) json_encode($json));
        $zip->close();

        return new UploadedFile($copy, 'backup.zip', 'application/zip', null, true);
    }

    /** @return array<string, mixed> */
    private function backupJson(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file->getPathname()));
        $json = json_decode((string) $zip->getFromName('backup-lapis.json'), true);
        $zip->close();

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function uploadInto(UploadedFile $file, User $actor, Organization $organization): DataImport
    {
        if (! app(Entitlements::class)->allowsFor($organization, 'data_backup_restore')) {
            $this->subscribeOrganizationTo($organization, 'pro');
        }

        $this->actingAs($actor)->withSession(['organization_id' => $organization->id])
            ->post('/data-imports', ['file' => $file])
            ->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('requested_by', $actor->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return array{rows: array<string, array<int, array<string, mixed>>>, counts: array<string, array<string, int>>, can_confirm: bool}
     */
    private function planFor(DataImport $import, User $actor, Organization $organization): array
    {
        return $this->inTenant($organization, fn (): array => app(BuildImportPlan::class)
            ->build($import->canonical_snapshot ?? [], $organization, $actor, []));
    }

    private function confirm(DataImport $import, User $actor, Organization $organization): void
    {
        $this->actingAs($actor)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

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

    #[Test]
    public function the_backup_is_v23_and_carries_exactly_the_documented_fields(): void
    {
        ['instrument' => $instrument, 'enrollment' => $enrollment, 'comment' => $comment] = $this->scenario();
        $organization = $this->teacher->personalOrganization();

        $backup = $this->backupJson($this->backupUpload($this->teacher, $organization));

        $this->assertSame(23, BackupSchemaCompatibility::CURRENT);
        $this->assertSame(23, $backup['schema_version']);
        $this->assertContains('instrument_student_comments', $backup['capabilities']);

        $row = collect($backup['instrument_student_comments'])->firstWhere('ulid', $comment->ulid);
        $this->assertNotNull($row);
        $this->assertSame($instrument->ulid, $row['instrument_ulid']);
        $this->assertSame($enrollment->ulid, $row['enrollment_ulid']);
        $this->assertSame($comment->body, $row['body']);
        $this->assertSame($this->teacher->email, $row['created_by_email']);
        $this->assertSame(
            ['ulid', 'instrument_ulid', 'enrollment_ulid', 'body', 'created_at', 'updated_at', 'created_by_email', 'updated_by_email'],
            array_keys($row),
        );
    }

    #[Test]
    public function the_readable_spreadsheet_has_a_sheet_with_the_comments(): void
    {
        $this->scenario();
        $organization = $this->teacher->personalOrganization();

        $file = $this->backupUpload($this->teacher, $organization);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file->getPathname()));
        $xlsx = tempnam(sys_get_temp_dir(), 'lapis-xlsx-').'.xlsx';
        file_put_contents($xlsx, $zip->getFromName('Exportacao-Lapispro.xlsx'));
        $zip->close();

        $sheet = IOFactory::load($xlsx)->getSheetByName('Comentários sobre alunos');
        $this->assertNotNull($sheet);
        $this->assertSame('Aluno Fictício', $sheet->getCell('E2')->getValue());
        $this->assertSame('Apanhado a copiar na Q2 (fictício).', $sheet->getCell('F2')->getValue());
    }

    #[Test]
    public function a_comment_of_a_class_the_user_does_not_teach_is_not_exported(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $otherTeacher = User::factory()->create();
        $organization->members()->attach($otherTeacher, ['joined_at' => now()]);
        $this->scenario($otherTeacher, $organization);

        $backup = $this->backupJson($this->backupUpload($owner, $organization));

        $this->assertSame([], $backup['instrument_student_comments']);
    }

    #[Test]
    public function a_v22_backup_without_the_collection_imports_with_zero_comments_and_destroys_nothing(): void
    {
        ['instrument' => $instrument] = $this->scenario();
        $organization = $this->teacher->personalOrganization();

        $backup = $this->backupUpload($this->teacher, $organization);
        $mutated = $this->rewriteBackup($backup, function (array $json): array {
            $json['schema_version'] = 22;
            unset($json['instrument_student_comments']);

            return $json;
        });

        $import = $this->uploadInto($mutated, $this->teacher, $organization);
        $plan = $this->planFor($import, $this->teacher, $organization);
        $this->assertSame(0, $plan['counts']['instrument_student_comments']['new'] ?? 0);

        $this->confirm($import, $this->teacher, $organization);

        $this->inTenant($organization, function () use ($instrument): void {
            $this->assertSame(1, InstrumentStudentComment::where('instrument_id', $instrument->id)->count());
        });
    }

    #[Test]
    public function reimporting_into_the_same_account_matches_the_comment_as_existing(): void
    {
        $this->scenario();
        $organization = $this->teacher->personalOrganization();

        $import = $this->uploadInto($this->backupUpload($this->teacher, $organization), $this->teacher, $organization);
        $plan = $this->planFor($import, $this->teacher, $organization);

        $this->assertSame(0, $plan['counts']['instrument_student_comments']['new']);
        $this->assertSame(1, $plan['counts']['instrument_student_comments']['existing']);

        $this->confirm($import, $this->teacher, $organization);

        $this->inTenant($organization, fn () => $this->assertSame(1, InstrumentStudentComment::count()));
    }

    #[Test]
    public function a_divergent_text_is_a_conflict_and_never_overwrites_what_the_teacher_wrote(): void
    {
        $this->scenario();
        $organization = $this->teacher->personalOrganization();

        $backup = $this->backupUpload($this->teacher, $organization);
        $mutated = $this->rewriteBackup($backup, function (array $json): array {
            $json['instrument_student_comments'][0]['body'] = 'Texto diferente do backup.';

            return $json;
        });

        $import = $this->uploadInto($mutated, $this->teacher, $organization);
        $this->assertSame(1, $this->planFor($import, $this->teacher, $organization)['counts']['instrument_student_comments']['conflict']);

        $this->confirm($import, $this->teacher, $organization);

        $this->inTenant($organization, fn () => $this->assertSame('Apanhado a copiar na Q2 (fictício).', InstrumentStudentComment::firstOrFail()->body));
    }

    #[Test]
    public function restoring_into_a_fresh_account_clones_the_comment_with_a_fresh_ulid_and_lock_version(): void
    {
        ['comment' => $source] = $this->scenario();
        $backup = $this->backupUpload($this->teacher, $this->teacher->personalOrganization());

        $newAccount = User::factory()->create();
        $destination = $newAccount->personalOrganization();

        $import = $this->uploadInto($backup, $newAccount, $destination);
        $plan = $this->planFor($import, $newAccount, $destination);
        $this->assertSame(1, $plan['counts']['instrument_student_comments']['new']);
        $this->assertNotNull(collect($plan['rows']['instrument_student_comments'])->firstWhere('ulid', $source->ulid)['notice']);

        $this->confirm($import, $newAccount, $destination);

        $this->inTenant($destination, function () use ($source): void {
            $comment = InstrumentStudentComment::firstOrFail();
            $this->assertNotSame($source->ulid, $comment->ulid);
            $this->assertSame($source->body, $comment->body);
            $this->assertSame(1, $comment->lock_version);
            $this->assertNull($comment->created_by);
            $this->assertSame($source->created_at->toIso8601String(), $comment->created_at->toIso8601String());

            $this->assertSame('Aluno Fictício', $comment->enrollment->student->identity->display_name);
            $this->assertSame('Ficha fictícia', $comment->instrument->title);
        });

        $this->assertSame(1, $import->fresh()->summary['instrument_student_comments_created']);
    }

    #[Test]
    public function malformed_rows_are_dropped_with_fixed_reasons_that_never_repeat_the_text(): void
    {
        $this->scenario();
        $organization = $this->teacher->personalOrganization();

        $backup = $this->backupUpload($this->teacher, $organization);
        $mutated = $this->rewriteBackup($backup, function (array $json): array {
            $good = $json['instrument_student_comments'][0];

            $emptyBody = $good;
            $emptyBody['ulid'] = (string) Str::ulid();
            $emptyBody['body'] = '  ';

            $tooLong = $good;
            $tooLong['ulid'] = (string) Str::ulid();
            $tooLong['body'] = str_repeat('x', 2001);

            $noEnrollment = $good;
            $noEnrollment['ulid'] = (string) Str::ulid();
            unset($noEnrollment['enrollment_ulid']);

            $duplicate = $good;

            $json['instrument_student_comments'] = [$good, $emptyBody, $tooLong, $noEnrollment, $duplicate];

            return $json;
        });

        $import = $this->uploadInto($mutated, $this->teacher, $organization);

        $rows = $import->canonical_snapshot['instrument_student_comments'];
        $this->assertCount(1, $rows);

        $plan = $this->planFor($import, $this->teacher, $organization);
        $this->assertSame(1, $plan['counts']['instrument_student_comments']['existing']);
    }
}
