<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\AnnulTaughtLesson;
use App\Actions\Lessons\MarkLessonAsTaught;
use App\Models\AttendanceStatus;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * Anular um «Lecionada» dado por engano: a aula não se realizou. A ação é a
 * única que desfaz um fecho, e faz tudo o que o registo normal de «Aula não
 * realizada» faria — sem reescrever nenhuma aula lecionada depois dela.
 */
class AnnulTaughtLessonTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        config(['lapis.lesson_not_held_enabled' => true]);
    }

    /** @return list<Lesson> quatro quintas-feiras numeradas 1–4, todas abertas */
    private function fourNumbered(): array
    {
        $slot = $this->makeSlot();
        $lessons = array_map(fn (string $day): Lesson => $this->makeLesson([
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => "2026-10-{$day} {$slot->starts_at}",
        ]), ['08', '15', '22', '29']);
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));

        return $lessons;
    }

    /**
     * Fecha pela via real: estado, resultado, titular e assiduidade consolidada.
     *
     * @param  list<string>  $absentStudentUlids
     */
    private function teach(Lesson $lesson, array $absentStudentUlids = []): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => app(MarkLessonAsTaught::class)->execute($lesson->refresh(), $this->teacher, $absentStudentUlids));
    }

    private function summary(Lesson $lesson, string $content): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => $content, 'homework' => 'TPC '.$content]));
    }

    /** @return array<string, mixed> */
    private function annul(Lesson $lesson, LessonNotHeldCause $cause = LessonNotHeldCause::WeatherClosure, string $motive = 'Não houve aula: escola encerrada por mau tempo.'): array
    {
        return $this->inTenant($this->organization, fn (): array => app(AnnulTaughtLesson::class)->execute($lesson->refresh(), $this->teacher, $cause, $motive));
    }

    #[Test]
    public function without_closed_lessons_after_the_summary_moves_on_and_the_open_ones_renumber_without_a_gap(): void
    {
        [$a, $b, $c, $d] = $this->fourNumbered();
        $this->enroll('Aluno Um');
        $this->teach($a);
        $this->teach($b);
        $this->summary($b, 'Elementos da narrativa');

        $result = $this->annul($b);

        $this->inTenant($this->organization, function () use ($a, $b, $c, $d, $result): void {
            $b->refresh();
            $this->assertSame(LessonOutcome::NotHeld, $b->outcome);
            $this->assertSame(LessonNotHeldCause::WeatherClosure, $b->outcome_cause);
            $this->assertSame(LessonStatus::Preparation, $b->status);
            $this->assertNull($b->lesson_number);
            $this->assertNull($b->attendance_recorded_at);
            $this->assertSame(0, $b->attendances()->count());
            $this->assertNull($b->summary()->first());
            // O titular continua a ser quem fechou: é ele que lê o histórico.
            $this->assertSame($this->teacher->id, $b->outcome_recorded_by);

            // O sumário desceu para a aula seguinte, e a numeração fechou sem lacuna.
            $this->assertSame('Elementos da narrativa', $c->refresh()->summary()->first()?->content);
            $this->assertSame(1, $a->refresh()->lesson_number);
            $this->assertSame(2, $c->lesson_number);
            $this->assertSame(3, $d->refresh()->lesson_number);
            $this->assertFalse($result['summary_kept_in_history']);
            $this->assertSame(1, $result['planning']['shifted']);

            $rectification = LessonOutcomeRectification::query()->where('lesson_id', $b->id)->sole();
            $this->assertSame(LessonOutcomeRectification::KIND_ANNULMENT, $rectification->kind);
            $this->assertSame('taught', $rectification->previous['outcome']);
            $this->assertSame(2, $rectification->previous['lesson_number']);
            $this->assertArrayNotHasKey('summary_content', $rectification->previous);
            $this->assertSame('not_held', $rectification->current['outcome']);
            $this->assertSame('weather_closure', $rectification->current['cause']);
            $this->assertSame($this->teacher->id, $rectification->rectified_by);
        });
    }

    #[Test]
    public function with_a_closed_lesson_after_the_summary_goes_to_the_history_and_the_gap_stays(): void
    {
        [$a, $b, $c, $d] = $this->fourNumbered();
        $this->teach($a);
        $this->teach($b);
        $this->summary($b, 'Texto da aula que não houve');
        $this->teach($c);
        $this->summary($c, 'Aula seguinte, lecionada');

        $result = $this->annul($b);

        $this->inTenant($this->organization, function () use ($a, $b, $c, $d, $result): void {
            $this->assertNull($b->refresh()->lesson_number);
            $this->assertNull($b->summary()->first());
            $this->assertTrue($result['summary_kept_in_history']);
            // Nenhuma lecionada muda; a lacuna fica no 2; a aberta a seguir continua 4.
            $this->assertSame(1, $a->refresh()->lesson_number);
            $this->assertSame(3, $c->refresh()->lesson_number);
            $this->assertSame('Aula seguinte, lecionada', $c->summary()->first()?->content);
            $this->assertSame(4, $d->refresh()->lesson_number);

            $rectification = LessonOutcomeRectification::query()->where('lesson_id', $b->id)->sole();
            $this->assertSame('Texto da aula que não houve', $rectification->previous['summary_content']);
            $this->assertSame('TPC Texto da aula que não houve', $rectification->previous['summary_homework']);

            $event = AuditEvent::query()->where('event', 'lesson.taught_annulled')->where('subject_id', $b->id)->sole();
            $this->assertTrue($event->properties['summary_kept_in_history']);
            $this->assertSame([2], $event->properties['gaps']);
            $this->assertSame(2, $event->properties['from_lesson_number']);
            $this->assertStringNotContainsString('Texto da aula', (string) json_encode($event->properties));
        });
    }

    #[Test]
    public function the_attendance_is_deleted_absences_included_and_only_counted_in_the_audit(): void
    {
        [$a] = $this->fourNumbered();
        $um = $this->enroll('Aluno Um');
        $this->enroll('Aluno Dois');
        $this->teach($a, [$um->student->ulid]);

        $this->inTenant($this->organization, fn () => $this->assertSame(1, $a->refresh()->attendances()->where('status', AttendanceStatus::Absent)->count()));

        $this->annul($a);

        $this->inTenant($this->organization, function () use ($a): void {
            $this->assertSame(0, $a->refresh()->attendances()->count());
            $this->assertNull($a->attendance_recorded_at);
            $this->assertNull($a->attendance_recorded_by);
            $event = AuditEvent::query()->where('event', 'lesson.taught_annulled')->where('subject_id', $a->id)->sole();
            $this->assertSame(2, $event->properties['attendance_rows_deleted']);
            $this->assertSame(1, $event->properties['absences_deleted']);
        });
    }

    #[Test]
    public function a_manual_number_is_handed_over_to_the_next_free_unit(): void
    {
        [$a, $b, $c] = $this->fourNumbered();
        $this->teach($a);
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($b->id)->update(['lesson_number' => 7, 'lesson_number_manual' => true]));
        $this->teach($b);

        $this->annul($b);

        $this->inTenant($this->organization, function () use ($b, $c): void {
            $this->assertNull($b->refresh()->lesson_number);
            $this->assertFalse($b->lesson_number_manual);
            $this->assertSame(7, $c->refresh()->lesson_number);
            $this->assertTrue($c->lesson_number_manual);
        });
    }

    #[Test]
    public function refuses_a_lesson_that_is_not_taught_a_shared_unit_and_an_other_cause_without_a_note_and_writes_nothing(): void
    {
        [$a, $b] = $this->fourNumbered();
        $this->teach($b);

        foreach ([
            [$a, LessonNotHeldCause::WeatherClosure, 'outcome'],
            [$b, LessonNotHeldCause::Other, 'cause_note'],
        ] as [$lesson, $cause, $key]) {
            try {
                $this->inTenant($this->organization, fn () => app(AnnulTaughtLesson::class)->execute($lesson->refresh(), $this->teacher, $cause, 'Motivo válido'));
                $this->fail('devia recusar');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
            }
        }

        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($b->id)->update(['lesson_unit_key' => '01JABCDEFGHJKMNPQRSTVWXYZ0']));

        try {
            $this->annul($b);
            $this->fail('devia recusar');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('outcome', $exception->errors());
        }

        $this->inTenant($this->organization, function () use ($b): void {
            $this->assertSame(LessonOutcome::Taught, $b->refresh()->outcome);
            $this->assertSame(2, $b->lesson_number);
            $this->assertSame(0, LessonOutcomeRectification::query()->count());
        });
    }

    #[Test]
    public function a_short_motive_is_refused(): void
    {
        [$a] = $this->fourNumbered();
        $this->teach($a);

        $this->expectException(ValidationException::class);
        $this->annul($a, LessonNotHeldCause::WeatherClosure, 'ok');
    }
}
