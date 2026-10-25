<?php

namespace Tests\Feature\DataImports;

use App\Models\Classification;
use App\Models\DataExport;
use App\Models\DataImportStatus;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use App\Services\Import\Backup\BuildImportPlan;
use App\Services\Import\Backup\ValidateBackupPayload;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsRectificationChainBackups;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;

/**
 * Schema v20 — the chain of rectifications in the backup and in the restore.
 *
 * SQLite does not enforce the table's CHECKs or its «one live row» index, so what
 * is proved here is the data: what the file carries, what the validator refuses
 * and what the restore writes. That the SEQUENCE the restore uses is one MySQL
 * accepts is proved by BackupRectificationChainMysqlTest, on a real server.
 */
class ClassificationRectificationPortabilityTest extends TestCase
{
    use BuildsRectificationChainBackups;
    use RefreshDatabase;
    use SubscribesOrganizations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->teacher = User::factory()->create();
    }

    /** @return array<string, mixed> */
    private function sourceBackupJson(): array
    {
        $this->chainWorld($this->teacher);

        return $this->backupJson($this->exportBackup($this->teacher, $this->teacher->personalOrganization()));
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $json, string $student): array
    {
        $enrollmentUlid = collect($json['enrollments'])->first(function (array $enrollment) use ($json, $student): bool {
            $row = collect($json['students'])->firstWhere('ulid', $enrollment['student_ulid']);

            return $row['display_name'] === $student;
        })['ulid'];

        return collect($json['classifications'])->where('enrollment_ulid', $enrollmentUlid)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{canonical: array<string, mixed>, rowIssues: list<array{domain: string, ulid: string|null, reason: string}>}
     */
    private function validated(array $json): array
    {
        return app(ValidateBackupPayload::class)->validate((string) json_encode($json));
    }

    // ------------------------------------------------------------- the file

    #[Test]
    public function the_backup_carries_every_row_of_the_chain_and_the_five_new_fields(): void
    {
        $json = $this->sourceBackupJson();

        $this->assertSame(20, BackupSchemaCompatibility::CURRENT);
        $this->assertSame(20, $json['schema_version']);

        $ana = $this->rowsOf($json, 'Ana');
        $this->assertCount(3, $ana);
        $this->assertSame(['superseded', 'superseded', 'published'], array_column($ana, 'status'));

        [$first, $second, $third] = $ana;
        $this->assertNull($first['rectifies_ulid']);
        $this->assertNull($first['rectification_reason']);
        $this->assertNull($first['rectified_at']);
        $this->assertSame($second['ulid'], $first['superseded_by_ulid']);
        $this->assertSame($first['ulid'], $second['rectifies_ulid']);
        $this->assertSame($third['ulid'], $second['superseded_by_ulid']);
        $this->assertSame($second['ulid'], $third['rectifies_ulid']);
        $this->assertNull($third['superseded_by_ulid']);
        $this->assertSame('entry_error', $second['rectification_reason']);
        $this->assertSame('other', $third['rectification_reason']);
        $this->assertSame('Reapreciação em conselho de turma.', $third['rectification_note']);
        $this->assertSame($this->teacher->email, $third['rectified_by_email']);
        $this->assertSame('2025-12-20', substr($third['rectified_at'], 0, 10));

        $carla = $this->rowsOf($json, 'Carla');
        $this->assertCount(1, $carla);
        $this->assertNull($carla[0]['rectifies_ulid']);
        $this->assertNull($carla[0]['rectified_by_email']);
    }

    #[Test]
    public function the_readable_export_shows_one_classification_per_cell_not_the_history(): void
    {
        $this->chainWorld($this->teacher);
        $organization = $this->teacher->personalOrganization();

        $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->teacher->id)->firstOrFail();
        $spreadsheet = IOFactory::load($this->extractWorkbook($export));
        $sheet = $spreadsheet->getSheetByName('Classificações');

        // Three students, three lines (plus the header) — Ana's three decisions
        // are one classification, the live one.
        $this->assertSame(4, $sheet->getHighestDataRow());
        $summary = $spreadsheet->getSheetByName('Resumo');
        $found = false;

        foreach ($summary->toArray() as $line) {
            if (($line[0] ?? null) === 'Nº de classificações') {
                $this->assertSame(3, (int) $line[1]);
                $found = true;
            }
        }

        $this->assertTrue($found);
    }

    private function extractWorkbook(DataExport $export): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->disk_path)));
        $target = tempnam(sys_get_temp_dir(), 'lapis-chain-xlsx-').'.xlsx';

        foreach (range(0, $zip->numFiles - 1) as $index) {
            $name = (string) $zip->getNameIndex($index);

            if (str_ends_with($name, '.xlsx')) {
                file_put_contents($target, $zip->getFromIndex($index));
            }
        }

        $zip->close();
        $this->assertFileExists($target);

        return $target;
    }

    // ------------------------------------------------------- the round trip

    #[Test]
    public function a_chain_round_trips_into_another_account_with_the_restorer_as_author(): void
    {
        $this->chainWorld($this->teacher);
        $origin = $this->chainsOf($this->teacher->personalOrganization());
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());

        $other = User::factory()->create();
        $destination = $other->personalOrganization();
        $import = $this->uploadInto($file, $other, $destination);
        $this->confirmImport($import, $other, $destination);

        $restored = $this->chainsOf($destination);

        $this->assertSame(['Ana', 'Bruno', 'Carla'], array_keys($restored));
        $this->assertCount(3, $restored['Ana']);
        $this->assertCount(2, $restored['Bruno']);
        $this->assertCount(1, $restored['Carla']);
        $this->assertSame(['superseded', 'superseded', 'published'], array_column($restored['Ana'], 'status'));
        $this->assertSame(1, collect($restored['Ana'])->where('status', '!=', 'superseded')->count());
        $this->assertSame(0, $this->selfPointers($destination));

        // Everything but who is the same, row by row.
        $strip = fn (array $chains): array => collect($chains)->map(fn (array $rows) => collect($rows)->map(
            fn (array $row) => collect($row)->except(['rectified_by', 'confirmed_by'])->all()
        )->all())->all();
        $this->assertSame($strip($origin), $strip($restored));

        // The author a rectification cannot do without is the account that restored.
        $this->assertSame([null, $other->email, $other->email], array_column($restored['Ana'], 'rectified_by'));
        $this->assertSame(3, $import->fresh()->summary['rectifications_in_restorer_name']);
        $this->assertSame(DataImportStatus::Imported, $import->fresh()->status);
    }

    #[Test]
    public function the_same_person_restoring_into_another_of_their_organizations_keeps_the_author(): void
    {
        $this->chainWorld($this->teacher);
        $origin = $this->chainsOf($this->teacher->personalOrganization());
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());

        $destination = Organization::factory()->withMember($this->teacher)->create(['owner_id' => $this->teacher->id, 'name' => 'Outra']);
        $import = $this->uploadInto($file, $this->teacher, $destination);
        $this->confirmImport($import, $this->teacher, $destination);

        $restored = $this->chainsOf($destination);

        // Whole equality: the rectifier, the confirmer, the dates, the notes, the
        // lock versions — and the live row is the third.
        $this->assertSame($origin, $restored);
        $this->assertSame(0, $this->selfPointers($destination));
        $this->assertSame(0, $import->fresh()->summary['rectifications_in_restorer_name']);
    }

    #[Test]
    public function the_order_of_the_rows_in_the_file_does_not_matter(): void
    {
        $this->chainWorld($this->teacher);
        $origin = $this->chainsOf($this->teacher->personalOrganization());
        $file = $this->rewriteBackup(
            $this->exportBackup($this->teacher, $this->teacher->personalOrganization()),
            function (array $json): array {
                $json['classifications'] = array_reverse($json['classifications']);

                return $json;
            },
        );

        $destination = Organization::factory()->withMember($this->teacher)->create(['owner_id' => $this->teacher->id, 'name' => 'Ao contrário']);
        $this->confirmImport($this->uploadInto($file, $this->teacher, $destination), $this->teacher, $destination);

        $this->assertSame($origin, $this->chainsOf($destination));
        $this->assertSame(0, $this->selfPointers($destination));
    }

    #[Test]
    public function restoring_the_same_backup_twice_changes_nothing(): void
    {
        $this->chainWorld($this->teacher);
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());

        $other = User::factory()->create();
        $destination = $other->personalOrganization();
        $this->confirmImport($this->uploadInto($file, $other, $destination), $other, $destination);
        $before = $this->chainsOf($destination);

        $second = $this->uploadInto($file, $other, $destination);
        $plan = $this->inTenant($destination, fn (): array => app(BuildImportPlan::class)->build($second->canonical_snapshot ?? [], $destination, $other, []));

        $this->assertSame(0, collect($plan['rows']['classifications'])->where('classification', 'new')->count());
        $this->assertSame($before, $this->chainsOf($destination));
    }

    #[Test]
    public function a_backup_without_the_new_fields_still_restores_as_plain_classifications(): void
    {
        $json = $this->sourceBackupJson();
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());

        // What a v19 file looked like: no superseded rows existed, and none of
        // the five keys.
        $legacy = $this->rewriteBackup($file, function (array $json): array {
            $json['schema_version'] = 19;
            $json['classifications'] = collect($json['classifications'])
                ->where('status', '!=', 'superseded')
                ->map(fn (array $row): array => collect($row)->except(['rectifies_ulid', 'rectification_reason', 'rectification_note', 'rectified_by_email', 'rectified_at'])->put('superseded_by_ulid', null)->all())
                ->values()->all();

            return $json;
        });

        $this->assertCount(6, $json['classifications']);

        $other = User::factory()->create();
        $destination = $other->personalOrganization();
        $this->confirmImport($this->uploadInto($legacy, $other, $destination), $other, $destination);

        $restored = $this->chainsOf($destination);
        $this->assertSame(['published'], array_unique(array_column(array_merge(...array_values($restored)), 'status')));
        $this->assertCount(1, $restored['Ana']);
        $this->assertSame([null], array_column($restored['Ana'], 'rectified_by'));
        $this->assertSame(0, $this->selfPointers($destination));
    }

    #[Test]
    public function an_unfinished_chain_leaves_the_destination_exactly_as_it_was(): void
    {
        $this->chainWorld($this->teacher);
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());

        $other = User::factory()->create();
        $destination = $other->personalOrganization();
        $import = $this->uploadInto($file, $other, $destination);

        // The third decision of Ana's chain fails to be written: the first two
        // already are, and so is everything the restore wrote before them.
        $dispatcher = Classification::getEventDispatcher();
        $dispatcher->listen('eloquent.created: '.Classification::class, function (Classification $classification): void {
            if ($classification->rectification_reason?->value === 'other') {
                throw new \RuntimeException('Falha injetada a meio da cadeia.');
            }
        });

        try {
            $this->actingAs($other)->withSession(['organization_id' => $destination->id])
                ->post("/data-imports/{$import->ulid}/confirm");
        } finally {
            $dispatcher->forget('eloquent.created: '.Classification::class);
        }

        $this->assertSame(DataImportStatus::Failed, $import->fresh()->status);
        $this->assertSame([], $this->chainsOf($destination));
        $this->assertSame(0, $this->inTenant($destination, fn (): int => Student::query()->count()));
        $this->assertSame(0, $this->inTenant($destination, fn (): int => Enrollment::query()->count()));
    }

    #[Test]
    public function a_chain_the_destination_cannot_hold_whole_is_not_half_restored(): void
    {
        $json = $this->sourceBackupJson();
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());
        $other = User::factory()->create();
        $destination = $other->personalOrganization();

        // First the chain as it was before Ana's last rectification…
        $ana = collect($this->rowsOf($json, 'Ana'));
        $short = $this->rewriteBackup($file, function (array $json) use ($ana): array {
            $json['classifications'] = collect($json['classifications'])
                ->reject(fn (array $row): bool => $row['ulid'] === $ana[2]['ulid'])
                ->map(function (array $row) use ($ana): array {
                    if ($row['ulid'] === $ana[1]['ulid']) {
                        $row['status'] = 'published';
                        $row['superseded_by_ulid'] = null;
                    }

                    return $row;
                })->values()->all();

            return $json;
        });
        $this->confirmImport($this->uploadInto($short, $other, $destination), $other, $destination);
        $this->assertCount(2, $this->chainsOf($destination)['Ana']);
        $before = $this->chainsOf($destination);

        // …then the whole one: the destination already holds the first two
        // (the second one live), so the third has no place and nothing is added.
        $whole = $this->uploadInto($file, $other, $destination);
        $plan = $this->inTenant($destination, fn (): array => app(BuildImportPlan::class)->build($whole->canonical_snapshot ?? [], $destination, $other, []));
        $verdict = collect($plan['rows']['classifications'])->keyBy('ulid');

        $this->assertSame('conflict', $verdict[$ana[2]['ulid']]['classification']);
        $this->assertSame($before, $this->chainsOf($destination));
    }

    #[Test]
    public function a_chain_for_a_cell_the_destination_already_decided_is_a_conflict_never_a_second_live_row(): void
    {
        $json = $this->sourceBackupJson();
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());
        $other = User::factory()->create();
        $destination = $other->personalOrganization();
        $ana = collect($this->rowsOf($json, 'Ana'));

        // The destination takes the first two decisions of Ana's chain…
        $short = $this->rewriteBackup($file, function (array $json) use ($ana): array {
            $json['classifications'] = collect($json['classifications'])
                ->reject(fn (array $row): bool => $row['ulid'] === $ana[2]['ulid'])
                ->map(function (array $row) use ($ana): array {
                    if ($row['ulid'] === $ana[1]['ulid']) {
                        $row['status'] = 'published';
                        $row['superseded_by_ulid'] = null;
                    }

                    return $row;
                })->values()->all();

            return $json;
        });
        $this->confirmImport($this->uploadInto($short, $other, $destination), $other, $destination);

        // …and then receives the whole chain under identities of its own, so
        // nothing recognises it by ulid and only the cell can say it is taken.
        $renamed = $this->rewriteBackup($file, function (array $json) use ($ana): array {
            $map = $ana->mapWithKeys(fn (array $row): array => [$row['ulid'] => (string) Str::ulid()])->all();
            $json['classifications'] = collect($json['classifications'])->map(function (array $row) use ($map): array {
                foreach (['ulid', 'superseded_by_ulid', 'rectifies_ulid'] as $key) {
                    $row[$key] = $map[$row[$key] ?? ''] ?? $row[$key];
                }

                return $row;
            })->all();

            return $json;
        });
        $import = $this->uploadInto($renamed, $other, $destination);
        $plan = $this->inTenant($destination, fn (): array => app(BuildImportPlan::class)->build($import->canonical_snapshot ?? [], $destination, $other, []));
        $verdicts = collect($plan['rows']['classifications']);

        $this->assertSame(['conflict'], $verdicts->whereIn('ulid', collect($import->canonical_snapshot['classifications'])->where('status', 'superseded')->pluck('ulid'))->pluck('classification')->unique()->values()->all());
        $this->assertSame(0, $verdicts->where('classification', 'new')->count());
    }

    // ------------------------------------------------------- the validator

    /**
     * @return array<string, array{0: callable(array<string, mixed>, array<int, array<string, mixed>>, array<int, array<string, mixed>>): array<string, mixed>, 1: string, 2: int}>
     */
    public static function refusals(): array
    {
        return [
            'a reason that does not exist' => [function (array $json, array $ana): array {
                return self::edit($json, $ana[1]['ulid'], ['rectification_reason' => 'because']);
            }, 'Ana', 1],
            'a rectification without its date' => [function (array $json, array $ana): array {
                return self::edit($json, $ana[2]['ulid'], ['rectified_at' => null]);
            }, 'Ana', 1],
            'a rectification without its author' => [function (array $json, array $ana): array {
                return self::edit($json, $ana[1]['ulid'], ['rectified_by_email' => null]);
            }, 'Ana', 1],
            'a note on something that rectifies nothing' => [function (array $json, array $ana, array $bruno, array $carla): array {
                return self::edit($json, $carla[0]['ulid'], ['rectification_note' => 'solta']);
            }, 'Carla', 1],
            'a rectified row that is not in the file' => [function (array $json, array $ana): array {
                return self::edit($json, $ana[1]['ulid'], ['rectifies_ulid' => (string) Str::ulid()]);
            }, 'Ana', 1],
            'a successor that is not in the file' => [function (array $json, array $ana): array {
                return self::edit($json, $ana[0]['ulid'], ['superseded_by_ulid' => (string) Str::ulid()]);
            }, 'Ana', 1],
            'a superseded row without a successor' => [function (array $json, array $ana, array $bruno): array {
                return self::edit($json, $bruno[0]['ulid'], ['superseded_by_ulid' => null]);
            }, 'Bruno', 1],
            'a successor on a row that is not superseded' => [function (array $json, array $ana, array $bruno): array {
                return self::edit($json, $bruno[1]['ulid'], ['superseded_by_ulid' => $bruno[0]['ulid']]);
            }, 'Bruno', 1],
            'a row that replaces itself' => [function (array $json, array $ana): array {
                return self::edit($json, $ana[0]['ulid'], ['superseded_by_ulid' => $ana[0]['ulid']]);
            }, 'Ana', 1],
            'two live rows in one cell' => [function (array $json, array $ana, array $bruno, array $carla): array {
                $twin = $carla[0];
                $twin['ulid'] = (string) Str::ulid();
                $json['classifications'][] = $twin;

                return $json;
            }, 'Carla', 1],
            'a cycle' => [function (array $json, array $ana, array $bruno): array {
                $json = self::edit($json, $bruno[0]['ulid'], ['rectifies_ulid' => $bruno[1]['ulid'], 'rectification_reason' => 'other', 'rectified_by_email' => 'a@b.test', 'rectified_at' => '2025-12-19T09:00:00+00:00']);

                return self::edit($json, $bruno[1]['ulid'], ['status' => 'superseded', 'superseded_by_ulid' => $bruno[0]['ulid']]);
            }, 'Bruno', 1],
            'two rows claiming the same predecessor' => [function (array $json, array $ana): array {
                $branch = $ana[1];
                $branch['ulid'] = (string) Str::ulid();
                $branch['status'] = 'published';
                $branch['superseded_by_ulid'] = null;
                $json['classifications'][] = $branch;

                return $json;
            }, 'Ana', 1],
            'a rectification that is not published' => [function (array $json, array $ana): array {
                return self::edit($json, $ana[2]['ulid'], ['status' => 'confirmed']);
            }, 'Ana', 1],
            'a pointer into another cell' => [function (array $json, array $ana, array $bruno): array {
                return self::edit($json, $ana[0]['ulid'], ['superseded_by_ulid' => $bruno[1]['ulid']]);
            }, 'Ana', 1],
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function edit(array $json, string $ulid, array $changes): array
    {
        $json['classifications'] = collect($json['classifications'])
            ->map(fn (array $row): array => $row['ulid'] === $ulid ? array_merge($row, $changes) : $row)
            ->all();

        return $json;
    }

    /**
     * @param  callable(array<string, mixed>, array<int, array<string, mixed>>, array<int, array<string, mixed>>, array<int, array<string, mixed>>): array<string, mixed>  $mutate
     */
    #[Test]
    #[DataProvider('refusals')]
    public function the_validator_refuses_what_the_table_would_refuse(callable $mutate, string $student, int $minimumIssues): void
    {
        $json = $this->sourceBackupJson();
        $edited = $mutate($json, $this->rowsOf($json, 'Ana'), $this->rowsOf($json, 'Bruno'), $this->rowsOf($json, 'Carla'));

        $result = $this->validated($edited);
        $kept = collect($result['canonical']['classifications']);
        $studentRows = collect($this->rowsOf($edited, $student))->pluck('ulid');

        // The student whose cell is wrong has NO classification restored — not a
        // partial chain, not just the live row…
        $this->assertSame(0, $kept->whereIn('ulid', $studentRows)->count(), "restored a row of {$student}");
        $this->assertGreaterThanOrEqual($minimumIssues, collect($result['rowIssues'])->where('domain', 'classifications')->count());

        // …and the other students' cells are untouched.
        $others = collect(['Ana', 'Bruno', 'Carla'])->reject(fn (string $name): bool => $name === $student)
            ->flatMap(fn (string $name) => collect($this->rowsOf($edited, $name))->pluck('ulid'));
        $this->assertSame($others->sort()->values()->all(), $kept->pluck('ulid')->sort()->values()->all());
    }

    #[Test]
    public function an_untouched_backup_validates_with_every_row_and_no_issue(): void
    {
        $result = $this->validated($this->sourceBackupJson());

        $this->assertCount(6, $result['canonical']['classifications']);
        $this->assertSame([], collect($result['rowIssues'])->where('domain', 'classifications')->all());
    }
}
