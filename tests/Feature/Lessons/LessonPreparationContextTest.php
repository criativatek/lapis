<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Aula anterior» — o contexto de quem prepara uma aula: UMA só aula, a mais
 * próxima antes dela que esteja lecionada ou preparada por lecionar, do mesmo
 * público, com o estado explícito. As datas são absolutas e o relógio é
 * congelado no setUp, ANTES de criar dados.
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

        $this->travelTo(Carbon::parse('2026-10-16 12:00:00', 'Europe/Lisbon'));

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);
        $this->schoolClass = $this->makeClass($this->teacher);
    }

    #[Test]
    public function a_prepared_lesson_with_a_summary_is_the_previous_one(): void
    {
        $prepared = $this->lesson('2026-10-06 09:00:00', content: "Terça planeada.\nSegunda linha.", homework: 'Ficha 3.', resources: 'https://exemplo.pt/ficha');
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($target)->assertOk();

        $this->assertSame($prepared->ulid, $response->json('lesson.ulid'));
        $this->assertSame('prepared', $response->json('lesson.state'));
        $this->assertSame('Preparada — por lecionar', $response->json('lesson.state_label'));
        $this->assertSame("Terça planeada.\nSegunda linha.", $response->json('lesson.content'));
        $this->assertSame('Ficha 3.', $response->json('lesson.homework'));
        $this->assertSame('https://exemplo.pt/ficha', $response->json('lesson.resources'));
        $this->assertSame($this->schoolClass->ulid, $response->json('lesson.class_ulid'));
        $this->assertArrayNotHasKey('private_notes', $response->json('lesson'));
        $this->assertArrayNotHasKey('has_more', $response->json());
    }

    #[Test]
    public function a_prepared_lesson_with_only_a_plan_counts_and_shows_the_plan(): void
    {
        $planned = $this->lesson('2026-10-06 09:00:00', plan: 'Plano vindo da sequência.');
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($target)->assertOk();

        $this->assertSame($planned->ulid, $response->json('lesson.ulid'));
        $this->assertSame('prepared', $response->json('lesson.state'));
        $this->assertSame('', $response->json('lesson.content'));
        $this->assertSame('Plano vindo da sequência.', $response->json('lesson.plan'));
    }

    #[Test]
    public function private_notes_never_reach_the_card_not_even_through_the_plan(): void
    {
        $planned = $this->lesson(
            '2026-10-06 09:00:00',
            content: 'Sumário público.',
            privateNotes: 'SEGREDO-QA no sumário.',
            plan: "Conteúdo planeado.\n\nRecursos: ficha 2\n\nTrabalho de casa: ficha 3\n\nNotas privadas: SEGREDO-QA no plano.\nSegunda linha SEGREDO-QA.",
        );
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($target)->assertOk();

        $this->assertSame($planned->ulid, $response->json('lesson.ulid'));
        $this->assertSame("Conteúdo planeado.\n\nRecursos: ficha 2\n\nTrabalho de casa: ficha 3", $response->json('lesson.plan'));
        $this->assertSame('Sumário público.', $response->json('lesson.content'));
        $this->assertStringNotContainsString('SEGREDO-QA', $response->getContent());
        $this->assertStringNotContainsString('Notas privadas', $response->getContent());
    }

    #[Test]
    public function a_plan_with_only_private_notes_is_not_eligible_and_the_previous_one_is_chosen(): void
    {
        $real = $this->lesson('2026-10-02 09:00:00', taught: true, content: 'Com conteúdo real.');
        $this->lesson('2026-10-06 09:00:00', plan: 'Notas privadas: SEGREDO-QA só nisto.');
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($target)->assertOk();

        $this->assertSame($real->ulid, $response->json('lesson.ulid'));
        $this->assertStringNotContainsString('SEGREDO-QA', $response->getContent());
    }

    #[Test]
    public function a_taught_lesson_whose_plan_is_only_private_notes_shows_no_plan(): void
    {
        $taught = $this->lesson('2026-10-06 09:00:00', taught: true, plan: 'Notas privadas: SEGREDO-QA.');
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($target)->assertOk();

        $this->assertSame($taught->ulid, $response->json('lesson.ulid'));
        $this->assertNull($response->json('lesson.plan'));
        $this->assertStringNotContainsString('SEGREDO-QA', $response->getContent());
    }

    #[Test]
    public function a_taught_lesson_is_the_previous_one(): void
    {
        $taught = $this->lesson('2026-10-06 09:00:00', taught: true, content: 'Dada.', plan: 'Planeada.');
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($target)->assertOk();

        $this->assertSame($taught->ulid, $response->json('lesson.ulid'));
        $this->assertSame('taught', $response->json('lesson.state'));
        $this->assertSame('Lecionada', $response->json('lesson.state_label'));
        $this->assertSame('Dada.', $response->json('lesson.content'));
        $this->assertSame('Planeada.', $response->json('lesson.plan'));
    }

    #[Test]
    public function a_taught_lesson_without_any_text_still_counts(): void
    {
        $taught = $this->lesson('2026-10-06 09:00:00', taught: true);
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($target)->assertOk();

        $this->assertSame($taught->ulid, $response->json('lesson.ulid'));
        $this->assertSame('taught', $response->json('lesson.state'));
        $this->assertSame('', $response->json('lesson.content'));
        $this->assertNull($response->json('lesson.plan'));
        $this->assertNull($response->json('lesson.resources'));
        $this->assertNull($response->json('lesson.homework'));
    }

    #[Test]
    public function an_empty_previous_lesson_is_skipped_for_the_one_before_it(): void
    {
        // Terça (a editar) → segunda vazia → sexta lecionada.
        $friday = $this->lesson('2026-10-02 09:00:00', taught: true, content: 'Sexta dada.');
        $this->lesson('2026-10-05 09:00:00'); // segunda, aberta e vazia
        $this->lesson('2026-10-05 11:00:00', content: "  \n "); // só espaços
        $this->lesson('2026-10-05 12:00:00', privateNotes: 'Só notas minhas.'); // só notas privadas
        $tuesday = $this->lesson('2026-10-06 09:00:00');

        $this->assertSame($friday->ulid, $this->context($tuesday)->json('lesson.ulid'));
    }

    #[Test]
    public function a_lesson_marked_as_no_lesson_took_place_is_ignored(): void
    {
        $taught = $this->lesson('2026-10-01 09:00:00', taught: true, content: 'Dada.');
        $this->lesson('2026-10-02 09:00:00', outcome: LessonOutcome::TeacherAbsent, content: 'Plano que já desceu.');
        $this->lesson('2026-10-05 09:00:00', outcome: LessonOutcome::ClassExternalActivity, content: 'Visita.', plan: 'Plano da visita.');
        $target = $this->lesson('2026-10-08 09:00:00');

        $this->assertSame($taught->ulid, $this->context($target)->json('lesson.ulid'));
    }

    #[Test]
    public function a_deleted_lesson_is_ignored(): void
    {
        $taught = $this->lesson('2026-10-01 09:00:00', taught: true, content: 'Dada.');
        $cancelled = $this->lesson('2026-10-05 09:00:00', content: 'Cancelada.');
        $target = $this->lesson('2026-10-08 09:00:00');
        $this->removeLesson($cancelled);

        $this->assertSame($taught->ulid, $this->context($target)->json('lesson.ulid'));
    }

    #[Test]
    public function two_lessons_on_the_same_day_pick_the_latest_one_before_the_target(): void
    {
        $morning = $this->lesson('2026-10-07 09:00:00', taught: true, content: 'Manhã.');
        $afternoon = $this->lesson('2026-10-07 14:00:00', content: 'Tarde planeada.');
        $target = $this->lesson('2026-10-07 15:00:00');
        $this->lesson('2026-10-07 16:00:00', content: 'Depois do destino.');

        $this->assertSame($afternoon->ulid, $this->context($target)->json('lesson.ulid'));
        // A aula em edição pode ser a segunda do dia: a da manhã é a anterior.
        $this->assertSame($morning->ulid, $this->context($afternoon)->json('lesson.ulid'));
    }

    #[Test]
    public function the_same_start_time_is_broken_by_id(): void
    {
        $first = $this->lesson('2026-10-07 09:00:00', content: 'Primeira.');
        $second = $this->lesson('2026-10-07 09:00:00', content: 'Segunda.');

        $this->assertSame($first->ulid, $this->context($second)->json('lesson.ulid'));
        $this->assertNull($this->context($first)->json('lesson'));
    }

    #[Test]
    public function it_never_picks_the_lesson_itself_or_a_later_one(): void
    {
        $before = $this->lesson('2026-10-05 09:00:00', content: 'Anterior.');
        $target = $this->lesson('2026-10-08 09:00:00', content: 'Em preparação.');
        $this->lesson('2026-10-12 09:00:00', content: 'Posterior preparada.');
        $this->lesson('2026-10-13 09:00:00', taught: true, content: 'Posterior lecionada.');

        $this->assertSame($before->ulid, $this->context($target)->json('lesson.ulid'));
    }

    #[Test]
    public function the_choice_follows_date_and_time_never_the_lesson_number(): void
    {
        // Números alterados à mão, fora de ordem: a de 05/10 tem o 40, a de 06/10 o 3.
        $this->lesson('2026-10-05 09:00:00', taught: true, content: 'Cinco.', number: 40, numberManual: true);
        $sixth = $this->lesson('2026-10-06 09:00:00', taught: true, content: 'Seis.', number: 3, numberManual: true);
        $seventh = $this->lesson('2026-10-07 09:00:00', taught: true, content: 'Sete.', number: 1, numberManual: true);
        $target = $this->lesson('2026-10-08 09:00:00', number: 99, numberManual: true);

        $response = $this->context($target)->assertOk();

        // O número da anterior (1) é o mais baixo, e a escolhida é a mais recente.
        $this->assertSame($seventh->ulid, $response->json('lesson.ulid'));
        $this->assertSame(1, $response->json('lesson.lesson_number'));

        $this->removeLesson($seventh);
        $this->assertSame($sixth->ulid, $this->context($target)->json('lesson.ulid'));
    }

    #[Test]
    public function the_lesson_number_range_is_returned(): void
    {
        $this->lesson('2026-10-05 09:00:00', taught: true, content: 'Duas lições.', number: 11, numberEnd: 12);
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($target);

        $this->assertSame(11, $response->json('lesson.lesson_number'));
        $this->assertSame(12, $response->json('lesson.lesson_number_end'));
    }

    #[Test]
    public function a_prepared_lesson_more_recent_than_the_taught_one_wins(): void
    {
        $this->lesson('2026-10-05 09:00:00', taught: true, content: 'Dada, mais antiga.');
        $prepared = $this->lesson('2026-10-06 09:00:00', content: 'Preparada, mais recente.');
        $target = $this->lesson('2026-10-08 09:00:00');

        $response = $this->context($target);

        $this->assertSame($prepared->ulid, $response->json('lesson.ulid'));
        $this->assertSame('prepared', $response->json('lesson.state'));
    }

    #[Test]
    public function a_taught_lesson_more_recent_than_the_prepared_one_wins(): void
    {
        $this->lesson('2026-10-05 09:00:00', content: 'Preparada, mais antiga.');
        $taught = $this->lesson('2026-10-06 09:00:00', taught: true, content: 'Dada, mais recente.');
        $target = $this->lesson('2026-10-08 09:00:00');

        $this->assertSame($taught->ulid, $this->context($target)->json('lesson.ulid'));
    }

    #[Test]
    public function without_any_history_the_lesson_is_null(): void
    {
        $target = $this->lesson('2026-10-08 09:00:00');
        $this->lesson('2026-10-12 09:00:00', taught: true, content: 'Só depois.');

        $this->context($target)->assertOk()->assertExactJson(['lesson' => null]);
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

        $this->assertSame($onT1->ulid, $this->context($targetT1)->json('lesson.ulid'));
        $this->assertSame($onT2->ulid, $this->context($targetT2)->json('lesson.ulid'));
        $this->assertSame($whole->ulid, $this->context($targetWhole)->json('lesson.ulid'));
        $this->assertSame('8.º F · T1', $this->context($targetT1)->json('lesson.context_label'));
        // T1 não vê a turma inteira nem T2, mesmo havendo aulas delas antes da sua primeira.
        $this->assertNull($this->context($this->lesson('2026-10-01 08:00:00', group: $t1))->json('lesson'));
    }

    #[Test]
    public function previous_summary_returns_the_most_recent_with_text_and_its_state(): void
    {
        $this->lesson('2026-10-05 09:00:00', taught: true, content: 'Lecionada com texto.');
        $this->lesson('2026-10-06 09:00:00', content: 'Preparada mais recente.', privateNotes: 'Nota minha.');
        $this->lesson('2026-10-07 09:00:00', resources: 'Só recurso, sem sumário.');
        $this->lesson('2026-10-07 10:00:00', plan: 'Só plano, sem sumário.');
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
    public function the_endpoint_is_a_get_and_changes_nothing_in_the_database(): void
    {
        $this->lesson('2026-10-05 09:00:00', content: 'Plano.', plan: 'Plano pendente.', number: 7);
        $this->lesson('2026-10-06 09:00:00', taught: true);
        $target = $this->lesson('2026-10-08 09:00:00');
        $snapshot = fn (): array => [
            'lessons' => DB::table('lessons')->orderBy('id')->get()->toArray(),
            'summaries' => DB::table('lesson_summaries')->orderBy('id')->get()->toArray(),
            'plans' => DB::table('lesson_plans')->orderBy('id')->get()->toArray(),
            'audit' => DB::table('audit_events')->count(),
        ];
        $before = $snapshot();

        $this->context($target)->assertOk();
        $this->asTeacher()->postJson("/lessons/{$target->ulid}/preparation-context")->assertStatus(405);

        $this->assertEquals($before, $snapshot());
    }

    /**
     * Uma aula cancelada deixa de ter linha (e leva consigo o sumário e o plano).
     */
    private function removeLesson(Lesson $lesson): void
    {
        DB::table('lesson_summaries')->where('lesson_id', $lesson->id)->delete();
        DB::table('lesson_plans')->where('lesson_id', $lesson->id)->delete();
        DB::table('lessons')->where('id', $lesson->id)->delete();
    }

    private function context(Lesson $lesson): TestResponse
    {
        return $this->asTeacher()->getJson("/lessons/{$lesson->ulid}/preparation-context");
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
        ?string $plan = null,
        ?int $number = null,
        ?int $numberEnd = null,
        bool $numberManual = false,
    ): Lesson {
        return $this->inTenant(function () use ($startsAt, $taught, $outcome, $content, $resources, $homework, $privateNotes, $group, $plan, $number, $numberEnd, $numberManual): Lesson {
            $lesson = Lesson::create([
                'class_id' => $this->schoolClass->id,
                'class_group_id' => $group?->id,
                'starts_at' => $startsAt,
                'ends_at' => null,
                'lesson_number' => $number,
                'lesson_number_end' => $numberEnd,
                'lesson_number_manual' => $numberManual,
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

            if ($plan !== null) {
                LessonPlan::create([
                    'lesson_id' => $lesson->id,
                    'planned_summary' => $plan,
                    'created_by' => $this->teacher->id,
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
