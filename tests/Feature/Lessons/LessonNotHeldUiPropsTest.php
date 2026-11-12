<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\AnnulTaughtLesson;
use App\Actions\Lessons\MarkLessonAsTaught;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * O que o servidor entrega à interface de «Aula não realizada»: as opções só
 * com a funcionalidade ligada, a especificação «Aderi» só com a sua chave, e a
 * permissão de registar por linha (um observador não regista resultados).
 */
class LessonNotHeldUiPropsTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    private function observer(): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($user, ['role' => 'observer']));

        return $user;
    }

    /** @return array<string, mixed> */
    private function props(User $user, string $url): array
    {
        $response = $this->actingAs($user)->withSession(['organization_id' => $this->organization->id])->get($url)->assertOk();

        return AssertableInertia::fromTestResponse($response)->toArray()['props'];
    }

    private function lesson(): Lesson
    {
        return $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
    }

    #[Test]
    public function the_options_are_absent_while_the_feature_is_off(): void
    {
        $lesson = $this->lesson();

        $this->assertNull($this->props($this->teacher, "/lessons/{$lesson->ulid}")['lesson']['not_held_options']);
        $this->assertNull($this->props($this->teacher, '/lessons?week=2026-10-14')['notHeldOptions']);
    }

    #[Test]
    public function the_options_list_every_cause_and_only_the_specifications_on_offer(): void
    {
        config(['lapis.lesson_not_held_enabled' => true]);
        $lesson = $this->lesson();

        $options = $this->props($this->teacher, "/lessons/{$lesson->ulid}")['lesson']['not_held_options'];

        $this->assertSame(
            ['strike', 'school_not_operating', 'weather_closure', 'facilities_problem', 'organizational', 'other'],
            array_column($options['causes'], 'value'),
        );
        // Sem a chave da adesão não se oferece «Greve do professor», mas «Greve — escola encerrada» sim; os legados nunca.
        $this->assertSame(['school_closed'], array_column($options['specifications'], 'value'));
        $this->assertSame('Greve — escola encerrada', $options['specifications'][0]['label']);

        config(['lapis.strike_adhesion_recording' => true]);
        $withAdhesion = $this->props($this->teacher, '/lessons?week=2026-10-14')['notHeldOptions'];
        $this->assertSame(['adhered', 'school_closed'], array_column($withAdhesion['specifications'], 'value'));
        $this->assertSame('Greve do professor', $withAdhesion['specifications'][0]['label']);
    }

    #[Test]
    public function an_observer_is_not_offered_outcomes_but_a_teacher_is(): void
    {
        $lesson = $this->lesson();

        $this->assertTrue($this->props($this->teacher, "/lessons/{$lesson->ulid}")['lesson']['can_record_outcome']);
        $this->assertFalse($this->props($this->observer(), "/lessons/{$lesson->ulid}")['lesson']['can_record_outcome']);

        $teacherWeek = $this->props($this->teacher, '/lessons?week=2026-10-14')['lessons'];
        $observerWeek = $this->props($this->observer(), '/lessons?week=2026-10-14')['lessons'];

        $this->assertTrue(collect($teacherWeek)->every(fn (array $row): bool => $row['can_record_outcome'] === true));
        $this->assertNotEmpty($observerWeek);
        $this->assertTrue(collect($observerWeek)->every(fn (array $row): bool => $row['can_record_outcome'] === false));
    }

    #[Test]
    public function the_retroactive_option_appears_only_for_an_open_lesson_before_a_closed_one_and_lists_the_blockers(): void
    {
        config(['lapis.lesson_not_held_enabled' => true]);
        $early = $this->makeLesson(['starts_at' => '2026-10-12 09:30:00']);
        $late = $this->makeLesson(['starts_at' => '2026-10-13 09:30:00']);

        // Sem aulas fechadas depois: nada de retroativo.
        $this->assertNull($this->props($this->teacher, "/lessons/{$early->ulid}")['lesson']['retroactive_outcome']);

        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($late->id)->update(['status' => LessonStatus::Taught, 'outcome' => 'taught']));

        $retro = $this->props($this->teacher, "/lessons/{$early->ulid}")['lesson']['retroactive_outcome'];
        $this->assertSame(['closed_after' => '13/10/2026', 'blockers' => []], $retro);
        $this->assertFalse($this->props($this->teacher, "/lessons/{$early->ulid}")['lesson']['can_record_outcome']);

        $this->inTenant($this->organization, fn () => LessonSummary::query()->create(['lesson_id' => $early->id, 'content' => 'Escrito']));
        $blocked = $this->props($this->teacher, "/lessons/{$early->ulid}")['lesson']['retroactive_outcome'];
        $this->assertSame(['tem sumário ou preparação escritos'], $blocked['blockers']);

        // Observador e funcionalidade desligada: nunca.
        $this->assertNull($this->props($this->observer(), "/lessons/{$early->ulid}")['lesson']['retroactive_outcome']);
        config(['lapis.lesson_not_held_enabled' => false]);
        $this->assertNull($this->props($this->teacher, "/lessons/{$early->ulid}")['lesson']['retroactive_outcome']);
    }

    #[Test]
    public function the_week_asks_the_same_retroactive_answer_as_the_lesson_page(): void
    {
        config(['lapis.lesson_not_held_enabled' => true]);
        $early = $this->makeLesson(['starts_at' => '2026-10-12 09:30:00']);
        $late = $this->makeLesson(['starts_at' => '2026-10-13 09:30:00']);
        $ask = fn (User $user): array => $this->actingAs($user)->withSession(['organization_id' => $this->organization->id])
            ->getJson("/lessons/{$early->ulid}/retroactive-outcome")->assertOk()->json();

        $this->assertSame(['retroactive' => null], $ask($this->teacher));

        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($late->id)->update(['status' => LessonStatus::Taught, 'outcome' => 'taught']));
        $this->inTenant($this->organization, fn () => LessonSummary::query()->create(['lesson_id' => $early->id, 'content' => 'Escrito']));

        // A mesma resposta da página da aula — uma só fonte, também para os obstáculos.
        $fromPage = $this->props($this->teacher, "/lessons/{$early->ulid}")['lesson']['retroactive_outcome'];
        $this->assertSame(['retroactive' => $fromPage], $ask($this->teacher));
        $this->assertSame(['tem sumário ou preparação escritos'], $fromPage['blockers']);

        // Um observador ou quem não é da turma nunca a recebe.
        $this->assertSame(['retroactive' => null], $ask($this->observer()));
        $stranger = User::factory()->create();
        $stranger->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->actingAs($stranger)->withSession(['organization_id' => $this->organization->id])
            ->getJson("/lessons/{$early->ulid}/retroactive-outcome")->assertForbidden();
    }

    #[Test]
    public function a_taught_lesson_tells_its_closer_it_can_be_annulled_and_where_the_summary_would_go(): void
    {
        config(['lapis.lesson_not_held_enabled' => true]);
        $lesson = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
        $later = $this->makeLesson(['starts_at' => '2026-10-16 09:30:00']);
        $this->inTenant($this->organization, function () use ($lesson, $later): void {
            app(MarkLessonAsTaught::class)->execute($lesson, $this->teacher);
            app(MarkLessonAsTaught::class)->execute($later, $this->teacher);
        });

        $props = $this->props($this->teacher, "/lessons/{$lesson->ulid}")['lesson'];

        $this->assertSame('history', $props['annul_taught']['summary_destination']);
        $this->assertSame('16/10/2026', $props['annul_taught']['closed_after']);
        $this->assertNull($props['outcome_rectification']);

        $alone = $this->props($this->teacher, "/lessons/{$later->ulid}")['lesson'];
        $this->assertSame('next', $alone['annul_taught']['summary_destination']);
        $this->assertNull($alone['annul_taught']['closed_after']);
    }

    #[Test]
    public function an_observer_and_the_feature_off_see_no_annulment(): void
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
        $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($lesson, $this->teacher));

        $this->assertNull($this->props($this->teacher, "/lessons/{$lesson->ulid}")['lesson']['annul_taught']);

        config(['lapis.lesson_not_held_enabled' => true]);
        $this->assertNull($this->props($this->observer(), "/lessons/{$lesson->ulid}")['lesson']['annul_taught']);
    }

    #[Test]
    public function after_the_annulment_the_history_names_the_number_and_the_removed_summary(): void
    {
        config(['lapis.lesson_not_held_enabled' => true]);
        $lesson = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
        $later = $this->makeLesson(['starts_at' => '2026-10-16 09:30:00']);
        $this->inTenant($this->organization, function () use ($lesson, $later): void {
            app(MarkLessonAsTaught::class)->execute($lesson, $this->teacher);
            LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => 'Texto removido']);
            app(MarkLessonAsTaught::class)->execute($later, $this->teacher);
            app(AnnulTaughtLesson::class)->execute($lesson->refresh(), $this->teacher, LessonNotHeldCause::WeatherClosure, 'Mau tempo.');
        });

        $history = $this->props($this->teacher, "/lessons/{$lesson->ulid}")['lesson']['outcome_rectification']['history'];

        $this->assertCount(1, $history);
        $this->assertSame('annulment', $history[0]['kind']);
        $this->assertFalse($history[0]['by_direction']);
        $fields = array_column($history[0]['changes'], 'from', 'field');
        $this->assertSame('Lecionada', $fields['outcome']);
        $this->assertArrayHasKey('lesson_number', $fields);
        $this->assertSame('Texto removido', $fields['summary']);
    }
}
