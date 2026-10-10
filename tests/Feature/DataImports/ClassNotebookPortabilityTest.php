<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicYear;
use App\Models\ClassNotebookEntry;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;
use ZipArchive;

/**
 * Schema v14 — the class notebook round-trip. Built like
 * ResultsAnalysisNotesPortabilityTest: a REAL export, the zip's
 * backup-lapis.json mutated in place when a scenario needs a specific shape,
 * then a REAL upload / preview / confirm — exercising GenerateDataExport,
 * ValidateBackupPayload, BuildClassNotebookEntriesPlan and
 * WriteClassNotebookEntries exactly as production does.
 *
 * What matters most here is privacy: the notebook is private to its author, so
 * an export carries only the exporter's own entries and an import restores
 * only entries signed by the account confirming it. Everything is fictitious.
 */
class ClassNotebookPortabilityTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    private const string REASON_OTHER_ACCOUNT = 'Este registo do caderno foi escrito por outra conta e não é restaurado: o caderno é privado de quem o escreve.';

    private const string REASON_NEUTRAL = 'Este registo do caderno não pode ser restaurado nesta conta.';

    private const string REASON_DELETED = 'Este registo foi eliminado do caderno depois da exportação e não é reposto.';

    private const string REASON_OTHER_CLASS = 'Já existe um registo do caderno com esta identidade, associado a outra turma.';

    private const string REASON_CLASS = 'A turma deste registo do caderno não pode ser restaurada.';

    private const string BODY_ONE = "Primeiro parágrafo com acentuação: ação, coração, até à próxima.\r\n\r\nSegundo parágrafo com «aspas», ç e emoji 📘.\n\nTerceiro, com tabulação\te espaços   finais.  ";

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = User::factory()->create();
    }

    // --- Fixtures ------------------------------------------------------------

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    private function classIn(Organization $organization, User $teacher, string $label = '7.º Z fictício'): SchoolClass
    {
        return $this->inTenant($organization, function () use ($organization, $teacher, $label): SchoolClass {
            $year = AcademicYear::query()->where('label', '2025/2026')->first()
                ?? AcademicYear::factory()->recycle($organization)->create([
                    'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
                ]);
            $subject = Subject::query()->where('name', 'Matemática Fictícia')->first()
                ?? Subject::factory()->recycle($organization)->create(['name' => 'Matemática Fictícia']);
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => $label,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            return $class->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(Organization $organization, SchoolClass $class, User $author, array $attributes = []): ClassNotebookEntry
    {
        return $this->inTenant($organization, function () use ($class, $author, $attributes): ClassNotebookEntry {
            $entry = new ClassNotebookEntry;
            $entry->timestamps = false;
            $entry->forceFill(array_merge([
                'title' => null,
                'body' => 'Registo fictício.',
                'is_pinned' => false,
                'lock_version' => 0,
                'edited_at' => null,
                'created_at' => '2026-01-10 09:00:00',
                'updated_at' => '2026-01-10 09:00:00',
            ], $attributes, ['class_id' => $class->id, 'author_id' => $author->id]));
            $entry->save();

            // A fresh instance: this one has `timestamps = false`, which
            // would keep its dates as plain strings.
            return ClassNotebookEntry::findOrFail($entry->getKey());
        });
    }

    /**
     * Three entries by the teacher: a pinned, edited, multi-paragraph one with
     * a title; one with no title; one with a title.
     *
     * @return array{class: SchoolClass, entries: array{0: ClassNotebookEntry, 1: ClassNotebookEntry, 2: ClassNotebookEntry}}
     */
    private function fixture(?User $teacher = null, ?Organization $organization = null): array
    {
        $teacher ??= $this->teacher;
        $organization ??= $teacher->personalOrganization();
        $class = $this->classIn($organization, $teacher);

        return [
            'class' => $class,
            'entries' => [
                $this->entry($organization, $class, $teacher, [
                    'title' => 'Reunião com o conselho de turma', 'body' => self::BODY_ONE, 'is_pinned' => true, 'lock_version' => 5,
                    'created_at' => '2026-01-10 09:15:00', 'updated_at' => '2026-01-12 18:40:00', 'edited_at' => '2026-01-12 18:40:00',
                ]),
                $this->entry($organization, $class, $teacher, [
                    'title' => null, 'body' => 'A aula correu com dificuldade; rever o tema na próxima.',
                    'created_at' => '2026-02-03 11:00:00', 'updated_at' => '2026-02-03 11:00:00',
                ]),
                $this->entry($organization, $class, $teacher, [
                    'title' => 'Combinado com a turma', 'body' => 'Trabalho de casa só à quinta-feira.',
                    'created_at' => '2026-02-20 14:30:00', 'updated_at' => '2026-02-20 14:30:00',
                ]),
            ],
        ];
    }

    /**
     * @return array{Organization, User}
     */
    private function institutionalOrganization(?User $owner = null): array
    {
        $owner ??= User::factory()->withoutOrganization()->create();
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

    private function member(Organization $organization): User
    {
        $user = User::factory()->create();
        $organization->members()->attach($user, ['joined_at' => now()]);

        return $user;
    }

    // --- Export / import plumbing -----------------------------------------------

    private function backupUpload(User $user, Organization $organization): UploadedFile
    {
        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $user->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function rewriteBackup(UploadedFile $file, callable $mutate): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'lapis-class-notebook-backup-').'.zip';
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
    private function entriesOf(array $json): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $json['class_notebook_entries'] ?? [];

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportedEntries(User $user, Organization $organization): array
    {
        return collect($this->entriesOf($this->backupJson($this->backupUpload($user, $organization))))->sortBy('ulid')->values()->all();
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

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function planRow(array $plan, string $ulid): array
    {
        $row = collect($plan['rows']['class_notebook_entries'])->firstWhere('ulid', $ulid);
        $this->assertNotNull($row, 'A linha do caderno devia constar do plano.');

        /** @var array<string, mixed> $row */
        return $row;
    }

    private function hardDeleteEntries(Organization $organization): void
    {
        $this->inTenant($organization, fn () => ClassNotebookEntry::withTrashed()->forceDelete());
    }

    private function entryCount(Organization $organization): int
    {
        return $this->inTenant($organization, fn (): int => ClassNotebookEntry::withTrashed()->count());
    }

    /**
     * @return list<string>
     */
    private function notebookOrder(Organization $organization, SchoolClass $class, User $author): array
    {
        return $this->inTenant($organization, fn (): array => ClassNotebookEntry::query()
            ->where('class_id', $class->id)->where('author_id', $author->id)
            ->orderedForNotebook()->pluck('ulid')->all());
    }

    // --- Export ---------------------------------------------------------------------

    #[Test]
    public function the_backup_json_carries_exactly_the_documented_fields(): void
    {
        ['class' => $class, 'entries' => [$first, $second]] = $this->fixture();
        $organization = $this->teacher->personalOrganization();

        $backup = $this->backupJson($this->backupUpload($this->teacher, $organization));

        $this->assertSame(14, BackupSchemaCompatibility::CURRENT);
        $this->assertSame(14, $backup['schema_version']);
        $this->assertContains('class_notebook_entries', $backup['capabilities']);
        $this->assertContains('results_analysis_notes', $backup['capabilities'], 'A coleção da v13 continua no backup.');
        $this->assertArrayHasKey('results_analysis_notes', $backup);
        $this->assertCount(3, $this->entriesOf($backup));

        $row = collect($this->entriesOf($backup))->firstWhere('ulid', $first->ulid);
        $this->assertSame(
            ['ulid', 'class_ulid', 'author_email', 'title', 'body', 'is_pinned', 'created_at', 'updated_at', 'edited_at'],
            array_keys($row),
        );
        $this->assertSame($class->ulid, $row['class_ulid']);
        $this->assertSame($this->teacher->email, $row['author_email']);
        $this->assertSame('Reunião com o conselho de turma', $row['title']);
        $this->assertSame(self::BODY_ONE, $row['body']);
        $this->assertTrue($row['is_pinned']);
        $this->assertSame($first->created_at?->toIso8601String(), $row['created_at']);
        $this->assertSame($first->updated_at?->toIso8601String(), $row['updated_at']);
        $this->assertSame($first->edited_at?->toIso8601String(), $row['edited_at']);

        foreach (['id', 'organization_id', 'class_id', 'author_id', 'lock_version', 'deleted_at'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }

        $untitled = collect($this->entriesOf($backup))->firstWhere('ulid', $second->ulid);
        $this->assertNull($untitled['title']);
        $this->assertFalse($untitled['is_pinned']);
        $this->assertNull($untitled['edited_at']);
    }

    #[Test]
    public function each_account_exports_only_its_own_entries_and_never_deleted_or_foreign_ones(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $teacherA = $this->member($organization);
        $teacherB = $this->member($organization);

        $shared = $this->classIn($organization, $teacherA);
        $this->inTenant($organization, fn () => $shared->teachers()->attach($teacherB, ['role' => 'co_teacher']));
        $ownersClass = $this->classIn($organization, $owner, '8.º W fictício');
        $this->inTenant($organization, fn () => $ownersClass->teachers()->attach($teacherA, ['role' => 'co_teacher']));
        $notOwnersClass = $this->classIn($organization, $teacherB, '9.º V fictício');

        $a1 = $this->entry($organization, $shared, $teacherA, ['body' => 'A, primeiro.']);
        $a2 = $this->entry($organization, $shared, $teacherA, ['body' => 'A, segundo (eliminado depois).']);
        $b1 = $this->entry($organization, $shared, $teacherB, ['body' => 'B, único.']);
        $ownerEntry = $this->entry($organization, $ownersClass, $owner, ['body' => 'Responsável, na sua turma.']);
        $aOnOwners = $this->entry($organization, $ownersClass, $teacherA, ['body' => 'A, na turma do responsável.']);
        // An entry by the owner in a class the owner does not teach (it can
        // only exist by writing to the table directly): it is not exported
        // either, because the class is not among the exporter's.
        $ownerOnForeignClass = $this->entry($organization, $notOwnersClass, $owner, ['body' => 'Responsável, numa turma que não ensina.']);

        // Another organization of teacher A.
        $personal = $teacherA->personalOrganization();
        $personalClass = $this->classIn($personal, $teacherA, '5.º P fictício');
        $elsewhere = $this->entry($personal, $personalClass, $teacherA, ['body' => 'A, noutra organização.']);

        $this->inTenant($organization, fn () => $a2->delete());

        $exportedBy = fn (User $user): array => collect($this->entriesOf($this->backupJson($this->backupUpload($user, $organization))))->pluck('ulid')->sort()->values()->all();

        $this->assertSame(collect([$a1->ulid, $aOnOwners->ulid])->sort()->values()->all(), $exportedBy($teacherA));
        $this->assertSame([$b1->ulid], $exportedBy($teacherB));
        $this->assertSame([$ownerEntry->ulid], $exportedBy($owner));

        $this->assertNotContains($ownerOnForeignClass->ulid, $exportedBy($owner));
        $this->assertNotContains($elsewhere->ulid, $exportedBy($teacherA));
        $this->assertNotContains($a2->ulid, $exportedBy($teacherA), 'Um registo eliminado nunca sai.');

        // And the personal organization exports only its own.
        $this->assertSame([$elsewhere->ulid], collect($this->entriesOf($this->backupJson($this->backupUpload($teacherA, $personal))))->pluck('ulid')->all());
    }

    #[Test]
    public function the_xlsx_carries_only_own_entries_in_its_sheet_and_the_summary_count(): void
    {
        ['entries' => [$first]] = $this->fixture();
        $organization = $this->teacher->personalOrganization();

        $colleague = User::factory()->create();
        $foreignClass = $this->classIn($colleague->personalOrganization(), $colleague, '6.º K fictício');
        $this->entry($colleague->personalOrganization(), $foreignClass, $colleague, ['body' => 'Registo alheio que não pode aparecer.']);

        $spreadsheet = $this->exportedSpreadsheet($this->teacher, $organization);

        $sheet = $spreadsheet->getSheetByName('Caderno da turma');
        $this->assertNotNull($sheet);
        $this->assertSame(
            ['Ano letivo', 'Turma', 'Título', 'Registo', 'Fixado', 'Criado em', 'Editado em'],
            [$sheet->getCell('A1')->getValue(), $sheet->getCell('B1')->getValue(), $sheet->getCell('C1')->getValue(), $sheet->getCell('D1')->getValue(), $sheet->getCell('E1')->getValue(), $sheet->getCell('F1')->getValue(), $sheet->getCell('G1')->getValue()],
        );

        $bodies = [];
        $titles = [];
        $pinned = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $titles[] = (string) $sheet->getCell("C{$row}")->getValue();
            $bodies[] = (string) $sheet->getCell("D{$row}")->getValue();
            $pinned[] = (string) $sheet->getCell("E{$row}")->getValue();
        }
        $this->assertCount(3, $bodies);
        $normalise = fn (string $text): string => trim(str_replace(chr(13), '', $text));
        $this->assertContains($normalise($first->body), array_map($normalise, $bodies));
        $this->assertNotContains('Registo alheio que não pode aparecer.', $bodies);
        $this->assertContains('—', $titles, 'Sem título, a célula mostra «—».');
        $this->assertContains('Sim', $pinned);
        $this->assertContains('Não', $pinned);

        $resumo = $spreadsheet->getSheetByName('Resumo');
        $this->assertNotNull($resumo);
        $counts = [];
        for ($row = 2; $row <= $resumo->getHighestDataRow(); $row++) {
            $counts[(string) $resumo->getCell("A{$row}")->getValue()] = $resumo->getCell("B{$row}")->getValue();
        }
        $this->assertSame(3, (int) $counts['Nº de registos do caderno da turma']);
    }

    #[Test]
    public function without_entries_the_xlsx_has_no_notebook_sheet_and_the_summary_says_zero(): void
    {
        $organization = $this->teacher->personalOrganization();
        $this->classIn($organization, $this->teacher);

        $spreadsheet = $this->exportedSpreadsheet($this->teacher, $organization);

        $this->assertNull($spreadsheet->getSheetByName('Caderno da turma'));

        $resumo = $spreadsheet->getSheetByName('Resumo');
        $this->assertNotNull($resumo);
        $counts = [];
        for ($row = 2; $row <= $resumo->getHighestDataRow(); $row++) {
            $counts[(string) $resumo->getCell("A{$row}")->getValue()] = $resumo->getCell("B{$row}")->getValue();
        }
        $this->assertSame(0, (int) $counts['Nº de registos do caderno da turma']);
    }

    private function exportedSpreadsheet(User $user, Organization $organization): Spreadsheet
    {
        $this->actingAs($user)->withSession(['organization_id' => $organization->id])->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $user->id)->latest('id')->firstOrFail();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->disk_path)) === true);

        $bytes = $zip->getFromName('Exportacao-Lapispro.xlsx');
        $this->assertNotFalse($bytes);
        $tempPath = tempnam(sys_get_temp_dir(), 'lapis_class_notebook_test_');
        file_put_contents($tempPath, $bytes);

        try {
            return IOFactory::createReader('Xlsx')->load($tempPath);
        } finally {
            @unlink($tempPath);
        }
    }

    // --- Round trip ----------------------------------------------------------------

    #[Test]
    public function a_discarded_restore_brings_the_notebook_back_byte_for_byte_and_re_exports_identically(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['class' => $class, 'entries' => $originals] = $this->fixture();

        $orderBefore = $this->notebookOrder($organization, $class, $this->teacher);
        $this->assertSame($originals[0]->ulid, $orderBefore[0], 'O fixado vem primeiro.');

        $firstFile = $this->backupUpload($this->teacher, $organization);
        $exported = collect($this->entriesOf($this->backupJson($firstFile)))->sortBy('ulid')->values()->all();
        $this->assertCount(3, $exported);

        // The "discardable installation": the class stays, the entries are
        // gone for good, so no ulid exists anywhere any more.
        $this->hardDeleteEntries($organization);
        $this->assertSame(0, $this->entryCount($organization));

        $import = $this->uploadInto($this->rewriteBackup($firstFile, fn (array $json): array => $json), $this->teacher, $organization);
        $plan = $this->planFor($import, $this->teacher, $organization);
        $this->assertSame(3, $plan['counts']['class_notebook_entries']['new']);
        $this->assertSame(0, $plan['counts']['class_notebook_entries']['invalid']);
        $this->assertNull(collect($plan['rows']['class_notebook_entries'])->pluck('notice')->filter()->first());

        $this->confirm($import, $this->teacher, $organization);
        $this->assertSame(3, $import->fresh()->summary['class_notebook_entries_created']);
        $this->assertSame(0, $import->fresh()->summary['records_without_original_author']);

        $this->inTenant($organization, function () use ($exported, $class): void {
            foreach ($exported as $row) {
                $entry = ClassNotebookEntry::where('ulid', $row['ulid'])->firstOrFail();
                $this->assertSame($row['body'], $entry->body, 'Texto byte a byte.');
                $this->assertSame($row['title'], $entry->title);
                $this->assertSame($row['is_pinned'], $entry->is_pinned);
                $this->assertSame($row['created_at'], $entry->created_at?->toIso8601String());
                $this->assertSame($row['updated_at'], $entry->updated_at?->toIso8601String());
                $this->assertSame($row['edited_at'], $entry->edited_at?->toIso8601String());
                $this->assertSame($this->teacher->id, $entry->author_id);
                $this->assertSame($class->id, $entry->class_id);
                $this->assertSame($class->ulid, $entry->schoolClass->ulid);
                $this->assertSame('2025/2026', $entry->schoolClass->academicYear->label);
                $this->assertSame(0, $entry->lock_version, 'O contador de concorrência recomeça nesta instalação.');
                $this->assertNull($entry->deleted_at);
            }
        });

        $this->assertSame($orderBefore, $this->notebookOrder($organization, $class, $this->teacher));

        // export → import → export: the same collection, row for row.
        $this->assertSame($exported, $this->exportedEntries($this->teacher, $organization));

        // A second pass of the same file writes nothing.
        $again = $this->uploadInto($this->rewriteBackup($firstFile, fn (array $json): array => $json), $this->teacher, $organization);
        $this->assertSame(0, $this->planFor($again, $this->teacher, $organization)['counts']['class_notebook_entries']['new']);
        $this->assertSame(3, $this->planFor($again, $this->teacher, $organization)['counts']['class_notebook_entries']['existing']);
        $this->assertSame(3, $this->entryCount($organization));
    }

    #[Test]
    public function a_v13_backup_imports_with_zero_entries_and_changes_none_at_the_destination(): void
    {
        ['entries' => [$first]] = $this->fixture();
        $organization = $this->teacher->personalOrganization();
        $file = $this->backupUpload($this->teacher, $organization);

        $v13 = $this->rewriteBackup($file, function (array $json): array {
            $json['schema_version'] = 13;
            unset($json['class_notebook_entries']);
            $json['capabilities'] = array_values(array_diff($json['capabilities'], ['class_notebook_entries']));

            return $json;
        });

        // Same account: nothing to restore, nothing touched.
        $import = $this->uploadInto($v13, $this->teacher, $organization);
        $plan = $this->planFor($import, $this->teacher, $organization);
        $this->assertSame(0, array_sum($plan['counts']['class_notebook_entries']));
        $this->assertSame(3, $this->entryCount($organization));
        $this->assertSame($first->body, $this->inTenant($organization, fn () => ClassNotebookEntry::where('ulid', $first->ulid)->firstOrFail()->body));

        // A fresh account: the class is restored, the notebook is simply absent.
        $fresh = User::factory()->create();
        $destination = $fresh->personalOrganization();
        $freshImport = $this->uploadInto($v13, $fresh, $destination);
        $this->confirm($freshImport, $fresh, $destination);

        $this->assertSame(0, $freshImport->fresh()->summary['class_notebook_entries_created']);
        $this->assertSame(0, $this->entryCount($destination));
        $this->assertSame(3, $this->entryCount($organization), 'O caderno de origem não é tocado.');
    }

    // --- Authorship: the notebook is private -------------------------------------------

    #[Test]
    public function an_account_importing_somebody_elses_backup_restores_no_notebook_entry_and_sees_no_text(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $teacherA = $this->member($organization);
        $teacherB = $this->member($organization);
        $class = $this->classIn($organization, $teacherA);
        $this->inTenant($organization, fn () => $class->teachers()->attach($teacherB, ['role' => 'co_teacher']));

        $marker = 'SEGREDO-FICTICIO-CADERNO';
        $a1 = $this->entry($organization, $class, $teacherA, ['title' => "Título {$marker}", 'body' => "Texto privado {$marker}"]);
        $a2 = $this->entry($organization, $class, $teacherA, ['body' => "Outro texto privado {$marker}"]);

        $file = $this->backupUpload($teacherA, $organization);
        $this->assertCount(2, $this->entriesOf($this->backupJson($file)));
        $entriesBefore = $this->entryCount($organization);

        // (1) Same organization, by the co-teacher.
        $import = $this->uploadInto($this->rewriteBackup($file, fn (array $json): array => $json), $teacherB, $organization);
        $plan = $this->planFor($import, $teacherB, $organization);
        $this->assertSame(2, $plan['counts']['class_notebook_entries']['invalid']);
        $this->assertSame(0, $plan['counts']['class_notebook_entries']['new']);

        foreach ([$a1, $a2] as $entry) {
            $row = $this->planRow($plan, $entry->ulid);
            $this->assertSame('invalid', $row['classification']);
            $this->assertSame(self::REASON_OTHER_ACCOUNT, $row['reason']);
            $this->assertSame(['ulid', 'classification', 'reason'], array_keys($row));
        }

        $encodedPlan = (string) json_encode($plan['rows']['class_notebook_entries']);
        $this->assertStringNotContainsString($marker, $encodedPlan);
        $this->assertStringNotContainsString($teacherA->email, $encodedPlan);

        $preview = $this->actingAs($teacherB)->withSession(['organization_id' => $organization->id])
            ->get("/data-imports/{$import->ulid}")->assertOk();
        $this->assertStringNotContainsString($marker, (string) $preview->getContent());
        $this->assertStringNotContainsString($teacherA->email, (string) json_encode(data_get($preview->viewData('page'), 'props.plan.rows.class_notebook_entries')));
        $this->assertSame($entriesBefore, $this->entryCount($organization));

        // (2) A brand-new organization of the co-teacher: the class is
        // restored, the notebook is not.
        $freshImport = $this->uploadInto($this->rewriteBackup($file, fn (array $json): array => $json), $teacherB, $teacherB->personalOrganization());
        $freshPlan = $this->planFor($freshImport, $teacherB, $teacherB->personalOrganization());
        $this->assertSame(2, $freshPlan['counts']['class_notebook_entries']['invalid']);
        $this->assertStringNotContainsString($marker, (string) json_encode($freshPlan['rows']['class_notebook_entries']));

        $this->confirm($freshImport, $teacherB, $teacherB->personalOrganization());
        $this->assertSame(0, $freshImport->fresh()->summary['class_notebook_entries_created']);
        $this->assertSame(0, ClassNotebookEntry::withoutGlobalScopes()->withTrashed()->where('author_id', $teacherB->id)->count());
        $this->assertSame(0, $this->entryCount($teacherB->personalOrganization()));
        $this->assertSame($entriesBefore, $this->entryCount($organization));

        // (3) A row with no author, or with an author that is nobody here, is
        // refused too — the same outcome, never a silent attribution.
        $anonymous = $this->rewriteBackup($file, function (array $json): array {
            $json['class_notebook_entries'][0]['author_email'] = null;
            unset($json['class_notebook_entries'][1]['author_email']);

            return $json;
        });
        $anonymousImport = $this->uploadInto($anonymous, $teacherA, $organization);
        $anonymousPlan = $this->planFor($anonymousImport, $teacherA, $organization);
        $this->assertSame(2, $anonymousPlan['counts']['class_notebook_entries']['invalid']);
    }

    #[Test]
    public function a_crafted_row_reusing_another_accounts_ulid_is_refused_neutrally_whatever_its_content(): void
    {
        [$organization] = $this->institutionalOrganization();
        $teacherA = $this->member($organization);
        $teacherB = $this->member($organization);
        $class = $this->classIn($organization, $teacherA);
        $this->inTenant($organization, fn () => $class->teachers()->attach($teacherB, ['role' => 'co_teacher']));

        $a1 = $this->entry($organization, $class, $teacherA, ['title' => 'Título do A', 'body' => 'Texto do A.']);
        $file = $this->backupUpload($teacherA, $organization);

        $classifications = [];

        foreach (['identical' => fn (array $row): array => $row, 'different' => fn (array $row): array => array_merge($row, ['body' => 'Texto forjado.', 'title' => 'Outro'])] as $variant => $tweak) {
            $crafted = $this->rewriteBackup($file, function (array $json) use ($teacherB, $tweak): array {
                $json['class_notebook_entries'] = collect($json['class_notebook_entries'])->map(function (array $row) use ($teacherB, $tweak): array {
                    $row['author_email'] = $teacherB->email;

                    return $tweak($row);
                })->all();

                return $json;
            });

            $import = $this->uploadInto($crafted, $teacherB, $organization);
            $row = $this->planRow($this->planFor($import, $teacherB, $organization), $a1->ulid);
            $classifications[$variant] = [$row['classification'], $row['reason']];
            $this->assertSame(['ulid', 'classification', 'reason'], array_keys($row));
        }

        $this->assertSame(['invalid', self::REASON_NEUTRAL], $classifications['identical']);
        $this->assertSame($classifications['identical'], $classifications['different'], 'Nenhuma comparação de conteúdo: a resposta é a mesma.');

        $this->inTenant($organization, function () use ($a1, $teacherA): void {
            $fresh = ClassNotebookEntry::findOrFail($a1->id);
            $this->assertSame('Texto do A.', $fresh->body);
            $this->assertSame('Título do A', $fresh->title);
            $this->assertSame($teacherA->id, $fresh->author_id);
        });
        $this->assertSame(1, $this->entryCount($organization));
    }

    // --- Deleted entries -------------------------------------------------------------

    #[Test]
    public function an_entry_deleted_after_the_export_is_not_resurrected_by_restoring_the_own_backup(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['class' => $class, 'entries' => [$first, $second, $third]] = $this->fixture();
        $file = $this->backupUpload($this->teacher, $organization);

        $this->inTenant($organization, fn () => $second->delete());

        // A fresh row alongside, so that something is `new` and the import can
        // be confirmed — the deleted one must stay deleted when it runs.
        $freshUlid = (string) Str::ulid();
        $mutated = $this->rewriteBackup($file, function (array $json) use ($freshUlid): array {
            $extra = $json['class_notebook_entries'][0];
            $extra['ulid'] = $freshUlid;
            $extra['title'] = 'Registo novo';
            $extra['body'] = 'Escrito noutra instalação.';
            $extra['created_at'] = '2026-03-01T10:00:00+00:00';
            $json['class_notebook_entries'][] = $extra;

            return $json;
        });

        $import = $this->uploadInto($mutated, $this->teacher, $organization);
        $plan = $this->planFor($import, $this->teacher, $organization);

        $deleted = $this->planRow($plan, $second->ulid);
        $this->assertSame('conflict', $deleted['classification']);
        $this->assertSame(self::REASON_DELETED, $deleted['reason']);
        $this->assertSame('existing', $this->planRow($plan, $first->ulid)['classification']);
        $this->assertSame('existing', $this->planRow($plan, $third->ulid)['classification']);
        $this->assertSame('new', $this->planRow($plan, $freshUlid)['classification']);
        $this->assertEqualsCanonicalizing(['ulid', 'classification', 'reason', 'existing_id'], array_keys($deleted));

        $this->confirm($import, $this->teacher, $organization);

        $this->inTenant($organization, function () use ($second, $freshUlid): void {
            $this->assertTrue(ClassNotebookEntry::withTrashed()->findOrFail($second->id)->trashed(), 'Continua eliminado.');
            $this->assertSame(4, ClassNotebookEntry::withTrashed()->count(), 'Nenhum duplicado do eliminado.');
            $this->assertSame(1, ClassNotebookEntry::where('ulid', $freshUlid)->count());
            $this->assertSame(3, ClassNotebookEntry::count());
        });
        $this->assertSame(1, $import->fresh()->summary['class_notebook_entries_created']);
        $this->assertSame($class->id, $this->inTenant($organization, fn () => ClassNotebookEntry::where('ulid', $freshUlid)->firstOrFail()->class_id));
    }

    #[Test]
    public function cloning_into_another_organization_is_idempotent_and_never_resurrects_a_deleted_clone(): void
    {
        $source = $this->teacher->personalOrganization();
        ['entries' => $originals] = $this->fixture();
        $file = $this->backupUpload($this->teacher, $source);

        [$destination] = $this->institutionalOrganization($this->teacher);

        // First restore: the class is created, the entries get FRESH ulids
        // (the source ulids belong to another organization).
        $first = $this->uploadInto($this->rewriteBackup($file, fn (array $json): array => $json), $this->teacher, $destination);
        $firstPlan = $this->planFor($first, $this->teacher, $destination);
        $this->assertSame(3, $firstPlan['counts']['class_notebook_entries']['new']);
        $this->assertFalse(collect($firstPlan['rows']['class_notebook_entries'])->pluck('preserve_ulid')->contains(true));

        $this->confirm($first, $this->teacher, $destination);
        $this->assertSame(3, $first->fresh()->summary['class_notebook_entries_created']);
        $this->assertSame(3, $this->entryCount($destination));

        $clones = $this->inTenant($destination, fn () => ClassNotebookEntry::query()->orderBy('id')->get());
        $this->assertEqualsCanonicalizing(
            collect($originals)->pluck('body')->all(),
            $clones->pluck('body')->all(),
        );
        $this->assertEmpty(array_intersect($clones->pluck('ulid')->all(), collect($originals)->pluck('ulid')->all()));
        $this->assertSame(0, $clones->firstWhere('title', 'Combinado com a turma')?->lock_version);

        // Second restore of the same file: nothing to create.
        $second = $this->uploadInto($this->rewriteBackup($file, fn (array $json): array => $json), $this->teacher, $destination);
        $secondPlan = $this->planFor($second, $this->teacher, $destination);
        $this->assertSame(0, $secondPlan['counts']['class_notebook_entries']['new']);
        $this->assertSame(3, $secondPlan['counts']['class_notebook_entries']['existing']);
        $this->assertSame(3, $this->entryCount($destination));

        // The teacher deletes one clone; restoring the file again neither
        // resurrects it nor writes a twin beside it.
        $deletedClone = $clones->firstWhere('title', 'Combinado com a turma');
        $this->inTenant($destination, fn () => $deletedClone->delete());

        $third = $this->uploadInto($this->rewriteBackup($file, fn (array $json): array => $json), $this->teacher, $destination);
        $thirdPlan = $this->planFor($third, $this->teacher, $destination);
        $this->assertSame(0, $thirdPlan['counts']['class_notebook_entries']['new']);
        $this->assertSame(2, $thirdPlan['counts']['class_notebook_entries']['existing']);
        $this->assertSame(1, $thirdPlan['counts']['class_notebook_entries']['conflict']);

        $conflict = collect($thirdPlan['rows']['class_notebook_entries'])->firstWhere('classification', 'conflict');
        $this->assertSame(self::REASON_DELETED, $conflict['reason']);
        $this->assertSame($deletedClone->id, $conflict['existing_id']);
        $this->assertSame(3, $this->entryCount($destination));
        $this->assertTrue($this->inTenant($destination, fn () => ClassNotebookEntry::withTrashed()->findOrFail($deletedClone->id)->trashed()));
    }

    #[Test]
    public function a_restore_into_an_institutional_organization_keeps_the_entries_with_the_importer_as_author(): void
    {
        $owner = User::factory()->withoutOrganization()->create();
        [$sourceOrg] = $this->institutionalOrganization($owner);
        ['class' => $sourceClass] = $this->fixture($owner, $sourceOrg);
        $file = $this->backupUpload($owner, $sourceOrg);

        [$destination] = $this->institutionalOrganization($owner);

        $import = $this->uploadInto($file, $owner, $destination);
        $this->confirm($import, $owner, $destination);

        $summary = $import->fresh()->summary;
        $this->assertSame(3, $summary['class_notebook_entries_created']);
        $this->assertSame(1, $summary['classes_needing_reassignment']);

        $this->inTenant($destination, function () use ($owner, $sourceClass): void {
            $class = SchoolClass::where('label', $sourceClass->label)->firstOrFail();
            $this->assertSame(0, $class->teachers()->count(), 'A turma criada no restauro fica sem professor.');
            $entries = ClassNotebookEntry::where('class_id', $class->id)->get();
            $this->assertCount(3, $entries);
            $this->assertSame([$owner->id], $entries->pluck('author_id')->unique()->values()->all());
        });
    }

    // --- Validation ---------------------------------------------------------------

    #[Test]
    public function malformed_rows_are_listed_with_a_fixed_reason_and_no_text_while_a_valid_row_still_restores(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['entries' => [$first]] = $this->fixture();
        $file = $this->backupUpload($this->teacher, $organization);
        $this->hardDeleteEntries($organization);

        $marker = 'MARCADOR-FICTICIO-NAO-MOSTRAR';

        $mutated = $this->rewriteBackup($file, function (array $json) use ($first, $marker): array {
            $good = collect($json['class_notebook_entries'])->firstWhere('ulid', $first->ulid);
            $variant = fn (array $changes): array => array_merge($good, ['ulid' => (string) Str::ulid(), 'body' => "{$marker} corpo"], $changes);

            $json['class_notebook_entries'] = [
                $good,
                $variant(['ulid' => 'not-a-ulid']),
                $variant(['class_ulid' => 'nope']),
                $variant(['body' => '']),
                $variant(['body' => "\u{00A0} \u{200B}\u{FEFF}"]),
                $variant(['body' => $marker.str_repeat('x', 20001)]),
                $variant(['body' => $marker.str_repeat('📘', 16380)]),
                $variant(['title' => $marker.str_repeat('t', 161)]),
                $variant(['title' => ['lista']]),
                $variant(['is_pinned' => 'sim']),
                $variant(['created_at' => 'ontem à tarde']),
                $variant(['edited_at' => 'amanhã']),
                array_merge($good, ['body' => "{$marker} segunda versão com o mesmo ulid"]),
            ];

            return $json;
        });

        $newAccount = $this->teacher;
        $import = $this->uploadInto($mutated, $newAccount, $organization);

        $this->assertStringNotContainsString($marker, (string) json_encode($import->canonical_snapshot), 'Nada recusado volta ao conteúdo canónico.');
        $this->assertCount(1, $import->canonical_snapshot['class_notebook_entries']);

        $expectedReasons = [
            'Registo do caderno com identificação ou turma em falta ou inválidas.',
            'Registo do caderno com identificação ou turma em falta ou inválidas.',
            'Registo do caderno sem texto.',
            'Registo do caderno sem texto.',
            'O texto deste registo do caderno ultrapassa o limite de 20 000 caracteres ou o espaço máximo de armazenamento.',
            'O texto deste registo do caderno ultrapassa o limite de 20 000 caracteres ou o espaço máximo de armazenamento.',
            'O título deste registo do caderno ultrapassa o limite de 160 caracteres.',
            'Registo do caderno com título inválido.',
            'Registo do caderno com o indicador de fixado inválido.',
            'Registo do caderno com data de criação, de alteração ou de edição inválida.',
            'Registo do caderno com data de criação, de alteração ou de edição inválida.',
            'Registo do caderno duplicado neste ficheiro.',
        ];

        foreach ([1, 2] as $visit) {
            $response = $this->actingAs($newAccount)->withSession(['organization_id' => $organization->id])
                ->get("/data-imports/{$import->ulid}")
                ->assertOk();

            $page = $response->viewData('page');
            $counts = data_get($page, 'props.plan.counts.class_notebook_entries');
            $this->assertSame(1, $counts['new'], "visita {$visit}");
            $this->assertSame(12, $counts['invalid'], "visita {$visit}");

            $reasons = collect(data_get($page, 'props.plan.rows.class_notebook_entries'))
                ->where('classification', 'invalid')->pluck('reason')->sort()->values()->all();
            $this->assertSame(collect($expectedReasons)->sort()->values()->all(), $reasons, "visita {$visit}");
            $this->assertStringNotContainsString($marker, (string) $response->getContent(), 'O texto recusado nunca aparece na pré-visualização.');
        }

        $this->confirm($import, $newAccount, $organization);

        $this->assertSame(1, $import->fresh()->summary['class_notebook_entries_created']);
        $this->inTenant($organization, function () use ($first): void {
            $this->assertSame(1, ClassNotebookEntry::count());
            $this->assertSame($first->body, ClassNotebookEntry::firstOrFail()->body);
        });
    }

    #[Test]
    public function an_empty_title_is_restored_as_no_title(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['entries' => [$first]] = $this->fixture();
        $file = $this->backupUpload($this->teacher, $organization);
        $this->hardDeleteEntries($organization);

        $mutated = $this->rewriteBackup($file, function (array $json) use ($first): array {
            $json['class_notebook_entries'] = collect($json['class_notebook_entries'])
                ->where('ulid', $first->ulid)
                ->map(fn (array $row): array => array_merge($row, ['title' => "  \t "]))
                ->values()->all();

            return $json;
        });

        $import = $this->uploadInto($mutated, $this->teacher, $organization);
        $this->confirm($import, $this->teacher, $organization);

        $this->assertNull($this->inTenant($organization, fn () => ClassNotebookEntry::where('ulid', $first->ulid)->firstOrFail()->title));
    }

    // --- Class and destination state --------------------------------------------------

    #[Test]
    public function an_entry_whose_class_cannot_be_restored_is_invalid(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['class' => $class, 'entries' => [$first, $second]] = $this->fixture();
        $file = $this->backupUpload($this->teacher, $organization);

        // The class was renamed after the export: it is a `conflict` now, and
        // the entries are gone, so nothing anchors them.
        $this->hardDeleteEntries($organization);
        $this->inTenant($organization, fn () => SchoolClass::whereKey($class->id)->update(['label' => '7.º Z renomeada']));

        $mutated = $this->rewriteBackup($file, function (array $json) use ($second): array {
            // One row points at a class that is not in the backup at all.
            $json['class_notebook_entries'] = collect($json['class_notebook_entries'])->map(function (array $row) use ($second): array {
                if ($row['ulid'] === $second->ulid) {
                    $row['class_ulid'] = (string) Str::ulid();
                }

                return $row;
            })->all();

            return $json;
        });

        $import = $this->uploadInto($mutated, $this->teacher, $organization);
        $plan = $this->planFor($import, $this->teacher, $organization);

        $this->assertSame('conflict', collect($plan['rows']['classes'])->firstWhere('ulid', $class->ulid)['classification']);

        foreach ($plan['rows']['class_notebook_entries'] as $row) {
            $this->assertSame('invalid', $row['classification']);
            $this->assertSame(self::REASON_CLASS, $row['reason']);
        }
        $this->assertSame(3, $plan['counts']['class_notebook_entries']['invalid']);
        $this->assertSame(0, $this->entryCount($organization));
    }

    #[Test]
    public function an_entry_ulid_that_exists_in_another_class_is_a_conflict_and_is_never_reassociated(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['class' => $class, 'entries' => [$first]] = $this->fixture();
        $otherClass = $this->classIn($organization, $this->teacher, '8.º Y fictício');
        $file = $this->backupUpload($this->teacher, $organization);

        $mutated = $this->rewriteBackup($file, function (array $json) use ($first, $otherClass): array {
            $json['class_notebook_entries'] = collect($json['class_notebook_entries'])->map(function (array $row) use ($first, $otherClass): array {
                if ($row['ulid'] === $first->ulid) {
                    $row['class_ulid'] = $otherClass->ulid;
                }

                return $row;
            })->all();

            return $json;
        });

        $import = $this->uploadInto($mutated, $this->teacher, $organization);
        $row = $this->planRow($this->planFor($import, $this->teacher, $organization), $first->ulid);

        $this->assertSame('conflict', $row['classification']);
        $this->assertSame(self::REASON_OTHER_CLASS, $row['reason']);
        $this->assertSame($class->id, $this->inTenant($organization, fn () => ClassNotebookEntry::findOrFail($first->id)->class_id));
    }

    #[Test]
    public function a_destination_entry_edited_after_the_export_is_a_conflict_and_is_never_overwritten(): void
    {
        $organization = $this->teacher->personalOrganization();
        ['entries' => [$first, $second, $third]] = $this->fixture();
        $file = $this->backupUpload($this->teacher, $organization);

        $this->inTenant($organization, function () use ($second, $third): void {
            ClassNotebookEntry::whereKey($second->id)->update(['body' => 'Texto revisto depois da exportação.']);
            ClassNotebookEntry::whereKey($third->id)->update(['is_pinned' => true]);
        });

        $import = $this->uploadInto($file, $this->teacher, $organization);
        $plan = $this->planFor($import, $this->teacher, $organization);

        $this->assertSame('existing', $this->planRow($plan, $first->ulid)['classification']);
        $this->assertSame('conflict', $this->planRow($plan, $second->ulid)['classification']);
        $this->assertSame('conflict', $this->planRow($plan, $third->ulid)['classification']);
        $this->assertNotNull($this->planRow($plan, $second->ulid)['reason']);

        $this->inTenant($organization, function () use ($second, $third): void {
            $this->assertSame('Texto revisto depois da exportação.', ClassNotebookEntry::findOrFail($second->id)->body);
            $this->assertTrue(ClassNotebookEntry::findOrFail($third->id)->is_pinned);
        });
    }
}
