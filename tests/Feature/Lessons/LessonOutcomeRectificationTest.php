<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\RecordLessonOutcome;
use App\Actions\Lessons\RectifyLessonOutcome;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\TeacherAbsenceReason;
use App\Models\TeacherStrikeSituation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * Corrigir o resultado já registado de uma aula: histórico auditável, só o titular, e NADA do que depende
 * da posição da aula se mexe — números, planeamento, sumário.
 */
class LessonOutcomeRectificationTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        config(['lapis.lesson_not_held_enabled' => true, 'lapis.strike_adhesion_recording' => true]);
    }

    private function record(Lesson $lesson, LessonOutcome $outcome, array $with = [], ?User $by = null): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => app(RecordLessonOutcome::class)->execute(
            $lesson,
            $outcome,
            $by ?? $this->teacher,
            $with['reason'] ?? null,
            $with['note'] ?? null,
            $with['cause'] ?? null,
            $with['situation'] ?? null,
            $with['cause_note'] ?? null,
        ));
    }

    /** @param  array<string, mixed>  $body */
    private function rectify(Lesson $lesson, array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->patchJson("/lessons/{$lesson->ulid}/outcome", $body);
    }

    private function colleague(string $role = 'co_teacher'): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($user, ['role' => $role]));

        return $user;
    }

    /** @return array<int, int|null> */
    private function numbers(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()->where('class_id', $this->schoolClass->id)->orderBy('id')->pluck('lesson_number', 'id')->all());
    }

    private function taught(string $startsAt, int $number): Lesson
    {
        return $this->makeLesson(['starts_at' => $startsAt, 'status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught, 'lesson_number' => $number]);
    }

    private function strike(): Lesson
    {
        return $this->record($this->makeLesson(['starts_at' => '2026-10-13 09:30:00']), LessonOutcome::NotHeld, ['cause' => LessonNotHeldCause::Strike]);
    }

    // ------------------------------------------------------------------
    // A — os detalhes do mesmo resultado
    // ------------------------------------------------------------------

    #[Test]
    public function the_specification_of_a_strike_is_rectified_and_the_previous_value_stays_in_the_history(): void
    {
        $lesson = $this->strike();

        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'strike', 'situation' => 'adhered', 'rectification_note' => 'Afinal não aderi'])->assertRedirect();

        $lesson->refresh();
        $this->assertSame(TeacherStrikeSituation::Adhered, $lesson->outcome_teacher_situation);
        $this->assertSame(LessonOutcome::NotHeld, $lesson->outcome);

        $row = $this->inTenant($this->organization, fn () => LessonOutcomeRectification::query()->where('lesson_id', $lesson->id)->sole());
        $this->assertSame($this->teacher->id, $row->rectified_by);
        $this->assertSame('details', $row->kind);
        $this->assertSame('Afinal não aderi', $row->motive);
        $this->assertNull($row->previous['situation']);
        $this->assertSame('adhered', $row->current['situation']);
        $this->assertSame('strike', $row->previous['cause']);
    }

    #[Test]
    public function changing_the_cause_clears_what_no_longer_applies_and_keeps_every_step(): void
    {
        $lesson = $this->record($this->makeLesson(['starts_at' => '2026-10-13 09:30:00']), LessonOutcome::NotHeld, ['cause' => LessonNotHeldCause::Strike, 'situation' => TeacherStrikeSituation::Adhered]);

        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'other', 'cause_note' => 'Avaria elétrica', 'rectification_note' => 'Foi uma avaria'])->assertRedirect();
        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'weather_closure', 'rectification_note' => 'Afinal foi o mau tempo'])->assertRedirect();

        $lesson->refresh();
        $this->assertSame(LessonNotHeldCause::WeatherClosure, $lesson->outcome_cause);
        $this->assertNull($lesson->outcome_teacher_situation);
        $this->assertNull($lesson->outcome_cause_note);
        $rows = $this->inTenant($this->organization, fn () => LessonOutcomeRectification::query()->where('lesson_id', $lesson->id)->orderBy('id')->get());
        $this->assertCount(2, $rows);
        $this->assertSame('adhered', $rows[0]->previous['situation']);
        $this->assertSame('Avaria elétrica', $rows[1]->previous['cause_note']);
    }

    #[Test]
    public function another_description_is_demanded_with_other_cause_and_a_noop_is_refused(): void
    {
        $lesson = $this->strike();

        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'other', 'rectification_note' => 'Corrigir'])->assertJsonValidationErrors('cause_note');
        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'strike', 'rectification_note' => 'Nada muda'])->assertJsonValidationErrors('outcome');
        $this->assertSame(0, $this->inTenant($this->organization, fn () => LessonOutcomeRectification::query()->count()));
    }

    #[Test]
    public function the_motive_of_the_rectification_is_required(): void
    {
        $lesson = $this->strike();

        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'organizational'])->assertJsonValidationErrors('rectification_note');
        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'organizational', 'rectification_note' => 'ab'])->assertJsonValidationErrors('rectification_note');
        $this->assertSame(LessonNotHeldCause::Strike, $lesson->refresh()->outcome_cause);
    }

    #[Test]
    public function a_stored_adhered_specification_may_stay_after_the_adhesion_switch_is_turned_off(): void
    {
        config(['lapis.strike_adhesion_recording' => true]);
        $lesson = $this->record($this->makeLesson(['starts_at' => '2026-10-13 09:30:00']), LessonOutcome::NotHeld, ['cause' => LessonNotHeldCause::Strike, 'situation' => TeacherStrikeSituation::Adhered]);
        config(['lapis.strike_adhesion_recording' => false]);

        // Com a chave desligada «Greve do professor» já não se oferece de novo, mas a que estava gravada lê-se
        // e mantém-se (corrigir «para o mesmo» é só um não-evento), e «Greve — escola encerrada» não depende da chave.
        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'strike', 'situation' => 'adhered', 'rectification_note' => 'Sem mudança'])->assertJsonValidationErrors('outcome');
        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'strike', 'situation' => 'school_closed', 'rectification_note' => 'A escola estava encerrada'])->assertRedirect();
        $this->assertSame(TeacherStrikeSituation::SchoolClosed, $lesson->refresh()->outcome_teacher_situation);
        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'strike', 'situation' => 'adhered', 'rectification_note' => 'Voltar atrás'])->assertJsonValidationErrors('situation');
    }

    #[Test]
    public function the_detail_of_a_class_activity_is_rectified_but_never_its_outcome(): void
    {
        $lesson = $this->record($this->makeLesson(['starts_at' => '2026-10-13 09:30:00']), LessonOutcome::ClassExternalActivity, ['note' => 'Visita']);

        $this->rectify($lesson, ['outcome' => 'class_external_activity', 'note' => 'Visita ao museu', 'rectification_note' => 'Completar'])->assertRedirect();
        $this->assertSame('Visita ao museu', $lesson->refresh()->outcome_note);

        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'strike', 'rectification_note' => 'Mudar'])
            ->assertJsonValidationErrors('outcome');
        $this->assertSame(LessonOutcome::ClassExternalActivity, $lesson->refresh()->outcome);
    }

    // ------------------------------------------------------------------
    // B — o resultado, sem mexer em nada do que depende da posição
    // ------------------------------------------------------------------

    #[Test]
    public function a_teacher_absence_becomes_not_held_and_back_without_touching_numbers_or_planning(): void
    {
        $this->taught('2026-10-06 09:30:00', 1);
        $absent = $this->record($this->makeLesson(['starts_at' => '2026-10-13 09:30:00']), LessonOutcome::TeacherAbsent, ['reason' => TeacherAbsenceReason::Training]);
        $this->taught('2026-10-14 09:30:00', 2);
        $this->inTenant($this->organization, fn () => LessonPlan::query()->create(['lesson_id' => $this->makeLesson(['starts_at' => '2026-10-20 09:30:00'])->id, 'planned_summary' => 'Plano que já desceu']));
        $numbers = $this->numbers();
        $plans = $this->inTenant($this->organization, fn () => LessonPlan::query()->orderBy('id')->get(['lesson_id', 'planned_summary'])->toArray());

        $this->rectify($absent, ['outcome' => 'not_held', 'cause' => 'school_not_operating', 'rectification_note' => 'A escola fechou'])->assertRedirect();

        $absent->refresh();
        $this->assertSame(LessonOutcome::NotHeld, $absent->outcome);
        $this->assertNull($absent->outcome_reason);
        $this->assertNull($absent->lesson_number);
        $this->assertSame(LessonNotHeldCause::SchoolNotOperating, $absent->outcome_cause);
        $this->assertSame($numbers, $this->numbers());
        $this->assertSame($plans, $this->inTenant($this->organization, fn () => LessonPlan::query()->orderBy('id')->get(['lesson_id', 'planned_summary'])->toArray()));

        $this->rectify($absent, ['outcome' => 'teacher_absent', 'reason' => 'official_duty', 'rectification_note' => 'Era serviço oficial'])->assertRedirect();

        $absent->refresh();
        $this->assertSame(LessonOutcome::TeacherAbsent, $absent->outcome);
        $this->assertNull($absent->outcome_cause);
        $this->assertSame(TeacherAbsenceReason::OfficialDuty, $absent->outcome_reason);
        $this->assertSame($numbers, $this->numbers());
        $kinds = $this->inTenant($this->organization, fn () => LessonOutcomeRectification::query()->where('lesson_id', $absent->id)->orderBy('id')->pluck('kind')->all());
        $this->assertSame(['outcome', 'outcome'], $kinds);
    }

    #[Test]
    public function the_original_author_and_date_of_the_record_are_kept(): void
    {
        $lesson = $this->strike();
        $recordedAt = $lesson->outcome_recorded_at->toDateTimeString();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'Europe/Lisbon'));

        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'organizational', 'rectification_note' => 'Foi organizacional'])->assertRedirect();

        $lesson->refresh();
        $this->assertSame($this->teacher->id, $lesson->outcome_recorded_by);
        $this->assertSame($recordedAt, $lesson->outcome_recorded_at->toDateTimeString());
        $row = $this->inTenant($this->organization, fn () => LessonOutcomeRectification::query()->sole());
        $this->assertSame('2026-10-15 08:00:00', $row->rectified_at->utc()->toDateTimeString());
    }

    // ------------------------------------------------------------------
    // Autorização e privacidade
    // ------------------------------------------------------------------

    #[Test]
    public function only_the_holder_of_the_record_may_rectify_it(): void
    {
        $lesson = $this->strike();
        $body = ['outcome' => 'not_held', 'cause' => 'organizational', 'rectification_note' => 'Tentativa'];

        $this->rectify($lesson, $body, $this->colleague())->assertForbidden();
        $this->rectify($lesson, $body, $this->colleague('observer'))->assertForbidden();
        $stranger = User::factory()->create();
        $stranger->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->rectify($lesson, $body, $stranger)->assertForbidden();

        $this->assertSame(LessonNotHeldCause::Strike, $lesson->refresh()->outcome_cause);
        $this->assertSame(0, $this->inTenant($this->organization, fn () => LessonOutcomeRectification::query()->count()));
    }

    #[Test]
    public function nothing_is_rectified_for_an_open_or_a_taught_lesson_or_with_the_feature_off(): void
    {
        $open = $this->makeLesson(['starts_at' => '2026-10-13 09:30:00']);
        $taught = $this->taught('2026-10-12 09:30:00', 1);
        $body = ['outcome' => 'not_held', 'cause' => 'organizational', 'rectification_note' => 'Tentativa'];

        $this->rectify($open, $body)->assertForbidden();
        $this->rectify($taught, $body)->assertForbidden();

        $lesson = $this->strike();
        config(['lapis.lesson_not_held_enabled' => false]);
        $this->rectify($lesson, $body)->assertForbidden();
        $this->assertSame(LessonNotHeldCause::Strike, $lesson->refresh()->outcome_cause);
    }

    #[Test]
    public function the_history_reaches_only_the_holder_and_a_colleague_gets_no_trace_of_it(): void
    {
        $lesson = $this->record($this->makeLesson(['starts_at' => '2026-10-13 09:30:00']), LessonOutcome::NotHeld, ['cause' => LessonNotHeldCause::Strike, 'situation' => TeacherStrikeSituation::Adhered]);
        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'strike', 'rectification_note' => 'Quero deixar de especificar'])->assertRedirect();
        $colleague = $this->colleague();
        $show = fn (User $user): array => AssertableInertia::fromTestResponse(
            $this->actingAs($user)->withSession(['organization_id' => $this->organization->id])->get("/lessons/{$lesson->ulid}")->assertOk(),
        )->toArray()['props']['lesson'];

        $mine = $show($this->teacher)['outcome_rectification'];
        $this->assertSame('not_held', $mine['current']['outcome']);
        $this->assertSame(['teacher_absent', 'not_held'], $mine['targets']);
        $this->assertCount(1, $mine['history']);
        $this->assertSame('Quero deixar de especificar', $mine['history'][0]['motive']);
        $this->assertSame(['Especificação perante a greve'], array_column($mine['history'][0]['changes'], 'label'));
        $this->assertSame('Greve do professor', $mine['history'][0]['changes'][0]['from']);

        $theirs = $show($colleague);
        $this->assertNull($theirs['outcome_rectification']);
        $this->assertStringNotContainsString('Quero deixar de especificar', json_encode($theirs, JSON_THROW_ON_ERROR));
        $this->assertNull($theirs['outcome_teacher_situation_label'], 'A declaração individual do titular não chega a um colega.');
    }

    #[Test]
    public function the_general_audit_trail_records_the_rectification_without_reserved_data_or_free_text(): void
    {
        $lesson = $this->record($this->makeLesson(['starts_at' => '2026-10-13 09:30:00']), LessonOutcome::NotHeld, ['cause' => LessonNotHeldCause::Strike, 'situation' => TeacherStrikeSituation::Adhered]);

        $this->rectify($lesson, ['outcome' => 'not_held', 'cause' => 'strike', 'rectification_note' => 'Texto livre do professor'])->assertRedirect();

        $event = $this->inTenant($this->organization, fn () => AuditEvent::query()->where('event', 'lesson.outcome_rectified')->sole());
        $this->assertSame($this->teacher->id, $event->causer_id);
        $json = json_encode($event->properties, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('adhered', $json);
        $this->assertStringNotContainsString('Texto livre do professor', $json);
        $this->assertTrue($event->properties['reserved_details_changed']);
        $this->assertSame('strike', $event->properties['from_cause']);
    }

    #[Test]
    public function the_action_itself_refuses_a_move_that_would_touch_numbering_even_if_called_directly(): void
    {
        $taught = $this->taught('2026-10-12 09:30:00', 1);

        $this->expectException(ValidationException::class);

        $this->inTenant($this->organization, fn () => app(RectifyLessonOutcome::class)->execute($taught, LessonOutcome::NotHeld, $this->teacher, 'Tentativa', cause: LessonNotHeldCause::Strike));
    }
}
