<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\ClassIdentityTone;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * A cor que cada professor dá a cada turma — gravada em
 * `class_teachers.identity_tone`, escolhida SÓ quando a ligação nasce e nunca
 * recalculada.
 */
class ClassIdentityToneTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    #[Test]
    public function the_palette_order_is_the_agreed_one(): void
    {
        $this->assertSame(
            ['blue', 'emerald', 'violet', 'amber', 'rose', 'stone'],
            array_map(fn (ClassIdentityTone $tone): string => $tone->value, ClassIdentityTone::cases()),
        );
    }

    #[Test]
    public function attaching_assigns_the_least_used_tone_in_palette_order(): void
    {
        // A turma das fixtures já é a primeira do professor.
        $this->assertSame('blue', $this->tone($this->schoolClass));

        $tones = array_map(fn (string $label): string => $this->tone($this->newClass($label)), ['B', 'C', 'D', 'E', 'F']);
        $this->assertSame(['emerald', 'violet', 'amber', 'rose', 'stone'], $tones);

        // Seis turmas, uma de cada; a sétima empata tudo e volta ao início.
        $this->assertSame('blue', $this->tone($this->newClass('G')));
        $this->assertSame('emerald', $this->tone($this->newClass('H')));
    }

    #[Test]
    public function adding_a_class_never_changes_the_existing_tones(): void
    {
        $first = $this->newClass('B');
        $second = $this->newClass('C');
        $before = [$this->tone($this->schoolClass), $this->tone($first), $this->tone($second)];

        $this->newClass('D');
        $this->newClass('E');

        $this->assertSame($before, [$this->tone($this->schoolClass), $this->tone($first), $this->tone($second)]);
    }

    #[Test]
    public function archiving_frees_the_tone_for_the_next_class_without_touching_the_others(): void
    {
        $emerald = $this->newClass('B');
        $violet = $this->newClass('C');
        $this->schoolClass->update(['archived_at' => now()]);

        // Arquivar não mexeu em nada.
        $this->assertSame(['blue', 'emerald', 'violet'], [$this->tone($this->schoolClass), $this->tone($emerald), $this->tone($violet)]);

        $reuses = $this->newClass('D');

        $this->assertSame('blue', $this->tone($reuses));
        $this->assertSame(['blue', 'emerald', 'violet'], [$this->tone($this->schoolClass), $this->tone($emerald), $this->tone($violet)]);
    }

    #[Test]
    public function unarchiving_or_detaching_another_class_changes_no_tone(): void
    {
        $second = $this->newClass('B');
        $third = $this->newClass('C');
        $this->schoolClass->update(['archived_at' => now()]);
        $this->schoolClass->update(['archived_at' => null]);
        $this->inTenant($this->organization, fn () => $second->teachers()->detach($this->teacher));

        $this->assertSame('blue', $this->tone($this->schoolClass));
        $this->assertSame('violet', $this->tone($third));
    }

    #[Test]
    public function co_teachers_get_independent_tones_for_the_same_class(): void
    {
        $second = $this->newClass('B');
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        // A colega entra primeiro pela turma B: para ela é a primeira.
        $this->inTenant($this->organization, function () use ($second, $colleague): void {
            $second->teachers()->attach($colleague, ['role' => 'co_teacher']);
            $this->schoolClass->teachers()->attach($colleague, ['role' => 'co_teacher']);
        });

        $this->assertSame('emerald', $this->tone($second, $this->teacher));
        $this->assertSame('blue', $this->tone($second, $colleague));
        $this->assertSame('emerald', $this->tone($this->schoolClass, $colleague));
        $this->assertSame('blue', $this->tone($this->schoolClass, $this->teacher));
    }

    #[Test]
    public function the_rule_counts_each_academic_year_on_its_own(): void
    {
        $this->newClass('B');
        $otherYear = $this->inTenant($this->organization, fn () => AcademicYear::factory()->recycle($this->organization)->create([
            'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30',
        ]));

        $nextYearClass = $this->newClass('A', year: $otherYear);

        $this->assertSame('blue', $this->tone($nextYearClass));
    }

    #[Test]
    public function an_explicit_tone_is_respected(): void
    {
        $class = $this->inTenant($this->organization, function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner', 'identity_tone' => 'rose']);

            return $class;
        });

        $this->assertSame('rose', $this->tone($class));
    }

    #[Test]
    public function the_timetable_carries_the_teachers_tone_per_slot_and_per_class(): void
    {
        $second = $this->newClass('B');
        $this->makeSlot(['class_id' => $second->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->makeSlot(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'day_of_week' => 2]);

        $props = $this->asTeacher()->get('/timetable?week=2026-10-05')->assertOk()->viewData('page')['props'];

        $slotTones = collect($props['slots'])->mapWithKeys(fn (array $slot): array => [$slot['school_class']['ulid'] => $slot['school_class']['identity_tone']]);
        $this->assertSame('blue', $slotTones[$this->schoolClass->ulid]);
        $this->assertSame('emerald', $slotTones[$second->ulid]);

        $classTones = collect($props['classes'])->mapWithKeys(fn (array $class): array => [$class['ulid'] => $class['identity_tone']]);
        $this->assertSame('blue', $classTones[$this->schoolClass->ulid]);
        $this->assertSame('emerald', $classTones[$second->ulid]);
    }

    #[Test]
    public function the_backfill_walks_each_teacher_and_year_in_creation_order_and_is_deterministic(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Só em SQLite; em MySQL verifica-se com migrate:rollback.');
        }

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $otherYear = $this->inTenant($this->organization, fn () => AcademicYear::factory()->recycle($this->organization)->create([
            'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30',
        ]));

        // Ano 1 do professor: c1 (ativa), c2 (ARQUIVADA), c3, c4, c5 e c6 com o
        // MESMO created_at — desempata o id. Ano 2: c7. Colega: c1 e c3.
        $classes = [
            'c1' => $this->schoolClass,
            'c2' => $this->newClass('c2'),
            'c3' => $this->newClass('c3'),
            'c4' => $this->newClass('c4'),
            'c5' => $this->newClass('c5'),
            'c6' => $this->newClass('c6'),
            'c7' => $this->newClass('c7', year: $otherYear),
        ];
        $classes['c2']->update(['archived_at' => '2026-10-01 10:00:00']);
        $this->inTenant($this->organization, function () use ($classes, $colleague): void {
            $classes['c3']->teachers()->attach($colleague, ['role' => 'co_teacher']);
            $classes['c1']->teachers()->attach($colleague, ['role' => 'co_teacher']);
        });

        // As ligações do colega foram criadas por último, mas c3 antes de c1.
        $this->setCreatedAt($classes['c1'], $this->teacher, '2026-09-01 08:00:00');
        $this->setCreatedAt($classes['c2'], $this->teacher, '2026-09-02 08:00:00');
        $this->setCreatedAt($classes['c3'], $this->teacher, '2026-09-03 08:00:00');
        foreach (['c4', 'c5', 'c6'] as $label) {
            $this->setCreatedAt($classes[$label], $this->teacher, '2026-09-04 08:00:00');
        }
        $this->setCreatedAt($classes['c7'], $this->teacher, '2026-09-01 07:00:00');
        $this->setCreatedAt($classes['c3'], $colleague, '2026-09-05 08:00:00');
        $this->setCreatedAt($classes['c1'], $colleague, '2026-09-06 08:00:00');

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_15_000200_add_identity_tone_to_class_teachers_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('class_teachers', 'identity_tone'));
        $migration->up();

        // c1 blue; c2 (arquivada) emerald mas não conta; c3 emerald; c4 violet;
        // c5 amber; c6 rose. Ano 2 recomeça: c7 blue. Colega: c3 blue, c1 emerald.
        $expected = [
            [$classes['c1'], $this->teacher, 'blue'],
            [$classes['c2'], $this->teacher, 'emerald'],
            [$classes['c3'], $this->teacher, 'emerald'],
            [$classes['c4'], $this->teacher, 'violet'],
            [$classes['c5'], $this->teacher, 'amber'],
            [$classes['c6'], $this->teacher, 'rose'],
            [$classes['c7'], $this->teacher, 'blue'],
            [$classes['c3'], $colleague, 'blue'],
            [$classes['c1'], $colleague, 'emerald'],
        ];
        foreach ($expected as [$class, $user, $tone]) {
            $this->assertSame($tone, $this->tone($class, $user), "{$class->label} / user {$user->id}");
        }

        // Determinístico: repetir o percurso dá exatamente o mesmo.
        DB::table('class_teachers')->update(['identity_tone' => null]);
        $migration->backfillIdentityTones();
        foreach ($expected as [$class, $user, $tone]) {
            $this->assertSame($tone, $this->tone($class, $user));
        }
    }

    // ------------------------------------------------------------------ apoio

    private function tone(SchoolClass $class, ?User $user = null): ?string
    {
        $tone = DB::table('class_teachers')
            ->where('class_id', $class->id)
            ->where('user_id', ($user ?? $this->teacher)->id)
            ->value('identity_tone');

        return $tone === null ? null : (string) $tone;
    }

    private function newClass(string $label, ?AcademicYear $year = null): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($label, $year): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $year?->id ?? $this->schoolClass->academic_year_id,
                'label' => $label,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });
    }

    private function setCreatedAt(SchoolClass $class, User $user, string $createdAt): void
    {
        DB::table('class_teachers')
            ->where('class_id', $class->id)
            ->where('user_id', $user->id)
            ->update(['created_at' => $createdAt]);
    }
}
