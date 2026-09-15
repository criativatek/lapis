<?php

namespace Tests\Feature\Lessons;

use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\RecurringLessonSlot;
use App\Services\Lessons\SplitLessonKeyInference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.145.3 — o backfill não conta como «segundo tempo semanal» um tempo que já
 * acabou, nunca teve aulas e não tem vínculo. Caso real: 8.º F, onde o tempo
 * de sexta de T1 terminou a 08/09 e o de quarta que o substituiu foi criado
 * sem `starts_on`.
 *
 * Relógio: 2026-09-15 (terça). Setembro de 2026: sexta 04, terça 08, sexta 11,
 * quarta 16.
 */
class SplitLessonKeyInferenceStaleSlotsTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    private ClassGroup $t1;

    private ClassGroup $t2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-15 10:00:00');
        $this->bootLessonFixtures();
        $this->t1 = $this->makeGroup('T1');
        $this->t2 = $this->makeGroup('T2');
    }

    /** O 8.º F: A expirado e vazio, B atual sem `starts_on`, C atual → B ↔ C. */
    #[Test]
    public function the_eighth_f_case_pairs_the_current_slots_and_leaves_the_stale_one_alone(): void
    {
        $stale = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 5, 'ends_on' => '2026-09-08']);
        $t1Current = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 3, 'starts_at' => '08:30', 'ends_at' => '09:20']);
        $t2Current = $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 3, 'starts_at' => '09:20', 'ends_at' => '10:10']);

        $result = $this->infer();
        $this->assertSame(SplitLessonKeyInference::Unambiguous, $result['status']);
        $this->assertSame([$t1Current->id, $t2Current->id], array_keys($result['keys']));
        $this->assertSame($result['keys'][$t1Current->id], $result['keys'][$t2Current->id]);

        $lessons = [
            $this->makeLesson(['starts_at' => '2026-09-11 09:30:00', 'lesson_number' => 1]),
            $this->makeLesson(['starts_at' => '2026-09-11 10:20:00', 'lesson_number' => 2]),
            $this->makeLesson(['starts_at' => '2026-09-15 15:10:00', 'lesson_number' => 3]),
            $this->onSlot($t1Current, '2026-09-16 08:30:00', 1),
            $this->onSlot($t2Current, '2026-09-16 09:20:00', 1),
            $this->makeLesson(['starts_at' => '2026-09-18 09:30:00', 'lesson_number' => 4]),
        ];

        $this->artisan('lapis:renumber-lessons')
            ->expectsOutputToContain('EMPARELHAMENTO INEQUÍVOCO — 2 tempo(s) a ligar')
            ->assertSuccessful();
        $this->assertNumbers([1, 2, 3, 1, 1, 4], $lessons);

        $this->artisan('lapis:renumber-lessons', ['--apply' => true])->assertSuccessful();
        $this->assertNumbers([1, 2, 3, 4, 4, 5], $lessons);

        $this->inTenant($this->organization, function () use ($stale, $t1Current, $t2Current): void {
            $this->assertNull($stale->fresh()?->split_lesson_key);
            $this->assertNotNull($t1Current->fresh()?->split_lesson_key);
            $this->assertSame($t1Current->fresh()?->split_lesson_key, $t2Current->fresh()?->split_lesson_key);
        });

        // F) reexecutar não encontra nada.
        $this->artisan('lapis:renumber-lessons', ['--apply' => true])
            ->expectsOutputToContain('Renumeradas: 0 turma(s), 0 aula(s).')
            ->assertSuccessful();
        $this->assertSame(SplitLessonKeyInference::AlreadyLinked, $this->infer()['status']);
    }

    /** B) um tempo expirado COM aulas conta: com sucessor datado é a mesma linhagem, e é ligado. */
    #[Test]
    public function an_expired_slot_with_lessons_still_takes_part(): void
    {
        $old = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 5, 'ends_on' => '2026-09-08']);
        $this->onSlot($old, '2026-09-04 09:30:00', 1);
        $successor = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 3, 'starts_on' => '2026-09-09']);
        $t2 = $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 3]);

        $result = $this->infer();
        $this->assertSame(SplitLessonKeyInference::Unambiguous, $result['status']);
        $this->assertEqualsCanonicalizing([$old->id, $successor->id, $t2->id], array_keys($result['keys']));
    }

    /** B) …e sem data que prove a sucessão, as aulas históricas mantêm a turma ambígua. */
    #[Test]
    public function an_expired_slot_with_lessons_and_an_undated_successor_stays_ambiguous(): void
    {
        $old = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 5, 'ends_on' => '2026-09-08']);
        $this->onSlot($old, '2026-09-04 09:30:00', 1);
        $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 3]);
        $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 3]);

        $this->assertSame(SplitLessonKeyInference::Ambiguous, $this->infer()['status']);
    }

    /** C) versões sucessivas que não se sobrepõem, ainda nenhuma expirada. */
    #[Test]
    public function successive_non_overlapping_versions_are_one_lineage(): void
    {
        $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 5, 'ends_on' => '2026-12-31']);
        $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 3, 'starts_on' => '2027-01-01']);
        $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 3]);

        $this->assertSame(SplitLessonKeyInference::Unambiguous, $this->infer()['status']);
    }

    /** D) dois tempos do mesmo grupo em vigor ao mesmo tempo continuam ambíguos. */
    #[Test]
    public function two_current_slots_of_one_group_are_ambiguous(): void
    {
        $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 5]);
        $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 3]);
        $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 3]);

        $result = $this->infer();
        $this->assertSame(SplitLessonKeyInference::Ambiguous, $result['status']);
        $this->assertSame('um grupo tem mais de um tempo semanal', $result['reason']);
    }

    /** E) um tempo antigo com vínculo explícito conta e nunca é reescrito. */
    #[Test]
    public function an_old_slot_with_an_explicit_link_is_preserved(): void
    {
        $key = (string) Str::ulid();
        $old = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 5, 'ends_on' => '2026-09-08', 'split_lesson_key' => $key]);
        $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 3]);
        $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 3]);

        $this->assertSame(SplitLessonKeyInference::Ambiguous, $this->infer()['status']);

        $this->artisan('lapis:renumber-lessons', ['--apply' => true])->assertSuccessful();

        $this->inTenant($this->organization, function () use ($old, $key): void {
            $this->assertSame($key, $old->fresh()?->split_lesson_key);
            $this->assertSame(1, RecurringLessonSlot::query()->whereNotNull('split_lesson_key')->count());
        });
    }

    /**
     * @return array{status: string, reason: string|null, keys: array<int, string>}
     */
    private function infer(): array
    {
        return $this->inTenant(
            $this->organization,
            fn (): array => app(SplitLessonKeyInference::class)->infer((int) $this->schoolClass->id),
        );
    }

    private function onSlot(RecurringLessonSlot $slot, string $startsAt, int $number): Lesson
    {
        return $this->makeLesson([
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => $startsAt,
            'lesson_number' => $number,
        ]);
    }

    /**
     * @param  list<int>  $expected
     * @param  list<Lesson>  $lessons
     */
    private function assertNumbers(array $expected, array $lessons): void
    {
        $this->inTenant($this->organization, function () use ($expected, $lessons): void {
            $this->assertSame($expected, array_map(fn (Lesson $lesson): ?int => $lesson->fresh()?->lesson_number, $lessons));
        });
    }
}
