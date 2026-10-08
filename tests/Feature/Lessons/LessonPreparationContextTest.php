<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Antes desta aula» — o contexto de quem prepara uma aula: as anteriores
 * lecionadas E as preparadas por lecionar, do mesmo público, com o estado
 * explícito. As datas são absolutas e o endpoint não lê o relógio.
 */
class LessonPreparationContextTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $teacher;

    private SchoolClass $schoolClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);
        $this->schoolClass = $this->makeClass($this->teacher);
    }

    #[Test]
    public function the_context_of_thursday_has_monday_taught_and_tuesday_prepared_in_that_order(): void
    {
        $monday = $this->lesson('2026-10-05 09:00:00', taught: true, content: 'Segunda dada.');
        $tuesday = $this->lesson('2026-10-06 09:00:00', content: 'Terça planeada.', homework: 'Ficha 3.');
        $thursday = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($thursday)->assertOk();

        $this->assertSame(
            [$monday->ulid, $tuesday->ulid],
            array_column($response->json('lessons'), 'ulid'),
        );
        $this->assertSame(['taught', 'prepared'], array_column($response->json('lessons'), 'state'));
        $this->assertSame(['Lecionada', 'Preparada — por lecionar'], array_column($response->json('lessons'), 'state_label'));
        $this->assertSame('Terça planeada.', $response->json('lessons.1.content'));
        $this->assertSame('Ficha 3.', $response->json('lessons.1.homework'));
        $this->assertFalse($response->json('has_more'));
        $this->assertArrayNotHasKey('private_notes', $response->json('lessons.0'));
    }

    #[Test]
    public function two_lessons_on_the_same_day_enter_by_time_and_a_later_one_does_not(): void
    {
        $morning = $this->lesson('2026-10-07 09:00:00', taught: true, content: 'Manhã.');
        $afternoon = $this->lesson('2026-10-07 14:00:00', content: 'Tarde planeada.');
        $target = $this->lesson('2026-10-07 15:00:00');
        $this->lesson('2026-10-07 16:00:00', content: 'Depois do destino.');

        $this->assertSame(
            [$morning->ulid, $afternoon->ulid],
            array_column($this->context($target)->json('lessons'), 'ulid'),
        );
        // A aula da tarde não vê a da manhã seguinte nem a si própria.
        $this->assertSame(
            [$morning->ulid],
            array_column($this->context($afternoon)->json('lessons'), 'ulid'),
        );
    }

    #[Test]
    public function the_same_start_time_is_broken_by_id(): void
    {
        $first = $this->lesson('2026-10-07 09:00:00', content: 'Primeira.');
        $second = $this->lesson('2026-10-07 09:00:00', content: 'Segunda.');

        $this->assertSame([$first->ulid], array_column($this->context($second)->json('lessons'), 'ulid'));
        $this->assertSame([], $this->context($first)->json('lessons'));
    }

    #[Test]
    public function it_excludes_the_lesson_itself_and_every_later_one(): void
    {
        $this->lesson('2026-10-05 09:00:00', content: 'Anterior.');
        $target = $this->lesson('2026-10-08 09:00:00', content: 'Em preparação.');
        $this->lesson('2026-10-12 09:00:00', content: 'Posterior preparada.');
        $this->lesson('2026-10-13 09:00:00', taught: true, content: 'Posterior lecionada.');

        $contents = array_column($this->context($target)->json('lessons'), 'content');

        $this->assertSame(['Anterior.'], $contents);
    }

    #[Test]
    public function it_excludes_closed_not_taught_and_empty_open_lessons_but_keeps_a_taught_one_without_text(): void
    {
        $this->lesson('2026-10-01 09:00:00', outcome: LessonOutcome::TeacherAbsent, content: 'Plano que já desceu.');
        $this->lesson('2026-10-02 09:00:00', outcome: LessonOutcome::ClassExternalActivity, content: 'Visita.');
        $this->lesson('2026-10-05 09:00:00'); // aberta e vazia
        $this->lesson('2026-10-06 09:00:00', content: "  \n "); // só espaços
        $taughtWithoutText = $this->lesson('2026-10-07 09:00:00', taught: true);
        $target = $this->lesson('2026-10-08 09:00:00');

        $lessons = $this->context($target)->json('lessons');

        $this->assertSame([$taughtWithoutText->ulid], array_column($lessons, 'ulid'));
        $this->assertSame('taught', $lessons[0]['state']);
        $this->assertSame('', $lessons[0]['content']);
    }

    #[Test]
    public function a_prepared_lesson_with_only_resources_counts(): void
    {
        $prepared = $this->lesson('2026-10-05 09:00:00', resources: 'https://exemplo.pt/ficha');
        $target = $this->lesson('2026-10-08 09:00:00');

        $this->assertSame([$prepared->ulid], array_column($this->context($target)->json('lessons'), 'ulid'));
    }

    #[Test]
    public function the_limit_keeps_the_most_recent_and_reports_has_more(): void
    {
        for ($day = 1; $day <= 8; $day++) {
            $this->lesson(sprintf('2026-09-%02d 09:00:00', $day), taught: true, content: "Antiga {$day}.");
        }
        $preparedA = $this->lesson('2026-10-05 09:00:00', content: 'Preparada A.');
        $preparedB = $this->lesson('2026-10-06 09:00:00', content: 'Preparada B.');
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($target, ['limit' => 5])->assertOk();
        $ulids = array_column($response->json('lessons'), 'ulid');

        $this->assertCount(5, $ulids);
        $this->assertSame([$preparedA->ulid, $preparedB->ulid], array_slice($ulids, -2));
        $this->assertTrue($response->json('has_more'));
        // Cronológica: a antiga mais recente vem antes das preparadas.
        $this->assertSame('Antiga 6.', $response->json('lessons.0.content'));

        $this->assertCount(5, $this->context($target)->json('lessons'), 'o limite por omissão é 5');
        $this->assertCount(10, $this->context($target, ['limit' => 99])->json('lessons'), 'acima do máximo corta em 20 e devolve tudo o que há');
        $this->assertFalse($this->context($target, ['limit' => 99])->json('has_more'));
    }

    #[Test]
    public function a_split_class_context_never_mixes_groups_or_the_whole_class(): void
    {
        $t1 = $this->group('T1');
        $t2 = $this->group('T2');
        $whole = $this->lesson('2026-10-01 09:00:00', content: 'Turma inteira.');
        $onT1 = $this->lesson('2026-10-02 09:00:00', content: 'Só T1.', group: $t1);
        $onT2 = $this->lesson('2026-10-05 09:00:00', content: 'Só T2.', group: $t2);
        $targetT1 = $this->lesson('2026-10-08 09:00:00', group: $t1);
        $targetT2 = $this->lesson('2026-10-08 10:00:00', group: $t2);
        $targetWhole = $this->lesson('2026-10-08 11:00:00');

        $this->assertSame([$onT1->ulid], array_column($this->context($targetT1)->json('lessons'), 'ulid'));
        $this->assertSame([$onT2->ulid], array_column($this->context($targetT2)->json('lessons'), 'ulid'));
        $this->assertSame([$whole->ulid], array_column($this->context($targetWhole)->json('lessons'), 'ulid'));
        $this->assertSame('8.º F · T1', $this->context($targetT1)->json('lessons.0.context_label'));
    }

    #[Test]
    public function previous_summary_returns_the_most_recent_with_text_and_its_state(): void
    {
        $this->lesson('2026-10-05 09:00:00', taught: true, content: 'Lecionada com texto.');
        $this->lesson('2026-10-06 09:00:00', content: 'Preparada mais recente.', privateNotes: 'Nota minha.');
        $this->lesson('2026-10-07 09:00:00', resources: 'Só recurso, sem sumário.');
        $target = $this->lesson('2026-10-08 09:00:00');

        $this->asTeacher()->get("/lessons/{$target->ulid}/previous-summary")
            ->assertOk()
            ->assertJson([
                'content' => 'Preparada mais recente.',
                'private_notes' => 'Nota minha.',
                'state' => 'prepared',
                'state_label' => 'Preparada — por lecionar',
            ]);
    }

    #[Test]
    public function previous_summary_never_returns_a_row_with_empty_content(): void
    {
        $this->lesson('2026-10-05 09:00:00', taught: true, content: 'Texto real.');
        $this->lesson('2026-10-06 09:00:00', taught: true); // lecionada sem texto
        $this->lesson('2026-10-07 09:00:00', homework: 'Só TPC.');
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->asTeacher()->get("/lessons/{$target->ulid}/previous-summary")->assertOk();

        $this->assertSame('Texto real.', $response->json('content'));
        $this->assertSame('taught', $response->json('state'));
    }

    #[Test]
    public function previous_summary_is_204_when_nothing_has_text(): void
    {
        $this->lesson('2026-10-06 09:00:00', taught: true);
        $target = $this->lesson('2026-10-08 09:00:00');

        $this->asTeacher()->get("/lessons/{$target->ulid}/previous-summary")->assertStatus(204);
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden_on_both_endpoints(): void
    {
        $target = $this->lesson('2026-10-08 09:00:00');
        $stranger = User::factory()->create();
        $this->organization->members()->attach($stranger, ['joined_at' => now()]);

        $this->actingAs($stranger)->withSession(['organization_id' => $this->organization->id])
            ->get("/lessons/{$target->ulid}/preparation-context")->assertForbidden();
        $this->actingAs($stranger)->withSession(['organization_id' => $this->organization->id])
            ->get("/lessons/{$target->ulid}/previous-summary")->assertForbidden();
    }

    #[Test]
    public function the_endpoint_writes_nothing(): void
    {
        $this->lesson('2026-10-05 09:00:00', content: 'Plano.');
        $target = $this->lesson('2026-10-08 09:00:00');
        $before = [Lesson::withoutGlobalScopes()->count(), LessonSummary::withoutGlobalScopes()->count()];

        $this->context($target)->assertOk();

        $this->assertSame($before, [Lesson::withoutGlobalScopes()->count(), LessonSummary::withoutGlobalScopes()->count()]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function context(Lesson $lesson, array $query = []): TestResponse
    {
        return $this->asTeacher()->getJson("/lessons/{$lesson->ulid}/preparation-context".($query === [] ? '' : '?'.http_build_query($query)));
    }

    private function asTeacher(): self
    {
        return $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id]);
    }

    private function makeClass(User $teacher): SchoolClass
    {
        return $this->inTenant(function () use ($teacher): SchoolClass {
            $schoolClass = SchoolClass::factory()
                ->recycle($this->organization)
                ->create([
                    'label' => '8.º F',
                    'academic_year_id' => AcademicYear::factory()
                        ->recycle($this->organization)
                        ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])
                        ->id,
                ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    private function group(string $label): ClassGroup
    {
        return $this->inTenant(fn (): ClassGroup => ClassGroup::factory()
            ->recycle($this->organization)
            ->create(['class_id' => $this->schoolClass->id, 'label' => $label]));
    }

    private function lesson(
        string $startsAt,
        bool $taught = false,
        ?LessonOutcome $outcome = null,
        ?string $content = null,
        ?string $resources = null,
        ?string $homework = null,
        ?string $privateNotes = null,
        ?ClassGroup $group = null,
    ): Lesson {
        return $this->inTenant(function () use ($startsAt, $taught, $outcome, $content, $resources, $homework, $privateNotes, $group): Lesson {
            $lesson = Lesson::create([
                'class_id' => $this->schoolClass->id,
                'class_group_id' => $group?->id,
                'starts_at' => $startsAt,
                'ends_at' => null,
                'status' => $taught ? LessonStatus::Taught : LessonStatus::Preparation,
                'outcome' => $outcome ?? ($taught ? LessonOutcome::Taught : null),
                'created_by' => $this->teacher->id,
            ]);

            if ($content !== null || $resources !== null || $homework !== null || $privateNotes !== null) {
                LessonSummary::create([
                    'lesson_id' => $lesson->id,
                    'content' => $content ?? '',
                    'resources' => $resources,
                    'homework' => $homework,
                    'private_notes' => $privateNotes,
                ]);
            }

            return $lesson;
        });
    }

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
