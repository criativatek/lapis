<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\User;
use App\Support\Lessons\LessonOutcomeDetails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * PR-0 — a categoria de uma ausência é um dado sobre um professor: só chega a
 * quem registou o resultado. Um colega da turma, observador incluído, vê que a
 * aula não se deu, nunca porquê; quem não é da turma não vê a aula.
 *
 * O resultado e a nota de uma atividade da turma (que descreve a turma, não um
 * professor) continuam visíveis a toda a turma.
 */
class LessonOutcomePrivacyTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($user, ['role' => $role]));

        return $user;
    }

    private function actingInOrganization(User $user): self
    {
        return $this->actingAs($user)->withSession(['organization_id' => $this->organization->id]);
    }

    private function absentLesson(?User $recorder = null, string $startsAt = '2026-10-15 09:30:00'): Lesson
    {
        $lesson = $this->makeLesson(['starts_at' => $startsAt]);
        $this->actingInOrganization($recorder ?? $this->teacher)
            ->post("/lessons/{$lesson->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'official_duty'])
            ->assertSessionHasNoErrors();

        return $lesson->refresh();
    }

    /** @return array<string, mixed> */
    private function showProps(User $viewer, Lesson $lesson): array
    {
        return $this->props($this->actingInOrganization($viewer)->get("/lessons/{$lesson->ulid}"))['lesson'];
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        return AssertableInertia::fromTestResponse($response)->toArray()['props'];
    }

    #[Test]
    public function the_recorder_reads_their_own_reason(): void
    {
        $lesson = $this->absentLesson();

        $props = $this->showProps($this->teacher, $lesson);

        $this->assertSame('Serviço oficial', $props['outcome_reason_label']);
        $this->assertFalse($props['outcome_reason_reserved']);
    }

    #[DataProvider('otherRoles')]
    #[Test]
    public function a_colleague_of_the_class_never_receives_the_reason(string $role): void
    {
        $lesson = $this->absentLesson();
        $colleague = $this->member($role);

        $response = $this->actingInOrganization($colleague)->get("/lessons/{$lesson->ulid}");
        $props = $this->props($response)['lesson'];

        $this->assertSame('teacher_absent', $props['outcome']);
        $this->assertNull($props['outcome_reason_label']);
        $this->assertTrue($props['outcome_reason_reserved']);
        // Nem na primeira visita (HTML com as props): a categoria registada não viaja.
        // A lista de opções do diálogo (`absence_reasons`) é de todos e não é o registo.
        $this->assertDoesNotMatchRegularExpression('/outcome_reason_label.{1,2}:"/', $response->content());
        $this->assertMatchesRegularExpression('/outcome_reason_reserved.{1,3}:true/', $response->content());
    }

    /** @return array<string, array{string}> */
    public static function otherRoles(): array
    {
        return ['co-professor' => ['co_teacher'], 'observador' => ['observer']];
    }

    #[Test]
    public function the_reason_follows_whoever_recorded_it_not_the_class_owner(): void
    {
        $author = $this->member('co_teacher');
        $lesson = $this->absentLesson($author);

        $this->assertSame('Serviço oficial', $this->showProps($author, $lesson)['outcome_reason_label']);
        $this->assertNull($this->showProps($this->teacher, $lesson)['outcome_reason_label']);
        $this->assertTrue($this->showProps($this->teacher, $lesson)['outcome_reason_reserved']);
    }

    #[Test]
    public function an_observer_who_recorded_a_reason_reads_only_what_they_recorded(): void
    {
        $observer = $this->member('observer');
        $theirs = $this->absentLesson(null, '2026-10-14 09:30:00');
        $mine = $this->absentLesson($observer);

        $this->assertSame('Serviço oficial', $this->showProps($observer, $mine)['outcome_reason_label']);
        $this->assertNull($this->showProps($observer, $theirs)['outcome_reason_label']);
    }

    #[Test]
    public function someone_who_left_the_class_no_longer_reads_it(): void
    {
        $author = $this->member('co_teacher');
        $lesson = $this->absentLesson($author);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->detach($author->getKey()));

        $this->assertFalse(LessonOutcomeDetails::mayReadReason($lesson->fresh(['schoolClass']), $author));
        $this->actingInOrganization($author)->get("/lessons/{$lesson->ulid}")->assertForbidden();
    }

    #[Test]
    public function without_a_known_author_only_the_sole_member_of_the_class_reads_the_reason(): void
    {
        $lesson = $this->absentLesson();
        Lesson::query()->whereKey($lesson->getKey())->update(['outcome_recorded_by' => null]);

        $this->assertSame('Serviço oficial', $this->showProps($this->teacher, $lesson)['outcome_reason_label']);

        $colleague = $this->member('co_teacher');

        $this->assertNull($this->showProps($this->teacher, $lesson)['outcome_reason_label']);
        $this->assertNull($this->showProps($colleague, $lesson)['outcome_reason_label']);
        $this->assertTrue($this->showProps($colleague, $lesson)['outcome_reason_reserved']);
    }

    #[Test]
    public function a_user_from_another_organization_cannot_open_the_lesson_at_all(): void
    {
        $lesson = $this->absentLesson();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->withSession(['organization_id' => $stranger->personalOrganization()->id])
            ->get("/lessons/{$lesson->ulid}")
            ->assertNotFound();
    }

    #[Test]
    public function the_reason_never_travels_in_the_week_or_the_dashboard_of_a_colleague(): void
    {
        $this->absentLesson();
        $colleague = $this->member('observer');

        foreach (['/lessons?week=2026-10-12', '/dashboard'] as $url) {
            $body = $this->actingInOrganization($colleague)->get($url)->content();
            $this->assertStringNotContainsString('outcome_reason', $body, $url);
        }
    }

    #[Test]
    public function the_class_activity_note_stays_visible_to_the_whole_class(): void
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00']);
        $this->actingInOrganization($this->teacher)
            ->post("/lessons/{$lesson->ulid}/outcome", ['outcome' => 'class_external_activity', 'note' => 'Visita de estudo'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Visita de estudo', $this->showProps($this->member('observer'), $lesson)['outcome_note']);
    }
}
