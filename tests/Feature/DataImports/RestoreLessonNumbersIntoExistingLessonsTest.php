<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TimetableActivity;
use App\Models\User;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;
use ZipArchive;

/**
 * 0.169.0 — restaurar para um destino que JÁ TEM aulas na turma.
 *
 * O restauro conserva as aulas do destino e acrescenta as do ficheiro ao lado,
 * com os números copiados tal como estão. Uma colisão de números fixos (manual
 * ou lecionado) é BLOQUEANTE: o plano traz `blocking`, a pré-visualização não
 * deixa confirmar e a confirmação recusa antes de gravar o que quer que seja —
 * sem marcar a importação como falhada, como a recusa em modo só de leitura da
 * autoavaliação. Tratar a colisão como `conflict` deixava a aula (e o sumário,
 * o plano e as faltas dela) de fora: um restauro parcial.
 *
 * Os casos: sem colisão restaura tudo; manual ou lecionada contra manual ou
 * lecionada do destino bloqueia sem gravar nada; a mesma `lesson_unit_key` com o
 * mesmo intervalo é a mesma lição e passa; aulas livres com números repetidos
 * não bloqueiam. E a compatibilidade v17/v18 das duas funcionalidades juntas.
 *
 * Relógio fixo ANTES de qualquer dado.
 */
class RestoreLessonNumbersIntoExistingLessonsTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    private const TABLES = [
        'lessons', 'lesson_summaries', 'lesson_plans', 'lesson_attendances', 'classes', 'class_groups',
        'recurring_lesson_slots', 'enrollments', 'students', 'timetable_activities', 'audit_events',
    ];

    private User $teacher;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'Europe/Lisbon'));
        Storage::fake('local');
        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeOrganizationTo($this->organization, 'pro');
    }

    // ---------------------------------------------------------------- (a)

    #[Test]
    public function without_a_collision_everything_in_the_file_is_restored_next_to_the_existing_lessons(): void
    {
        $class = $this->classFor();
        $fileLesson = $this->lesson($class, '2026-10-22 09:00:00', ['lesson_number' => 12, 'lesson_number_manual' => true]);
        $this->summary($fileLesson, 'Sumário do ficheiro.');
        $file = $this->backupUpload();
        $this->removeLessons([$fileLesson]);
        $this->lesson($class, '2026-10-23 09:00:00', ['lesson_number' => 5, 'lesson_number_manual' => true]);

        $import = $this->upload($file);

        $this->preview($import)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.blocking', [])
            ->where('plan.can_confirm', true));

        $this->confirm($import)->assertSessionHasNoErrors();

        $this->inTenant(function (): void {
            $lessons = Lesson::query()->orderBy('starts_at')->get();
            $this->assertSame([12, 5], $lessons->pluck('lesson_number')->all());
            $this->assertSame([true, true], $lessons->pluck('lesson_number_manual')->all());
            $this->assertSame('Sumário do ficheiro.', $lessons[0]->summary->content);
        });
        $this->assertSame('imported', $import->fresh()->status->value);
    }

    // ---------------------------------------------------------------- (b)

    #[Test]
    public function a_manual_number_that_a_manual_lesson_of_the_destination_already_has_blocks_the_whole_restore(): void
    {
        $this->assertBlockedAndNothingWritten(
            fileAttributes: ['lesson_number' => 12, 'lesson_number_manual' => true],
            destinationAttributes: ['lesson_number' => 12, 'lesson_number_manual' => true],
            expectedMessage: 'A aula de 22/10/2026 (8.º F) do ficheiro tem a Lição 12, que no destino já é da aula de 23/10/2026. Corrija o número no destino ou restaure noutra conta.',
        );
    }

    #[Test]
    public function a_manual_number_that_a_taught_lesson_of_the_destination_already_has_blocks_the_whole_restore(): void
    {
        $this->assertBlockedAndNothingWritten(
            fileAttributes: ['lesson_number' => 12, 'lesson_number_manual' => true],
            destinationAttributes: $this->taught(['lesson_number' => 12]),
            expectedMessage: 'A aula de 22/10/2026 (8.º F) do ficheiro tem a Lição 12, que no destino já é da aula de 23/10/2026.',
        );
    }

    #[Test]
    public function a_manual_range_that_overlaps_a_fixed_number_of_the_destination_blocks_the_whole_restore(): void
    {
        $this->assertBlockedAndNothingWritten(
            fileAttributes: ['lesson_number' => 11, 'lesson_number_end' => 12, 'lesson_number_manual' => true],
            destinationAttributes: ['lesson_number' => 12, 'lesson_number_manual' => true],
            expectedMessage: 'A aula de 22/10/2026 (8.º F) do ficheiro tem as lições 11–12, que no destino se sobrepõe à Lição 12 da aula de 23/10/2026.',
        );
    }

    // ---------------------------------------------------------------- (c)

    #[Test]
    public function a_taught_lesson_with_the_number_of_a_taught_lesson_of_the_destination_blocks_the_whole_restore(): void
    {
        $this->assertBlockedAndNothingWritten(
            fileAttributes: $this->taught(['lesson_number' => 12]),
            destinationAttributes: $this->taught(['lesson_number' => 12]),
            expectedMessage: 'A aula de 22/10/2026 (8.º F) do ficheiro tem a Lição 12, que no destino já é da aula de 23/10/2026.',
        );
    }

    // ---------------------------------------------------------------- (d)

    #[Test]
    public function the_same_lesson_unit_with_the_same_range_is_the_same_lesson_and_is_accepted(): void
    {
        $class = $this->classFor();
        $group = $this->group($class);
        $key = (string) Str::ulid();
        $fileLesson = $this->lesson($class, '2026-10-22 09:00:00', ['class_group_id' => $group->id, 'lesson_unit_key' => $key, 'lesson_number' => 12, 'lesson_number_manual' => true]);
        $file = $this->backupUpload();
        $this->removeLessons([$fileLesson]);
        $this->lesson($class, '2026-10-23 09:00:00', ['class_group_id' => $group->id, 'lesson_unit_key' => $key, 'lesson_number' => 12, 'lesson_number_manual' => true]);

        $import = $this->upload($file);
        $this->preview($import)->assertInertia(fn (AssertableInertia $page) => $page->where('plan.blocking', []));
        $this->confirm($import)->assertSessionHasNoErrors();

        $this->inTenant(fn () => $this->assertSame([12, 12], Lesson::query()->orderBy('starts_at')->pluck('lesson_number')->all()));
    }

    #[Test]
    public function the_same_lesson_unit_with_a_different_range_is_a_conflict(): void
    {
        $class = $this->classFor();
        $group = $this->group($class);
        $key = (string) Str::ulid();
        $fileLesson = $this->lesson($class, '2026-10-22 09:00:00', ['class_group_id' => $group->id, 'lesson_unit_key' => $key, 'lesson_number' => 12, 'lesson_number_manual' => true]);
        $file = $this->backupUpload();
        $this->removeLessons([$fileLesson]);
        $this->lesson($class, '2026-10-23 09:00:00', ['class_group_id' => $group->id, 'lesson_unit_key' => $key, 'lesson_number' => 13, 'lesson_number_manual' => true]);

        $import = $this->upload($file);
        $before = $this->counts();

        $this->preview($import)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.blocking.0', 'A aula de 22/10/2026 (8.º F) do ficheiro tem a Lição 12, mas no destino a mesma lição (aula de 23/10/2026) tem a Lição 13. Corrija o número no destino ou restaure noutra conta.'));
        $this->confirm($import)->assertSessionHasErrors('import');
        $this->assertSame($before, $this->counts());
    }

    // ---------------------------------------------------------------- (e)

    #[Test]
    public function free_lessons_with_repeated_numbers_do_not_block(): void
    {
        $class = $this->classFor();
        $fileLesson = $this->lesson($class, '2026-10-22 09:00:00', ['lesson_number' => 3]);
        $file = $this->backupUpload();
        $this->removeLessons([$fileLesson]);
        // Aberta e não manual: o número é uma sugestão que a derivação refaz.
        $this->lesson($class, '2026-10-23 09:00:00', ['lesson_number' => 3]);

        $import = $this->upload($file);
        $this->preview($import)->assertInertia(fn (AssertableInertia $page) => $page->where('plan.blocking', []));
        $this->confirm($import)->assertSessionHasNoErrors();

        $this->inTenant(fn () => $this->assertSame(2, Lesson::query()->count()));
    }

    // -------------------------------------------- compatibilidade v17 / v18

    #[Test]
    public function a_backup_with_a_non_teaching_activity_and_a_manual_range_restores_both_intact(): void
    {
        $class = $this->classFor();
        $manual = $this->lesson($class, '2026-10-22 09:00:00', ['lesson_number' => 11, 'lesson_number_end' => 12, 'lesson_number_manual' => true]);
        $this->activity();

        $file = $this->backupUpload();
        $this->assertSame(20, $this->backupJson($file)['schema_version']);

        $this->removeLessons([$manual]);
        $this->inTenant(fn () => TimetableActivity::query()->delete());

        $this->confirm($this->upload($file))->assertSessionHasNoErrors();

        $this->inTenant(function (): void {
            $lesson = Lesson::query()->sole();
            $this->assertSame([11, 12, true], [$lesson->lesson_number, $lesson->lesson_number_end, $lesson->lesson_number_manual]);
            $this->assertSame('11–12', $lesson->lessonNumberLabel());

            $activity = TimetableActivity::query()->sole();
            $this->assertSame('DT 8.º F', $activity->title);
            $this->assertSame($this->teacher->id, $activity->user_id);
        });
    }

    #[Test]
    public function a_v17_backup_restores_the_activities_and_the_lessons_as_not_manual(): void
    {
        $class = $this->classFor();
        $lesson = $this->lesson($class, '2026-10-22 09:00:00', ['lesson_number' => 4]);
        $this->activity();

        $legacy = $this->rewriteBackup($this->backupUpload(), function (array $json): array {
            $json['schema_version'] = 17;

            foreach ($json['lessons'] as $index => $row) {
                unset($json['lessons'][$index]['lesson_number_end'], $json['lessons'][$index]['lesson_number_manual']);
            }

            return $json;
        });
        $this->assertSame(BackupSchemaCompatibility::LegacyCompatible, BackupSchemaCompatibility::for(17));

        $this->removeLessons([$lesson]);
        $this->inTenant(fn () => TimetableActivity::query()->delete());

        $this->confirm($this->upload($legacy))->assertSessionHasNoErrors();

        $this->inTenant(function (): void {
            $restored = Lesson::query()->sole();
            $this->assertSame([4, null, false], [$restored->lesson_number, $restored->lesson_number_end, $restored->lesson_number_manual]);
            $this->assertSame('DT 8.º F', TimetableActivity::query()->sole()->title);
        });
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $fileAttributes
     * @param  array<string, mixed>  $destinationAttributes
     */
    private function assertBlockedAndNothingWritten(array $fileAttributes, array $destinationAttributes, string $expectedMessage): void
    {
        $class = $this->classFor();
        $fileLesson = $this->lesson($class, '2026-10-22 09:00:00', $fileAttributes);
        $this->summary($fileLesson, 'Sumário que não pode ficar de fora.');
        $this->inTenant(fn () => LessonPlan::create(['lesson_id' => $fileLesson->id, 'planned_summary' => 'Plano.', 'created_by' => $this->teacher->id]));
        $file = $this->backupUpload();
        $this->removeLessons([$fileLesson]);
        $this->lesson($class, '2026-10-23 09:00:00', $destinationAttributes);

        $import = $this->upload($file);
        $before = $this->counts();

        // A pré-visualização mostra a mensagem e não deixa confirmar.
        $this->preview($import)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.can_confirm', false)
            ->where('plan.blocking', fn ($blocking) => str_contains((string) ($blocking[0] ?? ''), $expectedMessage)));

        // A confirmação recusa antes de escrever e diz porquê.
        $response = $this->confirm($import);
        $response->assertSessionHasErrors('import');
        $this->assertStringContainsString($expectedMessage, (string) session('errors')->first('import'));
        $this->assertStringContainsString('nada foi gravado', (string) session('errors')->first('import'));

        // NENHUMA linha nova em nenhuma tabela: nem aula, nem sumário, nem plano...
        $this->assertSame($before, $this->counts());

        // ...e a importação continua confirmável, com o ficheiro guardado.
        $after = $import->fresh();
        $this->assertTrue($after->status->canBeConfirmed());
        $this->assertNotSame('failed', $after->status->value);
        $this->assertNotNull($after->stored_path);
        Storage::disk('local')->assertExists($after->stored_path);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function taught(array $extra): array
    {
        return [
            'status' => LessonStatus::Taught,
            'outcome' => LessonOutcome::Taught,
            'attendance_recorded_at' => '2026-10-10 10:00:00',
            ...$extra,
        ];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function classFor(): SchoolClass
    {
        return $this->inTenant(function (): SchoolClass {
            $year = AcademicYear::factory()->recycle($this->organization)->create([
                'label' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30',
            ]);
            $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Matemática Fictícia']);
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '8.º F',
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class->fresh();
        });
    }

    private function group(SchoolClass $class): ClassGroup
    {
        return $this->inTenant(fn (): ClassGroup => ClassGroup::create(['class_id' => $class->id, 'label' => 'T1', 'position' => 1]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lesson(SchoolClass $class, string $startsAt, array $attributes = []): Lesson
    {
        return $this->inTenant(fn (): Lesson => Lesson::create([
            'class_id' => $class->id,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes(50)->format('Y-m-d H:i:s'),
            'status' => LessonStatus::Preparation,
            'created_by' => $this->teacher->id,
            ...$attributes,
        ]));
    }

    private function summary(Lesson $lesson, string $content): void
    {
        $this->inTenant(fn () => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => $content]));
    }

    private function activity(): void
    {
        $this->inTenant(fn () => TimetableActivity::create([
            'user_id' => $this->teacher->id, 'kind' => 'class_direction', 'title' => 'DT 8.º F',
            'day_of_week' => 2, 'starts_at' => '10:30', 'ends_at' => '11:20',
        ]));
    }

    /**
     * @param  list<Lesson>  $lessons
     */
    private function removeLessons(array $lessons): void
    {
        $this->inTenant(function () use ($lessons): void {
            foreach ($lessons as $lesson) {
                LessonSummary::query()->where('lesson_id', $lesson->id)->delete();
                LessonPlan::query()->where('lesson_id', $lesson->id)->delete();
                Lesson::query()->whereKey($lesson->id)->delete();
            }
        });
    }

    private function backupUpload(): UploadedFile
    {
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->teacher->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
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

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function rewriteBackup(UploadedFile $file, callable $mutate): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'lapis-restore-numbers-').'.zip';
        copy($file->getPathname(), $copy);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($copy));
        $json = $mutate(json_decode((string) $zip->getFromName('backup-lapis.json'), true));
        $zip->addFromString('backup-lapis.json', (string) json_encode($json));
        $zip->close();

        return new UploadedFile($copy, 'backup.zip', 'application/zip', null, true);
    }

    private function upload(UploadedFile $file): DataImport
    {
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->post('/data-imports', ['file' => $file])
            ->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')
            ->where('organization_id', $this->organization->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function preview(DataImport $import): TestResponse
    {
        return $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->get("/data-imports/{$import->ulid}")
            ->assertOk();
    }

    private function confirm(DataImport $import): TestResponse
    {
        return $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->from("/data-imports/{$import->ulid}")
            ->post("/data-imports/{$import->ulid}/confirm");
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
