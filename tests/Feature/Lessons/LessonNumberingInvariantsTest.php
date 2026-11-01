<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\RecordLessonOutcome;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\TeacherAbsenceReason;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * INVARIANTES DA NUMERAÇÃO sob registos de «não realizada», com cenários gerados (semente fixa, reproduzíveis):
 * uma turma com aulas lecionadas, ausências e atividades já fechadas, e outras abertas — antes e depois delas —,
 * a que se regista uma «não realizada» (retroativa ou não). Depois de cada registo:
 *
 *  1. os números das aulas FECHADAS (lecionadas e atividades da turma) não mudam — nenhum;
 *  2. nenhum número se repete;
 *  3. uma aula que não numera (ausência, não realizada) não tem número;
 *  4. os números crescem com a data (ordem cronológica), sem inversões;
 *  5. o sumário e a assiduidade de aulas fechadas ficam intactos (aqui: o estado e o resultado delas);
 *  6. repetir o resequenciamento não muda nada (idempotente) — o calendário não oscila.
 */
class LessonNumberingInvariantsTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    /** @return array<string, array{int}> */
    public static function seeds(): array
    {
        $seeds = [];

        foreach (range(1, 60) as $seed) {
            $seeds["semente {$seed}"] = [$seed];
        }

        return $seeds;
    }

    #[Test]
    #[DataProvider('seeds')]
    public function recording_a_not_held_lesson_keeps_every_numbering_invariant(int $seed): void
    {
        mt_srand($seed);
        $count = mt_rand(6, 13);
        $lessons = [];

        // Semanalmente, a partir de 14 de setembro de 2026, tudo aberto e sem número.
        for ($i = 0; $i < $count; $i++) {
            $lessons[] = $this->makeLesson(['starts_at' => CarbonImmutable::parse('2026-09-14 09:30:00', 'Europe/Lisbon')->addWeeks($i)->utc()->toDateTimeString()]);
        }

        // Uma parte do passado é lecionada, ausência ou atividade (fechada); as outras ficam abertas. As ausências
        // e as atividades registam-se ANTES de numerar, como na vida real: só as que numeram recebem número.
        $closedKinds = [];

        foreach ($lessons as $i => $lesson) {
            $roll = mt_rand(1, 100);
            $closedKinds[$i] = $roll <= 40 ? 'taught' : ($roll <= 50 ? 'absent' : ($roll <= 58 ? 'activity' : 'open'));
        }

        foreach ($lessons as $i => $lesson) {
            if ($closedKinds[$i] === 'absent') {
                $this->close($lesson, ['outcome' => LessonOutcome::TeacherAbsent, 'outcome_reason' => TeacherAbsenceReason::Training]);
            }
        }

        $this->resequence();

        foreach ($lessons as $i => $lesson) {
            if ($closedKinds[$i] === 'taught') {
                $this->close($lesson, ['status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught]);
            } elseif ($closedKinds[$i] === 'activity') {
                $this->close($lesson, ['outcome' => LessonOutcome::ClassExternalActivity]);
            }
        }

        // Números GUARDADOS desatualizados nas abertas (de uma derivação antiga): alguns fora de ordem ou repetidos.
        foreach ($lessons as $i => $lesson) {
            if ($closedKinds[$i] === 'open' && mt_rand(1, 100) <= 40) {
                $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($lesson->id)->update(['lesson_number' => mt_rand(1, $count + 3)]));
            }
        }

        $closedBefore = $this->closedNumbers();
        $open = array_values(array_filter($lessons, fn (Lesson $lesson, int $i): bool => $closedKinds[$i] === 'open', ARRAY_FILTER_USE_BOTH));

        if ($open === []) {
            $this->markTestSkipped('Cenário sem aulas abertas.');
        }

        $target = $open[mt_rand(0, count($open) - 1)];
        $hasClosedAfter = collect($lessons)->contains(fn (Lesson $lesson, int $i): bool => $closedKinds[$i] !== 'open' && $lesson->starts_at->gt($target->starts_at));

        try {
            $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute(
                $target->refresh(), LessonOutcome::NotHeld, $this->teacher, null, null, LessonNotHeldCause::SchoolNotOperating, null, null, $hasClosedAfter,
            ));
        } catch (ValidationException) {
            // Recusado com razão (por exemplo a renumeração mexeria numa fechada): nada pode ter sido escrito.
            $this->assertSame($closedBefore, $this->closedNumbers(), "semente {$seed}: uma recusa não pode deixar escrita");

            return;
        }

        $rows = $this->inTenant($this->organization, fn () => Lesson::query()->where('class_id', $this->schoolClass->id)->orderBy('starts_at')->orderBy('id')->get());

        // 1. as fechadas não mudam de número
        $this->assertSame($closedBefore, $this->closedNumbers(), "semente {$seed}: número de aula fechada mudou");

        // 2. sem duplicados   3. quem não numera não tem número
        $numbers = $rows->pluck('lesson_number')->filter(fn (mixed $n): bool => $n !== null)->values()->all();
        $this->assertSame($numbers, array_values(array_unique($numbers)), "semente {$seed}: número repetido");

        foreach ($rows as $row) {
            if ($row->outcome !== null && ! $row->outcome->isNumbered()) {
                $this->assertNull($row->lesson_number, "semente {$seed}: aula que não numera tem número");
            }
        }

        // 4. ordem cronológica, sem inversões
        $sorted = $numbers;
        sort($sorted);
        $this->assertSame($sorted, $numbers, "semente {$seed}: números fora de ordem cronológica");

        // 5. o resultado e o estado das fechadas intactos
        foreach ($lessons as $i => $lesson) {
            if ($closedKinds[$i] === 'taught') {
                $this->assertSame(LessonOutcome::Taught, $lesson->refresh()->outcome, "semente {$seed}");
                $this->assertSame(LessonStatus::Taught, $lesson->status, "semente {$seed}");
            }
        }

        // 6. idempotente: repetir não muda nada
        $before = $this->allNumbers();
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequenceAcceptingGaps((int) $this->schoolClass->id));
        $this->assertSame($before, $this->allNumbers(), "semente {$seed}: o resequenciamento não é idempotente");
    }

    /** @param  array<string, mixed>  $attributes */
    private function close(Lesson $lesson, array $attributes): void
    {
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($lesson->id)->update(
            array_map(fn (mixed $v): mixed => $v instanceof \BackedEnum ? $v->value : $v, $attributes + ['outcome_recorded_at' => now(), 'outcome_recorded_by' => $this->teacher->id]),
        ));
    }

    private function resequence(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->numberMaterializedLessons((int) $this->schoolClass->id));
    }

    /** @return array<int, int|null> número das aulas FECHADAS que numeram (lecionadas e atividades) */
    private function closedNumbers(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('class_id', $this->schoolClass->id)
            ->whereIn('outcome', [LessonOutcome::Taught->value, LessonOutcome::ClassExternalActivity->value])
            ->orderBy('id')->pluck('lesson_number', 'id')->all());
    }

    /** @return array<int, int|null> */
    private function allNumbers(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()->where('class_id', $this->schoolClass->id)->orderBy('id')->pluck('lesson_number', 'id')->all());
    }
}
