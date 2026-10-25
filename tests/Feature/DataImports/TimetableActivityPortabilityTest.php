<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicYear;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TimetableActivity;
use App\Models\User;
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
 * Schema v17 — the non-teaching activities of the timetable in the backup.
 * A REAL export, the zip's backup-lapis.json mutated in place when a scenario
 * needs a specific shape, then a REAL upload / confirm.
 *
 * Personal to one teacher, like the notebook: an export carries only the
 * exporter's own activities, an import restores only rows signed by the account
 * confirming it, and nothing here ever creates a lesson.
 */
class TimetableActivityPortabilityTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    private User $teacher;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
    }

    #[Test]
    public function the_backup_carries_exactly_the_documented_fields_of_the_exporters_own_activities(): void
    {
        $class = $this->classFor($this->teacher);
        $mine = $this->activity($this->teacher, ['title' => 'DT 6.º D', 'kind' => 'class_direction', 'class_id' => $class->id, 'starts_on' => '2026-09-14', 'ends_on' => '2027-06-30']);

        $backup = $this->backupJson($this->backupUpload($this->teacher));

        $this->assertSame(20, BackupSchemaCompatibility::CURRENT);
        $this->assertSame(20, $backup['schema_version']);
        $this->assertContains('timetable_activities', $backup['capabilities']);
        $this->assertCount(1, $backup['timetable_activities']);

        $row = $backup['timetable_activities'][0];
        $this->assertSame(
            ['ulid', 'author_email', 'kind', 'title', 'class_ulid', 'day_of_week', 'starts_at', 'ends_at', 'starts_on', 'ends_on', 'numbering_enabled', 'numbering_start'],
            array_keys($row),
        );
        $this->assertSame($mine->ulid, $row['ulid']);
        $this->assertSame($this->teacher->email, $row['author_email']);
        $this->assertSame('class_direction', $row['kind']);
        $this->assertSame($class->ulid, $row['class_ulid']);
        $this->assertSame('10:30', $row['starts_at']);
        $this->assertSame('11:20', $row['ends_at']);
        $this->assertSame('2026-09-14', $row['starts_on']);

        foreach (['id', 'organization_id', 'user_id', 'class_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
    }

    #[Test]
    public function a_colleagues_activities_never_leave_in_my_export(): void
    {
        $this->activity($this->teacher, ['title' => 'Minha']);

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->activity($colleague, ['title' => 'Do colega']);

        $titles = collect($this->backupJson($this->backupUpload($this->teacher))['timetable_activities'])->pluck('title')->all();

        $this->assertSame(['Minha'], $titles);
    }

    #[Test]
    public function a_round_trip_restores_the_activities_with_their_class_and_creates_no_lesson(): void
    {
        $class = $this->classFor($this->teacher);
        $this->activity($this->teacher, ['title' => 'DT 6.º D', 'kind' => 'class_direction', 'class_id' => $class->id]);
        $this->activity($this->teacher, ['title' => 'RTC', 'kind' => 'coordination', 'day_of_week' => 2, 'starts_at' => '14:20', 'ends_at' => '15:10', 'starts_on' => '2026-09-14']);

        $file = $this->backupUpload($this->teacher);
        $this->inTenant(fn () => TimetableActivity::query()->delete());

        $this->confirm($this->upload($file));

        $this->inTenant(function () use ($class): void {
            $this->assertSame(2, TimetableActivity::query()->count());

            $dt = TimetableActivity::query()->where('title', 'DT 6.º D')->sole();
            $this->assertSame($this->teacher->id, $dt->user_id);
            $this->assertSame($class->id, $dt->class_id);
            $this->assertSame('class_direction', $dt->kind->value);

            $rtc = TimetableActivity::query()->where('title', 'RTC')->sole();
            $this->assertNull($rtc->class_id);
            $this->assertSame('2026-09-14', $rtc->starts_on->toDateString());
            $this->assertStringStartsWith('14:20', $rtc->starts_at);

            $this->assertSame(0, Lesson::query()->count());
        });
    }

    #[Test]
    public function restoring_the_same_backup_again_creates_no_duplicate(): void
    {
        $this->activity($this->teacher, ['title' => 'DT 6.º D']);
        $file = $this->backupUpload($this->teacher);

        $this->confirm($this->upload($file));

        $this->assertSame(1, $this->inTenant(fn (): int => TimetableActivity::query()->count()));
    }

    #[Test]
    public function an_activity_restored_under_a_fresh_ulid_is_recognised_by_what_it_is(): void
    {
        $this->activity($this->teacher, ['title' => 'DT 6.º D']);
        $file = $this->rewriteBackup($this->backupUpload($this->teacher), function (array $json): array {
            $json['timetable_activities'][0]['ulid'] = '01JZZZZZZZZZZZZZZZZZZZZZZZ';

            return $json;
        });

        $this->confirm($this->upload($file));

        $this->assertSame(1, $this->inTenant(fn (): int => TimetableActivity::query()->count()));
    }

    #[Test]
    public function a_row_signed_by_another_account_is_not_restored(): void
    {
        $this->activity($this->teacher, ['title' => 'DT 6.º D']);
        $file = $this->rewriteBackup($this->backupUpload($this->teacher), function (array $json): array {
            $json['timetable_activities'][0]['author_email'] = 'outra.pessoa@example.test';
            $json['timetable_activities'][0]['ulid'] = '01JYYYYYYYYYYYYYYYYYYYYYYY';

            return $json;
        });

        $this->confirm($this->upload($file));

        $this->assertSame(1, $this->inTenant(fn (): int => TimetableActivity::query()->count()));
        $this->assertSame(0, $this->inTenant(fn (): int => TimetableActivity::query()->where('ulid', '01JYYYYYYYYYYYYYYYYYYYYYYY')->count()));
    }

    #[Test]
    public function an_older_backup_without_the_collection_still_restores(): void
    {
        $this->classFor($this->teacher);
        $file = $this->rewriteBackup($this->backupUpload($this->teacher), function (array $json): array {
            unset($json['timetable_activities']);
            $json['schema_version'] = 16;

            return $json;
        });

        $this->confirm($this->upload($file));

        $this->assertSame(0, $this->inTenant(fn (): int => TimetableActivity::query()->count()));
    }

    #[Test]
    public function an_invalid_row_is_reported_with_a_fixed_sentence_and_never_the_title(): void
    {
        $this->activity($this->teacher, ['title' => 'DT 6.º D']);
        $file = $this->rewriteBackup($this->backupUpload($this->teacher), function (array $json): array {
            $json['timetable_activities'][0]['day_of_week'] = 9;
            $json['timetable_activities'][0]['title'] = 'Título secreto';

            return $json;
        });

        $import = $this->upload($file);
        $snapshot = $import->canonical_snapshot;

        $this->assertSame([], $snapshot['timetable_activities']);
        $this->assertStringNotContainsString('Título secreto', (string) json_encode($import->preview ?? $import->summary ?? []));
    }

    // ---------------------------------------------------------------- helpers

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    private function classFor(User $teacher): SchoolClass
    {
        return $this->inTenant(function () use ($teacher): SchoolClass {
            $year = AcademicYear::factory()->recycle($this->organization)->create([
                'label' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30',
            ]);
            $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Matemática Fictícia']);
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '6.º D',
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            return $class->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activity(User $owner, array $attributes = []): TimetableActivity
    {
        return $this->inTenant(fn (): TimetableActivity => TimetableActivity::create([
            'user_id' => $owner->id,
            'kind' => 'other',
            'title' => 'RTC',
            'day_of_week' => 2,
            'starts_at' => '10:30',
            'ends_at' => '11:20',
            ...$attributes,
        ]));
    }

    private function backupUpload(User $user): UploadedFile
    {
        $this->subscribeOrganizationTo($this->organization, 'pro');
        $this->actingAs($user)->withSession(['organization_id' => $this->organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $user->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function rewriteBackup(UploadedFile $file, callable $mutate): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'lapis-timetable-activity-backup-').'.zip';
        copy($file->getPathname(), $copy);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($copy));
        $json = $mutate(json_decode((string) $zip->getFromName('backup-lapis.json'), true));
        $zip->addFromString('backup-lapis.json', (string) json_encode($json));
        $zip->close();

        return new UploadedFile($copy, 'backup.zip', 'application/zip', null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function backupJson(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file->getPathname()));
        $json = json_decode((string) $zip->getFromName('backup-lapis.json'), true);
        $zip->close();

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function upload(UploadedFile $file): DataImport
    {
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->post('/data-imports', ['file' => $file])
            ->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')
            ->where('organization_id', $this->organization->id)
            ->where('requested_by', $this->teacher->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function confirm(DataImport $import): void
    {
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}
