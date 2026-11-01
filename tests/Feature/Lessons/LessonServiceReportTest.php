<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\RecordLessonOutcome;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Models\TeacherStrikeSituation;
use App\Models\User;
use App\Services\Lessons\ClassLessonRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * O relatório do serviço letivo do professor: contagens, percentagem de aulas
 * dadas (lecionadas ÷ previstas × 100, com as não realizadas no denominador),
 * causas e evolução — e a fronteira entre o que é da turma e o que é do próprio.
 */
class LessonServiceReportTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function closed(string $startsAt, LessonOutcome $outcome, array $attributes = []): Lesson
    {
        $lesson = $this->makeLesson(['starts_at' => $startsAt]);
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($lesson->id)->update([
            'status' => $outcome === LessonOutcome::Taught ? LessonStatus::Taught : LessonStatus::Preparation,
            'outcome' => $outcome->value,
            'outcome_recorded_at' => now(),
            'outcome_recorded_by' => $this->teacher->id,
        ] + $attributes));

        return $lesson;
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($user, ['role' => $role]));

        return $user;
    }

    /** @return array<string, mixed> */
    private function report(User $user, string $query = ''): array
    {
        $response = $this->actingAs($user)->withSession(['organization_id' => $this->organization->id])
            ->get('/lessons/service-report'.$query)->assertOk();

        return AssertableInertia::fromTestResponse($response)->toArray()['props'];
    }

    private function seedLessons(): void
    {
        $this->closed('2026-10-01 09:30:00', LessonOutcome::Taught);
        $this->closed('2026-10-08 09:30:00', LessonOutcome::Taught);
        $this->closed('2026-10-15 09:30:00', LessonOutcome::ClassExternalActivity, ['outcome_note' => 'Visita']);
        $this->closed('2026-10-22 09:30:00', LessonOutcome::NotHeld, ['outcome_cause' => LessonNotHeldCause::Strike->value, 'outcome_teacher_situation' => TeacherStrikeSituation::Adhered->value]);
        $this->makeLesson(['starts_at' => '2026-10-27 09:30:00']); // passada, sem registo
        $this->makeLesson(['starts_at' => '2026-11-05 09:30:00']); // futura
    }

    #[Test]
    public function the_percentage_is_given_over_planned_with_not_held_in_the_denominator_and_unrecorded_apart(): void
    {
        config(['lapis.strike_adhesion_recording' => true]);
        $this->seedLessons();

        $report = $this->report($this->teacher)['report'];

        $this->assertSame(5, $report['totals']['planned']);
        $this->assertSame(3, $report['totals']['counted_as_taught']);
        $this->assertSame(1, $report['totals']['not_held']);
        $this->assertSame(1, $report['totals']['not_recorded']);
        $this->assertEquals(60.0, $report['given_percentage']);
        $this->assertSame(1, $report['upcoming'], 'A futura não entra em nenhum número.');
    }

    #[Test]
    public function without_planned_lessons_there_is_no_percentage(): void
    {
        $report = $this->report($this->teacher)['report'];

        $this->assertSame(0, $report['totals']['planned']);
        $this->assertNull($report['given_percentage']);
        $this->assertSame([], $report['evolution']);
    }

    #[Test]
    public function the_evolution_is_monthly_and_follows_the_same_numbers(): void
    {
        $this->closed('2026-09-24 09:30:00', LessonOutcome::Taught);
        $this->closed('2026-09-30 09:30:00', LessonOutcome::NotHeld, ['outcome_cause' => LessonNotHeldCause::WeatherClosure->value]);
        $this->closed('2026-10-08 09:30:00', LessonOutcome::Taught);

        $evolution = $this->report($this->teacher)['report']['evolution'];

        $this->assertSame(['2026-09', '2026-10'], array_column($evolution, 'month'));
        $this->assertSame([2, 1], array_column($evolution, 'planned'));
        $this->assertEquals([50.0, 100.0], array_column($evolution, 'given_percentage'));
        $this->assertSame([1, 0], array_column($evolution, 'not_held'));
    }

    #[Test]
    public function the_personal_block_shows_my_causes_and_my_specification_including_records_without_one(): void
    {
        config(['lapis.strike_adhesion_recording' => true]);
        $this->closed('2026-10-01 09:30:00', LessonOutcome::NotHeld, ['outcome_cause' => LessonNotHeldCause::Strike->value]);
        $this->closed('2026-10-08 09:30:00', LessonOutcome::NotHeld, ['outcome_cause' => LessonNotHeldCause::Strike->value, 'outcome_teacher_situation' => TeacherStrikeSituation::Adhered->value]);
        $this->closed('2026-10-15 09:30:00', LessonOutcome::NotHeld, ['outcome_cause' => LessonNotHeldCause::Strike->value, 'outcome_teacher_situation' => TeacherStrikeSituation::NotAdhered->value]);
        $this->closed('2026-10-22 09:30:00', LessonOutcome::NotHeld, ['outcome_cause' => LessonNotHeldCause::Strike->value, 'outcome_teacher_situation' => TeacherStrikeSituation::NotInformed->value]);
        $this->closed('2026-10-23 09:30:00', LessonOutcome::NotHeld, ['outcome_cause' => LessonNotHeldCause::FacilitiesProblem->value]);

        $personal = $this->report($this->teacher)['report']['personal'];

        $this->assertSame(5, $personal['not_held_recorded']);
        $this->assertSame(0, $personal['not_held_by_others']);
        $this->assertSame(['strike' => 4, 'facilities_problem' => 1], array_column($personal['causes'], 'count', 'cause'));
        $this->assertSame(['total' => 4, 'unspecified' => 1, 'adhered' => 1, 'not_adhered' => 1, 'not_informed' => 1], $personal['strike']);
    }

    #[Test]
    public function a_colleague_reads_the_aggregated_cause_but_never_my_specification(): void
    {
        config(['lapis.strike_adhesion_recording' => true]);
        $this->closed('2026-10-08 09:30:00', LessonOutcome::NotHeld, ['outcome_cause' => LessonNotHeldCause::Strike->value, 'outcome_teacher_situation' => TeacherStrikeSituation::Adhered->value]);
        $this->closed('2026-10-15 09:30:00', LessonOutcome::NotHeld, ['outcome_cause' => LessonNotHeldCause::Other->value, 'outcome_cause_note' => 'Falha de eletricidade']);

        foreach (['co_teacher', 'observer'] as $role) {
            $props = $this->report($this->member($role));
            $report = $props['report'];

            // A causa agregada é da turma…
            $this->assertEqualsCanonicalizing(['strike' => 1, 'other' => 1], array_column($report['causes'], 'count', 'cause'));
            $this->assertSame(2, $report['totals']['not_held']);
            // …o que é pessoal não chega.
            $this->assertSame(0, $report['personal']['not_held_recorded']);
            $this->assertSame(2, $report['personal']['not_held_by_others']);
            $this->assertSame([], $report['personal']['causes']);
            $this->assertSame(0, $report['personal']['strike']['total']);
            $json = json_encode($props);
            foreach (['Aderi', 'Falha de eletricidade'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $json, "{$role} não pode ler «{$secret}»");
            }
        }
    }

    #[Test]
    public function a_class_the_teacher_does_not_teach_is_never_reported(): void
    {
        $this->seedLessons();
        $outsider = User::factory()->create();
        $outsider->organizations()->attach($this->organization, ['joined_at' => now()]);

        $props = $this->report($outsider, '?class='.$this->schoolClass->ulid);

        $this->assertNull($props['report']);
        $this->assertSame([], $props['classes']);
    }

    #[Test]
    public function the_date_range_narrows_every_number(): void
    {
        $this->seedLessons();

        $report = $this->report($this->teacher, '?from=2026-10-20&to=2026-10-30')['report'];

        $this->assertSame(2, $report['totals']['planned']);
        $this->assertSame(0, $report['totals']['counted_as_taught']);
        $this->assertEquals(0.0, $report['given_percentage']);
        $this->assertSame(1, $report['totals']['not_recorded']);
    }

    #[Test]
    public function the_class_record_exposes_only_the_aggregated_cause_in_the_general_report(): void
    {
        $this->closed('2026-10-08 09:30:00', LessonOutcome::NotHeld, ['outcome_cause' => LessonNotHeldCause::Strike->value, 'outcome_teacher_situation' => TeacherStrikeSituation::NotAdhered->value]);

        $record = $this->inTenant($this->organization, fn () => app(ClassLessonRecord::class)->for(SchoolClass::query()->findOrFail($this->schoolClass->id)));

        $this->assertSame([['cause' => 'strike', 'label' => 'Greve', 'count' => 1]], $record['causes']);
        $this->assertStringNotContainsString('adhered', (string) json_encode($record));
    }

    #[Test]
    public function months_without_lessons_between_the_first_and_the_last_appear_at_zero(): void
    {
        $this->closed('2026-09-24 09:30:00', LessonOutcome::Taught);
        $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00:00', 'Europe/Lisbon'));
        $this->closed('2026-12-10 09:30:00', LessonOutcome::Taught);

        $evolution = $this->report($this->teacher)['report']['evolution'];

        $this->assertSame(['2026-09', '2026-10', '2026-11', '2026-12'], array_column($evolution, 'month'));
        $this->assertSame([1, 0, 0, 1], array_column($evolution, 'planned'));
        $this->assertSame([null, null], [$evolution[1]['given_percentage'], $evolution[2]['given_percentage']]);
    }

    #[Test]
    public function given_splits_into_taught_and_class_activity_and_a_retroactive_not_held_counts_once_and_never_as_given(): void
    {
        $this->closed('2026-10-01 09:30:00', LessonOutcome::Taught);
        $this->closed('2026-10-08 09:30:00', LessonOutcome::ClassExternalActivity);
        $retro = $this->makeLesson(['starts_at' => '2026-10-02 09:30:00']);
        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute(
            $retro, LessonOutcome::NotHeld, $this->teacher, null, null, LessonNotHeldCause::SchoolNotOperating, null, null, true,
        ));

        $report = $this->report($this->teacher)['report'];

        $this->assertSame(3, $report['totals']['planned']);
        $this->assertSame(2, $report['totals']['counted_as_taught']);
        $this->assertSame(1, $report['totals']['subject_development']);
        $this->assertSame(1, $report['totals']['class_external_activity']);
        $this->assertSame(1, $report['totals']['not_held']);
        $this->assertEquals(66.7, $report['given_percentage']);
        $this->assertSame(1, array_sum(array_column($report['evolution'], 'not_held')));
    }

    /**
     * As cinco categorias PARTEM as aulas previstas: nenhuma aula conta em duas, e a soma fecha. É o que impede
     * dupla contagem entre «lecionadas», «outra atividade», «ausência», «não realizadas» e «sem registo».
     */
    #[Test]
    public function the_categories_partition_the_planned_lessons_so_nothing_is_counted_twice(): void
    {
        $this->seedLessons();
        $this->closed('2026-10-20 09:30:00', LessonOutcome::TeacherAbsent, ['outcome_reason' => 'training']);

        $totals = $this->report($this->teacher)['report']['totals'];

        $this->assertSame(
            $totals['planned'],
            $totals['subject_development'] + $totals['class_external_activity'] + $totals['teacher_absent'] + $totals['not_held'] + $totals['not_recorded'],
        );
        $this->assertSame($totals['counted_as_taught'], $totals['subject_development'] + $totals['class_external_activity']);
        $this->assertSame([6, 2, 1, 1, 1, 1], [$totals['planned'], $totals['subject_development'], $totals['class_external_activity'], $totals['teacher_absent'], $totals['not_held'], $totals['not_recorded']]);
    }

    #[Test]
    public function rectifying_an_absence_into_not_held_moves_one_lesson_between_categories_and_keeps_the_denominator(): void
    {
        config(['lapis.lesson_not_held_enabled' => true]);
        $this->seedLessons();
        $absent = $this->closed('2026-10-20 09:30:00', LessonOutcome::TeacherAbsent, ['outcome_reason' => 'training']);
        $before = $this->report($this->teacher)['report'];

        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->patchJson("/lessons/{$absent->ulid}/outcome", ['outcome' => 'not_held', 'cause' => 'strike', 'rectification_note' => 'Era greve'])->assertRedirect();

        $after = $this->report($this->teacher)['report'];

        $this->assertSame($before['totals']['planned'], $after['totals']['planned']);
        $this->assertSame($before['totals']['counted_as_taught'], $after['totals']['counted_as_taught']);
        $this->assertEquals($before['given_percentage'], $after['given_percentage']);
        $this->assertSame($before['totals']['teacher_absent'] - 1, $after['totals']['teacher_absent']);
        $this->assertSame($before['totals']['not_held'] + 1, $after['totals']['not_held']);
        $this->assertSame($before['personal']['teacher_absent'] - 1, $after['personal']['teacher_absent']);
        $this->assertSame($before['personal']['not_held_recorded'] + 1, $after['personal']['not_held_recorded']);
        $this->assertSame(['strike'], array_column($after['causes'], 'cause'));
    }
}
