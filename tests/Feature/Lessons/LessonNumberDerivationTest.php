<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.169.0 — a regra de derivação dos números das aulas LIVRES à volta das
 * FIXAS (manuais e lecionadas), caso a caso, sobre LessonNumbering diretamente.
 *
 * Cada caso descreve a turma como uma fila de aulas, uma por dia, a partir de
 * 01/10/2026 (a posição na fila é a ordem cronológica): `free()` é uma aula
 * aberta, `taught(n)` uma lecionada com o número n, `manual(n, fim)` uma com o
 * número indicado pelo professor, e `stored(n)` uma aberta que já tinha um
 * número guardado (o que o antigo «maior número + 1» deixou).
 */
class LessonNumberDerivationTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    /** @var list<Lesson> */
    private array $queue = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    #[Test]
    public function a_class_without_fixed_lessons_numbers_forwards_from_one(): void
    {
        $this->queue(['free', 'free', 'free', 'free']);

        $this->derive();

        $this->assertNumbers([1, 2, 3, 4]);
    }

    #[Test]
    public function free_lessons_after_taught_history_continue_it(): void
    {
        $this->queue([['taught', 1], ['taught', 2], ['taught', 3], ['taught', 4], ['taught', 5], 'free', 'free', 'free']);

        $this->derive();

        $this->assertNumbers([1, 2, 3, 4, 5, 6, 7, 8]);
    }

    #[Test]
    public function free_lessons_after_a_manual_first_lesson_follow_it(): void
    {
        $this->queue([['manual', 11], 'free', 'free', 'free']);

        $this->derive();

        $this->assertNumbers([11, 12, 13, 14]);
    }

    #[Test]
    public function a_manual_range_makes_the_next_lesson_start_after_its_end(): void
    {
        $this->queue([['manual', 11, 12], 'free']);

        $this->derive();

        $this->assertNumbers([11, 13]);
        $this->assertSame('11–12', $this->queue[0]->fresh()?->lessonNumberLabel());
    }

    #[Test]
    public function free_lessons_before_a_manual_one_number_backwards_from_it(): void
    {
        $this->queue(['free', 'free', ['manual', 11]]);

        $this->derive();

        $this->assertNumbers([9, 10, 11]);
    }

    #[Test]
    public function free_lessons_before_taught_history_have_no_room_and_stay_unnumbered(): void
    {
        $this->queue(['free', 'free', ['taught', 1]]);

        $this->derive();

        $this->assertNumbers([null, null, 1]);
    }

    #[Test]
    public function a_free_lesson_between_two_consecutive_manual_ones_has_no_number(): void
    {
        $this->queue([['manual', 11], 'free', ['manual', 12]]);

        $this->derive();

        $this->assertNumbers([11, null, 12]);
    }

    #[Test]
    public function an_incoherent_history_after_a_manual_lesson_makes_the_next_free_one_skip_the_occupied_numbers(): void
    {
        $this->queue([['manual', 11], ['taught', 2], ['taught', 3], ['taught', 4], ['taught', 5], ['taught', 6], ['taught', 7], ['taught', 8], ['taught', 9], ['taught', 10], 'free']);

        $this->derive();

        $this->assertNumbers([11, 2, 3, 4, 5, 6, 7, 8, 9, 10, 12]);
    }

    #[Test]
    public function the_free_lessons_between_two_manual_ones_take_the_numbers_in_between(): void
    {
        $this->queue([['manual', 11], 'free', 'free', 'free', ['manual', 15]]);

        $this->derive();

        $this->assertNumbers([11, 12, 13, 14, 15]);
    }

    #[Test]
    public function one_free_lesson_too_many_between_two_manual_ones_is_left_unnumbered(): void
    {
        $this->queue([['manual', 11], 'free', 'free', 'free', 'free', ['manual', 15]]);

        $this->derive();

        $this->assertNumbers([11, 12, 13, 14, null, 15]);
    }

    /**
     * O que o antigo «maior número + 1» deixou: 5 e 6 guardados antes de uma
     * Lição 1 lecionada. A derivação nova não lhes muda o número, e a aula nova
     * salta-os.
     */
    #[Test]
    public function numbers_stored_by_the_old_fallback_are_kept_and_never_duplicated(): void
    {
        $this->queue([['stored', 5], ['stored', 6], ['taught', 1], ['taught', 2], ['taught', 3], ['stored', 4], 'free']);

        $this->derive();

        $this->assertNumbers([5, 6, 1, 2, 3, 4, 7]);
    }

    /**
     * Um número MANTIDO por falta de lugar (o 3, antigo) não faz recuar a
     * contagem: a livre seguinte, no mesmo intervalo sem lugar entre 11 e 12,
     * também fica sem número em vez de receber um 4 fora de ordem.
     */
    #[Test]
    public function a_kept_lower_number_does_not_drag_the_next_free_lesson_out_of_order(): void
    {
        $this->queue([['manual', 11], ['stored', 3], 'free', ['manual', 12]]);

        $this->derive();

        $this->assertNumbers([11, 3, null, 12]);
    }

    #[Test]
    public function a_free_lesson_whose_stored_number_is_taken_loses_it(): void
    {
        $this->queue([['manual', 11], ['stored', 12], ['manual', 12]]);

        $this->derive();

        $this->assertNumbers([11, null, 12]);
    }

    #[Test]
    public function a_derived_number_replaces_a_stale_one_when_there_is_room(): void
    {
        $this->queue([['stored', 9], ['stored', 9], 'free']);

        $this->derive();

        $this->assertNumbers([1, 2, 3]);
    }

    #[Test]
    public function the_strict_guard_still_refuses_to_renumber_taught_history_without_manual_numbers(): void
    {
        $this->queue(['free', ['taught', 1]]);

        try {
            $this->resequence();
            $this->fail('A guarda devia ter recusado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lesson_number', $exception->errors());
        }

        $this->assertNumbers([null, 1]);
    }

    #[Test]
    public function a_manual_lesson_makes_the_strict_guard_restart_its_count(): void
    {
        // Lição 11 manual e, depois, uma lecionada 12 e uma aberta: coerente.
        $this->queue([['manual', 11], ['taught', 12], 'free']);

        $this->resequence();

        $this->assertNumbers([11, 12, 13]);
    }

    #[Test]
    public function the_guard_snapshot_only_protects_what_was_coherent(): void
    {
        // A segunda lecionada já está incoerente (é a 2.ª posição e tem o 7): a
        // guarda de sempre recusa tudo por causa dela; com a fotografia, só conta
        // a primeira, que está coerente.
        $this->queue([['taught', 1], ['taught', 7], 'free']);
        $numbering = app(LessonNumbering::class);
        $classId = (int) $this->schoolClass->id;

        $coherent = $this->inTenant($this->organization, fn (): array => $numbering->guardSnapshot($classId));
        $this->assertSame([$this->queue[0]->id => 1], $coherent);

        try {
            $this->inTenant($this->organization, fn () => $numbering->resequence($classId));
            $this->fail('Sem fotografia a guarda devia recusar por causa da incoerente.');
        } catch (ValidationException) {
            // Esperado: a turma ficava presa.
        }

        // Com a fotografia, passa.
        $this->inTenant($this->organization, fn () => $numbering->resequence($classId, $coherent));
        $this->assertNumbers([1, 7, 8]);
    }

    #[Test]
    public function the_guard_snapshot_refuses_when_a_coherent_lesson_would_move(): void
    {
        $this->queue([['taught', 1], ['taught', 7], 'free']);
        $numbering = app(LessonNumbering::class);
        $classId = (int) $this->schoolClass->id;
        $coherent = $this->inTenant($this->organization, fn (): array => $numbering->guardSnapshot($classId));

        // Uma aula nova ANTES da Lição 1 empurra-a para a posição 2.
        $this->makeLesson(['starts_at' => '2026-09-30 09:30:00']);

        $this->expectException(ValidationException::class);
        $this->inTenant($this->organization, fn () => $numbering->resequence($classId, $coherent));
    }

    /**
     * @param  list<string|array{0: string, 1: int, 2?: int}>  $spec
     */
    private function queue(array $spec): void
    {
        foreach ($spec as $index => $entry) {
            [$kind, $number, $end] = array_pad((array) $entry, 3, null);
            $day = CarbonImmutable::parse('2026-10-01')->addDays($index)->toDateString();

            $attributes = ['starts_at' => $day.' 09:30:00', 'ends_at' => $day.' 10:20:00'];

            $attributes += match ($kind) {
                'taught' => ['status' => LessonStatus::Taught, 'lesson_number' => $number],
                'manual' => ['lesson_number' => $number, 'lesson_number_end' => $end, 'lesson_number_manual' => true],
                'stored' => ['lesson_number' => $number],
                default => [],
            };

            $this->queue[] = $this->makeLesson($attributes);
        }
    }

    /** O caminho automático: a derivação, sem guarda e sem nunca falhar. */
    private function derive(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->numberMaterializedLessons((int) $this->schoolClass->id));
    }

    /** O caminho estrito, com a guarda do histórico. */
    private function resequence(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));
    }

    /**
     * @param  list<int|null>  $expected
     */
    private function assertNumbers(array $expected): void
    {
        $this->inTenant($this->organization, function () use ($expected): void {
            $this->assertSame(
                $expected,
                Lesson::query()->orderBy('starts_at')->get()->map(fn (Lesson $lesson): ?int => $lesson->lesson_number)->all(),
            );
        });
    }
}
