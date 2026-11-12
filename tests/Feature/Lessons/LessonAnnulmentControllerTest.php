<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\AnnulTaughtLesson;
use App\Actions\Lessons\MarkLessonAsTaught;
use App\Models\LessonNotHeldCause;
use App\Models\LessonSummary;
use App\Models\OrganizationType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * A lista «Aulas fechadas» da direção: o que mostra (nunca o sumário nem as
 * faltas), quem a abre, e o que fica dela depois de uma anulação.
 */
class LessonAnnulmentControllerTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        config(['lapis.lesson_not_held_enabled' => true]);
    }

    private function institutionHead(): User
    {
        $head = User::factory()->create();
        DB::table('organizations')->where('id', $this->organization->id)->update(['type' => OrganizationType::Institutional->value, 'owner_id' => $head->getKey()]);
        DB::table('organization_memberships')->insertOrIgnore(['organization_id' => $this->organization->id, 'user_id' => $head->getKey(), 'joined_at' => now()]);

        return $head;
    }

    /** @return array<string, mixed> */
    private function props(User $user, string $url): array
    {
        $response = $this->actingAs($user)->withSession(['organization_id' => $this->organization->id])->get($url)->assertOk();

        return AssertableInertia::fromTestResponse($response)->toArray()['props'];
    }

    #[Test]
    public function the_head_sees_the_closed_lessons_without_summary_or_attendance_and_may_annul_the_taught_ones(): void
    {
        $head = $this->institutionHead();
        $lesson = $this->makeLesson(['starts_at' => '2026-11-11 10:30:00']);
        $open = $this->makeLesson(['starts_at' => '2026-11-18 10:30:00']);
        $this->inTenant($this->organization, function () use ($lesson): void {
            app(MarkLessonAsTaught::class)->execute($lesson, $this->teacher);
            LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => 'Segredo do professor']);
        });

        $props = $this->props($head, "/classes/{$this->schoolClass->ulid}/retificacoes/aulas");

        $this->assertCount(1, $props['lessons']);
        $row = $props['lessons'][0];
        $this->assertSame($lesson->ulid, $row['ulid']);
        $this->assertSame('taught', $row['outcome']);
        $this->assertSame('Lecionada', $row['outcome_label']);
        $this->assertSame($this->teacher->name, $row['closed_by']);
        $this->assertTrue($row['can_annul']);
        $this->assertStringNotContainsString('Segredo', (string) json_encode($props));
        $this->assertSame([], $props['annulments']);
        $this->assertArrayNotHasKey('summary', $row);
        $this->assertArrayNotHasKey('attendance', $row);
        $this->assertNotContains($open->ulid, array_column($props['lessons'], 'ulid'));
        $this->assertNotEmpty($props['notHeldOptions']['causes']);
    }

    #[Test]
    public function after_an_annulment_the_list_shows_it_without_the_summary_text(): void
    {
        $head = $this->institutionHead();
        $lesson = $this->makeLesson(['starts_at' => '2026-11-11 10:30:00']);
        $later = $this->makeLesson(['starts_at' => '2026-11-13 10:30:00']);
        $this->inTenant($this->organization, function () use ($lesson, $later, $head): void {
            app(MarkLessonAsTaught::class)->execute($lesson, $this->teacher);
            LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => 'Segredo do professor']);
            app(MarkLessonAsTaught::class)->execute($later, $this->teacher);
            app(AnnulTaughtLesson::class)->execute($lesson->refresh(), $head, LessonNotHeldCause::WeatherClosure, 'Mau tempo.');
        });

        $props = $this->props($head, "/classes/{$this->schoolClass->ulid}/retificacoes/aulas");

        $this->assertCount(1, $props['annulments']);
        $this->assertSame('Mau tempo.', $props['annulments'][0]['motive']);
        $this->assertSame($head->name, $props['annulments'][0]['by']);
        $this->assertStringNotContainsString('Segredo', (string) json_encode($props));
        $annulled = collect($props['lessons'])->firstWhere('ulid', $lesson->ulid);
        $this->assertSame('not_held', $annulled['outcome']);
        $this->assertFalse($annulled['can_annul']);
    }

    #[Test]
    public function a_teacher_who_is_not_the_head_gets_403(): void
    {
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->get("/classes/{$this->schoolClass->ulid}/retificacoes/aulas")->assertForbidden();
    }
}
