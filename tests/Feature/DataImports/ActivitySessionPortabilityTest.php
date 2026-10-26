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
use App\Models\TimetableActivitySession;
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
 * Schema v19 — the per-session records of the «outras atividades» in the
 * backup. A REAL export, the zip's backup-lapis.json mutated in place when a
 * scenario needs a specific shape, then a REAL upload / confirm.
 *
 * Personal to one teacher: an export carries only the exporter's own sessions,
 * an import restores only rows signed by the account confirming it, a session
 * whose activity cannot be restored comes back as an orphan (identified by its
 * copy), and nothing here ever creates a lesson.
 */
class ActivitySessionPortabilityTest extends TestCase
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
    public function the_backup_carries_exactly_the_documented_fields_of_the_exporters_own_sessions(): void
    {
        $class = $this->classFor($this->teacher);
        $activity = $this->activity($this->teacher, ['title' => 'DT 6.º D', 'kind' => 'class_direction', 'class_id' => $class->id, 'numbering_enabled' => true, 'numbering_start' => 3]);
        $this->makeSession($activity, '2026-10-13', 'Reunião com a turma.', 'done', 3);

        $backup = $this->backupJson($this->backupUpload($this->teacher));

        $this->assertSame(20, BackupSchemaCompatibility::CURRENT);
        $this->assertSame(20, $backup['schema_version']);
        $this->assertContains('timetable_activity_occurrences', $backup['capabilities']);
        $this->assertCount(1, $backup['timetable_activity_occurrences']);

        $row = $backup['timetable_activity_occurrences'][0];
        $this->assertSame(
            ['ulid', 'author_email', 'activity_ulid', 'activity_kind', 'activity_title', 'class_ulid', 'occurs_on', 'starts_at', 'ends_at', 'summary', 'status', 'sequence_number'],
            array_keys($row),
        );
        $this->assertSame($activity->ulid, $row['activity_ulid']);
        $this->assertSame($class->ulid, $row['class_ulid']);
        $this->assertSame('2026-10-13', $row['occurs_on']);
        $this->assertSame('done', $row['status']);
        $this->assertSame(3, $row['sequence_number']);
        $this->assertSame('Reunião com a turma.', $row['summary']);

        foreach (['id', 'organization_id', 'user_id', 'timetable_activity_id', 'class_id', 'academic_year_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }

        $activityRow = $backup['timetable_activities'][0];
        $this->assertTrue($activityRow['numbering_enabled']);
        $this->assertSame(3, $activityRow['numbering_start']);
    }

    #[Test]
    public function untouched_occurrences_and_a_colleagues_sessions_never_leave_in_my_export(): void
    {
        $mine = $this->activity($this->teacher, ['title' => 'Minha']);
        $this->makeSession($mine, '2026-10-13', 'Meu texto.', 'planned');
        $this->makeSession($mine, '2026-10-20', null, 'planned');

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $theirs = $this->activity($colleague, ['title' => 'Do colega']);
        $this->makeSession($theirs, '2026-10-13', 'Texto do colega.', 'done', null, $colleague);

        $backup = $this->backupJson($this->backupUpload($this->teacher));

        $this->assertSame(['Meu texto.'], collect($backup['timetable_activity_occurrences'])->pluck('summary')->all());
        $this->assertStringNotContainsString('Texto do colega.', (string) json_encode($backup));
    }

    #[Test]
    public function a_round_trip_restores_relations_dates_states_numbers_and_orphans_without_lessons(): void
    {
        $class = $this->classFor($this->teacher);
        $activity = $this->activity($this->teacher, ['title' => 'Clube de Xadrez', 'kind' => 'club', 'class_id' => $class->id, 'numbering_enabled' => true]);
        $this->makeSession($activity, '2026-10-13', 'Aberturas.', 'done', 1);
        $this->makeSession($activity, '2026-10-20', 'Finais.', 'not_held');
        $this->makeSession($activity, '2026-10-27', 'Preparada.', 'planned');

        // Uma sessão órfã: o bloco foi eliminado, o texto ficou.
        $gone = $this->activity($this->teacher, ['title' => 'Apoio antigo', 'kind' => 'student_support', 'day_of_week' => 4]);
        $this->makeSession($gone, '2026-10-15', 'Ficou escrito.', 'done');
        $this->inTenant(fn () => TimetableActivity::query()->whereKey($gone->id)->delete());

        $file = $this->backupUpload($this->teacher);
        $this->inTenant(function (): void {
            TimetableActivitySession::query()->delete();
            TimetableActivity::query()->delete();
        });

        $this->confirm($this->upload($file));

        $this->inTenant(function () use ($class): void {
            $restoredActivity = TimetableActivity::query()->where('title', 'Clube de Xadrez')->sole();
            $this->assertTrue($restoredActivity->numbering_enabled);
            $this->assertSame('club', $restoredActivity->kind->value);

            $this->assertSame(4, TimetableActivitySession::query()->count());

            $done = TimetableActivitySession::query()->whereDate('occurs_on', '2026-10-13')->sole();
            $this->assertSame($restoredActivity->id, $done->timetable_activity_id);
            $this->assertSame($class->id, $done->class_id);
            $this->assertSame($this->teacher->id, $done->user_id);
            $this->assertSame('done', $done->status->value);
            $this->assertSame(1, $done->session_number);
            $this->assertSame('Aberturas.', $done->summary);
            $this->assertNotNull($done->academic_year_id);

            $notHeld = TimetableActivitySession::query()->whereDate('occurs_on', '2026-10-20')->sole();
            $this->assertSame('not_held', $notHeld->status->value);
            $this->assertSame('Finais.', $notHeld->summary);

            $prepared = TimetableActivitySession::query()->whereDate('occurs_on', '2026-10-27')->sole();
            $this->assertSame('planned', $prepared->status->value);

            $orphan = TimetableActivitySession::query()->whereDate('occurs_on', '2026-10-15')->sole();
            $this->assertNull($orphan->timetable_activity_id);
            $this->assertSame('Apoio antigo', $orphan->activity_title);
            $this->assertSame('student_support', $orphan->activity_kind);
            $this->assertSame('Ficou escrito.', $orphan->summary);

            $this->assertSame(0, Lesson::query()->count());
        });
    }

    #[Test]
    public function restoring_the_same_backup_again_creates_no_duplicate(): void
    {
        $activity = $this->activity($this->teacher, ['title' => 'DT']);
        $this->makeSession($activity, '2026-10-13', 'Texto.', 'done');
        $orphanActivity = $this->activity($this->teacher, ['title' => 'Antiga', 'day_of_week' => 4]);
        $this->makeSession($orphanActivity, '2026-10-15', 'Órfã.', 'done');
        $this->inTenant(fn () => TimetableActivity::query()->whereKey($orphanActivity->id)->delete());

        $file = $this->backupUpload($this->teacher);
        $this->confirm($this->upload($file));
        $this->confirm($this->upload($file));

        $this->assertSame(2, $this->inTenant(fn (): int => TimetableActivitySession::query()->count()));
    }

    #[Test]
    public function a_session_restored_under_a_fresh_ulid_is_recognised_by_its_natural_key(): void
    {
        $activity = $this->activity($this->teacher, ['title' => 'DT']);
        $this->makeSession($activity, '2026-10-13', 'Texto.', 'done');

        $file = $this->rewriteBackup($this->backupUpload($this->teacher), function (array $json): array {
            $json['timetable_activity_occurrences'][0]['ulid'] = '01JZZZZZZZZZZZZZZZZZZZZZZZ';

            return $json;
        });

        $this->confirm($this->upload($file));

        $this->assertSame(1, $this->inTenant(fn (): int => TimetableActivitySession::query()->count()));
    }

    #[Test]
    public function a_row_signed_by_another_account_is_not_restored(): void
    {
        $activity = $this->activity($this->teacher, ['title' => 'DT']);
        $this->makeSession($activity, '2026-10-13', 'Texto.', 'done');

        $file = $this->rewriteBackup($this->backupUpload($this->teacher), function (array $json): array {
            $json['timetable_activity_occurrences'][0]['author_email'] = 'outra.pessoa@example.test';
            $json['timetable_activity_occurrences'][0]['ulid'] = '01JYYYYYYYYYYYYYYYYYYYYYYY';
            $json['timetable_activity_occurrences'][0]['occurs_on'] = '2026-10-20';

            return $json;
        });

        $this->confirm($this->upload($file));

        $this->assertSame(1, $this->inTenant(fn (): int => TimetableActivitySession::query()->count()));
        $this->assertSame(0, $this->inTenant(fn (): int => TimetableActivitySession::query()->where('ulid', '01JYYYYYYYYYYYYYYYYYYYYYYY')->count()));
    }

    #[Test]
    public function a_v18_backup_with_activities_and_no_sessions_still_restores(): void
    {
        $this->activity($this->teacher, ['title' => 'DT']);
        $file = $this->rewriteBackup($this->backupUpload($this->teacher), function (array $json): array {
            unset($json['timetable_activity_occurrences']);

            foreach (array_keys($json['timetable_activities']) as $index) {
                unset($json['timetable_activities'][$index]['numbering_enabled'], $json['timetable_activities'][$index]['numbering_start']);
            }

            $json['schema_version'] = 18;

            return $json;
        });
        $this->inTenant(fn () => TimetableActivity::query()->delete());

        $this->confirm($this->upload($file));

        $this->inTenant(function (): void {
            $restored = TimetableActivity::query()->sole();
            $this->assertFalse($restored->numbering_enabled);
            $this->assertSame(1, $restored->numbering_start);
            $this->assertSame(0, TimetableActivitySession::query()->count());
        });
    }

    #[Test]
    public function an_invalid_row_is_reported_with_a_fixed_sentence_and_never_the_text(): void
    {
        $activity = $this->activity($this->teacher, ['title' => 'DT']);
        $this->makeSession($activity, '2026-10-13', 'Texto secreto da sessão.', 'done');

        $file = $this->rewriteBackup($this->backupUpload($this->teacher), function (array $json): array {
            $json['timetable_activity_occurrences'][0]['status'] = 'inventado';

            return $json;
        });

        $import = $this->upload($file);

        $this->assertSame([], $import->canonical_snapshot['timetable_activity_occurrences']);
        $stored = (string) json_encode($import->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Texto secreto da sessão.', $stored);
    }

    #[Test]
    public function a_session_whose_activity_is_not_restorable_comes_back_as_an_orphan(): void
    {
        $activity = $this->activity($this->teacher, ['title' => 'DT']);
        $this->makeSession($activity, '2026-10-13', 'Texto.', 'done');
        $file = $this->backupUpload($this->teacher);

        // A atividade do ficheiro deixa de ser válida; a sessão fica na mesma.
        $broken = $this->rewriteBackup($file, function (array $json): array {
            $json['timetable_activities'][0]['day_of_week'] = 9;

            return $json;
        });
        $this->inTenant(function (): void {
            TimetableActivitySession::query()->delete();
            TimetableActivity::query()->delete();
        });

        $this->confirm($this->upload($broken));

        $this->inTenant(function (): void {
            $this->assertSame(0, TimetableActivity::query()->count());
            $session = TimetableActivitySession::query()->sole();
            $this->assertNull($session->timetable_activity_id);
            $this->assertSame('DT', $session->activity_title);
            $this->assertSame('Texto.', $session->summary);
        });
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

    private function makeSession(TimetableActivity $activity, string $date, ?string $summary, string $status, ?int $number = null, ?User $owner = null): TimetableActivitySession
    {
        return $this->inTenant(function () use ($activity, $date, $summary, $status, $number, $owner): TimetableActivitySession {
            $session = new TimetableActivitySession;
            $session->forceFill([
                'user_id' => ($owner ?? $this->teacher)->id,
                'timetable_activity_id' => $activity->id,
                'activity_kind' => $activity->kind->value,
                'activity_title' => $activity->title,
                'class_id' => $activity->class_id,
                'occurs_on' => $date,
                'starts_at' => $activity->starts_at,
                'ends_at' => $activity->ends_at,
                'summary' => $summary,
                'status' => $status,
                'session_number' => $number,
            ])->save();

            return $session;
        });
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
        $copy = tempnam(sys_get_temp_dir(), 'lapis-activity-session-backup-').'.zip';
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
