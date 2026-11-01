<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * As aulas abertas ANTERIORES à primeira aula lecionada conservam o número que
 * o professor já via (a lacuna fica onde uma aula deixou de numerar). Só uma
 * âncora MANUAL conta para trás, e um número guardado fora de ordem não vence.
 *
 * Quintas-feiras de outubro de 2026: 08, 15, 22 e 29.
 */
class LessonNumberingStoredNumbersTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    #[Test]
    public function open_lessons_before_a_taught_one_keep_their_stored_numbers_across_a_resequence(): void
    {
        [$a, $b, $c, $d] = $this->thursdays();
        $this->resequence();
        $this->teach($c);
        // Uma aula do meio deixa de numerar (por exemplo, uma não realizada).
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($b->id)->update(['outcome' => LessonOutcome::NotHeld->value, 'lesson_number' => null, 'status' => LessonStatus::Preparation]));

        // O resequenciamento estrito recusaria (a lecionada passaria de 3 a 2); o
        // que aceita lacunas, usado pelo registo retroativo, fixa o histórico.
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequenceAcceptingGaps((int) $this->schoolClass->id));
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequenceAcceptingGaps((int) $this->schoolClass->id));

        $this->inTenant($this->organization, function () use ($a, $c, $d): void {
            $this->assertSame([1, 3, 4], [$a->refresh()->lesson_number, $c->refresh()->lesson_number, $d->refresh()->lesson_number]);
        });
    }

    #[Test]
    public function a_manual_anchor_still_counts_backwards_from_the_number_the_teacher_indicated(): void
    {
        [$a, $b, $c] = $this->thursdays();
        $this->resequence();
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($c->id)->update(['lesson_number' => 12, 'lesson_number_manual' => true]));

        $this->resequence();

        $this->inTenant($this->organization, function () use ($a, $b, $c): void {
            $this->assertSame([10, 11, 12], [$a->refresh()->lesson_number, $b->refresh()->lesson_number, $c->refresh()->lesson_number]);
        });
    }

    #[Test]
    public function stored_numbers_out_of_order_do_not_beat_the_order_of_the_lessons(): void
    {
        [$a, $b, $c] = $this->thursdays();
        $this->resequence();
        $this->teach($c);
        $this->inTenant($this->organization, function () use ($a, $b): void {
            Lesson::query()->whereKey($a->id)->update(['lesson_number' => 5]);
            Lesson::query()->whereKey($b->id)->update(['lesson_number' => 2]);
        });

        $this->resequence();

        $this->inTenant($this->organization, function () use ($a, $b, $c): void {
            $this->assertSame([1, 2, 3], [$a->refresh()->lesson_number, $b->refresh()->lesson_number, $c->refresh()->lesson_number]);
        });
    }

    #[Test]
    public function a_taught_number_never_changes_whatever_the_open_lessons_before_it_do(): void
    {
        [$a, $b, $c] = $this->thursdays();
        $this->resequence();
        $this->teach($c);

        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($a->id)->update(['lesson_number' => null]));
        $this->resequence();

        $this->inTenant($this->organization, function () use ($a, $b, $c): void {
            $this->assertSame(3, $c->refresh()->lesson_number);
            $this->assertSame([1, 2], [$a->refresh()->lesson_number, $b->refresh()->lesson_number]);
        });
    }

    /**
     * @return list<Lesson>
     */
    private function thursdays(): array
    {
        $slot = $this->makeSlot();

        return array_map(fn (string $day): Lesson => $this->makeLesson([
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => "2026-10-{$day} {$slot->starts_at}",
        ]), ['08', '15', '22', '29']);
    }

    private function teach(Lesson $lesson): void
    {
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($lesson->id)->update(['status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught->value]));
    }

    private function resequence(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));
    }
}
