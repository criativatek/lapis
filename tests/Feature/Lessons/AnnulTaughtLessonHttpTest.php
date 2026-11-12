<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MarkLessonAsTaught;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\OrganizationType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * Quem pode anular um «Lecionada» pela rota: o titular do fecho e a direção de
 * uma instituição; nunca um colega, um observador, o dono de uma organização
 * pessoal que não leciona, nem ninguém com a funcionalidade desligada.
 */
class AnnulTaughtLessonHttpTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        config(['lapis.lesson_not_held_enabled' => true]);
    }

    private function taughtLesson(): Lesson
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-11-11 10:30:00']);

        return $this->inTenant($this->organization, fn (): Lesson => app(MarkLessonAsTaught::class)->execute($lesson, $this->teacher));
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['cause' => 'weather_closure', 'rectification_note' => 'Escola encerrada por mau tempo.'];
    }

    /** @param  array<string, string>  $payload */
    private function annulAs(User $user, Lesson $lesson, array $payload): TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['organization_id' => $this->organization->id])
            ->patch("/lessons/{$lesson->ulid}/outcome/annul", $payload);
    }

    /** Outro professor na turma, com o papel dado. */
    private function colleague(string $role = 'teacher'): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($user, ['role' => $role]));

        return $user;
    }

    /** A organização passa a instituição com uma direção que não leciona a turma. */
    private function institutionHead(): User
    {
        $head = User::factory()->create();
        DB::table('organizations')->where('id', $this->organization->id)->update([
            'type' => OrganizationType::Institutional->value,
            'owner_id' => $head->getKey(),
        ]);
        DB::table('organization_memberships')->insertOrIgnore([
            'organization_id' => $this->organization->id,
            'user_id' => $head->getKey(),
            'joined_at' => now(),
        ]);

        return $head;
    }

    #[Test]
    public function the_teacher_who_closed_the_lesson_annuls_it(): void
    {
        $lesson = $this->taughtLesson();

        $this->annulAs($this->teacher, $lesson, $this->payload())->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(LessonOutcome::NotHeld, $lesson->refresh()->outcome);
            $this->assertSame($this->teacher->id, LessonOutcomeRectification::query()->where('lesson_id', $lesson->id)->sole()->rectified_by);
        });
    }

    #[Test]
    public function another_teacher_of_the_class_and_an_observer_are_refused(): void
    {
        $lesson = $this->taughtLesson();

        $this->annulAs($this->colleague(), $lesson, $this->payload())->assertForbidden();
        $this->annulAs($this->colleague('observer'), $lesson, $this->payload())->assertForbidden();
        $this->inTenant($this->organization, fn () => $this->assertSame(LessonOutcome::Taught, $lesson->refresh()->outcome));
    }

    #[Test]
    public function the_head_of_an_institution_annuls_a_lesson_of_a_class_they_do_not_teach(): void
    {
        $lesson = $this->taughtLesson();
        $head = $this->institutionHead();

        $this->annulAs($head, $lesson, $this->payload())->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $head): void {
            $this->assertSame(LessonOutcome::NotHeld, $lesson->refresh()->outcome);
            // O titular do fecho continua a ser o professor; a direção fica no histórico.
            $this->assertSame($this->teacher->id, $lesson->outcome_recorded_by);
            $this->assertSame($head->id, LessonOutcomeRectification::query()->where('lesson_id', $lesson->id)->sole()->rectified_by);
        });
    }

    #[Test]
    public function the_owner_of_a_personal_organization_who_does_not_teach_is_refused(): void
    {
        $lesson = $this->taughtLesson();
        $owner = User::factory()->create();
        DB::table('organizations')->where('id', $this->organization->id)->update(['owner_id' => $owner->getKey()]);
        DB::table('organization_memberships')->insertOrIgnore(['organization_id' => $this->organization->id, 'user_id' => $owner->getKey(), 'joined_at' => now()]);

        $this->annulAs($owner, $lesson, $this->payload())->assertForbidden();
    }

    #[Test]
    public function with_the_feature_off_nobody_annuls(): void
    {
        $lesson = $this->taughtLesson();
        config(['lapis.lesson_not_held_enabled' => false]);

        $this->annulAs($this->teacher, $lesson, $this->payload())->assertForbidden();
    }

    #[Test]
    public function the_request_validates_cause_and_motive(): void
    {
        $lesson = $this->taughtLesson();

        $this->annulAs($this->teacher, $lesson, ['rectification_note' => 'Motivo válido'])->assertSessionHasErrors('cause');
        $this->annulAs($this->teacher, $lesson, ['cause' => 'weather_closure'])->assertSessionHasErrors('rectification_note');
        $this->annulAs($this->teacher, $lesson, ['cause' => 'other', 'rectification_note' => 'Motivo válido'])->assertSessionHasErrors('cause_note');
        $this->inTenant($this->organization, fn () => $this->assertSame(LessonOutcome::Taught, $lesson->refresh()->outcome));
    }

    #[Test]
    public function a_guest_goes_to_login(): void
    {
        $lesson = $this->taughtLesson();

        $this->patch("/lessons/{$lesson->ulid}/outcome/annul", $this->payload())->assertRedirect('/login');
    }
}
