<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MarkLessonAsTaught;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\CalendarEventType;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
use App\Models\LessonSequence;
use App\Models\LessonSummary;
use App\Models\Module;
use App\Models\OrganizationModuleOverride;
use App\Models\User;
use App\Services\Lessons\LessonDayEvents;
use App\Services\Lessons\LessonNumbering;
use App\Support\Entitlements\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.171.0 — «Registar e adiar a preparação»: uma atividade do calendário ocupa
 * a aula, o resultado fica «Turma em outras atividades letivas» e a preparação
 * avança uma aula pela cadeia de sempre (ShiftLessonPlanning).
 *
 * Quintas-feiras de outubro de 2026: 01, 08, 15, 22 e 29.
 */
class PostponeLessonForCalendarActivityTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RELÓGIO FIXO antes de criar dados (ver LessonOutcomeTest).
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    #[Test]
    public function postponing_an_applied_sequence_moves_each_element_one_lesson_and_a_reapply_does_not_duplicate(): void
    {
        [$first, $second, $third, $fourth] = $this->manualLessons(['01', '08', '15', '22']);
        $this->resequence();
        $sequence = $this->sequence(['Elemento 1', 'Elemento 2', 'Elemento 3']);
        $this->applySequence($sequence);
        $event = $this->event(['title' => 'Visita de estudo', 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-08']);
        $before = $this->snapshot();
        $versionFirst = $this->summaryVersion($first);

        $preview = $this->preview($second, $event)->assertOk();

        $this->assertCount(2, $preview->json('moves'));
        $this->assertSame($second->ulid, $preview->json('moves.0.from.ulid'));
        $this->assertSame($third->ulid, $preview->json('moves.0.to.ulid'));
        $this->assertSame($fourth->ulid, $preview->json('moves.1.to.ulid'));
        $this->assertSame('Elemento 2', $preview->json('moves.0.excerpt'));
        $this->assertSame(['content'], $preview->json('moves.0.fields'));
        $this->assertTrue($preview->json('requires_cascade_confirmation'));
        $this->assertSame('Turma em outras atividades letivas', $preview->json('outcome_label'));
        $this->assertSame($before, $this->snapshot(), 'A pré-visualização não grava nada.');

        $token = $preview->json('plan_token');
        $this->postpone($second, $event, $token)->assertSessionHasErrors('confirm_cascade');
        $this->assertSame($before, $this->snapshot(), 'Sem a confirmação nada muda.');

        $this->postpone($second, $event, $token, true)->assertSessionHasNoErrors()->assertRedirect();

        $this->inTenant($this->organization, function () use ($first, $second, $third, $fourth, $sequence, $versionFirst): void {
            $second->refresh();
            $this->assertSame(LessonOutcome::ClassExternalActivity, $second->outcome);
            $this->assertSame('Visita de estudo', $second->outcome_note);
            $this->assertNull($second->summary()->first());

            $items = $sequence->items()->orderBy('position')->get();
            $this->assertSame('Elemento 2', $third->summary()->sole()->content);
            $this->assertSame($items[1]->id, $third->summary()->sole()->lesson_sequence_item_id);
            $this->assertSame($sequence->id, $third->summary()->sole()->lesson_sequence_id);
            $this->assertSame('Elemento 3', $fourth->summary()->sole()->content);
            $this->assertSame($items[2]->id, $fourth->summary()->sole()->lesson_sequence_item_id);

            // A aula anterior ficou como estava.
            $this->assertSame('Elemento 1', $first->summary()->sole()->content);
            $this->assertSame($versionFirst, $this->summaryVersion($first));

            foreach (['Elemento 1', 'Elemento 2', 'Elemento 3'] as $content) {
                $this->assertSame(1, LessonSummary::query()->where('content', $content)->count(), "«{$content}» existe uma só vez.");
            }
        });

        // Reaplicar a sequência reconhece os elementos nas aulas novas.
        $this->applySequence($sequence);
        $this->inTenant($this->organization, function (): void {
            foreach (['Elemento 1', 'Elemento 2', 'Elemento 3'] as $content) {
                $this->assertSame(1, LessonSummary::query()->where('content', $content)->count(), "«{$content}» continua a existir uma só vez.");
            }
            $this->assertSame(3, LessonSummary::query()->count());
        });

        $audit = AuditEvent::query()->where('event', 'lesson.postponed_for_calendar_activity')->sole();
        $this->assertStringNotContainsString('Visita de estudo', json_encode($audit->properties));
        $this->assertStringNotContainsString('Elemento', json_encode($audit->properties));
        $this->assertSame(2, $audit->properties['moves']);
    }

    #[Test]
    public function a_destination_that_already_has_teacher_content_advances_and_is_never_overwritten(): void
    {
        [$first, $second, $third] = $this->manualLessons(['08', '15', '22']);
        $this->summary($first, 'Plano A');
        $this->summary($second, 'Plano do professor B');
        $event = $this->event(['title' => 'Teste de outra disciplina']);

        $preview = $this->preview($first, $event)->assertOk();
        $this->assertCount(2, $preview->json('moves'));

        $this->postpone($first, $event, $preview->json('plan_token'), true)->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($first, $second, $third): void {
            $this->assertNull($first->summary()->first());
            $this->assertSame('Plano A', $second->summary()->sole()->content);
            $this->assertSame('Plano do professor B', $third->summary()->sole()->content);
        });
    }

    #[Test]
    public function without_a_destination_the_plan_stays_pending_on_this_lesson_and_nothing_is_deleted(): void
    {
        [$lesson] = $this->manualLessons(['08']);
        $this->summary($lesson, 'Plano A', ['resources' => 'Manual p. 12', 'homework' => 'Ficha 3', 'private_notes' => 'Nota só minha']);
        $event = $this->event(['title' => 'Visita']);

        $preview = $this->preview($lesson, $event)->assertOk();

        $this->assertSame([], $preview->json('moves'));
        $this->assertSame(true, $preview->json('pending.on_this_lesson'));
        $this->assertSame('Plano A', $preview->json('pending.excerpt'));
        $this->assertFalse($preview->json('requires_cascade_confirmation'));
        // As notas privadas nunca aparecem na pré-visualização.
        $this->assertStringNotContainsString('Nota só minha', $preview->getContent());

        $this->postpone($lesson, $event, $preview->json('plan_token'))->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $plan = LessonPlan::query()->where('lesson_id', $lesson->id)->sole();
            $this->assertStringContainsString('Plano A', $plan->planned_summary);
            $this->assertStringContainsString('Manual p. 12', $plan->planned_summary);
            $this->assertStringContainsString('Ficha 3', $plan->planned_summary);
            $this->assertSame(1, Lesson::query()->count());
        });
    }

    /**
     * Depois da operação, REABRIR as duas aulas. A original identifica a
     * atividade pelo resultado que já existia (o título na nota curta — nunca as
     * notas do calendário, que podem ter dados pessoais) e continua a mostrar o
     * acontecimento; a de destino tem a preparação inteira: o texto do
     * professor, mesmo a linha que ele alterou, e os outros campos — sem a linha
     * que «Incluir no sumário» gerou.
     */
    #[Test]
    public function reopening_both_lessons_shows_the_activity_on_the_original_and_the_whole_preparation_on_the_destination(): void
    {
        [$original, $destination] = $this->manualLessons(['08', '15']);
        $event = $this->event(['title' => 'Visita de estudo ao museu', 'description' => 'Autorizações do João por entregar']);
        $line = LessonDayEvents::summaryLine($event);
        $this->summary(
            $original,
            "Plano A: poesia\n{$line}\n{$line} (com almoço)\nLer o poema 3 depois da visita",
            ['resources' => 'Manual p. 12', 'homework' => 'Ficha 2', 'private_notes' => 'Só minha'],
        );

        $preview = $this->preview($original, $event)->assertOk();
        $this->assertSame('Visita de estudo ao museu', $preview->json('note'));
        $this->postpone($original, $event, $preview->json('plan_token'))->assertSessionHasNoErrors();

        $this->asTeacher()->get("/lessons/{$original->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.outcome', 'class_external_activity')
                ->where('lesson.outcome_label', 'Turma em outras atividades letivas')
                ->where('lesson.outcome_note', 'Visita de estudo ao museu')
                ->where('lesson.summary', null)
                ->where('lesson.pending_plan', null)
                ->where('day_events.0.title', 'Visita de estudo ao museu')
                ->where('day_events.0.notes', 'Autorizações do João por entregar'));

        $this->asTeacher()->get("/lessons/{$destination->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.status', 'prepared')
                ->where('lesson.outcome', null)
                ->where('lesson.summary.content', "Plano A: poesia\n{$line} (com almoço)\nLer o poema 3 depois da visita")
                ->where('lesson.summary.resources', 'Manual p. 12')
                ->where('lesson.summary.homework', 'Ficha 2')
                ->where('lesson.summary.private_notes', 'Só minha'));

        // As notas do calendário não entram no registo da aula.
        $this->inTenant($this->organization, fn () => $this->assertStringNotContainsString('João', (string) $original->refresh()->outcome_note));
    }

    /**
     * O token conta QUAIS rascunhos de falta vão ser descartados, e não só
     * quantos: trocar a aluna entre a pré-visualização e a confirmação invalida-o.
     */
    #[Test]
    public function swapping_which_absence_draft_would_be_dropped_invalidates_the_plan_token(): void
    {
        $alice = $this->enroll('Alice');
        $bruno = $this->enroll('Bruno');
        [$lesson] = $this->manualLessons(['08']);
        $this->asTeacher()->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => [$alice->student->ulid]])->assertSessionHasNoErrors();
        $event = $this->event(['title' => 'Visita']);

        $preview = $this->preview($lesson, $event)->assertOk();
        $this->assertSame(1, $preview->json('discarded_attendance_drafts'));

        // O mesmo número de rascunhos, outra aluna.
        $this->asTeacher()->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => [$bruno->student->ulid]])->assertSessionHasNoErrors();
        $before = $this->snapshot();

        $this->postpone($lesson, $event, $preview->json('plan_token'))->assertSessionHasErrors('plan_token');
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * A → B, e a preparação de B já não cabe em aula nenhuma: um só
     * deslocamento, mas DUAS preparações mudam de lugar — e isso pede a mesma
     * confirmação que uma cadeia de dois deslocamentos.
     */
    #[Test]
    public function a_preparation_pushed_into_pending_counts_as_displaced_and_needs_confirmation(): void
    {
        [$first, $second] = $this->manualLessons(['08', '15']);
        $this->summary($first, 'Plano A');
        $this->summary($second, 'Plano B', ['homework' => 'Ficha 4']);
        $event = $this->event(['title' => 'Visita']);
        $before = $this->snapshot();

        $preview = $this->preview($first, $event)->assertOk();

        $this->assertCount(1, $preview->json('moves'));
        $this->assertSame($second->ulid, $preview->json('pending.on.ulid'));
        $this->assertSame('Plano B', $preview->json('pending.excerpt'));
        $this->assertSame(['content', 'homework'], $preview->json('pending.fields'));
        $this->assertSame(2, $preview->json('displaced_preparations'));
        $this->assertTrue($preview->json('requires_cascade_confirmation'));

        $this->postpone($first, $event, $preview->json('plan_token'))->assertSessionHasErrors('confirm_cascade');
        $this->assertSame($before, $this->snapshot());

        $this->postpone($first, $event, $preview->json('plan_token'), true)->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($second): void {
            $this->assertSame('Plano A', $second->summary()->sole()->content);
            $pending = LessonPlan::query()->where('lesson_id', $second->id)->sole()->planned_summary;
            $this->assertStringContainsString('Plano B', $pending);
            $this->assertStringContainsString('Ficha 4', $pending);
        });
    }

    #[Test]
    public function the_next_timetable_occurrence_is_shown_by_the_preview_and_only_created_by_the_execution(): void
    {
        $slot = $this->makeSlot();
        $lesson = $this->makeLesson([
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => '2026-10-08 09:30:00',
        ]);
        $this->summary($lesson, 'Plano A');
        $event = $this->event(['title' => 'Visita']);

        $preview = $this->preview($lesson, $event)->assertOk();

        $this->assertTrue($preview->json('moves.0.created_from_timetable'));
        $this->assertNull($preview->json('moves.0.to.ulid'));
        $this->assertStringContainsString('2026-10-15', $preview->json('moves.0.to.starts_at'));
        $this->inTenant($this->organization, fn () => $this->assertSame(1, Lesson::query()->count(), 'Depois de revertida a aula não existe.'));

        $this->postpone($lesson, $event, $preview->json('plan_token'))->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($slot): void {
            $created = Lesson::query()->where('recurring_lesson_slot_id', $slot->id)->where('starts_at', '>', '2026-10-09')->sole();
            $this->assertSame('Plano A', $created->summary()->sole()->content);
        });
    }

    #[Test]
    public function taught_lessons_manual_numbers_and_ranges_are_preserved(): void
    {
        [$taught, $open, $next, $manual] = $this->manualLessons(['01', '08', '15', '22']);
        $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($taught, $this->teacher, consolidateAttendance: false));
        $this->resequence();
        $this->summary($open, 'Plano A');
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($manual->getKey())->update([
            'lesson_number' => 20, 'lesson_number_end' => 21, 'lesson_number_manual' => true,
        ]));
        $taughtNumber = $this->inTenant($this->organization, fn () => $taught->refresh()->lesson_number);
        $event = $this->event(['title' => 'Visita']);

        $preview = $this->preview($open, $event)->assertOk();
        $this->postpone($open, $event, $preview->json('plan_token'))->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($taught, $manual, $next, $taughtNumber): void {
            $this->assertSame($taughtNumber, $taught->refresh()->lesson_number);
            $manual->refresh();
            $this->assertSame(20, $manual->lesson_number);
            $this->assertSame(21, $manual->lesson_number_end);
            $this->assertTrue((bool) $manual->lesson_number_manual);
            $this->assertSame('Plano A', $next->summary()->sole()->content);
        });
    }

    #[Test]
    public function a_closed_lesson_later_or_consolidated_attendance_is_a_422_without_any_write(): void
    {
        [$open, $later] = $this->manualLessons(['08', '15']);
        $this->summary($open, 'Plano A');
        $event = $this->event(['title' => 'Visita']);
        $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($later, $this->teacher, consolidateAttendance: false));
        $before = $this->snapshot();

        $this->preview($open, $event)->assertStatus(422)->assertJsonValidationErrors('outcome');
        $this->postpone($open, $event, str_repeat('a', 64))->assertSessionHasErrors('outcome');
        $this->assertSame($before, $this->snapshot());

        // Assiduidade consolidada.
        [$recorded] = $this->manualLessons(['29']);
        $this->summary($recorded, 'Plano R');
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($recorded->getKey())->toBase()->update(['attendance_recorded_at' => now()]));
        $before = $this->snapshot();

        $this->preview($recorded, $event)->assertStatus(422)->assertJsonValidationErrors('outcome');
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function attendance_drafts_are_counted_by_the_preview_and_dropped_by_the_execution(): void
    {
        $student = $this->enroll('Alice');
        [$lesson] = $this->manualLessons(['08']);
        $this->asTeacher()->put("/lessons/{$lesson->ulid}/attendance/draft", ['absent' => [$student->student->ulid]])->assertSessionHasNoErrors();
        $event = $this->event(['title' => 'Visita']);

        $preview = $this->preview($lesson, $event)->assertOk();
        $this->assertSame(1, $preview->json('discarded_attendance_drafts'));
        $this->inTenant($this->organization, fn () => $this->assertSame(1, LessonAttendance::query()->count()));

        $this->postpone($lesson, $event, $preview->json('plan_token'))->assertSessionHasNoErrors();
        $this->inTenant($this->organization, fn () => $this->assertSame(0, LessonAttendance::query()->count()));
    }

    #[Test]
    public function the_included_activity_line_does_not_travel_but_a_similar_line_does(): void
    {
        [$first, $second] = $this->manualLessons(['08', '15']);
        $event = $this->event(['title' => 'Visita de estudo', 'description' => 'Autocarro às 9h']);
        $this->summary($first, "Plano A\nVisita de estudo — Autocarro às 9h\nVisita de estudo — Autocarro às 9h30\nFim");

        $preview = $this->preview($first, $event)->assertOk();
        $this->assertTrue($preview->json('stripped_activity_line'));

        $this->postpone($first, $event, $preview->json('plan_token'))->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($second): void {
            $this->assertSame("Plano A\nVisita de estudo — Autocarro às 9h30\nFim", $second->summary()->sole()->content);
        });
    }

    #[Test]
    public function an_activity_line_alone_leaves_nothing_to_move(): void
    {
        [$first, $second] = $this->manualLessons(['08', '15']);
        $event = $this->event(['title' => 'Visita']);
        $this->summary($first, 'Visita');

        $preview = $this->preview($first, $event)->assertOk();
        $this->assertTrue($preview->json('nothing_to_move'));

        $this->postpone($first, $event, $preview->json('plan_token'))->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($first, $second): void {
            $this->assertNull($first->summary()->first());
            $this->assertNull($second->summary()->first());
        });
    }

    #[Test]
    public function a_stale_plan_token_is_refused_and_nothing_changes(): void
    {
        [$first, $second, $third] = $this->manualLessons(['08', '15', '22']);
        $this->summary($first, 'Plano A');
        $this->summary($second, 'Plano B');
        $event = $this->event(['title' => 'Visita']);

        $preview = $this->preview($first, $event)->assertOk();
        // O token é determinístico: o mesmo pedido, o mesmo valor.
        $this->assertSame($preview->json('plan_token'), $this->preview($first, $event)->json('plan_token'));

        // O sumário de uma aula da cadeia muda entre a pré-visualização e a confirmação.
        $this->inTenant($this->organization, fn () => $second->summary()->sole()->update(['content' => 'Plano B editado']));
        $before = $this->snapshot();

        $this->postpone($first, $event, $preview->json('plan_token'), true)->assertSessionHasErrors('plan_token');

        $this->assertSame($before, $this->snapshot());
        $this->inTenant($this->organization, fn () => $this->assertNull($third->summary()->first()));
    }

    #[Test]
    public function cancelling_is_only_a_preview_and_leaves_the_database_byte_for_byte(): void
    {
        [$first, $second] = $this->manualLessons(['08', '15']);
        $this->summary($first, 'Plano A');
        $this->summary($second, 'Plano B');
        $event = $this->event(['title' => 'Visita']);
        $before = $this->snapshot();

        $this->preview($first, $event)->assertOk();
        $this->preview($first, $event)->assertOk();

        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function an_event_that_does_not_coincide_or_is_not_the_teachers_or_has_no_calendar_access_is_refused_without_writing(): void
    {
        [$lesson] = $this->manualLessons(['08']);
        $this->summary($lesson, 'Plano A');
        $apart = $this->event(['title' => 'Mais tarde', 'starts_at' => '14:00', 'ends_at' => '15:00']);
        $foreign = $this->event(['title' => 'Alheio'], User::factory()->create());
        $coinciding = $this->event(['title' => 'Coincide']);
        $before = $this->snapshot();

        $this->preview($lesson, $apart)->assertStatus(422)->assertJsonValidationErrors('calendar_event');
        $this->preview($lesson, $foreign)->assertStatus(422)->assertJsonValidationErrors('calendar_event');
        $this->asTeacher()->postJson("/lessons/{$lesson->ulid}/activity-postponement/preview", ['calendar_event' => 'nao-existe'])
            ->assertStatus(422)->assertJsonValidationErrors('calendar_event');
        $this->postpone($lesson, $apart, str_repeat('a', 64))->assertSessionHasErrors('calendar_event');
        $this->assertSame($before, $this->snapshot());

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'module_id' => Module::where('key', 'calendar')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de entitlement.',
        ]);
        app(Entitlements::class)->flush();

        $this->preview($lesson, $coinciding)->assertStatus(422)->assertJsonValidationErrors('calendar_event');
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function the_toast_names_the_real_destination_and_record_outcome_gives_one_too(): void
    {
        [$first, $second, $third, $fourth] = $this->manualLessons(['08', '15', '22', '29']);
        $this->summary($first, 'Plano A');
        $event = $this->event(['title' => 'Visita']);
        $preview = $this->preview($first, $event)->assertOk();

        $this->postpone($first, $event, $preview->json('plan_token'))
            ->assertSessionHas('inertia.flash_data', fn ($flash) => ($flash['toast'] ?? null) === [
                'type' => 'success',
                'message' => 'Atividade registada. A preparação passou para a aula de qui., 15/10.',
            ]);

        // O plano chegou à segunda; fechá-la pelo fecho rápido diz para onde o leva.
        $this->asTeacher()->post("/lessons/{$second->ulid}/outcome", ['outcome' => 'class_external_activity'])
            ->assertSessionHas('success')
            ->assertSessionHas('inertia.flash_data', fn ($flash) => ($flash['toast']['message'] ?? null) === 'Atividade registada. A preparação passou para a aula de qui., 22/10.');

        $this->asTeacher()->post("/lessons/{$fourth->ulid}/outcome", ['outcome' => 'class_external_activity'])
            ->assertSessionHas('inertia.flash_data', fn ($flash) => ($flash['toast']['message'] ?? null) === 'Atividade registada. Esta aula não tinha preparação.');
    }

    #[Test]
    public function another_teacher_of_the_class_cannot_postpone_with_someone_elses_event(): void
    {
        [$lesson] = $this->manualLessons(['08']);
        $this->summary($lesson, 'Plano A');
        $event = $this->event(['title' => 'Visita']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson("/lessons/{$lesson->ulid}/activity-postponement/preview", ['calendar_event' => $event->ulid])
            ->assertNotFound();
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param  list<string>  $days
     * @return list<Lesson>
     */
    private function manualLessons(array $days): array
    {
        return array_map(fn (string $day): Lesson => $this->makeLesson([
            'origin' => 'manual',
            'starts_at' => "2026-10-{$day} 09:30:00",
            'ends_at' => "2026-10-{$day} 10:20:00",
        ]), $days);
    }

    /**
     * @param  array<string, string|null>  $extra
     */
    private function summary(Lesson $lesson, string $content, array $extra = []): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => $content] + $extra));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes = [], ?User $owner = null): CalendarEvent
    {
        return $this->inTenant($this->organization, function () use ($attributes, $owner): CalendarEvent {
            $event = CalendarEvent::create(array_merge([
                'user_id' => ($owner ?? $this->teacher)->getKey(),
                'type' => CalendarEventType::Meeting,
                'title' => 'Um acontecimento',
                'starts_on' => '2026-10-01',
                'ends_on' => '2026-10-31',
            ], $attributes));
            $event->schoolClasses()->sync([$this->schoolClass->getKey()]);

            return $event;
        });
    }

    /**
     * @param  list<string>  $summaries
     */
    private function sequence(array $summaries): LessonSequence
    {
        return $this->inTenant($this->organization, function () use ($summaries): LessonSequence {
            $sequence = LessonSequence::create([
                'user_id' => $this->teacher->id,
                'subject_id' => $this->schoolClass->subject_id,
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'grade_level' => null,
                'name' => 'Sequência de teste',
            ]);

            foreach ($summaries as $index => $summary) {
                $sequence->items()->create(['position' => $index + 1, 'summary' => $summary]);
            }

            return $sequence;
        });
    }

    private function applySequence(LessonSequence $sequence): void
    {
        $payload = ['class_id' => $this->schoolClass->id, 'from' => '2026-09-30', 'summary' => true];
        $token = $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $payload)->assertOk()->json('plan_token');

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $payload + ['plan_token' => $token])->assertSessionHasNoErrors();
    }

    private function preview(Lesson $lesson, CalendarEvent $event): TestResponse
    {
        return $this->asTeacher()->postJson("/lessons/{$lesson->ulid}/activity-postponement/preview", ['calendar_event' => $event->ulid]);
    }

    private function postpone(Lesson $lesson, CalendarEvent $event, string $token, bool $confirmCascade = false): TestResponse
    {
        return $this->asTeacher()->post("/lessons/{$lesson->ulid}/activity-postponement", [
            'calendar_event' => $event->ulid,
            'plan_token' => $token,
            'confirm_cascade' => $confirmCascade,
        ]);
    }

    private function resequence(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));
    }

    /**
     * O estado da base de dados relevante, para provar que nada mudou: aulas,
     * sumários (com versões), planos pendentes, rascunhos e auditoria.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $dump = [];

        foreach (['lessons', 'lesson_summaries', 'lesson_plans', 'lesson_attendances', 'audit_events'] as $table) {
            $dump[$table] = hash('sha256', (string) json_encode(DB::table($table)->orderBy('id')->get()->all()));
        }

        return $dump;
    }
}
