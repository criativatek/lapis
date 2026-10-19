<?php

namespace Tests\Feature\Console;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `lapis:lesson-numbering-recovery-check` — que recuperação se aplica à 0.169.0
 * (docs/lesson-numbering-recovery.md): 0 quando não há números indicados nem
 * intervalos (um rollback só de código é completo), 1 caso contrário; com
 * `--export` grava as aulas em causa e com `--restore` repõe-nas. Só ids,
 * números e contagens.
 */
class LessonNumberingRecoveryCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Organization $organization;

    private SchoolClass $class;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'Europe/Lisbon'));
        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->path = tempnam(sys_get_temp_dir(), 'lapis-numbering-').'.json';

        $this->class = $this->inTenant(fn (): SchoolClass => SchoolClass::factory()->recycle($this->organization)->create([
            'academic_year_id' => AcademicYear::factory()->recycle($this->organization)->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])->id,
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    #[Test]
    public function it_exits_zero_when_there_is_no_manual_number_and_no_range(): void
    {
        $this->lesson('2026-10-08 09:30:00', ['lesson_number' => 1]);

        $this->artisan('lapis:lesson-numbering-recovery-check')
            ->expectsOutputToContain('Cenário a)')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_exits_one_and_counts_per_organization_when_there_are_manual_numbers(): void
    {
        $this->lesson('2026-10-08 09:30:00', ['lesson_number' => 11, 'lesson_number_end' => 12, 'lesson_number_manual' => true]);
        $this->lesson('2026-10-15 09:30:00', ['lesson_number' => 13]);

        $this->artisan('lapis:lesson-numbering-recovery-check')
            ->expectsOutputToContain("organização #{$this->organization->id}: números indicados: 1; intervalos: 1")
            ->expectsOutputToContain('Cenário b)')
            ->assertExitCode(1);
    }

    #[Test]
    public function the_export_has_only_ids_and_numbers_of_the_affected_lessons(): void
    {
        $manual = $this->lesson('2026-10-08 09:30:00', ['lesson_number' => 11, 'lesson_number_end' => 12, 'lesson_number_manual' => true]);
        $this->lesson('2026-10-15 09:30:00', ['lesson_number' => 13]);

        $this->artisan('lapis:lesson-numbering-recovery-check', ['--export' => $this->path])->assertExitCode(1);

        $json = json_decode((string) file_get_contents($this->path), true);
        $this->assertCount(1, $json['lessons']);
        $this->assertSame([
            'lesson_ulid' => $manual->ulid,
            'class_ulid' => $this->class->ulid,
            'organization_id' => $this->organization->id,
            'lesson_number' => 11,
            'lesson_number_end' => 12,
            'lesson_number_manual' => true,
        ], $json['lessons'][0]);
    }

    #[Test]
    public function the_restore_dry_run_reports_and_writes_nothing(): void
    {
        $manual = $this->lesson('2026-10-08 09:30:00', ['lesson_number' => 11, 'lesson_number_end' => 12, 'lesson_number_manual' => true]);
        $this->artisan('lapis:lesson-numbering-recovery-check', ['--export' => $this->path])->assertExitCode(1);

        // O que o código antigo faria: reescrever por posição.
        DB::table('lessons')->where('id', $manual->id)->update(['lesson_number' => 1, 'lesson_number_end' => null, 'lesson_number_manual' => false]);

        $this->artisan('lapis:lesson-numbering-recovery-check', ['--restore' => $this->path])
            ->expectsOutputToContain("aula #{$manual->id}")
            ->expectsOutputToContain('A repor: 1 aula(s)')
            ->assertExitCode(0);

        $this->assertSame([1, null, 0], $this->triple($manual));
    }

    #[Test]
    public function the_restore_apply_puts_the_numbers_back_and_renumbers_the_free_lessons_around_them(): void
    {
        $manual = $this->lesson('2026-10-08 09:30:00', ['lesson_number' => 11, 'lesson_number_end' => 12, 'lesson_number_manual' => true]);
        $free = $this->lesson('2026-10-15 09:30:00', ['lesson_number' => 13]);
        $this->artisan('lapis:lesson-numbering-recovery-check', ['--export' => $this->path])->assertExitCode(1);

        DB::table('lessons')->where('id', $manual->id)->update(['lesson_number' => 1, 'lesson_number_end' => null, 'lesson_number_manual' => false]);
        DB::table('lessons')->where('id', $free->id)->update(['lesson_number' => 2]);

        $this->artisan('lapis:lesson-numbering-recovery-check', ['--restore' => $this->path, '--apply' => true])
            ->expectsOutputToContain('Repostas: 1 aula(s)')
            ->assertExitCode(0);

        $this->assertSame([11, 12, 1], $this->triple($manual));
        // A livre volta a ser derivada a partir do NÚMERO (0.172.0: o fim legado não ocupa números): 12.
        $this->assertSame(12, DB::table('lessons')->where('id', $free->id)->value('lesson_number'));

        // Idempotente: uma segunda reposição não encontra nada para mudar.
        $this->artisan('lapis:lesson-numbering-recovery-check', ['--restore' => $this->path, '--apply' => true])
            ->expectsOutputToContain('Repostas: 0 aula(s)')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_restore_is_not_refused_when_the_numbering_is_frozen(): void
    {
        $manual = $this->lesson('2026-10-08 09:30:00', ['lesson_number' => 11, 'lesson_number_manual' => true]);
        $this->artisan('lapis:lesson-numbering-recovery-check', ['--export' => $this->path])->assertExitCode(1);
        DB::table('lessons')->where('id', $manual->id)->update(['lesson_number' => 1, 'lesson_number_manual' => false]);

        config(['lapis.lesson_numbering_frozen' => true]);
        $this->artisan('lapis:lesson-numbering-recovery-check', ['--restore' => $this->path, '--apply' => true])->assertExitCode(0);

        $this->assertSame([11, null, 1], $this->triple($manual));
    }

    #[Test]
    public function a_malformed_file_writes_nothing(): void
    {
        $manual = $this->lesson('2026-10-08 09:30:00', ['lesson_number' => 11, 'lesson_number_end' => 12, 'lesson_number_manual' => true]);
        file_put_contents($this->path, json_encode(['lessons' => [
            ['lesson_ulid' => $manual->ulid, 'class_ulid' => $this->class->ulid, 'organization_id' => $this->organization->id,
                'lesson_number' => 5, 'lesson_number_end' => 3, 'lesson_number_manual' => true],
        ]]));

        $this->artisan('lapis:lesson-numbering-recovery-check', ['--restore' => $this->path, '--apply' => true])->assertExitCode(1);

        $this->assertSame([11, 12, 1], $this->triple($manual));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lesson(string $startsAt, array $attributes): Lesson
    {
        return $this->inTenant(fn (): Lesson => Lesson::create([
            'class_id' => $this->class->id,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes(50)->format('Y-m-d H:i:s'),
            'status' => LessonStatus::Preparation,
            'created_by' => $this->teacher->id,
            ...$attributes,
        ]));
    }

    /** @return array{0: int|null, 1: int|null, 2: int} */
    private function triple(Lesson $lesson): array
    {
        $row = DB::table('lessons')->where('id', $lesson->id)->first();

        return [$row->lesson_number, $row->lesson_number_end, (int) $row->lesson_number_manual];
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
