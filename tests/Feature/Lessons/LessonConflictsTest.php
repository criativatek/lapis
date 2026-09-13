<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Services\Lessons\LessonConflicts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * §41 — a integridade que faltava: uma turma não pode ter duas aulas
 * semanticamente incompatíveis no mesmo pedaço de tempo.
 */
class LessonConflictsTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    #[Test]
    public function the_same_class_at_exactly_the_same_time_conflicts(): void
    {
        $this->makeLesson();

        $this->assertNotNull($this->conflictAt('2026-10-08 09:30:00', '2026-10-08 10:20:00'));
    }

    /**
     * O caso que uma comparação de igualdade nunca apanharia, e que é o mais
     * comum quando o horário está mal configurado: 14:10–15:00 contra
     * 14:30–15:20.
     */
    #[Test]
    public function a_partial_overlap_conflicts(): void
    {
        $this->makeLesson([
            'starts_at' => '2026-10-08 14:10:00',
            'ends_at' => '2026-10-08 15:00:00',
        ]);

        $this->assertNotNull($this->conflictAt('2026-10-08 14:30:00', '2026-10-08 15:20:00'));
    }

    /** Encostadas não é sobreposto: 09:30–10:20 e 10:20–11:10 são dois tempos seguidos. */
    #[Test]
    public function back_to_back_lessons_do_not_conflict(): void
    {
        $this->makeLesson();

        $this->assertNull($this->conflictAt('2026-10-08 10:20:00', '2026-10-08 11:10:00'));
    }

    #[Test]
    public function the_same_group_conflicts_with_itself(): void
    {
        $group = $this->makeGroup('T1');
        $this->makeLesson(['class_group_id' => $group->id]);

        $this->assertNotNull($this->conflictAt(
            '2026-10-08 09:30:00',
            '2026-10-08 10:20:00',
            $group->id,
        ));
    }

    #[Test]
    public function a_whole_class_lesson_conflicts_with_a_group_lesson_both_ways(): void
    {
        $group = $this->makeGroup('T1');
        $this->makeLesson();

        // Grupo contra a turma inteira que já lá está.
        $this->assertNotNull($this->conflictAt(
            '2026-10-08 09:30:00',
            '2026-10-08 10:20:00',
            $group->id,
        ));

        $this->inTenant($this->organization, fn () => Lesson::query()->delete());
        $this->makeLesson(['class_group_id' => $group->id]);

        // E a turma inteira contra o grupo que já lá está.
        $this->assertNotNull($this->conflictAt('2026-10-08 09:30:00', '2026-10-08 10:20:00'));
    }

    /** É exatamente para isto que as turmas desdobradas existem. */
    #[Test]
    public function two_different_groups_of_the_same_class_may_share_the_same_time(): void
    {
        $first = $this->makeGroup('T1');
        $second = $this->makeGroup('T2');
        $this->makeLesson(['class_group_id' => $first->id]);

        $this->assertNull($this->conflictAt(
            '2026-10-08 09:30:00',
            '2026-10-08 10:20:00',
            $second->id,
        ));
    }

    #[Test]
    public function two_different_classes_may_share_the_same_time(): void
    {
        $this->makeLesson();

        $otherClass = $this->inTenant($this->organization, function () {
            $class = SchoolClass::factory()
                ->recycle($this->organization)
                ->create(['academic_year_id' => $this->schoolClass->academic_year_id]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });

        $this->assertNull($this->inTenant(
            $this->organization,
            fn () => app(LessonConflicts::class)->conflictingLesson(
                (int) $otherClass->id,
                null,
                CarbonImmutable::parse('2026-10-08 09:30:00', 'Europe/Lisbon'),
                CarbonImmutable::parse('2026-10-08 10:20:00', 'Europe/Lisbon'),
            ),
        ));
    }

    /** §3, §41-G: a recusa está no BACKEND, e não só no formulário. */
    #[Test]
    public function the_schedule_endpoint_refuses_an_overlapping_slot(): void
    {
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '14:10', 'ends_at' => '15:00']);

        $this->asTeacher()
            ->post('/lesson-slots', [
                'class_id' => $this->schoolClass->id,
                'day_of_week' => 4,
                'starts_at' => '14:30',
                'ends_at' => '15:20',
            ])
            ->assertSessionHasErrors('starts_at');

        $this->inTenant($this->organization, fn () => $this->assertSame(
            1,
            $this->schoolClass->recurringLessonSlots()->count(),
        ));
    }

    /**
     * Dois tempos SEGUIDOS não se sobrepõem — 14:10–15:00 e 15:00–15:50 são o
     * caso normal de duas aulas a seguir uma à outra.
     *
     * O caso que apanha a comparação de horas feita como TEXTO: `ends_at` é uma
     * coluna `time`, guardada «15:00:00», e comparada em cru contra «15:00» dá
     * «15:00:00» > «15:00» — verdadeiro por o primeiro ser mais comprido, e não
     * por ser mais tarde.
     */
    #[Test]
    public function the_schedule_endpoint_allows_two_back_to_back_slots(): void
    {
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '14:10', 'ends_at' => '15:00']);

        $this->asTeacher()
            ->post('/lesson-slots', [
                'class_id' => $this->schoolClass->id,
                'day_of_week' => 4,
                'starts_at' => '15:00',
                'ends_at' => '15:50',
            ])
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, fn () => $this->assertSame(
            2,
            $this->schoolClass->recurringLessonSlots()->count(),
        ));
    }

    #[Test]
    public function the_schedule_endpoint_still_allows_two_different_groups_at_the_same_time(): void
    {
        $first = $this->makeGroup('T1');
        $second = $this->makeGroup('T2');
        $this->makeSlot(['class_group_id' => $first->id]);

        $this->asTeacher()
            ->post('/lesson-slots', [
                'class_id' => $this->schoolClass->id,
                'class_group_id' => $second->id,
                'day_of_week' => 4,
                'starts_at' => '09:30',
                'ends_at' => '10:20',
            ])
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, fn () => $this->assertSame(
            2,
            $this->schoolClass->recurringLessonSlots()->count(),
        ));
    }

    /**
     * §41-H. Dois tempos da mesma turma que se pisam não podem produzir duas
     * aulas: a chave única não os vê, porque inclui o tempo do horário.
     */
    #[Test]
    public function materialization_does_not_create_a_duplicate_for_overlapping_slots(): void
    {
        // Criados diretamente (e não pelo endpoint, que já os recusa) para
        // reproduzir o horário que uma instalação anterior a esta fatia pode
        // ter na base de dados.
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20']);
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '09:50', 'ends_at' => '10:40']);

        $this->asTeacher()
            ->post("/classes/{$this->schoolClass->ulid}/lessons/materialize", [
                'from' => '2026-10-08',
                'to' => '2026-10-08',
            ])
            ->assertRedirect();

        $this->inTenant($this->organization, fn () => $this->assertSame(
            1,
            Lesson::query()->where('class_id', $this->schoolClass->id)->count(),
        ));
    }

    private function conflictAt(string $startsAt, string $endsAt, ?int $classGroupId = null): ?Lesson
    {
        return $this->inTenant(
            $this->organization,
            fn () => app(LessonConflicts::class)->conflictingLesson(
                (int) $this->schoolClass->id,
                $classGroupId,
                CarbonImmutable::parse($startsAt, 'Europe/Lisbon'),
                CarbonImmutable::parse($endsAt, 'Europe/Lisbon'),
            ),
        );
    }

    #[Test]
    public function a_taught_lesson_still_counts_as_a_conflict(): void
    {
        $this->makeLesson(['status' => LessonStatus::Taught]);

        $this->assertNotNull($this->conflictAt('2026-10-08 09:30:00', '2026-10-08 10:20:00'));
    }
}
