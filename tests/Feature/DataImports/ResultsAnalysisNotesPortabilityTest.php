<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\ResultsAnalysisNote;
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
 * Janela AH — schema v13, the results-analysis observations round-trip.
 * Built the same way as InstrumentEligibilityRestoreTest/PedagogicalRoundTripTest:
 * a REAL export, the zip's backup-lapis.json mutated in place when a scenario
 * needs a specific/legacy shape, then a REAL import confirm — exercising
 * GenerateDataExport, ValidateBackupPayload, BuildResultsAnalysisNotesPlan
 * and WriteResultsAnalysisNotes exactly as production does.
 */
class ResultsAnalysisNotesPortabilityTest extends TestCase
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
     * @return array{class: SchoolClass, instrument: Instrument, note: ResultsAnalysisNote}
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
            Enrollment::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'student_id' => $student->id, 'enrolled_on' => '2025-09-01',
            ]);

            $type = InstrumentType::where('code', 'TEST')->firstOrFail();
            $instrument = Instrument::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'academic_period_id' => $period->id, 'instrument_type_id' => $type->id,
                'title' => 'Ficha fictícia', 'applied_on' => '2025-10-15', 'status' => 'completed',
            ]);

            $note = ResultsAnalysisNote::create([
                'context_kind' => 'instrument',
                'instrument_id' => $instrument->id,
                'body' => 'Observação fictícia de resultados: a turma melhorou.',
                'lock_version' => 1,
                'created_by' => $teacher->id,
                'updated_by' => $teacher->id,
            ]);

            return ['class' => $class->fresh(), 'instrument' => $instrument->fresh(), 'note' => $note->fresh()];
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
        $copy = tempnam(sys_get_temp_dir(), 'lapis-results-notes-backup-').'.zip';
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

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function notesOf(array $json): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $json['results_analysis_notes'] ?? [];

        return $rows;
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
     * The preview page (`DataImportController::edit()`) builds the plan on
     * the fly from `canonical_snapshot` rather than storing it on the model
     * — rebuilt here the same way, inside the destination's own tenant
     * context, instead of asserting on a rendered Inertia response.
     *
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

    #[Test]
    public function the_backup_json_carries_exactly_the_documented_fields(): void
    {
        ['instrument' => $instrument, 'note' => $note] = $this->scenario();
        $organization = $this->teacher->personalOrganization();

        $backup = $this->backupJson($this->backupUpload($this->teacher, $organization));

        // Schema v14 added the class notebook on top; the v13 collection and
        // its documented shape are exactly what they were.
        $this->assertSame(14, BackupSchemaCompatibility::CURRENT);
        $this->assertSame(14, $backup['schema_version']);
        $this->assertContains('results_analysis_notes', $backup['capabilities']);
        $this->assertNotEmpty($backup['results_analysis_notes']);

        $row = collect($this->notesOf($backup))->firstWhere('ulid', $note->ulid);
        $this->assertNotNull($row);
        $this->assertSame('instrument', $row['context_kind']);
        $this->assertSame($instrument->ulid, $row['instrument_ulid']);
        $this->assertSame($note->body, $row['body']);
        $this->assertSame($this->teacher->email, $row['created_by_email']);
        $this->assertSame($this->teacher->email, $row['updated_by_email']);
        $this->assertArrayNotHasKey('id', $row);
        $this->assertArrayNotHasKey('organization_id', $row);
        $this->assertArrayNotHasKey('instrument_id', $row);
        $this->assertArrayNotHasKey('lock_version', $row);
        $this->assertSame(['ulid', 'context_kind', 'instrument_ulid', 'body', 'created_at', 'updated_at', 'created_by_email', 'updated_by_email'], array_keys($row));
    }

    #[Test]
    public function long_accented_text_survives_the_round_trip_byte_for_byte(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['instrument' => $instrument] = $this->scenario();

        $longBody = str_repeat('Observação com acentuação, «aspas», ç, emoji 📘 e quebras de linha.'."\n", 260);
        $longBody = mb_substr($longBody, 0, 19000);

        $this->inTenant($organization, function () use ($instrument, $longBody): void {
            ResultsAnalysisNote::where('instrument_id', $instrument->id)->update(['body' => $longBody]);
        });

        $backup = $this->backupJson($this->backupUpload($this->teacher, $organization));
        $row = collect($this->notesOf($backup))->firstWhere('instrument_ulid', $instrument->ulid);

        $this->assertSame($longBody, $row['body']);
    }

    #[Test]
    public function a_note_of_an_instrument_the_user_does_not_teach_is_not_exported(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $otherTeacher = User::factory()->create();
        $organization->members()->attach($otherTeacher, ['joined_at' => now()]);

        ['instrument' => $instrument] = $this->scenario($otherTeacher, $organization);

        $backup = $this->backupJson($this->backupUpload($owner, $organization));

        $this->assertArrayHasKey('results_analysis_notes', $backup);
        $this->assertEmpty(collect($this->notesOf($backup))->where('instrument_ulid', $instrument->ulid));
    }

    #[Test]
    public function a_note_of_another_organization_never_appears(): void
    {
        $otherTeacher = User::factory()->create();
        ['note' => $otherNote] = $this->scenario($otherTeacher, $otherTeacher->personalOrganization());

        $organization = $this->teacher->personalOrganization();
        $this->scenario($this->teacher, $organization);

        $backup = $this->backupJson($this->backupUpload($this->teacher, $organization));

        $this->assertEmpty(collect($this->notesOf($backup))->where('ulid', $otherNote->ulid));
    }

    #[Test]
    public function the_xlsx_carries_the_sheet_and_the_summary_count_and_empty_notes_are_never_exported(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['instrument' => $instrument, 'note' => $note] = $this->scenario();

        // A second, empty note for a second instrument — never exported.
        $this->inTenant($organization, function () use ($organization, $instrument): void {
            $type = InstrumentType::where('code', 'TEST')->firstOrFail();
            $emptyInstrument = Instrument::factory()->recycle($organization)->create([
                'class_id' => $instrument->class_id, 'academic_period_id' => $instrument->academic_period_id,
                'instrument_type_id' => $type->id, 'title' => 'Ficha sem observações', 'applied_on' => '2025-11-01', 'status' => 'completed',
            ]);
            ResultsAnalysisNote::create([
                'context_kind' => 'instrument', 'instrument_id' => $emptyInstrument->id, 'body' => '   ',
                'lock_version' => 1, 'created_by' => $this->teacher->id, 'updated_by' => $this->teacher->id,
            ]);
        });

        $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->teacher->id)->latest('id')->firstOrFail();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->disk_path)) === true);

        $bytes = $zip->getFromName('Exportacao-Lapispro.xlsx');
        $this->assertNotFalse($bytes);
        $tempPath = tempnam(sys_get_temp_dir(), 'lapis_results_notes_test_');
        file_put_contents($tempPath, $bytes);

        try {
            $spreadsheet = IOFactory::createReader('Xlsx')->load($tempPath);
        } finally {
            @unlink($tempPath);
        }

        $sheet = $spreadsheet->getSheetByName('Observações dos Resultados');
        $this->assertNotNull($sheet);

        $bodies = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $bodies[] = (string) $sheet->getCell("E{$row}")->getValue();
        }
        $this->assertContains($note->body, $bodies);
        $this->assertCount(1, $bodies, 'A nota vazia nunca deve constar na folha.');

        $resumo = $spreadsheet->getSheetByName('Resumo');
        $labels = [];
        $counts = [];
        for ($row = 2; $row <= $resumo->getHighestDataRow(); $row++) {
            $labels[] = (string) $resumo->getCell("A{$row}")->getValue();
            $counts[] = $resumo->getCell("B{$row}")->getValue();
        }
        $index = array_search('Nº de observações de resultados', $labels, true);
        $this->assertNotFalse($index);
        $this->assertSame(1, (int) $counts[$index]);
    }

    #[Test]
    public function malformed_rows_become_issues_while_the_rest_of_the_backup_still_imports(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        ['instrument' => $instrument] = $this->scenario($owner, $organization);

        $backup = $this->backupUpload($owner, $organization);

        $mutated = $this->rewriteBackup($backup, function (array $json) use ($instrument): array {
            $good = collect($this->notesOf($json))->firstWhere('instrument_ulid', $instrument->ulid);

            $badUlid = $good;
            $badUlid['ulid'] = 'not-a-ulid';
            $badUlid['instrument_ulid'] = $instrument->ulid;

            $badContextKind = $good;
            $badContextKind['ulid'] = (string) Str::ulid();
            $badContextKind['context_kind'] = 'class';

            $emptyBody = $good;
            $emptyBody['ulid'] = (string) Str::ulid();
            $emptyBody['body'] = '';

            $tooLong = $good;
            $tooLong['ulid'] = (string) Str::ulid();
            $tooLong['body'] = str_repeat('x', 20001);

            $malformedDate = $good;
            $malformedDate['ulid'] = (string) Str::ulid();
            $malformedDate['created_at'] = 12345;

            $missingInstrument = $good;
            $missingInstrument['ulid'] = (string) Str::ulid();
            unset($missingInstrument['instrument_ulid']);

            $json['results_analysis_notes'] = [
                $good, $badUlid, $badContextKind, $emptyBody, $tooLong, $malformedDate, $missingInstrument,
            ];

            return $json;
        });

        $import = $this->uploadInto($mutated, $owner, $organization);

        $plan = $this->planFor($import, $owner, $organization);
        $counts = $plan['counts']['results_analysis_notes'];
        // ValidateBackupPayload drops a malformed row at whitelist time —
        // the same as every other domain in this pipeline (whitelistRows())
        // — so only the one well-formed row ever reaches the plan. It is
        // the note that already exists at the destination (this same
        // organization), so it classifies `existing`, never `new`.
        $this->assertSame(1, $counts['existing']);
        $this->assertSame(0, $counts['invalid']);
        $this->assertSame(1, array_sum($counts));

        $this->confirm($import, $owner, $organization);

        $this->inTenant($organization, function () use ($instrument): void {
            $this->assertSame(1, ResultsAnalysisNote::where('instrument_id', $instrument->id)->count());
        });
    }

    #[Test]
    public function a_v12_backup_without_the_collection_imports_with_zero_notes_and_destroys_nothing(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        ['instrument' => $instrument] = $this->scenario($owner, $organization);
        $existingCount = $this->inTenant($organization, fn (): int => ResultsAnalysisNote::where('instrument_id', $instrument->id)->count());

        $backup = $this->backupUpload($owner, $organization);
        $mutated = $this->rewriteBackup($backup, function (array $json): array {
            $json['schema_version'] = 12;
            unset($json['results_analysis_notes']);

            return $json;
        });

        $import = $this->uploadInto($mutated, $owner, $organization);
        $plan = $this->planFor($import, $owner, $organization);
        $this->assertSame(0, $plan['counts']['results_analysis_notes']['new'] ?? 0);

        $this->confirm($import, $owner, $organization);

        $this->inTenant($organization, function () use ($instrument, $existingCount): void {
            $this->assertSame($existingCount, ResultsAnalysisNote::where('instrument_id', $instrument->id)->count());
        });
    }

    #[Test]
    public function an_instrument_reference_outside_the_backup_is_invalid_and_a_foreign_instrument_is_never_associated(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        ['instrument' => $ownInstrument] = $this->scenario($owner, $organization);

        $otherTeacher = User::factory()->create();
        ['instrument' => $foreignInstrument] = $this->scenario($otherTeacher, $otherTeacher->personalOrganization());

        $backup = $this->backupUpload($owner, $organization);
        $mutated = $this->rewriteBackup($backup, function (array $json) use ($ownInstrument, $foreignInstrument): array {
            $good = collect($this->notesOf($json))->firstWhere('instrument_ulid', $ownInstrument->ulid);

            $notInBackup = $good;
            $notInBackup['ulid'] = (string) Str::ulid();
            $notInBackup['instrument_ulid'] = (string) Str::ulid();

            $foreign = $good;
            $foreign['ulid'] = (string) Str::ulid();
            $foreign['instrument_ulid'] = $foreignInstrument->ulid;

            $json['results_analysis_notes'] = [$good, $notInBackup, $foreign];

            return $json;
        });

        $import = $this->uploadInto($mutated, $owner, $organization);
        $plan = $this->planFor($import, $owner, $organization);
        // Same-org round trip: $good already exists at the destination.
        $this->assertSame(1, $plan['counts']['results_analysis_notes']['existing']);
        $this->assertSame(2, $plan['counts']['results_analysis_notes']['invalid']);

        $this->confirm($import, $owner, $organization);

        $this->inTenant($organization, function () use ($ownInstrument): void {
            $this->assertSame(1, ResultsAnalysisNote::where('instrument_id', $ownInstrument->id)->count());
        });

        $this->inTenant($otherTeacher->personalOrganization(), function () use ($foreignInstrument): void {
            $this->assertSame(1, ResultsAnalysisNote::where('instrument_id', $foreignInstrument->id)->count(), 'A observação da outra organização fica intacta.');
        });
    }

    #[Test]
    public function restoring_into_a_fresh_account_clones_the_note_leaves_authorship_empty_and_shows_the_notice(): void
    {
        ['instrument' => $sourceInstrument, 'note' => $sourceNote] = $this->scenario();
        $sourceOrganization = $this->teacher->personalOrganization();
        $backup = $this->backupUpload($this->teacher, $sourceOrganization);

        // A different, brand-new account: the destination has no matching
        // class/instrument yet, so BuildImportPlan creates everything `new`,
        // including the instrument this note references — exercising
        // preserve_ulid=true (never seen elsewhere) and unresolved authorship.
        $newAccount = User::factory()->create();
        $destination = $newAccount->personalOrganization();

        $import = $this->uploadInto($backup, $newAccount, $destination);
        $plan = $this->planFor($import, $newAccount, $destination);
        $this->assertSame(1, $plan['counts']['results_analysis_notes']['new']);
        $noteRow = collect($plan['rows']['results_analysis_notes'])->firstWhere('ulid', $sourceNote->ulid);
        $this->assertNotNull($noteRow['notice']);

        $this->confirm($import, $newAccount, $destination);

        // The source account/organization still exists (never deleted), so
        // both the cloned instrument and the cloned note get a FRESH ulid
        // (`preserve_ulid = ! elsewhere->has(ulid)`) — never the source's
        // own ulid, which would collide with the still-live source row.
        $this->inTenant($destination, function () use ($sourceInstrument, $sourceNote): void {
            $note = ResultsAnalysisNote::where('body', $sourceNote->body)->firstOrFail();
            $this->assertNotSame($sourceNote->ulid, $note->ulid);
            $this->assertSame($sourceNote->body, $note->body);
            $this->assertSame(1, $note->lock_version);
            $this->assertNull($note->created_by);
            $this->assertNull($note->updated_by);
            $this->assertSame($sourceNote->created_at->toIso8601String(), $note->created_at->toIso8601String());

            $instrument = Instrument::where('title', $sourceInstrument->title)->firstOrFail();
            $this->assertNotSame($sourceInstrument->ulid, $instrument->ulid);
            $this->assertSame($instrument->id, $note->instrument_id);
        });

        $summary = $import->fresh()->summary;
        $this->assertSame(1, $summary['results_analysis_notes_created']);
        $this->assertGreaterThanOrEqual(1, $summary['records_without_original_author']);
    }

    #[Test]
    public function reimporting_the_same_backup_into_the_same_account_creates_nothing_new(): void
    {
        $this->scenario();
        $organization = $this->teacher->personalOrganization();
        $backup = $this->backupUpload($this->teacher, $organization);

        $firstImport = $this->uploadInto($backup, $this->teacher, $organization);
        $firstPlan = $this->planFor($firstImport, $this->teacher, $organization);
        $this->assertSame(0, $firstPlan['counts']['results_analysis_notes']['new']);
        $this->assertSame(1, $firstPlan['counts']['results_analysis_notes']['existing']);
        // Same-account export already matched everything as `existing`
        // before even confirming — nothing new to write either way.
        $this->confirm($firstImport, $this->teacher, $organization);

        $secondBackup = $this->rewriteBackup($backup, fn (array $json): array => $json);
        $secondImport = $this->uploadInto($secondBackup, $this->teacher, $organization);
        $secondPlan = $this->planFor($secondImport, $this->teacher, $organization);
        $this->assertSame(0, $secondPlan['counts']['results_analysis_notes']['new']);
        $this->assertSame(1, $secondPlan['counts']['results_analysis_notes']['existing']);
    }

    #[Test]
    public function editing_the_text_after_export_produces_a_conflict_that_never_overwrites_the_destination(): void
    {
        ['instrument' => $instrument, 'note' => $note] = $this->scenario();
        $organization = $this->teacher->personalOrganization();
        $backup = $this->backupUpload($this->teacher, $organization);

        $originalBody = $note->body;
        $this->inTenant($organization, function () use ($instrument): void {
            ResultsAnalysisNote::where('instrument_id', $instrument->id)->update(['body' => 'Texto alterado depois da exportação.']);
        });

        $import = $this->uploadInto($backup, $this->teacher, $organization);
        $plan = $this->planFor($import, $this->teacher, $organization);
        $this->assertSame(0, $plan['counts']['results_analysis_notes']['new']);
        $this->assertSame(1, $plan['counts']['results_analysis_notes']['conflict']);

        $this->confirm($import, $this->teacher, $organization);

        $this->inTenant($organization, function () use ($instrument): void {
            $this->assertSame('Texto alterado depois da exportação.', ResultsAnalysisNote::where('instrument_id', $instrument->id)->firstOrFail()->body);
        });
    }

    #[Test]
    public function a_colleague_at_the_destination_with_their_own_note_never_violates_the_unique_index(): void
    {
        ['instrument' => $sourceInstrument, 'note' => $sourceNote] = $this->scenario();
        $sourceOrganization = $this->teacher->personalOrganization();
        $backup = $this->backupUpload($this->teacher, $sourceOrganization);

        [$destinationOrg, $owner] = $this->institutionalOrganization();
        ['instrument' => $destinationInstrument] = $this->scenario($owner, $destinationOrg);

        // A colleague at the destination already wrote their OWN note for
        // the instrument the backup's note will resolve to, under a
        // different ulid — replacing the note `scenario()` itself created
        // for that instrument, so only ONE note exists at the destination
        // before the import runs.
        $this->inTenant($destinationOrg, function () use ($destinationInstrument): void {
            ResultsAnalysisNote::where('instrument_id', $destinationInstrument->id)->delete();
            ResultsAnalysisNote::create([
                'context_kind' => 'instrument', 'instrument_id' => $destinationInstrument->id,
                'body' => 'Nota do colega no destino.', 'lock_version' => 1,
                'created_by' => null, 'updated_by' => null,
            ]);
        });

        $mutated = $this->rewriteBackup($backup, function (array $json) use ($destinationInstrument): array {
            // Point the note's instrument reference at the SAME instrument
            // ulid already present as `existing` in the destination, by
            // reusing the source instrument's row shape under the
            // destination's ulid — simplest correct way is to keep the
            // instrument_ulid pointing at $sourceInstrument, which the
            // BuildImportPlan resolves to a NEW instrument, not this
            // colleague's EXISTING one; so instead we rewrite the note to
            // reference the destination instrument's ulid directly, and add
            // an instrument row for it so it resolves as `existing`.
            $json['instruments'][] = [
                'ulid' => $destinationInstrument->ulid, 'class_ulid' => $json['classes'][0]['ulid'] ?? $json['instruments'][0]['class_ulid'],
                'title' => $destinationInstrument->title, 'status' => 'completed',
                'applied_on' => $destinationInstrument->applied_on->toDateString(),
                'academic_period_ulid' => $json['instruments'][0]['academic_period_ulid'] ?? null,
                'instrument_type' => $json['instruments'][0]['instrument_type'] ?? null,
                'purpose' => null, 'counts_toward_classification' => true, 'total_points' => null,
                'scale' => null, 'weight' => null, 'allow_bonus' => false,
            ];

            $json['results_analysis_notes'] = collect($this->notesOf($json))->map(function (array $row) use ($destinationInstrument): array {
                $row['instrument_ulid'] = $destinationInstrument->ulid;

                return $row;
            })->values()->all();

            return $json;
        });

        $import = $this->uploadInto($mutated, $owner, $destinationOrg);
        $plan = $this->planFor($import, $owner, $destinationOrg);
        $counts = $plan['counts']['results_analysis_notes'];
        $this->assertSame(1, $counts['conflict'], 'Texto diferente do colega no destino ⇒ conflict, nunca sobreposto.');

        $this->confirm($import, $owner, $destinationOrg);

        $this->inTenant($destinationOrg, function () use ($destinationInstrument): void {
            $notes = ResultsAnalysisNote::where('instrument_id', $destinationInstrument->id)->get();
            $this->assertCount(1, $notes, 'O índice único (instrument_id, context_kind) nunca é violado.');
            $this->assertSame('Nota do colega no destino.', $notes->first()->body);
        });
    }

    #[Test]
    public function a_note_ulid_belonging_to_another_organization_is_cloned_with_a_fresh_ulid(): void
    {
        $otherTeacher = User::factory()->create();
        ['note' => $foreignNote] = $this->scenario($otherTeacher, $otherTeacher->personalOrganization());

        ['instrument' => $ownInstrument] = $this->scenario($this->teacher, $this->teacher->personalOrganization());
        $organization = $this->teacher->personalOrganization();
        $backup = $this->backupUpload($this->teacher, $organization);

        $mutated = $this->rewriteBackup($backup, function (array $json) use ($foreignNote, $ownInstrument): array {
            $json['results_analysis_notes'] = collect($this->notesOf($json))
                ->where('instrument_ulid', $ownInstrument->ulid)->values()->all();
            // Reuse another organization's real ulid on this note.
            $json['results_analysis_notes'][0]['ulid'] = $foreignNote->ulid;

            return $json;
        });

        $import = $this->uploadInto($mutated, $this->teacher, $organization);
        $this->confirm($import, $this->teacher, $organization);

        $this->inTenant($organization, function () use ($ownInstrument, $foreignNote): void {
            $note = ResultsAnalysisNote::where('instrument_id', $ownInstrument->id)->firstOrFail();
            $this->assertNotSame($foreignNote->ulid, $note->ulid, 'Nunca reutiliza o ulid de outra organização — clona com um novo.');
        });

        $this->inTenant($otherTeacher->personalOrganization(), function () use ($foreignNote): void {
            $this->assertNotNull(ResultsAnalysisNote::where('ulid', $foreignNote->ulid)->first(), 'A nota da outra organização fica intacta.');
        });
    }

    #[Test]
    public function the_preview_shows_new_existing_conflict_and_invalid_counts_for_the_domain(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        ['instrument' => $instrument] = $this->scenario($owner, $organization);

        $backup = $this->backupUpload($owner, $organization);
        $import = $this->uploadInto($backup, $owner, $organization);

        $plan = $this->planFor($import, $owner, $organization);
        $counts = $plan['counts']['results_analysis_notes'];
        $this->assertArrayHasKey('new', $counts);
        $this->assertArrayHasKey('existing', $counts);
        $this->assertArrayHasKey('conflict', $counts);
        $this->assertArrayHasKey('invalid', $counts);
        // Same-org round trip: the note already exists at the destination.
        $this->assertSame(1, $counts['existing']);
    }

    #[Test]
    public function a_discarded_restore_of_several_notes_keeps_identity_authorship_and_dates_and_re_exports_identically(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['class' => $class, 'instrument' => $firstInstrument] = $this->scenario();

        $secondInstrument = $this->inTenant($organization, function () use ($organization, $firstInstrument): Instrument {
            $type = InstrumentType::where('code', 'TEST')->firstOrFail();
            $instrument = Instrument::factory()->recycle($organization)->create([
                'class_id' => $firstInstrument->class_id, 'academic_period_id' => $firstInstrument->academic_period_id,
                'instrument_type_id' => $type->id, 'title' => 'Segunda ficha fictícia', 'applied_on' => '2025-11-20', 'status' => 'completed',
            ]);
            $note = new ResultsAnalysisNote;
            $note->timestamps = false;
            $note->forceFill([
                'context_kind' => 'instrument', 'instrument_id' => $instrument->id,
                'body' => "Segunda observação fictícia.\nDois parágrafos, com «aspas» e ç.",
                'lock_version' => 7, 'created_by' => $this->teacher->id, 'updated_by' => $this->teacher->id,
                'created_at' => '2025-11-21 09:15:00', 'updated_at' => '2025-12-02 18:40:00',
            ])->save();

            return $instrument;
        });

        $firstJson = $this->backupJson($this->backupUpload($this->teacher, $organization));
        $exportedNotes = collect($this->notesOf($firstJson))->sortBy('ulid')->values()->all();
        $this->assertCount(2, $exportedNotes);
        $this->assertEqualsCanonicalizing(
            [$firstInstrument->ulid, $secondInstrument->ulid],
            array_column($exportedNotes, 'instrument_ulid'),
        );

        // The "discardable installation": the class stays, but its
        // instruments and their notes are gone — hard-deleted, so neither
        // ulid exists anywhere any more and the restore may keep both.
        $this->inTenant($organization, function () use ($class): void {
            $instrumentIds = Instrument::withTrashed()->where('class_id', $class->id)->pluck('id');
            ResultsAnalysisNote::whereIn('instrument_id', $instrumentIds)->delete();
            Instrument::withTrashed()->whereIn('id', $instrumentIds)->forceDelete();
            $this->assertSame(0, ResultsAnalysisNote::count());
        });

        $import = $this->uploadInto($this->rewriteBackup($this->backupUpload($this->teacher, $organization), fn (array $json): array => $firstJson), $this->teacher, $organization);
        $plan = $this->planFor($import, $this->teacher, $organization);
        $this->assertSame(2, $plan['counts']['results_analysis_notes']['new']);
        $this->assertNull(collect($plan['rows']['results_analysis_notes'])->pluck('notice')->filter()->first(), 'Autoria própria: nenhum aviso.');

        $this->confirm($import, $this->teacher, $organization);

        $this->inTenant($organization, function () use ($exportedNotes): void {
            foreach ($exportedNotes as $exported) {
                $note = ResultsAnalysisNote::where('ulid', $exported['ulid'])->firstOrFail();
                $this->assertSame($exported['instrument_ulid'], Instrument::findOrFail($note->instrument_id)->ulid);
                $this->assertSame($exported['body'], $note->body);
                $this->assertSame($this->teacher->id, $note->created_by);
                $this->assertSame($this->teacher->id, $note->updated_by);
                $this->assertSame(1, $note->lock_version, 'O contador de concorrência recomeça nesta instalação.');
                $this->assertSame($exported['created_at'], $note->created_at->toIso8601String());
                $this->assertSame($exported['updated_at'], $note->updated_at->toIso8601String());
            }
        });

        $this->assertSame(2, $import->fresh()->summary['results_analysis_notes_created']);
        $this->assertSame(0, $import->fresh()->summary['records_without_original_author']);

        // export → import → export: the collection is the same, row for row.
        $secondJson = $this->backupJson($this->backupUpload($this->teacher, $organization));
        $this->assertSame($exportedNotes, collect($this->notesOf($secondJson))->sortBy('ulid')->values()->all());

        // And a second confirm of the same file writes nothing.
        $again = $this->uploadInto($this->rewriteBackup($this->backupUpload($this->teacher, $organization), fn (array $json): array => $firstJson), $this->teacher, $organization);
        $this->assertSame(0, $this->planFor($again, $this->teacher, $organization)['counts']['results_analysis_notes']['new']);
        $this->confirm($again, $this->teacher, $organization);
        $this->inTenant($organization, fn () => $this->assertSame(2, ResultsAnalysisNote::count()));
    }

    #[Test]
    public function rows_the_validator_refuses_never_reach_the_snapshot_and_never_break_the_import(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['instrument' => $instrument] = $this->scenario();

        $mutated = $this->rewriteBackup($this->backupUpload($this->teacher, $organization), function (array $json) use ($instrument): array {
            $good = collect($this->notesOf($json))->firstWhere('instrument_ulid', $instrument->ulid);

            $missingContext = $good;
            $missingContext['ulid'] = (string) Str::ulid();
            unset($missingContext['context_kind']);

            $wordDate = $good;
            $wordDate['ulid'] = (string) Str::ulid();
            $wordDate['updated_at'] = 'ontem à tarde';

            $sameUlid = $good;
            $sameUlid['body'] = 'Outra versão com o mesmo ulid.';

            $sameInstrument = $good;
            $sameInstrument['ulid'] = (string) Str::ulid();

            // 20 000 characters, 80 000 bytes: within the character limit
            // the form enforces, beyond what a TEXT column can hold.
            $tooManyBytes = $good;
            $tooManyBytes['ulid'] = (string) Str::ulid();
            $tooManyBytes['body'] = str_repeat('📘', 20000);

            $json['results_analysis_notes'] = [$good, $missingContext, $wordDate, $sameUlid, $sameInstrument, $tooManyBytes];

            return $json;
        });

        $import = $this->uploadInto($mutated, $this->teacher, $organization);
        $snapshotNotes = $import->canonical_snapshot['results_analysis_notes'];
        $this->assertCount(1, $snapshotNotes);
        $this->assertSame('Observação fictícia de resultados: a turma melhorou.', $snapshotNotes[0]['body']);

        $this->confirm($import, $this->teacher, $organization);
        $this->assertSame('imported', $import->fresh()->status->value);
        $this->inTenant($organization, fn () => $this->assertSame(1, ResultsAnalysisNote::count()));
    }

    #[Test]
    public function an_identical_note_on_an_instrument_edited_since_the_export_is_existing_not_a_reassociation(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['instrument' => $instrument, 'note' => $note] = $this->scenario();
        $backup = $this->backupUpload($this->teacher, $organization);

        // The instrument itself changed after the export — it is now a
        // `conflict` in the plan — but the note is untouched and still
        // attached to it.
        $this->inTenant($organization, fn () => Instrument::whereKey($instrument->id)->update(['title' => 'Ficha fictícia (revista)']));

        $import = $this->uploadInto($backup, $this->teacher, $organization);
        $plan = $this->planFor($import, $this->teacher, $organization);
        $this->assertSame('conflict', collect($plan['rows']['instruments'])->firstWhere('ulid', $instrument->ulid)['classification']);
        $row = collect($plan['rows']['results_analysis_notes'])->firstWhere('ulid', $note->ulid);
        $this->assertSame('existing', $row['classification']);
        $this->assertSame($note->id, $row['existing_id']);
    }

    #[Test]
    public function the_preview_lists_every_refused_observation_with_its_reason_on_every_visit_and_the_valid_one_still_restores(): void
    {
        ['instrument' => $instrument, 'note' => $note] = $this->scenario();
        $source = $this->teacher->personalOrganization();

        $marker = 'MARCADOR-FICTICIO-NAO-MOSTRAR';

        $mutated = $this->rewriteBackup($this->backupUpload($this->teacher, $source), function (array $json) use ($instrument, $marker): array {
            $good = collect($this->notesOf($json))->firstWhere('instrument_ulid', $instrument->ulid);

            $tooManyBytes = $good;
            $tooManyBytes['ulid'] = (string) Str::ulid();
            $tooManyBytes['body'] = $marker.str_repeat('📘', 16380);

            $badDate = $good;
            $badDate['ulid'] = (string) Str::ulid();
            $badDate['body'] = $marker.' data má';
            $badDate['updated_at'] = 'ontem à tarde';

            $badContext = $good;
            $badContext['ulid'] = (string) Str::ulid();
            $badContext['body'] = $marker.' contexto mau';
            $badContext['context_kind'] = 'turma';

            $json['results_analysis_notes'] = [$good, $tooManyBytes, $badDate, $badContext];

            return $json;
        });

        // A different account: the valid note is `new` there.
        $newAccount = User::factory()->create();
        $destination = $newAccount->personalOrganization();
        $import = $this->uploadInto($mutated, $newAccount, $destination);

        $this->assertStringNotContainsString($marker, (string) json_encode($import->canonical_snapshot), 'Nada recusado volta ao conteúdo canónico.');

        $expectedReasons = [
            'O texto desta observação ultrapassa o limite de 20 000 caracteres ou o espaço máximo de armazenamento.',
            'Observação com data de criação ou de alteração inválida.',
            'Observação com identificação, contexto ou elemento de avaliação em falta ou inválidos.',
        ];

        // Twice: the refused rows must not vanish on a plain reload.
        foreach ([1, 2] as $visit) {
            $response = $this->actingAs($newAccount)->withSession(['organization_id' => $destination->id])
                ->get("/data-imports/{$import->ulid}")
                ->assertOk();

            $page = $response->viewData('page');
            $counts = data_get($page, 'props.plan.counts.results_analysis_notes');
            $this->assertSame(1, $counts['new'], "visita {$visit}");
            $this->assertSame(3, $counts['invalid'], "visita {$visit}");

            $reasons = collect(data_get($page, 'props.plan.rows.results_analysis_notes'))
                ->where('classification', 'invalid')->pluck('reason')->sort()->values()->all();
            $this->assertSame(collect($expectedReasons)->sort()->values()->all(), $reasons, "visita {$visit}");
            $this->assertStringNotContainsString($marker, (string) $response->getContent(), 'O texto recusado nunca aparece na pré-visualização.');
        }

        $this->confirm($import, $newAccount, $destination);

        $this->assertSame('imported', $import->fresh()->status->value);
        $this->assertSame(1, $import->fresh()->summary['results_analysis_notes_created']);
        $this->inTenant($destination, function () use ($note): void {
            $this->assertSame(1, ResultsAnalysisNote::count());
            $this->assertSame($note->body, ResultsAnalysisNote::firstOrFail()->body);
        });
    }

    #[Test]
    public function an_import_uploaded_before_issues_were_stored_still_previews(): void
    {
        $this->scenario();
        $organization = $this->teacher->personalOrganization();
        $import = $this->uploadInto($this->backupUpload($this->teacher, $organization), $this->teacher, $organization);

        $snapshot = $import->canonical_snapshot;
        unset($snapshot['validation_issues']);
        $import->forceFill(['canonical_snapshot' => $snapshot])->save();

        $page = $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])
            ->get("/data-imports/{$import->ulid}")->assertOk()->viewData('page');

        $this->assertSame(0, data_get($page, 'props.plan.counts.results_analysis_notes.invalid'));
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
}
