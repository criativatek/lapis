<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\RecordLessonOutcome;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * PR-3 — registo RETROATIVO de uma aula não realizada: uma aula aberta e VAZIA
 * que ficou para trás de aulas já fechadas. Nenhuma lecionada muda de número,
 * nada se perde, e o buraco fica onde a aula estava.
 *
 * Calendário de outubro de 2026: as quintas-feiras são 08, 15, 22 e 29.
 */
class LessonNotHeldRetroactiveTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    #[Test]
    public function an_empty_open_lesson_before_a_taught_one_is_recorded_leaving_a_gap_and_changing_no_taught_number(): void
    {
        [$a, $b, $c, $d] = $this->numberedWithThirdTaught();
        $this->summary($d, 'Plano D');

        $this->recordRetroactively($b);

        $this->inTenant($this->organization, function () use ($a, $b, $c, $d): void {
            $this->assertSame(LessonOutcome::NotHeld, $b->refresh()->outcome);
            $this->assertNull($b->lesson_number);
            // As abertas ANTES de uma lecionada contam para trás a partir dela (regra
            // da numeração, não desta operação): a 1.ª aberta passa a n.º 2 e a lacuna
            // fica no início. O que não pode mudar é a lecionada.
            $this->assertSame(2, $a->refresh()->lesson_number);
            $this->assertSame(3, $c->refresh()->lesson_number);
            $this->assertSame(LessonStatus::Taught, $c->status);
            $this->assertSame(4, $d->refresh()->lesson_number);
            $this->assertSame('Plano D', $d->summary()->first()?->content);

            $event = AuditEvent::query()->where('event', 'lesson.outcome_recorded')->where('subject_id', $b->id)->sole();
            $this->assertTrue($event->properties['retroactive']);
            $this->assertSame([1], $event->properties['gaps']);
            $this->assertEquals([['from' => 1, 'to' => 2], ['from' => 2, 'to' => null]], $event->properties['renumbered_numbers']);
            $this->assertSame('school_not_operating', $event->properties['cause']);
            $this->assertSame(0, $event->properties['planning_shifted']);
        });
    }

    #[Test]
    public function without_the_retroactive_request_the_old_refusal_stands_and_nothing_is_written(): void
    {
        [, $b, $c] = $this->numberedWithThirdTaught();

        try {
            $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute(
                $b, LessonOutcome::NotHeld, $this->teacher, null, null, LessonNotHeldCause::SchoolNotOperating,
            ));
            $this->fail('devia recusar');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('outcome', $exception->errors());
        }

        $this->inTenant($this->organization, function () use ($b, $c): void {
            $this->assertNull($b->refresh()->outcome);
            $this->assertSame(2, $b->lesson_number);
            $this->assertSame(3, $c->refresh()->lesson_number);
        });
    }

    #[Test]
    public function the_retroactive_path_exists_only_for_not_held(): void
    {
        [, $b] = $this->numberedWithThirdTaught();

        $this->expectException(ValidationException::class);
        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute(
            $b, LessonOutcome::ClassExternalActivity, $this->teacher, null, null, null, null, null, true,
        ));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonEmptyLessons(): array
    {
        return [
            'sumário escrito' => ['summary'],
            'planeamento pendente' => ['plan'],
            'faltas em rascunho' => ['draft'],
            'número indicado pelo professor' => ['manual'],
            'lição partilhada com outro grupo' => ['unit'],
        ];
    }

    #[DataProvider('nonEmptyLessons')]
    #[Test]
    public function a_lesson_with_content_is_refused_with_the_reason_and_nothing_is_lost(string $kind): void
    {
        [, $b, $c] = $this->numberedWithThirdTaught();
        $student = $this->enroll('Aluno Um');

        $this->inTenant($this->organization, function () use ($kind, $b): void {
            match ($kind) {
                'summary' => LessonSummary::query()->create(['lesson_id' => $b->id, 'content' => 'Texto precioso']),
                'plan' => LessonPlan::query()->create(['lesson_id' => $b->id, 'planned_summary' => 'Plano guardado']),
                'manual' => Lesson::query()->whereKey($b->id)->update(['lesson_number_manual' => true]),
                'unit' => Lesson::query()->whereKey($b->id)->update(['lesson_unit_key' => '01JABCDEFGHJKMNPQRSTVWXYZ0']),
                default => null,
            };
        });

        if ($kind === 'draft') {
            $this->asTeacher()->put("/lessons/{$b->ulid}/attendance/draft", ['absent' => [$student->student->ulid]])->assertSessionHasNoErrors();
        }

        try {
            $this->recordRetroactively($b);
            $this->fail('devia recusar');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('trata-a individualmente', $exception->errors()['outcome'][0]);
        }

        $this->inTenant($this->organization, function () use ($kind, $b, $c): void {
            $this->assertNull($b->refresh()->outcome);
            $this->assertSame(3, $c->refresh()->lesson_number);

            match ($kind) {
                'summary' => $this->assertSame('Texto precioso', $b->summary()->first()?->content),
                'plan' => $this->assertSame('Plano guardado', LessonPlan::query()->where('lesson_id', $b->id)->value('planned_summary')),
                default => null,
            };
        });
    }

    #[Test]
    public function a_blank_summary_row_does_not_count_as_content(): void
    {
        [, $b] = $this->numberedWithThirdTaught();
        $this->inTenant($this->organization, fn () => LessonSummary::query()->create(['lesson_id' => $b->id, 'content' => '']));

        $this->recordRetroactively($b);

        $this->inTenant($this->organization, fn () => $this->assertSame(LessonOutcome::NotHeld, $b->refresh()->outcome));
    }

    #[Test]
    public function a_consolidated_attendance_still_refuses_even_when_retroactive(): void
    {
        [, $b] = $this->numberedWithThirdTaught();
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($b->id)->update(['attendance_recorded_at' => now()]));

        $this->expectException(ValidationException::class);
        $this->recordRetroactively($b);
    }

    #[Test]
    public function the_http_request_takes_the_retroactive_flag_only_with_the_feature_on_and_only_for_not_held(): void
    {
        [, $b] = $this->numberedWithThirdTaught();

        $this->asTeacher()->post("/lessons/{$b->ulid}/outcome", ['outcome' => 'not_held', 'cause' => 'weather_closure', 'retroactive' => true])
            ->assertSessionHasErrors('outcome');
        $this->inTenant($this->organization, fn () => $this->assertNull($b->refresh()->outcome));

        config(['lapis.lesson_not_held_enabled' => true]);

        $this->asTeacher()->post("/lessons/{$b->ulid}/outcome", ['outcome' => 'not_held', 'cause' => 'weather_closure'])
            ->assertSessionHasErrors('outcome');
        $this->asTeacher()->post("/lessons/{$b->ulid}/outcome", ['outcome' => 'class_external_activity', 'retroactive' => true])
            ->assertSessionHasErrors('retroactive');

        $this->asTeacher()->post("/lessons/{$b->ulid}/outcome", ['outcome' => 'not_held', 'cause' => 'weather_closure', 'retroactive' => true])
            ->assertSessionHasNoErrors();
        $this->inTenant($this->organization, fn () => $this->assertSame(LessonOutcome::NotHeld, $b->refresh()->outcome));
    }

    #[Test]
    public function two_retroactive_records_keep_every_taught_number_and_leave_both_gaps(): void
    {
        $slot = $this->makeSlot();
        $lessons = $this->thursdays($slot, ['08', '15', '22', '29']);
        $this->resequence();
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($lessons[3]->id)->update(['status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught->value]));

        $this->recordRetroactively($lessons[0]);
        $this->recordRetroactively($lessons[1]);

        $this->inTenant($this->organization, function () use ($lessons): void {
            $this->assertNull($lessons[0]->refresh()->lesson_number);
            $this->assertNull($lessons[1]->refresh()->lesson_number);
            $this->assertSame(3, $lessons[2]->refresh()->lesson_number);
            $this->assertSame(4, $lessons[3]->refresh()->lesson_number);
        });
    }

    // ------------------------------------------------------------------

    /**
     * Quatro quintas-feiras numeradas 1–4; a terceira lecionada (fixa, n.º 3).
     *
     * @return list<Lesson>
     */
    private function numberedWithThirdTaught(): array
    {
        $slot = $this->makeSlot();
        $lessons = $this->thursdays($slot, ['08', '15', '22', '29']);
        $this->resequence();
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($lessons[2]->id)->update(['status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught->value]));

        return $lessons;
    }

    private function recordRetroactively(Lesson $lesson): void
    {
        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute(
            $lesson, LessonOutcome::NotHeld, $this->teacher, null, null, LessonNotHeldCause::SchoolNotOperating, null, null, true,
        ));
    }

    /**
     * @param  list<string>  $days
     * @return list<Lesson>
     */
    private function thursdays(RecurringLessonSlot $slot, array $days): array
    {
        return array_map(fn (string $day): Lesson => $this->makeLesson([
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => "2026-10-{$day} {$slot->starts_at}",
        ]), $days);
    }

    private function summary(Lesson $lesson, string $content): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => $content]));
    }

    private function resequence(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));
    }
}
