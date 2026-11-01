<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
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
        // Sem a chave da adesão, «Aderi» não se oferece; o legado «not_informed» nunca.
        $this->assertSame(['not_adhered'], array_column($options['specifications'], 'value'));

        config(['lapis.strike_adhesion_recording' => true]);
        $withAdhesion = $this->props($this->teacher, '/lessons?week=2026-10-14')['notHeldOptions'];
        $this->assertSame(['adhered', 'not_adhered'], array_column($withAdhesion['specifications'], 'value'));
        $this->assertSame('Aderi à greve', $withAdhesion['specifications'][0]['label']);
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
}
