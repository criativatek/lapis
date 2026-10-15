<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\DataImportStatus;
use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\Organization;
use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupConfirmation;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Services\Import\Backup\BuildImportPlan;
use App\Support\Entitlements\Entitlements;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;
use ZipArchive;

/**
 * Schema v15 — the logbook follow-up in the backup: cases and «Registado no
 * Inovar» declarations, through a REAL export and a REAL upload / preview /
 * confirm, like the notebook's round trip. Fictitious data only.
 *
 * The rules under test: references mapped to the destination's records, never
 * by numeric id; no duplicates on reimport; the destination never overwritten;
 * deleted records never resurrected (and their absence never claimed as a
 * full restore); authorship never invented; old backups leave everything as
 * it was; a restore never raises an alert.
 */
class RecordFollowupPortabilityTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    private User $teacher;

    private Organization $organization;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private Enrollment $ana;

    private Enrollment $rui;

    private Enrollment $eva;

    /** @var list<EvidenceRecord> */
    private array $anaLateness = [];

    private RecordFollowupCase $anaCase;

    private RecordFollowupCase $ruiCase;

    private RecordFollowupConfirmation $undone;

    private RecordFollowupConfirmation $inForce;

    private RecordFollowupConfirmation $byColleague;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->teacher = User::factory()->create(['email' => 'professora.ficticia@example.test']);
        $this->organization = $this->teacher->personalOrganization();
        $this->colleague = User::factory()->create(['email' => 'colega.ficticio@example.test']);

        $this->inTenant($this->organization, function (): void {
            $year = AcademicYear::factory()->recycle($this->organization)->create(['label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
            $this->period = AcademicPeriod::create(['academic_year_id' => $year->id, 'label' => '1.º Período', 'kind' => 'term', 'sequence' => 1, 'starts_on' => '2025-09-15', 'ends_on' => '2025-12-16', 'status' => 'draft']);
            AcademicPeriod::create(['academic_year_id' => $year->id, 'label' => '2.º Período', 'kind' => 'term', 'sequence' => 2, 'starts_on' => '2026-01-05', 'ends_on' => '2026-03-27', 'status' => 'draft']);
            $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Matemática Fictícia']);
            $this->class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '7.º Z']);
            $this->class->teachers()->attach($this->teacher, ['role' => 'owner']);

            $this->ana = $this->enroll('Ana Fictícia Costa', 5);
            $this->rui = $this->enroll('Rui Fictício Sousa', 7);
            $this->eva = $this->enroll('Eva Fictícia Lima', 9);

            foreach (['2025-10-01', '2025-10-02', '2025-10-03', '2025-10-06'] as $day) {
                $this->anaLateness[] = $this->record($this->ana, 'lateness', $day);
            }
            $ruiMaterial = [];
            foreach (['2025-10-01', '2025-10-08', '2025-10-15'] as $day) {
                $ruiMaterial[] = $this->record($this->rui, 'missing_material', $day);
            }
            // Eva: three TPC not done and NO case — a case from before alerts.
            foreach (['2025-10-01', '2025-10-08', '2025-10-15'] as $day) {
                $this->record($this->eva, 'homework', $day, 'not_done');
            }

            $this->anaCase = $this->makeCase($this->ana, 'lateness', '2025-10-03 10:00:00', 3);
            $this->ruiCase = $this->makeCase($this->rui, 'missing_material', null, null);

            $this->undone = $this->makeConfirmation($this->anaCase, $this->teacher, '2025-10-04 09:00:00', array_slice($this->anaLateness, 0, 3), '2025-10-04 09:05:00', $this->teacher);
            $this->inForce = $this->makeConfirmation($this->anaCase, $this->teacher, '2025-10-07 09:00:00', $this->anaLateness);
            $this->byColleague = $this->makeConfirmation($this->ruiCase, $this->colleague, '2025-10-16 09:00:00', $ruiMaterial);
        });
    }

    // --- Fixtures ----------------------------------------------------------------

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    private function enroll(string $name, int $number, ?SchoolClass $class = null): Enrollment
    {
        $class ??= $this->class;
        $student = Student::factory()->recycle($class->organization)->create();
        StudentIdentity::create(['student_id' => $student->id, 'organization_id' => $class->organization_id, 'display_name' => $name]);

        return Enrollment::create(['class_id' => $class->id, 'student_id' => $student->id, 'class_number' => $number, 'enrolled_on' => '2025-09-01', 'status' => 'active', 'is_late_entry' => false]);
    }

    private function record(Enrollment $enrollment, string $kind, string $day, ?string $homeworkStatus = null): EvidenceRecord
    {
        return EvidenceRecord::create([
            'class_id' => $enrollment->class_id, 'enrollment_id' => $enrollment->id, 'occurred_at' => $day,
            'kind' => $kind, 'homework_status' => $homeworkStatus, 'description' => "Registo fictício de {$day}.", 'created_by' => $this->teacher->id,
        ]);
    }

    private function makeCase(Enrollment $enrollment, string $category, ?string $alertedAt, ?int $alertedCount): RecordFollowupCase
    {
        return RecordFollowupCase::create([
            'class_id' => $enrollment->class_id, 'enrollment_id' => $enrollment->id, 'academic_period_id' => $this->period->id,
            'category' => $category, 'alerted_at' => $alertedAt, 'alerted_count' => $alertedCount,
        ]);
    }

    /**
     * @param  list<EvidenceRecord>  $records
     */
    private function makeConfirmation(RecordFollowupCase $case, User $by, string $at, array $records, ?string $undoneAt = null, ?User $undoneBy = null): RecordFollowupConfirmation
    {
        return RecordFollowupConfirmation::create([
            'record_followup_case_id' => $case->id,
            'confirmed_by' => $by->id,
            'confirmed_at' => $at,
            'occurrence_count' => count($records),
            'occurrences' => array_map(fn (EvidenceRecord $record): array => ['ulid' => $record->ulid, 'date' => $record->occurred_at->format('Y-m-d')], $records),
            'undone_at' => $undoneAt,
            'undone_by' => $undoneBy?->id,
        ]);
    }

    // --- Export / import plumbing ---------------------------------------------------

    private function backupUpload(?User $user = null, ?Organization $organization = null): UploadedFile
    {
        $user ??= $this->teacher;
        $organization ??= $this->organization;

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $user->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function rewriteBackup(UploadedFile $file, callable $mutate): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'lapis-followup-backup-').'.zip';
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

        return $json;
    }

    private function uploadInto(UploadedFile $file, User $actor, Organization $organization): DataImport
    {
        if (! app(Entitlements::class)->allowsFor($organization, 'data_backup_restore')) {
            $this->subscribeOrganizationTo($organization, 'pro');
        }

        $this->actingAs($actor)->withSession(['organization_id' => $organization->id])->post('/data-imports', ['file' => $file])->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')->where('organization_id', $organization->id)->where('requested_by', $actor->id)->latest('id')->firstOrFail();
    }

    /**
     * @return array{rows: array<string, array<int, array<string, mixed>>>, counts: array<string, array<string, int>>, can_confirm: bool}
     */
    private function planFor(DataImport $import, User $actor, Organization $organization): array
    {
        return $this->inTenant($organization, fn (): array => app(BuildImportPlan::class)->build($import->canonical_snapshot ?? [], $organization, $actor, $import->canonical_snapshot['validation_issues'] ?? []));
    }

    private function confirm(DataImport $import, User $actor, Organization $organization): DataImport
    {
        $this->actingAs($actor)->withSession(['organization_id' => $organization->id])->post("/data-imports/{$import->ulid}/confirm")->assertRedirect()->assertSessionHasNoErrors();

        return $import->fresh();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function planRow(array $plan, string $domain, string $ulid): array
    {
        $row = collect($plan['rows'][$domain])->firstWhere('ulid', $ulid);
        $this->assertNotNull($row, "A linha {$ulid} devia constar do plano ({$domain}).");

        return $row;
    }

    private function forgetFollowups(Organization $organization): void
    {
        $this->inTenant($organization, function (): void {
            RecordFollowupConfirmation::query()->delete();
            RecordFollowupCase::query()->delete();
        });
    }

    private function exportedSpreadsheet(): Spreadsheet
    {
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->teacher->id)->latest('id')->firstOrFail();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->disk_path)) === true);
        $path = tempnam(sys_get_temp_dir(), 'lapis_followup_xlsx_');
        file_put_contents($path, (string) $zip->getFromName('Exportacao-Lapispro.xlsx'));

        try {
            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            @unlink($path);
        }
    }

    // --- Export ------------------------------------------------------------------------

    #[Test]
    public function the_backup_carries_cases_and_every_declaration_with_documented_fields_only(): void
    {
        // A colleague's class in the same organization never leaves in this export.
        $this->inTenant($this->organization, function (): void {
            $this->organization->members()->attach($this->colleague, ['joined_at' => now()]);
            $other = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->class->academic_year_id, 'subject_id' => $this->class->subject_id, 'label' => '9.º Y']);
            $other->teachers()->attach($this->colleague, ['role' => 'owner']);
            $stranger = $this->enroll('Aluno Alheio', 1, $other);
            RecordFollowupCase::create(['class_id' => $other->id, 'enrollment_id' => $stranger->id, 'academic_period_id' => $this->period->id, 'category' => 'lateness', 'alerted_at' => '2025-10-05 10:00:00', 'alerted_count' => 3]);
        });

        $json = $this->backupJson($this->backupUpload());

        $this->assertSame(16, BackupSchemaCompatibility::CURRENT);
        $this->assertSame(16, $json['schema_version']);
        $this->assertContains('record_followup_cases', $json['capabilities']);
        $this->assertContains('record_followup_confirmations', $json['capabilities']);

        $cases = collect($json['record_followup_cases']);
        $this->assertCount(2, $cases);
        $this->assertSame(['ulid', 'class_ulid', 'enrollment_ulid', 'academic_period_ulid', 'category', 'alerted_at', 'alerted_count', 'created_at', 'updated_at'], array_keys($cases->first()));

        $ana = $cases->firstWhere('ulid', $this->anaCase->ulid);
        $this->assertSame($this->class->ulid, $ana['class_ulid']);
        $this->assertSame($this->ana->ulid, $ana['enrollment_ulid']);
        $this->assertSame($this->period->ulid, $ana['academic_period_ulid']);
        $this->assertSame('lateness', $ana['category']);
        $this->assertSame(3, $ana['alerted_count']);
        $this->assertNull($cases->firstWhere('ulid', $this->ruiCase->ulid)['alerted_at']);

        $confirmations = collect($json['record_followup_confirmations']);
        $this->assertCount(3, $confirmations);
        $this->assertSame(['ulid', 'case_ulid', 'confirmed_by_email', 'confirmed_at', 'occurrence_count', 'occurrences', 'undone_by_email', 'undone_at', 'created_at', 'updated_at'], array_keys($confirmations->first()));

        $undone = $confirmations->firstWhere('ulid', $this->undone->ulid);
        $this->assertSame('professora.ficticia@example.test', $undone['confirmed_by_email']);
        $this->assertSame('professora.ficticia@example.test', $undone['undone_by_email']);
        $this->assertNotNull($undone['undone_at']);
        $this->assertSame(3, $undone['occurrence_count']);
        $this->assertSame(['ulid' => $this->anaLateness[0]->ulid, 'date' => '2025-10-01'], $undone['occurrences'][0]);
        $this->assertSame('colega.ficticio@example.test', $confirmations->firstWhere('ulid', $this->byColleague->ulid)['confirmed_by_email']);

        $encoded = (string) json_encode($json['record_followup_cases']).json_encode($json['record_followup_confirmations']);
        $this->assertStringNotContainsString('"id"', $encoded);
        $this->assertStringNotContainsString('organization_id', $encoded);
        $this->assertStringNotContainsString('Aluno Alheio', $encoded);
        $this->assertStringNotContainsString('Ana Fictícia', $encoded);
    }

    #[Test]
    public function the_readable_workbook_shows_the_follow_up_state_dates_and_declarations(): void
    {
        $spreadsheet = $this->exportedSpreadsheet();
        $sheet = $spreadsheet->getSheetByName('Acompanhamento');
        $this->assertNotNull($sheet);

        $rows = $sheet->toArray(null, true, false, false);
        $header = array_shift($rows);
        $this->assertSame(['Ano letivo', 'Turma', 'Disciplina', 'N.º', 'Aluno', 'Tipo', 'Período', 'Ocorrências', 'Datas das ocorrências', 'Estado', 'Alerta em', 'Registado no Inovar em', 'Marcado por', 'Ocorrências declaradas', 'Marcações desfeitas'], $header);

        $byStudent = collect($rows)->keyBy(fn (array $row): string => (string) $row[4]);

        $ana = $byStudent->get('Ana Fictícia Costa');
        $this->assertSame('Atrasos', $ana[5]);
        $this->assertSame(4, (int) $ana[7]);
        $this->assertSame('01/10/2025, 02/10/2025, 03/10/2025, 06/10/2025', $ana[8]);
        $this->assertSame('Registado no Inovar', $ana[9]);
        $this->assertNotNull($ana[10]);
        $this->assertNotNull($ana[11]);
        $this->assertSame($this->teacher->name, $ana[12]);
        $this->assertSame('01/10/2025, 02/10/2025, 03/10/2025, 06/10/2025', $ana[13]);
        $this->assertStringContainsString('Marcado em 04/10/2025 09:00 e desfeito em 04/10/2025 09:05', (string) $ana[14]);

        // A case from before alerts: no row on record, three occurrences, por tratar.
        $eva = $byStudent->get('Eva Fictícia Lima');
        $this->assertSame('TPC não realizados', $eva[5]);
        $this->assertSame('Por tratar', $eva[9]);
        $this->assertNull($eva[10]);

        $summary = collect($spreadsheet->getSheetByName('Resumo')?->toArray() ?? [])->mapWithKeys(fn (array $row): array => [(string) $row[0] => $row[1]]);
        $this->assertSame(3, (int) $summary['Nº de casos de acompanhamento (TPC, atrasos, material)']);
    }

    // --- Round trips -------------------------------------------------------------------

    #[Test]
    public function the_same_account_restores_cases_and_declarations_with_their_authors_and_records(): void
    {
        $file = $this->backupUpload();
        $this->forgetFollowups($this->organization);
        Notification::fake();
        Mail::fake();

        $import = $this->uploadInto($file, $this->teacher, $this->organization);
        $plan = $this->planFor($import, $this->teacher, $this->organization);
        $this->assertSame('new', $this->planRow($plan, 'record_followup_cases', $this->anaCase->ulid)['classification']);
        $this->assertSame('new', $this->planRow($plan, 'record_followup_confirmations', $this->inForce->ulid)['classification']);
        // The colleague's declaration is restored without an author, with a notice.
        $colleagueRow = $this->planRow($plan, 'record_followup_confirmations', $this->byColleague->ulid);
        $this->assertSame('new', $colleagueRow['classification']);
        $this->assertTrue($colleagueRow['author_unresolved']);
        $this->assertNotNull($colleagueRow['notice']);

        $import = $this->confirm($import, $this->teacher, $this->organization);
        $this->assertSame(DataImportStatus::Imported, $import->status);
        $this->assertSame(2, $import->summary['record_followup_cases_created']);
        $this->assertSame(3, $import->summary['record_followup_confirmations_created']);
        $this->assertSame(0, $import->summary['record_followup_confirmations_incomplete']);
        // Only the colleague's declaration is restored without its author.
        $this->assertSame(1, $import->summary['records_without_original_author']);
        // Restoring two cases and three declarations tells nobody anything.
        Notification::assertNothingSent();
        Mail::assertNothingSent();

        $this->inTenant($this->organization, function (): void {
            $case = RecordFollowupCase::query()->where('ulid', $this->anaCase->ulid)->sole();
            $this->assertSame($this->ana->id, $case->enrollment_id);
            $this->assertSame('2025-10-03 10:00:00', $case->alerted_at?->format('Y-m-d H:i:s'));
            $this->assertSame(3, $case->alerted_count);

            $inForce = RecordFollowupConfirmation::query()->where('ulid', $this->inForce->ulid)->sole();
            $this->assertSame($this->teacher->id, $inForce->confirmed_by);
            $this->assertNull($inForce->undone_at);
            $this->assertNotNull($inForce->restored_at);
            $this->assertSame(array_map(fn (EvidenceRecord $record): string => $record->ulid, $this->anaLateness), array_column($inForce->occurrences, 'ulid'));

            $undone = RecordFollowupConfirmation::query()->where('ulid', $this->undone->ulid)->sole();
            $this->assertSame('2025-10-04 09:05:00', $undone->undone_at?->format('Y-m-d H:i:s'));
            $this->assertSame($this->teacher->id, $undone->undone_by);

            $this->assertNull(RecordFollowupConfirmation::query()->where('ulid', $this->byColleague->ulid)->sole()->confirmed_by);
        });

        // The restored state is the source state, alert date included.
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->get('/records?'.http_build_query(['year' => $this->class->academicYear->ulid, 'period' => $this->period->ulid, 'view' => 'lateness', 'case_class' => $this->class->ulid, 'case_student' => $this->ana->ulid, 'case_category' => 'lateness']))
            ->assertInertia(fn ($page) => $page
                ->where('openCase.status', 'registered')
                ->where('openCase.confirmation.missing', [])
                ->has('openCase.history', 2));
    }

    #[Test]
    public function reimporting_the_same_backup_changes_nothing_and_duplicates_nothing(): void
    {
        $file = $this->backupUpload();

        $import = $this->uploadInto($file, $this->teacher, $this->organization);
        $plan = $this->planFor($import, $this->teacher, $this->organization);
        $this->assertSame(['new' => 0, 'existing' => 2, 'conflict' => 0, 'invalid' => 0, 'unsupported' => 0], $plan['counts']['record_followup_cases']);
        $this->assertSame(['new' => 0, 'existing' => 3, 'conflict' => 0, 'invalid' => 0, 'unsupported' => 0], $plan['counts']['record_followup_confirmations']);

        $this->forgetFollowups($this->organization);
        $first = $this->confirm($this->uploadInto($file, $this->teacher, $this->organization), $this->teacher, $this->organization);
        $this->assertSame(3, $first->summary['record_followup_confirmations_created']);

        $again = $this->uploadInto($file, $this->teacher, $this->organization);
        $againPlan = $this->planFor($again, $this->teacher, $this->organization);
        $this->assertSame(0, $againPlan['counts']['record_followup_cases']['new']);
        $this->assertSame(0, $againPlan['counts']['record_followup_confirmations']['new']);

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(2, RecordFollowupCase::query()->count());
            $this->assertSame(3, RecordFollowupConfirmation::query()->count());
        });
    }

    #[Test]
    public function tpc_saved_from_the_grid_without_a_description_travels_and_its_declaration_restores_whole(): void
    {
        // The homework grid saves every TPC with an empty description — the
        // form allows it — and a restore used to refuse those records.
        $this->inTenant($this->organization, function (): void {
            $evaTpc = EvidenceRecord::query()->where('enrollment_id', $this->eva->id)->orderBy('occurred_at')->get();
            $evaTpc->each(fn (EvidenceRecord $record) => $record->forceFill(['description' => ''])->save());
            $case = $this->makeCase($this->eva, 'homework_not_done', null, null);
            $this->makeConfirmation($case, $this->teacher, '2025-10-16 09:00:00', $evaTpc->all());
            // A lateness record keeps needing a description, as in the form.
            $this->anaLateness[3]->forceFill(['description' => ''])->save();
        });
        $file = $this->backupUpload();
        $other = User::factory()->create();
        $destination = $other->personalOrganization();

        $import = $this->uploadInto($file, $other, $destination);
        $plan = $this->planFor($import, $other, $destination);
        $this->assertSame('invalid', $this->planRow($plan, 'evidence_records', $this->anaLateness[3]->ulid)['classification']);

        $import = $this->confirm($import, $other, $destination);
        $this->assertSame(DataImportStatus::Imported, $import->status);

        $this->inTenant($destination, function (): void {
            $this->assertSame(3, EvidenceRecord::query()->where('kind', 'homework')->where('description', '')->count());
            $tpc = RecordFollowupConfirmation::query()->whereHas('followupCase', fn ($query) => $query->where('category', 'homework_not_done'))->sole();
            $this->assertNotContains(null, array_column($tpc->occurrences, 'ulid'));
        });
        // Only Ana's in-force declaration lacks one occurrence: the lateness without a description.
        $this->assertSame(1, $import->summary['record_followup_confirmations_incomplete']);
    }

    #[Test]
    public function a_clone_into_another_account_maps_declarations_to_the_new_records_without_authors(): void
    {
        $file = $this->backupUpload();
        $other = User::factory()->create(['email' => 'outra.conta@example.test']);
        $destination = $other->personalOrganization();

        $import = $this->confirm($this->uploadInto($file, $other, $destination), $other, $destination);
        $this->assertSame(DataImportStatus::Imported, $import->status);
        $this->assertSame(2, $import->summary['record_followup_cases_created']);
        $this->assertSame(3, $import->summary['record_followup_confirmations_created']);
        $this->assertSame(0, $import->summary['record_followup_confirmations_incomplete']);
        // The ten logbook records and the three declarations: none attributed to the importer.
        $this->assertSame(13, $import->summary['records_without_original_author']);

        $this->inTenant($destination, function () use ($destination): void {
            $inForce = RecordFollowupConfirmation::query()->whereNull('undone_at')->whereHas('followupCase', fn ($query) => $query->where('category', 'lateness'))->sole();
            $this->assertNull($inForce->confirmed_by);
            $this->assertCount(4, $inForce->occurrences);

            // Each covered occurrence points at a record of THIS organization.
            $records = EvidenceRecord::query()->whereIn('ulid', array_column($inForce->occurrences, 'ulid'))->get();
            $this->assertCount(4, $records);
            $this->assertTrue($records->every(fn (EvidenceRecord $record): bool => $record->organization_id === $destination->id && $record->kind->value === 'lateness'));
        });

        // The source organization was never touched.
        $this->inTenant($this->organization, fn () => $this->assertSame(3, RecordFollowupConfirmation::query()->count()));
    }

    // --- Conflicts -----------------------------------------------------------------------

    #[Test]
    public function a_destination_changed_after_the_export_is_kept_and_reported(): void
    {
        $file = $this->backupUpload();

        $this->inTenant($this->organization, function (): void {
            // The alert moved, the declaration in force was undone.
            $this->anaCase->forceFill(['alerted_at' => '2025-10-03 11:30:00'])->save();
            $this->inForce->forceFill(['undone_at' => '2025-10-20 10:00:00', 'undone_by' => $this->teacher->id])->save();
        });

        $import = $this->uploadInto($file, $this->teacher, $this->organization);
        $plan = $this->planFor($import, $this->teacher, $this->organization);

        $case = $this->planRow($plan, 'record_followup_cases', $this->anaCase->ulid);
        $this->assertSame('conflict', $case['classification']);
        $this->assertStringContainsString('outra informação de alerta', $case['reason']);
        $this->assertStringContainsString('Atrasos · 7.º Z · 1.º Período', $case['label']);
        $this->assertStringNotContainsString('Ana', $case['label']);

        $confirmation = $this->planRow($plan, 'record_followup_confirmations', $this->inForce->ulid);
        $this->assertSame('conflict', $confirmation['classification']);
        $this->assertStringContainsString('outro estado', $confirmation['reason']);

        $this->confirm($import, $this->teacher, $this->organization);

        $this->inTenant($this->organization, function (): void {
            $this->assertSame('2025-10-03 11:30:00', $this->anaCase->fresh()?->alerted_at?->format('Y-m-d H:i:s'));
            $this->assertNotNull($this->inForce->fresh()?->undone_at);
            $this->assertSame(3, RecordFollowupConfirmation::query()->count());
        });
    }

    #[Test]
    public function a_second_declaration_in_force_is_never_created_beside_the_destinations(): void
    {
        $file = $this->backupUpload();

        $this->inTenant($this->organization, function (): void {
            $this->inForce->delete();
            $this->makeConfirmation($this->anaCase, $this->teacher, '2025-11-02 09:00:00', $this->anaLateness);
        });

        $import = $this->uploadInto($file, $this->teacher, $this->organization);
        $row = $this->planRow($this->planFor($import, $this->teacher, $this->organization), 'record_followup_confirmations', $this->inForce->ulid);
        $this->assertSame('conflict', $row['classification']);
        $this->assertStringContainsString('em vigor para este caso', $row['reason']);

        $this->confirm($import, $this->teacher, $this->organization);
        $this->inTenant($this->organization, fn () => $this->assertSame(1, RecordFollowupConfirmation::query()->whereNull('undone_at')->where('record_followup_case_id', $this->anaCase->id)->count()));
    }

    // --- Deleted records and missing references ---------------------------------------------

    #[Test]
    public function a_record_deleted_in_the_destination_is_never_resurrected_and_the_declaration_restores_as_incomplete(): void
    {
        $file = $this->backupUpload();

        $this->inTenant($this->organization, fn () => $this->anaLateness[3]->delete());
        $this->forgetFollowups($this->organization);

        $import = $this->uploadInto($file, $this->teacher, $this->organization);
        $plan = $this->planFor($import, $this->teacher, $this->organization);

        // Issue #61: the deleted record is a conflict, not a failed restore.
        $record = $this->planRow($plan, 'evidence_records', $this->anaLateness[3]->ulid);
        $this->assertSame('conflict', $record['classification']);
        $this->assertStringContainsString('eliminado no destino', $record['reason']);

        $confirmation = $this->planRow($plan, 'record_followup_confirmations', $this->inForce->ulid);
        $this->assertSame('new', $confirmation['classification']);
        $this->assertSame(1, $confirmation['missing_occurrences']);
        $this->assertNotNull($confirmation['warning']);

        $import = $this->confirm($import, $this->teacher, $this->organization);
        $this->assertSame(DataImportStatus::Imported, $import->status);
        $this->assertSame(1, $import->summary['record_followup_confirmations_incomplete']);

        $this->inTenant($this->organization, function (): void {
            $this->assertTrue(EvidenceRecord::withTrashed()->where('ulid', $this->anaLateness[3]->ulid)->sole()->trashed());
            $restored = RecordFollowupConfirmation::query()->where('ulid', $this->inForce->ulid)->sole();
            $this->assertSame(4, $restored->occurrence_count);
            // assertEquals, not assertSame: MySQL stores JSON with its keys reordered.
            $this->assertEquals(['ulid' => null, 'date' => '2025-10-06'], $restored->occurrences[3]);
        });

        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->get('/records?'.http_build_query(['year' => $this->class->academicYear->ulid, 'period' => $this->period->ulid, 'view' => 'lateness', 'case_class' => $this->class->ulid, 'case_student' => $this->ana->ulid, 'case_category' => 'lateness']))
            ->assertInertia(fn ($page) => $page
                ->where('openCase.confirmation.missing', ['2025-10-06'])
                ->where('openCase.confirmation.restored', true));
    }

    #[Test]
    public function a_record_moved_to_another_student_after_the_export_is_not_counted_as_recovered(): void
    {
        $file = $this->backupUpload();

        $this->inTenant($this->organization, fn () => $this->anaLateness[0]->forceFill(['enrollment_id' => $this->rui->id])->save());
        $this->forgetFollowups($this->organization);

        $import = $this->uploadInto($file, $this->teacher, $this->organization);
        $confirmation = $this->planRow($this->planFor($import, $this->teacher, $this->organization), 'record_followup_confirmations', $this->inForce->ulid);
        $this->assertSame('new', $confirmation['classification']);
        $this->assertSame(1, $confirmation['missing_occurrences']);

        $this->confirm($import, $this->teacher, $this->organization);

        $this->inTenant($this->organization, function (): void {
            $restored = RecordFollowupConfirmation::query()->where('ulid', $this->inForce->ulid)->sole();
            // Rui's record is never presented as one of Ana's declared occurrences.
            $this->assertEquals(['ulid' => null, 'date' => '2025-10-01'], $restored->occurrences[0]);
            $this->assertSame($this->anaLateness[1]->ulid, $restored->occurrences[1]['ulid']);
        });
    }

    #[Test]
    public function missing_references_and_malformed_rows_are_shown_in_the_preview_and_never_written(): void
    {
        $file = $this->rewriteBackup($this->backupUpload(), function (array $json): array {
            foreach ($json['record_followup_cases'] as $index => $case) {
                if ($case['ulid'] === $this->anaCase->ulid) {
                    $json['record_followup_cases'][$index]['enrollment_ulid'] = '01JABCDEFGHJKMNPQRSTVWXYZ0';
                }
            }

            foreach ($json['record_followup_confirmations'] as $index => $confirmation) {
                if ($confirmation['ulid'] === $this->byColleague->ulid) {
                    $json['record_followup_confirmations'][$index]['occurrence_count'] = 99;
                }
            }

            return $json;
        });
        $this->forgetFollowups($this->organization);

        $import = $this->uploadInto($file, $this->teacher, $this->organization);
        $plan = $this->planFor($import, $this->teacher, $this->organization);

        $case = $this->planRow($plan, 'record_followup_cases', $this->anaCase->ulid);
        $this->assertSame('invalid', $case['classification']);
        $this->assertStringContainsString('não podem ser restaurados', $case['reason']);
        $this->assertSame('invalid', $this->planRow($plan, 'record_followup_confirmations', $this->inForce->ulid)['classification']);
        $this->assertSame('invalid', $this->planRow($plan, 'record_followup_confirmations', $this->byColleague->ulid)['classification']);

        $this->confirm($import, $this->teacher, $this->organization);
        $this->inTenant($this->organization, function (): void {
            $this->assertSame(1, RecordFollowupCase::query()->count());
            $this->assertSame(0, RecordFollowupConfirmation::query()->count());
        });
    }

    #[Test]
    public function the_preview_lists_the_follow_up_issues_with_enough_to_decide(): void
    {
        $file = $this->backupUpload();
        $this->inTenant($this->organization, fn () => $this->anaCase->forceFill(['alerted_at' => '2025-10-03 11:30:00'])->save());
        $import = $this->uploadInto($file, $this->teacher, $this->organization);

        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->get("/data-imports/{$import->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('plan.counts.record_followup_cases.conflict', 1)
                ->where('plan.counts.record_followup_confirmations.existing', 3)
                ->etc());
    }

    // --- Old backups, authorship, alerts ------------------------------------------------------

    #[Test]
    public function an_old_backup_without_follow_up_leaves_the_destination_exactly_as_it_was(): void
    {
        $file = $this->rewriteBackup($this->backupUpload(), function (array $json): array {
            unset($json['record_followup_cases'], $json['record_followup_confirmations']);
            $json['schema_version'] = 14;
            $json['capabilities'] = array_values(array_diff($json['capabilities'], ['record_followup_cases', 'record_followup_confirmations']));

            return $json;
        });

        $import = $this->uploadInto($file, $this->teacher, $this->organization);
        $plan = $this->planFor($import, $this->teacher, $this->organization);
        $this->assertSame(0, array_sum($plan['counts']['record_followup_cases']));
        $this->assertSame(0, array_sum($plan['counts']['record_followup_confirmations']));

        $this->confirm($import, $this->teacher, $this->organization);

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(2, RecordFollowupCase::query()->count());
            $this->assertSame(3, RecordFollowupConfirmation::query()->count());
            $this->assertSame('2025-10-03 10:00:00', $this->anaCase->fresh()?->alerted_at?->format('Y-m-d H:i:s'));
        });
    }

    #[Test]
    public function a_restore_raises_no_alert_even_where_records_reach_three(): void
    {
        $file = $this->rewriteBackup($this->backupUpload(), function (array $json): array {
            $json['record_followup_cases'] = [];
            $json['record_followup_confirmations'] = [];

            return $json;
        });
        $other = User::factory()->create();
        $destination = $other->personalOrganization();
        Notification::fake();
        Mail::fake();

        $this->confirm($this->uploadInto($file, $other, $destination), $other, $destination);
        Notification::assertNothingSent();
        Mail::assertNothingSent();

        // Ana (4 lateness), Rui (3 missing material), Eva (3 TPC): three cases at
        // three or more — and not one alert or case row created by the restore.
        $this->inTenant($destination, function (): void {
            $this->assertSame(10, EvidenceRecord::query()->count());
            $this->assertSame(0, RecordFollowupCase::query()->count());
        });
    }

    #[Test]
    public function a_record_restored_into_a_class_that_already_exists_keeps_its_student(): void
    {
        $file = $this->backupUpload();
        $this->inTenant($this->organization, fn () => $this->anaLateness[0]->forceDelete());

        $this->confirm($this->uploadInto($file, $this->teacher, $this->organization), $this->teacher, $this->organization);

        $this->inTenant($this->organization, function (): void {
            $restored = EvidenceRecord::query()->where('ulid', $this->anaLateness[0]->ulid)->sole();
            $this->assertSame($this->ana->id, $restored->enrollment_id);
        });
    }

    #[Test]
    public function only_the_teacher_who_asked_sees_and_confirms_the_restore_and_a_support_session_cannot(): void
    {
        $file = $this->backupUpload();
        $import = $this->uploadInto($file, $this->teacher, $this->organization);

        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => 999])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertForbidden();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->withSession(['organization_id' => $stranger->personalOrganization()->id])
            ->get("/data-imports/{$import->ulid}")
            ->assertNotFound();

        $this->assertSame(DataImportStatus::Validated, $import->fresh()?->status);
    }
}
