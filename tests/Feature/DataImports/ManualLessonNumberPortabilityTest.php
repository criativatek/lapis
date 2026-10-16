<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicYear;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;
use ZipArchive;

/**
 * Schema v18 (0.169.0) — o número da lição indicado pelo professor viaja no
 * backup: `lessons[].lesson_number_end` e `lessons[].lesson_number_manual`.
 *
 * Copiados tal como estão, nunca renumerados: um «11–12» manual exporta-se e
 * restaura-se igual; um backup anterior, sem as chaves, restaura-se sem fim e
 * sem marca; um fim que não é posterior ao início deixa a linha inválida.
 */
class ManualLessonNumberPortabilityTest extends TestCase
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

    private function inOrganization(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    #[Test]
    public function a_manual_range_survives_an_export_and_a_restore_untouched(): void
    {
        $this->scenario();

        $backup = $this->backupUpload();
        $json = $this->backupJson($backup);
        $this->assertSame(18, $json['schema_version']);

        $rows = collect($json['lessons'])->keyBy('lesson_number');
        $this->assertSame(12, $rows[11]['lesson_number_end']);
        $this->assertTrue($rows[11]['lesson_number_manual']);
        $this->assertNull($rows[13]['lesson_number_end']);
        $this->assertFalse($rows[13]['lesson_number_manual']);

        $colleague = User::factory()->create(['email' => 'colega-numeracao@example.test']);
        $destination = $colleague->personalOrganization();
        $this->confirmAs($destination, $colleague, $this->uploadIntoAs($destination, $colleague, $backup));

        $this->inOrganization($destination, function (): void {
            $lessons = Lesson::query()->orderBy('starts_at')->get();
            $this->assertCount(2, $lessons);
            $this->assertSame([11, 13], $lessons->pluck('lesson_number')->all());
            $this->assertSame([12, null], $lessons->pluck('lesson_number_end')->all());
            $this->assertSame([true, false], $lessons->pluck('lesson_number_manual')->all());
            $this->assertSame('11–12', $lessons[0]->lessonNumberLabel());
        });
    }

    #[Test]
    public function a_backup_older_than_v18_restores_without_an_end_and_not_manual(): void
    {
        $this->scenario();
        $legacy = $this->rewriteBackup($this->backupUpload(), function (array $json): array {
            $json['schema_version'] = 17;

            foreach ($json['lessons'] as $index => $row) {
                unset($json['lessons'][$index]['lesson_number_end'], $json['lessons'][$index]['lesson_number_manual']);
            }

            return $json;
        });

        $colleague = User::factory()->create(['email' => 'colega-v17@example.test']);
        $destination = $colleague->personalOrganization();
        $this->assertSame(BackupSchemaCompatibility::LegacyCompatible, BackupSchemaCompatibility::for(17));
        $this->confirmAs($destination, $colleague, $this->uploadIntoAs($destination, $colleague, $legacy));

        $this->inOrganization($destination, function (): void {
            $lessons = Lesson::query()->orderBy('starts_at')->get();
            $this->assertCount(2, $lessons);
            $this->assertSame([11, 13], $lessons->pluck('lesson_number')->all());
            $this->assertSame([null, null], $lessons->pluck('lesson_number_end')->all());
            $this->assertSame([false, false], $lessons->pluck('lesson_number_manual')->all());
        });
    }

    #[Test]
    public function an_end_that_is_not_after_the_start_makes_the_row_invalid_and_it_is_never_written(): void
    {
        $this->scenario();
        $tampered = $this->rewriteBackup($this->backupUpload(), function (array $json): array {
            foreach ($json['lessons'] as $index => $row) {
                if ($row['lesson_number'] === 11) {
                    $json['lessons'][$index]['lesson_number_end'] = 11;
                }
            }

            return $json;
        });

        $colleague = User::factory()->create(['email' => 'colega-fim@example.test']);
        $destination = $colleague->personalOrganization();
        $this->confirmAs($destination, $colleague, $this->uploadIntoAs($destination, $colleague, $tampered));

        $this->inOrganization($destination, function (): void {
            $this->assertSame([13], Lesson::query()->pluck('lesson_number')->all());
        });
    }

    #[Test]
    public function a_manual_interval_that_overlaps_an_earlier_manual_one_in_the_same_class_is_invalid(): void
    {
        $this->scenario();
        $tampered = $this->rewriteBackup($this->backupUpload(), function (array $json): array {
            foreach ($json['lessons'] as $index => $row) {
                if ($row['lesson_number'] === 13) {
                    // 12 cai dentro de 11–12, da aula anterior.
                    $json['lessons'][$index]['lesson_number'] = 12;
                    $json['lessons'][$index]['lesson_number_manual'] = true;
                }
            }

            return $json;
        });

        $colleague = User::factory()->create(['email' => 'colega-sobreposto@example.test']);
        $destination = $colleague->personalOrganization();
        $this->confirmAs($destination, $colleague, $this->uploadIntoAs($destination, $colleague, $tampered));

        $this->inOrganization($destination, function (): void {
            $this->assertSame([11], Lesson::query()->pluck('lesson_number')->all());
        });
    }

    #[Test]
    public function the_backup_applies_the_same_bounds_as_the_form(): void
    {
        $this->scenario();

        foreach ([
            'span of eleven lessons' => fn (array &$row) => $row['lesson_number_end'] = 22,
            'manual number above 9999' => function (array &$row): void {
                $row['lesson_number'] = 10000;
                $row['lesson_number_end'] = null;
            },
            'manual number zero' => function (array &$row): void {
                $row['lesson_number'] = 0;
                $row['lesson_number_end'] = null;
            },
        ] as $label => $mutate) {
            $tampered = $this->rewriteBackup($this->backupUpload(), function (array $json) use ($mutate): array {
                foreach ($json['lessons'] as $index => $row) {
                    if ($row['lesson_number'] === 11) {
                        $mutate($json['lessons'][$index]);
                    }
                }

                return $json;
            });

            $colleague = User::factory()->create();
            $destination = $colleague->personalOrganization();
            $this->confirmAs($destination, $colleague, $this->uploadIntoAs($destination, $colleague, $tampered));

            $this->inOrganization($destination, function () use ($label): void {
                $this->assertSame([13], Lesson::query()->pluck('lesson_number')->all(), $label);
            });
        }

        // Dez lições (11 a 20) é o máximo e passa.
        $limit = $this->rewriteBackup($this->backupUpload(), function (array $json): array {
            foreach ($json['lessons'] as $index => $row) {
                if ($row['lesson_number'] === 11) {
                    $json['lessons'][$index]['lesson_number_end'] = 20;
                }
            }

            return $json;
        });
        $colleague = User::factory()->create();
        $destination = $colleague->personalOrganization();
        $this->confirmAs($destination, $colleague, $this->uploadIntoAs($destination, $colleague, $limit));
        $this->inOrganization($destination, fn () => $this->assertSame('11–20', Lesson::query()->orderBy('starts_at')->firstOrFail()->lessonNumberLabel()));
    }

    private function scenario(): void
    {
        app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), function (): void {
            $organization = $this->teacher->personalOrganization();
            $year = AcademicYear::factory()->recycle($organization)->create([
                'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
            ]);
            $subject = Subject::factory()->recycle($organization)->create(['name' => 'Matemática']);
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '7.º A numeração',
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            Lesson::create([
                'class_id' => $class->id, 'starts_at' => '2025-10-02 09:00:00', 'ends_at' => '2025-10-02 10:00:00',
                'status' => LessonStatus::Preparation, 'created_by' => $this->teacher->id,
                'lesson_number' => 11, 'lesson_number_end' => 12, 'lesson_number_manual' => true,
            ]);
            Lesson::create([
                'class_id' => $class->id, 'starts_at' => '2025-10-09 09:00:00', 'ends_at' => '2025-10-09 10:00:00',
                'status' => LessonStatus::Preparation, 'created_by' => $this->teacher->id,
                'lesson_number' => 13,
            ]);
        });
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

    private function backupUpload(): UploadedFile
    {
        $organization = $this->teacher->personalOrganization();
        $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->teacher->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    private function uploadIntoAs(Organization $organization, User $actor, UploadedFile $file): DataImport
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

    private function confirmAs(Organization $organization, User $actor, DataImport $import): void
    {
        $this->actingAs($actor)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function rewriteBackup(UploadedFile $file, callable $mutate): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'lapis-number-backup-').'.zip';
        copy($file->getPathname(), $copy);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($copy));
        $json = $mutate(json_decode((string) $zip->getFromName('backup-lapis.json'), true));
        $zip->addFromString('backup-lapis.json', (string) json_encode($json));
        $zip->close();

        return new UploadedFile($copy, 'backup.zip', 'application/zip', null, true);
    }
}
