<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\RecordLessonOutcome;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\TeacherStrikeSituation;
use App\Models\User;
use App\Services\Lessons\ClassLessonRecord;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * PR-1 — `not_held`: aula prevista e NÃO realizada, com causa estruturada.
 * Calendário de outubro de 2026: as quintas-feiras são 08, 15, 22 e 29.
 */
class LessonNotHeldTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    // ------------------------------------------------------------------
    // Predicados: nenhum caso do enum fica por definir
    // ------------------------------------------------------------------

    #[Test]
    public function every_outcome_answers_every_predicate_and_not_held_is_a_closed_unnumbered_non_taught_one(): void
    {
        foreach (LessonOutcome::cases() as $outcome) {
            $this->assertIsBool($outcome->isNumbered(), $outcome->value);
            $this->assertIsBool($outcome->countsAsTaught(), $outcome->value);
            $this->assertIsBool($outcome->developsSubject(), $outcome->value);
            $this->assertIsBool($outcome->takesAttendance(), $outcome->value);
            $this->assertIsBool($outcome->shiftsPlanning(), $outcome->value);
            $this->assertNotSame('', $outcome->label());
        }

        $notHeld = LessonOutcome::NotHeld;
        $this->assertFalse($notHeld->isNumbered());
        $this->assertFalse($notHeld->countsAsTaught());
        $this->assertFalse($notHeld->developsSubject());
        $this->assertFalse($notHeld->takesAttendance());
        $this->assertTrue($notHeld->shiftsPlanning());
        $this->assertSame('Aula não realizada', $notHeld->label());
    }

    #[Test]
    public function the_sql_list_of_unnumbered_values_is_the_negation_of_the_php_predicate(): void
    {
        $expected = array_values(array_map(
            fn (LessonOutcome $outcome): string => $outcome->value,
            array_filter(LessonOutcome::cases(), fn (LessonOutcome $outcome): bool => ! $outcome->isNumbered()),
        ));

        $this->assertEqualsCanonicalizing(['teacher_absent', 'not_held'], $expected);
        $this->assertEqualsCanonicalizing($expected, LessonOutcome::unnumberedValues());
    }

    // ------------------------------------------------------------------
    // Numeração, planeamento, sumário, assiduidade
    // ------------------------------------------------------------------

    #[Test]
    public function a_not_held_lesson_never_receives_a_number_and_later_ones_keep_the_sequence(): void
    {
        $slot = $this->makeSlot();
        [$first, $second, $third, $fourth] = $this->thursdays($slot, ['08', '15', '22', '29']);
        $this->resequence();

        $this->record($second, LessonNotHeldCause::SchoolNotOperating);

        $this->inTenant($this->organization, function () use ($first, $second, $third, $fourth): void {
            $this->assertSame(LessonOutcome::NotHeld, $second->refresh()->outcome);
            $this->assertNull($second->lesson_number);
            $this->assertNull($second->lesson_unit_key);
            $this->assertSame([1, 2, 3], [$first->refresh()->lesson_number, $third->refresh()->lesson_number, $fourth->refresh()->lesson_number]);
        });

        // Uma renumeração qualquer, depois, continua a não lhe dar número.
        $this->resequence();
        $this->inTenant($this->organization, fn () => $this->assertNull($second->refresh()->lesson_number));
    }

    #[Test]
    public function a_taught_lesson_before_it_keeps_its_number(): void
    {
        $slot = $this->makeSlot();
        [$first, $second, $third] = $this->thursdays($slot, ['08', '15', '22']);
        $this->resequence();
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($first->id)->update(['status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught->value]));

        $this->record($second, LessonNotHeldCause::WeatherClosure);

        $this->inTenant($this->organization, function () use ($first, $third): void {
            $this->assertSame(1, $first->refresh()->lesson_number);
            $this->assertSame(2, $third->refresh()->lesson_number);
        });
    }

    #[Test]
    public function the_planning_moves_to_the_next_lesson_and_the_summary_row_of_the_closed_one_goes(): void
    {
        $slot = $this->makeSlot();
        [$a, $b] = $this->thursdays($slot, ['08', '15']);
        $this->summary($a, 'Plano A', ['homework' => 'TPC A']);

        $this->record($a, LessonNotHeldCause::Organizational);

        $this->inTenant($this->organization, function () use ($a, $b): void {
            $this->assertNull($a->summary()->first());
            $this->assertSame('Plano A', $b->summary()->first()?->content);
            $this->assertSame('TPC A', $b->summary()->first()?->homework);
        });
    }

    #[Test]
    public function attendance_drafts_are_dropped_and_consolidated_attendance_refuses_the_outcome(): void
    {
        $slot = $this->makeSlot();
        [$draft, $consolidated] = $this->thursdays($slot, ['08', '15']);
        $student = $this->enroll('Aluno Um');
        $this->asTeacher()->put("/lessons/{$draft->ulid}/attendance/draft", ['absent' => [$student->student->ulid]])->assertSessionHasNoErrors();
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($consolidated->id)->update(['attendance_recorded_at' => now()]));

        $this->record($draft, LessonNotHeldCause::FacilitiesProblem);
        $this->inTenant($this->organization, fn () => $this->assertSame(0, LessonAttendance::query()->where('lesson_id', $draft->id)->count()));

        $this->expectException(ValidationException::class);
        $this->record($consolidated, LessonNotHeldCause::FacilitiesProblem);
    }

    #[Test]
    public function a_closed_later_lesson_refuses_it_and_nothing_is_written(): void
    {
        $slot = $this->makeSlot();
        [$a, $b] = $this->thursdays($slot, ['08', '15']);
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($b->id)->update(['status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught->value]));

        try {
            $this->record($a, LessonNotHeldCause::SchoolNotOperating);
            $this->fail('devia recusar');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('outcome', $exception->errors());
        }

        $this->inTenant($this->organization, fn () => $this->assertNull($a->refresh()->outcome));
    }

    // ------------------------------------------------------------------
    // Coerência: causa, situação, descrição
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{LessonNotHeldCause|null, TeacherStrikeSituation|null, string|null, string}>
     */
    public static function incoherentCombinations(): array
    {
        return [
            'sem causa' => [null, null, null, 'cause'],
            'situação sem greve' => [LessonNotHeldCause::WeatherClosure, TeacherStrikeSituation::NotAdhered, null, 'situation'],
            'outra causa sem descrição' => [LessonNotHeldCause::Other, null, null, 'cause_note'],
            'outra causa só com espaços' => [LessonNotHeldCause::Other, null, '   ', 'cause_note'],
            'descrição sem outra causa' => [LessonNotHeldCause::Strike, null, 'texto', 'cause_note'],
        ];
    }

    #[DataProvider('incoherentCombinations')]
    #[Test]
    public function incoherent_combinations_are_refused_before_any_write(?LessonNotHeldCause $cause, ?TeacherStrikeSituation $situation, ?string $causeNote, string $field): void
    {
        $slot = $this->makeSlot();
        [$a] = $this->thursdays($slot, ['08']);

        try {
            $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute($a, LessonOutcome::NotHeld, $this->teacher, null, null, $cause, $situation, $causeNote));
            $this->fail('devia recusar');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }

        $this->inTenant($this->organization, fn () => $this->assertNull($a->refresh()->outcome));
    }

    #[Test]
    public function a_cause_on_any_other_outcome_is_refused(): void
    {
        $slot = $this->makeSlot();
        [$a] = $this->thursdays($slot, ['08']);

        $this->expectException(ValidationException::class);
        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute($a, LessonOutcome::ClassExternalActivity, $this->teacher, null, null, LessonNotHeldCause::Strike));
    }

    #[Test]
    public function other_keeps_a_short_description_and_the_others_keep_none(): void
    {
        $slot = $this->makeSlot();
        [$a, $b] = $this->thursdays($slot, ['08', '15']);

        $this->record($a, LessonNotHeldCause::Other, causeNote: '  Falha de eletricidade na escola  ');
        $this->record($b, LessonNotHeldCause::Organizational);

        $this->inTenant($this->organization, function () use ($a, $b): void {
            $this->assertSame('Falha de eletricidade na escola', $a->refresh()->outcome_cause_note);
            $this->assertNull($a->outcome_note);
            $this->assertNull($b->refresh()->outcome_cause_note);
        });
    }

    // ------------------------------------------------------------------
    // Greve: cenários B e C
    // ------------------------------------------------------------------

    #[Test]
    public function a_strike_defaults_to_adhesion_not_confirmed_and_the_teacher_may_declare_not_adhered(): void
    {
        $slot = $this->makeSlot();
        [$a, $b] = $this->thursdays($slot, ['08', '15']);

        $this->record($a, LessonNotHeldCause::Strike);
        $this->record($b, LessonNotHeldCause::Strike, TeacherStrikeSituation::NotAdhered);

        $this->inTenant($this->organization, function () use ($a, $b): void {
            $this->assertSame(TeacherStrikeSituation::NotInformed, $a->refresh()->outcome_teacher_situation);
            $this->assertSame(TeacherStrikeSituation::NotAdhered, $b->refresh()->outcome_teacher_situation);
        });
    }

    #[Test]
    public function an_observer_can_never_declare_a_situation_for_anyone(): void
    {
        $observer = User::factory()->create();
        $observer->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($observer, ['role' => 'observer']));
        $slot = $this->makeSlot();
        [$a] = $this->thursdays($slot, ['08']);

        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute(
            $a, LessonOutcome::NotHeld, $observer, null, null, LessonNotHeldCause::Strike, TeacherStrikeSituation::NotAdhered,
        ));

        $this->inTenant($this->organization, fn () => $this->assertSame(TeacherStrikeSituation::NotInformed, $a->refresh()->outcome_teacher_situation));
    }

    #[Test]
    public function the_audit_event_carries_the_cause_code_but_neither_the_situation_nor_the_description(): void
    {
        $slot = $this->makeSlot();
        [$a] = $this->thursdays($slot, ['08']);

        $this->record($a, LessonNotHeldCause::Strike, TeacherStrikeSituation::NotAdhered);

        $this->inTenant($this->organization, function (): void {
            $event = AuditEvent::query()->where('event', 'lesson.outcome_recorded')->latest('id')->firstOrFail();
            $properties = json_encode($event->properties);
            $this->assertSame('strike', $event->properties['cause']);
            $this->assertStringNotContainsString('not_adhered', (string) $properties);
            $this->assertStringNotContainsString('situation', (string) $properties);
        });
    }

    // ------------------------------------------------------------------
    // Pedido HTTP: desligado por defeito
    // ------------------------------------------------------------------

    #[Test]
    public function the_http_request_refuses_not_held_while_the_feature_is_off(): void
    {
        $slot = $this->makeSlot();
        [$a] = $this->thursdays($slot, ['08']);

        $this->asTeacher()
            ->post("/lessons/{$a->ulid}/outcome", ['outcome' => 'not_held', 'cause' => 'strike'])
            ->assertSessionHasErrors('outcome');

        $this->inTenant($this->organization, fn () => $this->assertNull($a->refresh()->outcome));
    }

    #[Test]
    public function with_the_feature_on_the_request_records_it_and_validates_the_shape(): void
    {
        config(['lapis.lesson_not_held_enabled' => true]);
        $slot = $this->makeSlot();
        [$a, $b] = $this->thursdays($slot, ['08', '15']);

        $this->asTeacher()->post("/lessons/{$a->ulid}/outcome", ['outcome' => 'not_held'])->assertSessionHasErrors('cause');
        $this->asTeacher()->post("/lessons/{$a->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training', 'cause' => 'strike'])->assertSessionHasErrors('cause');
        $this->asTeacher()->post("/lessons/{$a->ulid}/outcome", ['outcome' => 'not_held', 'cause' => 'inventada'])->assertSessionHasErrors('cause');

        $this->asTeacher()
            ->post("/lessons/{$a->ulid}/outcome", ['outcome' => 'not_held', 'cause' => 'school_not_operating'])
            ->assertSessionHasNoErrors();
        $this->inTenant($this->organization, fn () => $this->assertSame(LessonOutcome::NotHeld, $a->refresh()->outcome));
        $this->assertNotNull($b);
    }

    // ------------------------------------------------------------------
    // Privacidade: a causa é do titular
    // ------------------------------------------------------------------

    #[Test]
    public function a_colleague_sees_that_the_lesson_was_not_held_but_never_why(): void
    {
        $slot = $this->makeSlot();
        [$a] = $this->thursdays($slot, ['08']);
        $this->record($a, LessonNotHeldCause::Strike, TeacherStrikeSituation::NotAdhered);

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($colleague, ['role' => 'observer']));

        $own = $this->asTeacher()->get("/lessons/{$a->ulid}")->assertOk();
        $own->assertInertia(fn ($page) => $page
            ->where('lesson.outcome', 'not_held')
            ->where('lesson.outcome_cause_label', 'Greve')
            ->where('lesson.outcome_teacher_situation_label', 'Não aderi')
            ->where('lesson.outcome_reason_reserved', false)
            ->etc());

        $response = $this->actingAs($colleague)->withSession(['organization_id' => $this->organization->id])->get("/lessons/{$a->ulid}")->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('lesson.outcome', 'not_held')
            ->where('lesson.outcome_cause_label', null)
            ->where('lesson.outcome_teacher_situation_label', null)
            ->where('lesson.outcome_cause_note', null)
            ->where('lesson.outcome_reason_reserved', true)
            ->etc());
        $this->assertStringNotContainsString('Não aderi', $response->content());
    }

    // ------------------------------------------------------------------
    // Relatório de turma
    // ------------------------------------------------------------------

    #[Test]
    public function the_class_record_counts_not_held_apart_and_never_as_taught(): void
    {
        $slot = $this->makeSlot();
        [$a, $b, $c] = $this->thursdays($slot, ['08', '15', '22']);
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($a->id)->update(['status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught->value]));
        $this->record($b, LessonNotHeldCause::Strike);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:00', 'Europe/Lisbon'));

        $record = $this->inTenant($this->organization, fn () => app(ClassLessonRecord::class)->for($this->schoolClass->refresh(), null, null));

        $this->assertSame(1, $record['totals']['not_held']);
        $this->assertSame(1, $record['totals']['counted_as_taught']);
        $this->assertSame(1, $record['totals']['not_recorded']);
        $this->assertSame(
            $record['totals']['planned'],
            $record['totals']['counted_as_taught'] + $record['totals']['teacher_absent'] + $record['totals']['not_held'] + $record['totals']['not_recorded'],
        );
        $this->assertContains('not_held', array_column($record['rows'], 'outcome'));
        $this->assertNotNull($c);
    }

    // ------------------------------------------------------------------
    // Recuperação
    // ------------------------------------------------------------------

    #[Test]
    public function the_recovery_check_is_zero_without_records_and_one_with_them(): void
    {
        $this->artisan('lapis:lesson-outcome-recovery-check')->expectsOutputToContain('o rollback só de código é seguro')->assertExitCode(0);

        $slot = $this->makeSlot();
        [$a] = $this->thursdays($slot, ['08']);
        $this->record($a, LessonNotHeldCause::Strike, TeacherStrikeSituation::NotAdhered);

        $this->artisan('lapis:lesson-outcome-recovery-check')
            ->expectsOutputToContain('aulas não realizadas: 1')
            ->doesntExpectOutputToContain('not_adhered')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------------

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

    /**
     * @param  array<string, string>  $extra
     */
    private function summary(Lesson $lesson, string $content, array $extra = []): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => $content] + $extra));
    }

    private function record(Lesson $lesson, LessonNotHeldCause $cause, ?TeacherStrikeSituation $situation = null, ?string $causeNote = null): void
    {
        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute(
            $lesson, LessonOutcome::NotHeld, $this->teacher, null, null, $cause, $situation, $causeNote,
        ));
    }

    private function resequence(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));
    }
}
