<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\SaveLessonAttendanceDraft;
use App\Actions\Lessons\SaveLessonSummary;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonSequence;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\TeacherAbsenceReason;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Lessons\ShiftLessonPlanning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * Aplicar uma sequência ao HORÁRIO REAL da turma (quintas-feiras 09:30, a
 * partir de 01/10/2026 — «agora» é 01/10 às 08:00): preservar aulas
 * preparadas sem perder elementos, substituir com confirmação, reaplicar sem
 * duplicar, e tudo-ou-nada.
 *
 * Quintas-feiras de outubro de 2026: 01, 08, 15, 22, 29.
 */
class ApplyLessonSequenceScheduleTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
        Carbon::setTestNow('2026-10-01 08:00:00');
    }

    #[Test]
    public function a_prepared_lesson_in_the_middle_is_preserved_and_every_item_is_placed_in_order(): void
    {
        $slot = $this->makeSlot();
        $prepared = $this->preparedOn($slot, '2026-10-08', 'Aula do professor.');
        $versionBefore = $this->summaryVersion($prepared);
        $sequence = $this->sequence(['A', 'B', 'C']);

        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame(
            ['2026-10-01' => 'A', '2026-10-08' => 'Aula do professor.', '2026-10-15' => 'B', '2026-10-22' => 'C'],
            $this->contentByDay(),
        );

        $this->inTenant($this->organization, function () use ($prepared, $versionBefore, $sequence): void {
            $this->assertSame(3, LessonSummary::query()->where('lesson_sequence_id', $sequence->id)->count());
            $this->assertSame($versionBefore, $this->summaryVersion($prepared));
            $this->assertSame(LessonStatus::Prepared, $prepared->refresh()->status);
            $this->assertNull($prepared->summary()->sole()->lesson_sequence_id);
        });
    }

    #[Test]
    public function a_lesson_prepared_the_day_after_the_chosen_date_does_not_make_the_first_item_disappear(): void
    {
        $this->makeSlot();
        $friday = $this->makeSlot(['day_of_week' => 5]);
        $this->preparedOn($friday, '2026-10-02', 'Sexta preparada.');
        $sequence = $this->sequence(['A', 'B', 'C']);

        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame(
            ['2026-10-01' => 'A', '2026-10-02' => 'Sexta preparada.', '2026-10-08' => 'B', '2026-10-09' => 'C'],
            $this->contentByDay(),
        );
    }

    #[Test]
    public function a_confirmed_replacement_overwrites_the_selected_fields_and_keeps_the_unselected_ones(): void
    {
        $slot = $this->makeSlot();
        $prepared = $this->preparedOn($slot, '2026-10-08', 'Texto do professor.', ['private_notes' => 'Nota privada minha.']);
        $sequence = $this->sequence(['A', 'B']);
        $payload = ['from' => '2026-10-01', 'private_notes' => false, 'replace' => [$prepared->ulid]];

        // Sem confirmação: recusa e nada fica escrito.
        $preview = $this->preview($sequence, $payload)->assertOk()->assertJsonPath('requires_replace_confirmation', true);
        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->payload($payload) + ['plan_token' => $preview->json('plan_token')])
            ->assertSessionHasErrors('confirm_replace');

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(0, LessonSummary::query()->whereNotNull('lesson_sequence_id')->count());
            $this->assertSame(1, Lesson::query()->count());
        });

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->payload($payload) + [
            'plan_token' => $preview->json('plan_token'),
            'confirm_replace' => true,
        ])->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($prepared): void {
            $summary = $prepared->summary()->sole();
            $this->assertSame('B', $summary->content);
            $this->assertSame('Nota privada minha.', $summary->private_notes);
            $this->assertNotNull($summary->lesson_sequence_item_id);
        });
        $this->assertSame('A', $this->contentByDay()['2026-10-01']);
    }

    #[Test]
    public function the_preview_writes_nothing_even_though_it_materializes_the_schedule_to_compute_the_plan(): void
    {
        $this->makeSlot();
        $sequence = $this->sequence(['A', 'B', 'C']);

        $counts = fn (): array => $this->inTenant($this->organization, fn (): array => [
            Lesson::query()->count(),
            LessonSummary::query()->count(),
            AuditEvent::query()->count(),
        ]);
        $before = $counts();

        $response = $this->preview($sequence, ['from' => '2026-10-01'])->assertOk()
            ->assertJsonPath('complete', true)
            ->assertJsonPath('counts.fill', 3)
            ->assertJsonCount(3, 'steps')
            // As aulas ainda não existem: sem ULID.
            ->assertJsonPath('steps.0.lesson.ulid', null);

        $this->assertSame('A', $response->json('steps.0.item.summary'));
        $this->assertSame($before, $counts());
    }

    #[Test]
    public function reapplying_after_editing_an_item_only_changes_that_lesson_and_never_duplicates(): void
    {
        $this->makeSlot();
        $sequence = $this->sequence(['A', 'B', 'C']);
        $this->apply($sequence, ['from' => '2026-10-01']);

        $this->editSequence($sequence, ['A', 'B editado', 'C']);

        $this->preview($sequence, ['from' => '2026-10-01'])
            ->assertJsonPath('counts.unchanged', 2)
            ->assertJsonPath('counts.update', 1);

        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame(['2026-10-01' => 'A', '2026-10-08' => 'B editado', '2026-10-15' => 'C'], $this->contentByDay());
        $this->inTenant($this->organization, fn () => $this->assertSame(3, LessonSummary::query()->where('lesson_sequence_id', $sequence->id)->count()));
    }

    #[Test]
    public function a_lesson_taught_after_the_first_application_keeps_its_item_and_is_not_placed_again(): void
    {
        $this->makeSlot();
        $sequence = $this->sequence(['A', 'B', 'C']);
        $this->apply($sequence, ['from' => '2026-10-01']);

        $this->inTenant($this->organization, fn () => Lesson::query()->whereDate('starts_at', '2026-10-01')->sole()
            ->forceFill(['status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught])->save());

        $this->preview($sequence, ['from' => '2026-10-01'])
            ->assertJsonPath('counts.closed', 1)
            ->assertJsonPath('counts.unchanged', 2)
            ->assertJsonCount(1, 'already_applied');

        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame(['2026-10-01' => 'A', '2026-10-08' => 'B', '2026-10-15' => 'C'], $this->contentByDay());
    }

    #[Test]
    public function removing_an_item_moves_the_followers_up_and_releases_the_duplicate_but_never_a_teacher_edit(): void
    {
        $this->makeSlot();
        $sequence = $this->sequence(['A', 'B', 'C']);
        $this->apply($sequence, ['from' => '2026-10-01']);

        $this->editSequence($sequence, ['A', 'C'], keepIndexes: [0, 2]);

        $this->preview($sequence, ['from' => '2026-10-01'])
            ->assertJsonPath('counts.unchanged', 1)
            ->assertJsonPath('counts.update', 1)
            ->assertJsonPath('counts.release', 1);

        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame(['2026-10-01' => 'A', '2026-10-08' => 'C'], $this->contentByDay());
        $this->inTenant($this->organization, function (): void {
            $released = Lesson::query()->whereDate('starts_at', '2026-10-15')->sole();
            $this->assertNull($released->summary()->first());
            $this->assertSame(LessonStatus::Preparation, $released->status);
        });
    }

    #[Test]
    public function a_lesson_the_teacher_changed_is_never_released_or_rewritten_without_replace(): void
    {
        $this->makeSlot();
        $sequence = $this->sequence(['A', 'B', 'C']);
        $this->apply($sequence, ['from' => '2026-10-01']);

        // O professor mexe no texto da 3.ª aula (continua com a proveniência).
        $this->inTenant($this->organization, function (): void {
            $summary = Lesson::query()->whereDate('starts_at', '2026-10-15')->sole()->summary()->sole();
            $summary->content = 'C do professor.';
            $summary->save();
        });

        $this->editSequence($sequence, ['A', 'C'], keepIndexes: [0, 2]);
        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame('C do professor.', $this->contentByDay()['2026-10-15']);
    }

    #[Test]
    public function lessons_before_the_chosen_date_are_never_touched_and_the_sequence_starts_on_it(): void
    {
        $slot = $this->makeSlot();
        $this->preparedOn($slot, '2026-09-24', 'Passada com conteúdo.');
        $this->lessonOn($slot, '2026-10-01');
        $sequence = $this->sequence(['A', 'B']);

        $this->apply($sequence, ['from' => '2026-10-08'])->assertSessionHasNoErrors();

        $this->assertSame(['2026-09-24' => 'Passada com conteúdo.', '2026-10-08' => 'A', '2026-10-15' => 'B'], $this->contentByDay());
        $this->inTenant($this->organization, fn () => $this->assertNull(Lesson::query()->whereDate('starts_at', '2026-10-01')->sole()->summary()->first()));
    }

    #[Test]
    public function a_closed_lesson_after_the_date_is_never_written_even_if_listed_for_replacement(): void
    {
        $slot = $this->makeSlot();
        $taught = $this->preparedOn($slot, '2026-10-08', 'Dada.', [], LessonStatus::Taught);
        $sequence = $this->sequence(['A', 'B']);

        $this->apply($sequence, ['from' => '2026-10-01', 'replace' => [$taught->ulid], 'confirm_replace' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(['2026-10-01' => 'A', '2026-10-08' => 'Dada.', '2026-10-15' => 'B'], $this->contentByDay());
    }

    #[Test]
    public function a_holiday_in_the_middle_is_skipped(): void
    {
        $this->makeSlot();
        $this->inTenant($this->organization, fn () => AcademicCalendarException::factory()->recycle($this->organization)->create([
            'academic_year_id' => $this->schoolClass->academic_year_id,
            'type' => AcademicCalendarExceptionType::Holiday,
            'title' => 'Feriado',
            'starts_on' => '2026-10-08',
            'ends_on' => '2026-10-08',
        ]));
        $sequence = $this->sequence(['A', 'B']);

        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame(['2026-10-01' => 'A', '2026-10-15' => 'B'], $this->contentByDay());
        $this->inTenant($this->organization, fn () => $this->assertSame(0, Lesson::query()->whereDate('starts_at', '2026-10-08')->count()));
    }

    #[Test]
    public function an_incomplete_plan_is_reported_and_refused_without_writing_anything(): void
    {
        $this->makeSlot();
        $sequence = $this->sequence(array_map(fn (int $i): string => "Item {$i}", range(1, 60)));

        $this->preview($sequence, ['from' => '2026-10-01'])
            ->assertOk()
            ->assertJsonPath('complete', false);

        $token = $this->preview($sequence, ['from' => '2026-10-01'])->json('plan_token');
        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->payload(['from' => '2026-10-01']) + ['plan_token' => $token])
            ->assertSessionHasErrors('from');

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(0, Lesson::query()->count());
            $this->assertSame(0, LessonSummary::query()->count());
        });
    }

    #[Test]
    public function a_stale_plan_token_is_refused_without_writing_anything(): void
    {
        $slot = $this->makeSlot();
        $lesson = $this->lessonOn($slot, '2026-10-08');
        $sequence = $this->sequence(['A', 'B']);

        $token = $this->preview($sequence, ['from' => '2026-10-01'])->json('plan_token');

        // Alguém prepara a aula entre a pré-visualização e a confirmação.
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => 'Entretanto.']));

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->payload(['from' => '2026-10-01']) + ['plan_token' => $token])
            ->assertSessionHasErrors('plan_token');

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(0, LessonSummary::query()->whereNotNull('lesson_sequence_id')->count());
            $this->assertSame(1, LessonSummary::query()->count());
        });
    }

    #[Test]
    public function a_failure_halfway_through_writing_persists_nothing(): void
    {
        $this->makeSlot();
        $sequence = $this->sequence(['A', 'B', 'C']);
        $token = $this->preview($sequence, ['from' => '2026-10-01'])->json('plan_token');

        $this->app->bind(SaveLessonSummary::class, fn () => new class(app(AuditLog::class), app(SaveLessonAttendanceDraft::class)) extends SaveLessonSummary
        {
            private int $calls = 0;

            public function execute(Lesson $lesson, array $details, User $actor, ?array $absentStudentUlids = null, ?int $expectedVersion = null): LessonSummary
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('falha forçada');
                }

                return parent::execute($lesson, $details, $actor, $absentStudentUlids, $expectedVersion);
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->payload(['from' => '2026-10-01']) + ['plan_token' => $token]);
            $this->fail('A falha forçada devia propagar-se.');
        } catch (RuntimeException $exception) {
            $this->assertSame('falha forçada', $exception->getMessage());
        }

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(0, LessonSummary::query()->count());
            $this->assertSame(0, Lesson::query()->count());
            $this->assertSame(0, AuditEvent::query()->where('event', 'lesson_sequence.applied')->count());
        });
    }

    #[Test]
    public function a_chosen_group_only_touches_its_own_lessons_and_the_whole_class_does_not_touch_groups(): void
    {
        $group = $this->makeGroup('T1');
        $this->makeSlot(); // turma inteira, quintas
        $this->makeSlot(['day_of_week' => 5, 'class_group_id' => $group->id]); // T1, sextas
        $sequence = $this->sequence(['A', 'B']);

        $this->apply($sequence, ['from' => '2026-10-01', 'class_group_id' => $group->id])->assertSessionHasNoErrors();

        $this->assertSame(['2026-10-02' => 'A', '2026-10-09' => 'B'], $this->contentByDay());

        $other = $this->sequence(['X', 'Y']);
        $this->apply($other, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame(
            ['2026-10-01' => 'X', '2026-10-02' => 'A', '2026-10-08' => 'Y', '2026-10-09' => 'B'],
            $this->contentByDay(),
        );
    }

    #[Test]
    public function shifting_planning_after_an_absence_carries_the_provenance_and_a_reapplication_does_not_duplicate(): void
    {
        $this->makeSlot();
        $sequence = $this->sequence(['A', 'B', 'C']);
        $this->apply($sequence, ['from' => '2026-10-01']);

        $this->inTenant($this->organization, function (): void {
            $first = Lesson::query()->whereDate('starts_at', '2026-10-01')->sole();
            $first->forceFill([
                'outcome' => LessonOutcome::TeacherAbsent,
                'outcome_reason' => TeacherAbsenceReason::cases()[0],
                'outcome_recorded_at' => now(),
                'outcome_recorded_by' => $this->teacher->id,
            ])->save();

            DB::transaction(fn () => app(ShiftLessonPlanning::class)->from($first, $this->teacher));
        });

        $this->assertSame(['2026-10-08' => 'A', '2026-10-15' => 'B', '2026-10-22' => 'C'], $this->contentByDay());
        $this->inTenant($this->organization, function () use ($sequence): void {
            $this->assertSame(3, LessonSummary::query()->where('lesson_sequence_id', $sequence->id)->whereNotNull('lesson_sequence_item_id')->count());
            $this->assertSame(
                $sequence->items()->orderBy('position')->first()->id,
                Lesson::query()->whereDate('starts_at', '2026-10-08')->sole()->summary()->sole()->lesson_sequence_item_id,
            );
        });

        $this->preview($sequence, ['from' => '2026-10-01'])
            ->assertJsonPath('counts.unchanged', 3)
            ->assertJsonPath('counts.fill', 0);

        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame(['2026-10-08' => 'A', '2026-10-15' => 'B', '2026-10-22' => 'C'], $this->contentByDay());
    }

    #[Test]
    public function an_unopened_week_between_two_materialized_ones_is_not_skipped(): void
    {
        $slot = $this->makeSlot();
        // Semana 1 e semana 3 abertas; a semana 2 (08/10) nunca foi.
        $this->lessonOn($slot, '2026-10-01');
        $this->lessonOn($slot, '2026-10-15');
        $sequence = $this->sequence(['A', 'B', 'C', 'D']);
        $count = fn (): int => $this->inTenant($this->organization, fn (): int => Lesson::query()->count());

        $this->preview($sequence, ['from' => '2026-10-01'])->assertOk()
            ->assertJsonPath('complete', true)
            ->assertJsonPath('steps.1.item.summary', 'B')
            ->assertJsonPath('steps.1.lesson.ulid', null);
        $this->assertSame(2, $count());

        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame(
            ['2026-10-01' => 'A', '2026-10-08' => 'B', '2026-10-15' => 'C', '2026-10-22' => 'D'],
            $this->contentByDay(),
        );
    }

    /**
     * Para lá do primeiro bloco materializado (01–28/10), uma aula da própria
     * sequência não pode receber um elemento por cima de uma aula vazia que o
     * plano ainda não viu: F vai para 29/10 (esvaziada pelo professor) e não
     * fica em 05/11 com um buraco antes.
     */
    #[Test]
    public function an_item_is_never_placed_past_an_empty_lesson_beyond_the_first_block(): void
    {
        $this->makeSlot();
        $sequence = $this->sequence(['A', 'B', 'C', 'D', 'E', 'F']);
        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();
        $this->assertSame(
            ['2026-10-01' => 'A', '2026-10-08' => 'B', '2026-10-15' => 'C', '2026-10-22' => 'D', '2026-10-29' => 'E', '2026-11-05' => 'F'],
            $this->contentByDay(),
        );

        // O professor esvazia 29/10 e E sai da sequência.
        $this->inTenant($this->organization, fn () => Lesson::query()->whereDate('starts_at', '2026-10-29')->sole()->summary()->sole()->delete());
        $this->editSequence($sequence, ['A', 'B', 'C', 'D', 'F'], keepIndexes: [0, 1, 2, 3, 5]);

        $this->preview($sequence, ['from' => '2026-10-01'])->assertOk()
            ->assertJsonPath('complete', true)
            ->assertJsonPath('counts.fill', 1)
            ->assertJsonPath('counts.release', 1);

        $this->apply($sequence, ['from' => '2026-10-01'])->assertSessionHasNoErrors();

        $this->assertSame(
            ['2026-10-01' => 'A', '2026-10-08' => 'B', '2026-10-15' => 'C', '2026-10-22' => 'D', '2026-10-29' => 'F'],
            $this->contentByDay(),
        );
    }

    // ------------------------------------------------------------ helpers

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

    /**
     * Edita a sequência pelo endpoint real, mantendo a identidade (ulid) dos
     * elementos que continuam.
     *
     * @param  list<string>  $summaries
     * @param  list<int>|null  $keepIndexes  índices (0-based) dos elementos ORIGINAIS que continuam, por ordem
     */
    private function editSequence(LessonSequence $sequence, array $summaries, ?array $keepIndexes = null): void
    {
        $original = $this->inTenant($this->organization, fn () => $sequence->items()->orderBy('position')->pluck('ulid')->all());
        $keepIndexes ??= array_keys($summaries);

        $items = [];

        foreach ($summaries as $i => $summary) {
            $items[] = ['ulid' => $original[$keepIndexes[$i]], 'summary' => $summary];
        }

        $this->asTeacher()->put("/lessons/sequences/{$sequence->ulid}", [
            'name' => 'Sequência de teste',
            'subject_id' => $sequence->subject_id,
            'academic_year_id' => $sequence->academic_year_id,
            'grade_level' => null,
            'items' => $items,
        ])->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'class_id' => $this->schoolClass->id,
            'summary' => true,
            'resources' => true,
            'homework' => true,
            'private_notes' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function preview(LessonSequence $sequence, array $overrides = []): TestResponse
    {
        return $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $this->payload($overrides));
    }

    /**
     * Pré-visualiza e confirma, como o ecrã.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function apply(LessonSequence $sequence, array $overrides = []): TestResponse
    {
        $preview = $this->preview($sequence, $overrides)->assertOk();

        return $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->payload($overrides) + [
            'plan_token' => $preview->json('plan_token'),
            'confirm_replace' => $overrides['confirm_replace'] ?? $preview->json('requires_replace_confirmation'),
        ]);
    }

    private function lessonOn(RecurringLessonSlot $slot, string $date, array $attributes = []): Lesson
    {
        return $this->makeLesson(array_merge([
            'recurring_lesson_slot_id' => $slot->id,
            'class_group_id' => $slot->class_group_id,
            'starts_at' => "{$date} 09:30:00",
            'ends_at' => "{$date} 10:20:00",
        ], $attributes));
    }

    /**
     * Uma aula com sumário do professor (e, por omissão, «Preparada»).
     *
     * @param  array<string, string>  $extra
     */
    private function preparedOn(RecurringLessonSlot $slot, string $date, string $content, array $extra = [], LessonStatus $status = LessonStatus::Prepared): Lesson
    {
        $lesson = $this->lessonOn($slot, $date, ['status' => $status]);
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => $content] + $extra));

        return $lesson;
    }

    /**
     * @return array<string, string>
     */
    private function contentByDay(): array
    {
        return $this->inTenant($this->organization, function (): array {
            $days = [];

            foreach (Lesson::query()->with('summary')->orderBy('starts_at')->get() as $lesson) {
                if ($lesson->summary !== null) {
                    $days[$lesson->starts_at->format('Y-m-d')] = $lesson->summary->content;
                }
            }

            return $days;
        });
    }
}
