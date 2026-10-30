<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\CalendarExceptionChange;
use App\Actions\Lessons\CalendarReconciliationPlan;
use App\Actions\Lessons\CalendarReconciliationScope;
use App\Actions\Lessons\DeleteLesson;
use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Actions\Lessons\ReconcileLessonsWithCalendarExceptions;
use App\Actions\Lessons\ReconcileLessonsWithSlotValidity;
use App\Actions\Lessons\SaveLessonSummary;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Models\AttendanceStatus;
use App\Models\AuditEvent;
use App\Models\CancelledLessonOccurrence;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOrigin;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Classes\ClassArchivalWindow;
use App\Services\Lessons\LessonConflicts;
use App\Services\Lessons\LessonNumbering;
use App\Services\Lessons\NonTeachingDayConflicts;
use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * A Fatia 2 da reconciliação com o calendário: o que acontece às aulas que JÁ
 * EXISTEM quando uma exceção (feriado, interrupção, dia não letivo) é criada,
 * editada ou eliminada depois de materializadas.
 *
 * O RELÓGIO ESTÁ FIXO (setUp) e as datas são absolutas — há testes-bomba de
 * relógio neste projeto. Calendário de referência (outubro/novembro de 2026):
 * quintas 01, 08, 15, 22, 29 / 05, 12, 19, 26; segundas 05, 12, 19; terças 06,
 * 13, 20. O ano letivo vai de 2026-09-01 a 2027-06-30.
 */
class ReconcileLessonsWithCalendarExceptionsTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    // ------------------------------------------------------------------
    // 1. A retiradas sem marca; a exceção desaparece ou encurta
    // ------------------------------------------------------------------

    #[Test]
    public function empty_lessons_on_a_new_exception_are_retired_without_a_cancellation_mark_and_numbers_close_up(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->assertSame(['01', '08', '15', '22', '29', '05', '12', '19', '26'], $this->days());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $this->numbers());

        $this->exception('2026-10-22', '2026-11-05');
        $plan = $this->runTeacher();

        $this->assertSame(3, $plan->totals['retired']);
        $this->assertSame(0, $plan->totals['shifted']);
        $this->assertSame(0, $plan->totals['flagged']);
        $this->assertSame(['01', '08', '15', '12', '19', '26'], $this->days());
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->numbers());
        $this->assertSame([], $plan->gaps);
        $this->assertSame(0, $this->marksCount());
    }

    #[Test]
    public function removing_the_exception_and_opening_the_week_recreates_the_lessons_without_duplicates(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $exception = $this->exception('2026-10-22', '2026-11-05');
        $this->runTeacher();
        $this->assertSame(6, $this->lessonCount());

        $this->inTenant($this->organization, fn () => $exception->delete());
        $this->materialize('2026-10-01', '2026-11-30');
        $this->materialize('2026-10-19', '2026-11-08');

        $this->assertSame(['01', '08', '15', '22', '29', '05', '12', '19', '26'], $this->days());
        $this->assertSame(9, $this->lessonCount());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $this->numbers());
        $this->assertSame(0, $this->marksCount());
    }

    #[Test]
    public function shortening_the_exception_reports_the_freed_days_and_only_those_come_back(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $exception = $this->exception('2026-10-22', '2026-11-05');
        $this->runTeacher();

        $change = CalendarExceptionChange::update($exception, ['ends_on' => '2026-10-29']);
        $plan = $this->preview($this->teacherScope(), $change);

        $this->assertSame(
            ['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'],
            $plan->freedDays,
        );
        $this->assertSame(0, $plan->existingOnFreedDays['count']);
        $this->assertSame(0, $plan->totals['retired']);
        // A pré-visualização reverteu tudo: a exceção continua como estava.
        $this->assertSame('2026-11-05', $this->exceptionRow($exception)->ends_on->toDateString());

        $this->execute($this->teacherScope(), $plan->token, $change);
        $this->assertSame('2026-10-29', $this->exceptionRow($exception)->ends_on->toDateString());

        $this->materialize('2026-10-19', '2026-11-08');
        $this->assertSame(['01', '08', '15', '05', '12', '19', '26'], array_map(
            fn (string $day): string => $day,
            $this->days(),
        ));
        $this->assertSame(0, $this->marksCount());
    }

    #[Test]
    public function removing_an_exception_lists_the_review_lessons_that_already_exist_on_the_freed_days(): void
    {
        $this->thursdaySlot();
        $exception = $this->exception('2026-10-22', '2026-10-22');
        $taught = $this->makeLesson(['starts_at' => '2026-10-22 09:30:00', 'status' => LessonStatus::Taught]);

        $plan = $this->preview($this->teacherScope(), CalendarExceptionChange::delete($exception));

        $this->assertSame(['2026-10-22'], $plan->freedDays);
        $this->assertSame(1, $plan->existingOnFreedDays['count']);
        $this->assertSame([$taught->ulid], array_column($plan->existingOnFreedDays['lessons'], 'ulid'));
        $this->assertTrue($plan->existingOnFreedDays['lessons'][0]['closed']);
        $this->assertDatabaseHas('academic_calendar_exceptions', ['id' => $exception->id]);
    }

    // ------------------------------------------------------------------
    // 2. B: o planeamento desce
    // ------------------------------------------------------------------

    #[Test]
    public function planning_moves_to_the_next_valid_lesson_and_three_consecutive_holidays_keep_the_order(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        // Três aulas seguidas em exceção (15, 22, 29) com P1, P2, P3; a
        // primeira depois das férias (05/11) já tinha Q.
        $this->summary('2026-10-15', 'P1', ['resources' => 'R1', 'homework' => 'TPC1', 'private_notes' => 'NP1']);
        $this->summary('2026-10-22', 'P2');
        $this->summary('2026-10-29', 'P3');
        $this->summary('2026-11-05', 'Q');
        $before = $this->contents();
        $this->exception('2026-10-15', '2026-10-29');

        $plan = $this->runTeacher();

        $this->assertSame(3, $plan->totals['shifted']);
        $this->assertSame(0, $plan->totals['retired']);
        $this->assertSame(['01', '08', '05', '12', '19', '26'], $this->days());
        $this->assertSame(
            ['2026-11-05' => 'P1', '2026-11-12' => 'P2', '2026-11-19' => 'P3', '2026-11-26' => 'Q'],
            $this->contentByDay(),
        );
        // Nada se perdeu: o mesmo conjunto de conteúdos, e os outros campos viajaram.
        $this->assertEqualsCanonicalizing($before, $this->contents());
        $first = $this->summaryRow('2026-11-05');
        $this->assertSame(['R1', 'TPC1', 'NP1'], [$first->resources, $first->homework, $first->private_notes]);
        $this->assertSame(0, LessonPlan::withoutGlobalScopes()->count());
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->numbers());
        $this->assertSame(0, $this->marksCount());
    }

    #[Test]
    public function planning_with_no_free_destination_waits_as_a_recoverable_pending_plan(): void
    {
        // O horário acaba a 05/11: a única aula depois da exceção é a de 05/11.
        $this->thursdaySlot('2026-11-05');
        $this->materialize('2026-10-01', '2026-11-05');
        $this->summary('2026-10-15', 'P1');
        $this->summary('2026-10-22', 'Q1');
        $this->summary('2026-10-29', 'Q2');
        $this->summary('2026-11-05', 'Q3');
        $this->exception('2026-10-15', '2026-10-15');

        $plan = $this->runTeacher();

        $this->assertSame(1, $plan->totals['shifted']);
        $this->assertSame(['01', '08', '22', '29', '05'], $this->days());
        $this->assertSame(
            ['2026-10-22' => 'P1', '2026-10-29' => 'Q1', '2026-11-05' => 'Q2'],
            $this->contentByDay(),
        );
        // Q3 não se perdeu: ficou como planeamento pendente da aula onde a cadeia acabou.
        $pending = LessonPlan::withoutGlobalScopes()->get();
        $this->assertCount(1, $pending);
        $this->assertSame('Q3', $pending[0]->planned_summary);
        $this->assertSame($this->lessonAt('2026-11-05')->id, $pending[0]->lesson_id);
    }

    #[Test]
    public function planning_with_nowhere_to_go_at_all_leaves_the_lesson_untouched_and_flagged(): void
    {
        $this->thursdaySlot('2026-10-15');
        $this->materialize('2026-10-01', '2026-10-15');
        $this->summary('2026-10-15', 'P1');
        $this->exception('2026-10-15', '2026-10-15');

        $plan = $this->runTeacher();

        $this->assertSame(0, $plan->totals['shifted']);
        $this->assertSame(0, $plan->totals['retired']);
        $this->assertSame(1, $plan->totals['flagged']);
        $this->assertSame(['no_destination'], $plan->classes[0]['lessons'][0]['reasons']);
        $this->assertSame(['01', '08', '15'], $this->days());
        $this->assertSame('P1', $this->summaryRow('2026-10-15')->content);
        $this->assertSame(0, LessonPlan::withoutGlobalScopes()->count());
    }

    #[Test]
    public function planning_before_a_closed_lesson_of_the_same_audience_is_never_moved(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->summary('2026-10-15', 'P1');
        $this->markTaught('2026-10-22');
        $this->exception('2026-10-15', '2026-10-15');

        $plan = $this->runTeacher();

        $this->assertSame(0, $plan->totals['shifted']);
        $this->assertSame(1, $plan->totals['flagged']);
        $this->assertSame(['closed_lesson_after'], $plan->classes[0]['lessons'][0]['reasons']);
        $this->assertSame('P1', $this->summaryRow('2026-10-15')->content);
        $this->assertSame(9, $this->lessonCount());
    }

    // ------------------------------------------------------------------
    // 3. C: nunca se toca
    // ------------------------------------------------------------------

    #[Test]
    public function lessons_that_carry_history_or_decisions_are_left_exactly_as_they_are(): void
    {
        $taught = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00', 'status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught, 'lesson_number' => 1]);
        $closed = $this->makeLesson(['starts_at' => '2026-10-16 09:30:00', 'outcome' => LessonOutcome::TeacherAbsent, 'outcome_recorded_at' => now()]);
        $recorded = $this->makeLesson(['starts_at' => '2026-10-17 09:30:00', 'attendance_recorded_at' => now()]);
        $drafted = $this->makeLesson(['starts_at' => '2026-10-18 09:30:00']);
        $enrollment = $this->enroll('Ana Silva');
        $this->inTenant($this->organization, fn () => LessonAttendance::create([
            'lesson_id' => $drafted->id, 'enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'status' => AttendanceStatus::Absent,
        ]));
        $manualNumber = $this->makeLesson(['starts_at' => '2026-10-19 09:30:00', 'lesson_number' => 9, 'lesson_number_manual' => true]);
        $manualOrigin = $this->makeLesson(['starts_at' => '2026-10-20 09:30:00', 'origin' => LessonOrigin::Manual]);
        $pending = $this->makeLesson(['starts_at' => '2026-10-21 09:30:00']);
        $this->inTenant($this->organization, fn () => LessonPlan::create(['lesson_id' => $pending->id, 'planned_summary' => 'Plano pendente', 'created_by' => $this->teacher->id]));
        $this->exception('2026-10-15', '2026-10-21');
        // Uma vazia no mesmo intervalo: essa sim, sai.
        $empty = $this->makeLesson(['starts_at' => '2026-10-22 09:30:00']);
        $this->exception('2026-10-22', '2026-10-22');

        $snapshot = $this->lessonSnapshot();
        $plan = $this->runTeacher();

        $this->assertSame(1, $plan->totals['retired']);
        $this->assertSame(7, $plan->totals['flagged']);
        $this->assertDatabaseMissing('lessons', ['id' => $empty->id]);

        // Nada mudou nestas aulas. A única exceção possível é o número das ABERTAS
        // (a derivação sugere-o a quem ainda não tinha); o das fechadas é histórico.
        foreach ([$taught, $closed, $recorded, $drafted, $manualNumber, $manualOrigin, $pending] as $lesson) {
            $now = $this->lessonSnapshot()[$lesson->id];
            $was = $snapshot[$lesson->id];

            if (! in_array($lesson->id, [$taught->id, $closed->id, $manualNumber->id], true)) {
                unset($now['lesson_number'], $was['lesson_number']);
            }

            $this->assertSame($was, $now, 'Aula '.$lesson->id.' mudou.');
        }

        $this->assertSame(1, LessonAttendance::withoutGlobalScopes()->count());
        $this->assertSame('Plano pendente', LessonPlan::withoutGlobalScopes()->firstOrFail()->planned_summary);
    }

    #[Test]
    public function a_summary_that_cannot_travel_without_loss_keeps_the_lesson(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $reviewed = $this->summary('2026-10-15', 'Revisto');
        $this->inTenant($this->organization, fn () => $reviewed->forceFill(['reviewed_at' => now()])->save());
        $this->exception('2026-10-15', '2026-10-15');

        $plan = $this->runTeacher();

        $this->assertSame(1, $plan->totals['flagged']);
        $this->assertSame('Revisto', $this->summaryRow('2026-10-15')->content);
        $this->assertSame(9, $this->lessonCount());
    }

    // ------------------------------------------------------------------
    // 4. Numeração
    // ------------------------------------------------------------------

    #[Test]
    public function taught_lessons_keep_their_numbers_and_the_gap_is_reported(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-12');
        $this->markTaught('2026-10-01');
        $this->markTaught('2026-10-22');
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->numbers());
        $taughtBefore = $this->taughtNumbers();
        $this->exception('2026-10-15', '2026-10-15');

        // Só retirar a aula 3 faria DeleteLesson recusar: a lecionada 4 passava a 3.
        $this->assertDeleteIsRefused('2026-10-15');

        $plan = $this->runTeacher();

        $this->assertSame(1, $plan->totals['retired']);
        $this->assertSame($taughtBefore, $this->taughtNumbers());
        $this->assertSame(['2026-10-01' => 1, '2026-10-22' => 4], $this->taughtNumbers());
        $this->assertSame([1, 2, 4, 5, 6, 7], $this->numbers());
        $this->assertSame([3], array_values($plan->gaps)[0]);
        $this->assertSame([3], $plan->classes[0]['gaps']);
    }

    #[Test]
    public function open_lessons_before_a_taught_one_close_the_hole_and_leave_the_gap_up_front(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-05');
        $this->markTaught('2026-10-22');
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->numbers());
        $this->exception('2026-10-15', '2026-10-15');

        $plan = $this->runTeacher();

        // As abertas anteriores à fixa (01 e 08) derivam para trás a partir da lecionada.
        $this->assertSame(['2026-10-01' => 2, '2026-10-08' => 3, '2026-10-22' => 4, '2026-10-29' => 5, '2026-11-05' => 6], $this->numberByDay());
        $this->assertSame([1], array_values($plan->gaps)[0]);
        $this->assertSame(2, $plan->classes[0]['renumbered']);
    }

    #[Test]
    public function a_frozen_numbering_changes_no_existing_number_and_reports_the_whole_gap(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-05');
        config(['lapis.lesson_numbering_frozen' => true]);
        $this->exception('2026-10-15', '2026-10-15');

        $plan = $this->runTeacher();

        $this->assertSame(1, $plan->totals['retired']);
        $this->assertSame(['2026-10-01' => 1, '2026-10-08' => 2, '2026-10-22' => 4, '2026-10-29' => 5, '2026-11-05' => 6], $this->numberByDay());
        $this->assertSame([3], array_values($plan->gaps)[0]);
        $this->assertSame(0, $plan->classes[0]['renumbered']);
    }

    #[Test]
    public function the_gap_tolerant_renumbering_refuses_and_writes_nothing_if_a_closed_numbered_lesson_would_change(): void
    {
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $unit = (string) Str::ulid();
        // Uma lição partilhada: T1 lecionada com o número 5, T2 aberta com o número 7 indicado pelo professor.
        // A unidade é MANUAL (7): a derivação quereria mudar a lecionada para 7.
        $closed = $this->makeLesson(['class_group_id' => $t1->id, 'lesson_unit_key' => $unit, 'starts_at' => '2026-10-05 09:30:00', 'status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught, 'lesson_number' => 5]);
        $open = $this->makeLesson(['class_group_id' => $t2->id, 'lesson_unit_key' => $unit, 'starts_at' => '2026-10-06 09:30:00', 'lesson_number' => 7, 'lesson_number_manual' => true]);
        $before = $this->lessonSnapshot();

        try {
            $this->inTenant($this->organization, fn () => DB::transaction(
                fn () => app(LessonNumbering::class)->resequenceAcceptingGaps((int) $this->schoolClass->id),
            ));
            $this->fail('Devia recusar: a lecionada mudaria de número.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lesson_number', $exception->errors());
        }

        $this->assertSame($before, $this->lessonSnapshot());
        $this->assertSame([5, 7], [$this->lessonSnapshot()[$closed->id]['lesson_number'], $this->lessonSnapshot()[$open->id]['lesson_number']]);
    }

    // ------------------------------------------------------------------
    // 5. Grupos desdobrados e turmas de apoio
    // ------------------------------------------------------------------

    #[Test]
    public function both_lessons_of_a_split_pair_are_retired_together(): void
    {
        $this->splitSlots();
        $this->materialize('2026-10-05', '2026-10-25');
        $this->assertCount(6, $this->lessonRows());
        $this->exception('2026-10-12', '2026-10-13');

        $plan = $this->runTeacher();

        $this->assertSame(2, $plan->totals['retired']);
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-19', '2026-10-20'], array_map(
            fn (Lesson $lesson): string => $lesson->starts_at->format('Y-m-d'),
            $this->lessonRows(),
        ));
        $this->assertSame([1, 1, 2, 2], array_map(fn (Lesson $lesson): ?int => $lesson->lesson_number, $this->lessonRows()));
    }

    #[Test]
    public function if_one_of_the_pair_is_not_retirable_neither_is(): void
    {
        $this->splitSlots();
        $this->materialize('2026-10-05', '2026-10-25');
        // T2 de 13/10 lecionada (fora da exceção de segunda-feira): protege a lição.
        $this->markTaughtAt('2026-10-13 09:30:00');
        $this->exception('2026-10-12', '2026-10-12');

        $plan = $this->runTeacher();

        $this->assertSame(0, $plan->totals['retired']);
        $this->assertSame(1, $plan->totals['flagged']);
        $this->assertSame(['shared_unit'], $plan->classes[0]['lessons'][0]['reasons']);
        $this->assertCount(6, $this->lessonRows());
    }

    /**
     * A planificação de cada grupo só desce dentro do seu grupo — com a lição
     * partilhada INTEIRA dentro da operação: a interrupção cobre a segunda (T1)
     * e a terça (T2).
     */
    #[Test]
    public function planning_of_one_group_only_goes_to_that_group(): void
    {
        [$t1, $t2] = $this->splitSlots();
        $this->materialize('2026-10-05', '2026-10-25');
        $this->summaryAt('2026-10-12 09:30:00', 'PT1');
        $this->summaryAt('2026-10-13 09:30:00', 'PT2');
        $this->exception('2026-10-12', '2026-10-13');

        $plan = $this->runTeacher();

        $this->assertSame(2, $plan->totals['shifted']);
        $this->assertSame('PT1', $this->summaryAtRow('2026-10-19 09:30:00')->content);
        $this->assertSame('PT2', $this->summaryAtRow('2026-10-20 09:30:00')->content);
        $this->assertSame($t1->class_group_id, $this->lessonAtTime('2026-10-19 09:30:00')->class_group_id);
        $this->assertSame($t2->class_group_id, $this->lessonAtTime('2026-10-20 09:30:00')->class_group_id);
        $this->assertCount(4, $this->lessonRows());
    }

    /**
     * A LIÇÃO PARTILHADA NÃO SE DESFAZ (decisão do dono do produto: o caminho
     * conservador). O feriado só cobre a segunda (T1); a terça (T2) é dia
     * letivo e continua a ter a lição 2. Retirar a T1 — vazia (A) ou com
     * planeamento (B, que desceria para 19/10) — deixava a T2 com a lição 2 e
     * a T1 a dar o conteúdo dela numa aula numerada 3, e o par seguinte
     * trocado para sempre, sem aviso. Por isso a T1 fica para revisão
     * (`shared_unit`), nos dois âmbitos, e nada muda. Passar a chave da lição
     * para a aula seguinte (como RecordLessonOutcome::handOverLessonUnit) fica
     * para uma evolução futura.
     *
     * Antes desta regra, só uma irmã PROTEGIDA bloqueava: este teste consagrava
     * que o PT1 descia para 19/10 com a T2 a ficar na lição 2.
     */
    #[Test]
    public function a_t1_lesson_whose_t2_partner_still_has_class_is_kept_with_its_shared_lesson(): void
    {
        $this->splitSlots();
        $this->materialize('2026-10-05', '2026-10-25');
        $this->summaryAt('2026-10-12 09:30:00', 'PT1');
        $this->summaryAt('2026-10-13 09:30:00', 'PT2');
        $exception = $this->exception('2026-10-12', '2026-10-12');

        $plan = $this->runTeacher();

        $this->assertSame(['retired' => 0, 'shifted' => 0, 'awaiting_teacher' => 0, 'flagged' => 1], $plan->totals);
        $this->assertSame(['shared_unit'], $plan->classes[0]['lessons'][0]['reasons']);
        $this->assertSame('PT1', $this->summaryAtRow('2026-10-12 09:30:00')->content);
        $this->assertNull($this->summaryAtRowOrNull('2026-10-19 09:30:00'));
        $this->assertCount(6, $this->lessonRows());

        // E vazia (A): também não sai, nem pelo professor nem pelo responsável.
        $this->inTenant($this->organization, fn () => LessonSummary::query()->where('content', 'PT1')->delete());

        $teacher = $this->runTeacher();
        $this->assertSame(['retired' => 0, 'shifted' => 0, 'awaiting_teacher' => 0, 'flagged' => 1], $teacher->totals);
        $this->assertSame(['shared_unit'], $teacher->classes[0]['lessons'][0]['reasons']);

        $ownerScope = CalendarReconciliationScope::forOrganizationOwner($this->year());
        $change = CalendarExceptionChange::update($exception, ['title' => 'Feriado municipal']);
        $owner = $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => $this->action()->preview($ownerScope, $this->teacher, $change));
        $this->assertSame(['retired' => 0, 'shifted' => 0, 'awaiting_teacher' => 0, 'flagged' => 1], $owner->totals);
        $this->inTenant($this->organization, fn () => $this->action()->execute($ownerScope, $owner->token, $this->teacher, $change));

        $this->assertCount(6, $this->lessonRows());
        $this->assertSame([1, 1, 2, 2, 3, 3], $this->numbers());
    }

    /** E quando as DUAS irmãs caem em dias de exceção e são retiráveis, saem juntas. */
    #[Test]
    public function a_shared_lesson_entirely_on_exception_days_still_leaves_whole(): void
    {
        $this->splitSlots();
        $this->materialize('2026-10-05', '2026-10-25');
        $this->summaryAt('2026-10-12 09:30:00', 'PT1');
        // A T2 de terça fica vazia (A); a lição sai inteira.
        $this->exception('2026-10-12', '2026-10-12');
        $this->exception('2026-10-13', '2026-10-13');

        $plan = $this->runTeacher();

        $this->assertSame(['retired' => 1, 'shifted' => 1, 'awaiting_teacher' => 0, 'flagged' => 0], $plan->totals);
        $this->assertSame('PT1', $this->summaryAtRow('2026-10-19 09:30:00')->content);
        $this->assertCount(4, $this->lessonRows());
        $this->assertSame([1, 1, 2, 2], $this->numbers());
    }

    #[Test]
    public function a_support_class_is_reconciled_on_its_own_and_keeps_its_own_numbers(): void
    {
        $support = $this->inTenant($this->organization, function (): SchoolClass {
            $support = SchoolClass::factory()->recycle($this->organization)->support()->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'label' => 'Apoio 7.º A',
            ]);
            $support->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $support;
        });
        $this->thursdaySlot();
        $this->thursdaySlot(null, $support);
        $this->materialize('2026-10-01', '2026-11-05');
        $this->materialize('2026-10-01', '2026-11-05', $support);
        $this->markTaught('2026-10-22', $support);
        $this->exception('2026-10-15', '2026-10-15');

        $plan = $this->runTeacher();

        $this->assertSame(2, $plan->totals['retired']);
        $this->assertCount(2, $plan->classes);
        $this->assertSame([1, 2, 3, 4, 5], $this->classNumbers($this->schoolClass));
        $this->assertSame([2, 3, 4, 5, 6], $this->classNumbers($support));
        $this->assertSame([$support->id => [1]], $plan->gaps);
    }

    // ------------------------------------------------------------------
    // 6. plan_token
    // ------------------------------------------------------------------

    #[Test]
    public function preview_and_execute_give_the_same_plan_and_the_same_token(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->summary('2026-10-15', 'P1');
        $this->exception('2026-10-15', '2026-10-22');

        $first = $this->preview($this->teacherScope());
        $second = $this->preview($this->teacherScope());
        $this->assertSame($first->token, $second->token);
        $this->assertSame(9, $this->lessonCount(), 'A pré-visualização não grava nada.');

        $executed = $this->execute($this->teacherScope(), $first->token);

        $this->assertSame($first->toArray(), $executed->toArray());
        $this->assertSame(7, $this->lessonCount());
    }

    #[Test]
    public function editing_the_exception_between_preview_and_execute_invalidates_the_plan(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $exception = $this->exception('2026-10-22', '2026-10-22');
        $plan = $this->preview($this->teacherScope());
        $before = $this->world();

        $this->inTenant($this->organization, fn () => $exception->forceFill(['title' => 'Outro título'])->save());
        $after = $this->world();

        $this->assertStale($plan->token);
        $this->assertSame($after, $this->world(), 'Nada foi escrito.');
        $this->assertNotSame($before, $after);
    }

    #[Test]
    public function a_summary_saved_on_an_affected_lesson_between_preview_and_execute_invalidates_the_plan(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->exception('2026-10-22', '2026-10-22');
        $plan = $this->preview($this->teacherScope());
        $this->assertSame(1, $plan->totals['retired']);

        $this->summary('2026-10-22', 'Gravado entretanto');
        $before = $this->world();

        $this->assertStale($plan->token);
        $this->assertSame($before, $this->world());
        $this->assertSame('Gravado entretanto', $this->summaryRow('2026-10-22')->content);
    }

    #[Test]
    public function a_result_recorded_by_another_teacher_between_preview_and_execute_invalidates_the_plan(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->exception('2026-10-22', '2026-10-22');
        $plan = $this->preview($this->teacherScope());

        $this->markTaught('2026-10-22');
        $before = $this->world();

        $this->assertStale($plan->token);
        $this->assertSame($before, $this->world());
        $this->assertSame(9, $this->lessonCount());
    }

    #[Test]
    public function materializing_another_week_between_preview_and_execute_invalidates_the_plan(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-10-31');
        $this->exception('2026-10-22', '2026-10-22');
        $plan = $this->preview($this->teacherScope());

        $this->materialize('2026-11-01', '2026-11-30');
        $before = $this->world();

        $this->assertStale($plan->token);
        $this->assertSame($before, $this->world());
    }

    #[Test]
    public function a_hypothetical_change_is_part_of_the_token_and_nothing_is_written_when_it_goes_stale(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $change = CalendarExceptionChange::create([
            'type' => AcademicCalendarExceptionType::Holiday->value,
            'title' => 'Greve',
            'starts_on' => '2026-10-22',
            'ends_on' => '2026-10-22',
        ]);

        $plan = $this->preview($this->teacherScope(), $change);
        $this->assertSame(1, $plan->totals['retired']);
        $this->assertSame(0, AcademicCalendarException::withoutGlobalScopes()->count(), 'A exceção hipotética não ficou.');
        $this->assertSame(9, $this->lessonCount());

        // Outra pessoa lança, entretanto, outra exceção.
        $this->exception('2026-11-12', '2026-11-12');
        $before = $this->world();

        try {
            $this->execute($this->teacherScope(), $plan->token, $change);
            $this->fail('O plano devia estar desatualizado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan_token', $exception->errors());
        }

        $this->assertSame($before, $this->world());
        $this->assertSame(1, AcademicCalendarException::withoutGlobalScopes()->count());

        // Um pedido diferente com o mesmo estado também não aproveita o token.
        $other = CalendarExceptionChange::create([
            'type' => AcademicCalendarExceptionType::Holiday->value,
            'title' => 'Greve',
            'starts_on' => '2026-10-29',
            'ends_on' => '2026-10-29',
        ]);
        $fresh = $this->preview($this->teacherScope(), $change);
        $this->assertNotSame($fresh->token, $this->preview($this->teacherScope(), $other)->token);

        $this->execute($this->teacherScope(), $fresh->token, $change);
        $this->assertSame(2, AcademicCalendarException::withoutGlobalScopes()->count());
        $this->assertNotContains('22', $this->days());
    }

    // ------------------------------------------------------------------
    // 7. Autorização
    // ------------------------------------------------------------------

    #[Test]
    public function a_teacher_only_reaches_their_own_classes(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-05');
        $this->exception('2026-10-15', '2026-10-15');
        $colleague = $this->colleague(teaches: false);
        $year = $this->year();

        // Pedir o âmbito de outra pessoa: recusado.
        $this->expectAuthorization(fn () => $this->action()->plan(CalendarReconciliationScope::forTeacher($this->teacher, $year), $colleague));

        // O âmbito do colega, sem turmas: nada a fazer, e nada tocado.
        $plan = $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => $this->action()->plan(CalendarReconciliationScope::forTeacher($colleague, $year), $colleague));
        $this->assertSame(0, $plan->totals['retired']);
        $this->assertSame([], $plan->classes);
        $this->assertSame(6, $this->lessonCount());
    }

    #[Test]
    public function a_user_from_another_organization_is_refused(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-05');
        $this->exception('2026-10-15', '2026-10-15');
        $outsider = User::factory()->create();
        $outsiderOrganization = $outsider->personalOrganization();
        $year = $this->year();

        $this->expectAuthorization(fn () => $this->inTenant($outsiderOrganization, fn () => $this->action()->plan(CalendarReconciliationScope::forTeacher($outsider, $year), $outsider)));
        $this->assertSame(6, $this->lessonCount());
    }

    #[Test]
    public function the_organization_owner_retires_empty_lessons_but_never_moves_planning(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->summary('2026-10-22', 'Planeamento do professor');
        // A aula criada à mão (C) fica ANTES da B: uma C num feriado DEPOIS dela
        // estaria na cadeia do planeamento e faria da B uma C também (ver
        // a_review_lesson_on_a_holiday_down_the_chain_is_never_skipped).
        $this->inTenant($this->organization, fn () => Lesson::query()->where('starts_at', '2026-10-15 09:30:00')->update(['origin' => LessonOrigin::Manual->value]));
        $this->exception('2026-10-15', '2026-10-29');
        $scope = CalendarReconciliationScope::forOrganizationOwner($this->year());

        $plan = $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => $this->action()->preview($scope, $this->teacher));

        $this->assertSame(1, $plan->totals['retired']);
        $this->assertSame(1, $plan->totals['awaiting_teacher']);
        $this->assertSame(1, $plan->totals['flagged']);
        // Só contagens por turma: nunca aulas nem conteúdo.
        $this->assertNull($plan->classes[0]['lessons']);
        $this->assertStringNotContainsString('Planeamento do professor', (string) json_encode($plan->toArray()));

        $this->inTenant($this->organization, fn () => $this->action()->execute($scope, $plan->token, $this->teacher));

        $this->assertSame(['01', '08', '15', '22', '05', '12', '19', '26'], $this->days());
        $this->assertSame('Planeamento do professor', $this->summaryRow('2026-10-22')->content);
    }

    #[Test]
    public function only_the_owner_may_ask_for_the_organization_scope(): void
    {
        $this->thursdaySlot();
        $colleague = $this->colleague(teaches: true);

        $this->expectAuthorization(fn () => $this->inTenant($this->organization, fn () => $this->action()->plan(CalendarReconciliationScope::forOrganizationOwner($this->year()), $colleague)));
    }

    #[Test]
    public function a_closed_year_is_refused_and_an_archived_class_is_left_alone(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-05');
        $this->exception('2026-10-15', '2026-10-15');

        $this->inTenant($this->organization, fn () => $this->schoolClass->forceFill(['archived_at' => now()])->save());
        $plan = $this->preview($this->teacherScope());
        $this->assertSame([], $plan->classes);
        $this->assertSame(6, $this->lessonCount());
        $this->inTenant($this->organization, fn () => $this->schoolClass->forceFill(['archived_at' => null])->save());

        $this->inTenant($this->organization, fn () => $this->year()->forceFill(['status' => AcademicYearStatus::Closed])->save());

        try {
            $this->preview($this->teacherScope());
            $this->fail('Um ano encerrado devia ser recusado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('academic_year', $exception->errors());
        }

        $this->assertSame(6, $this->lessonCount());
    }

    // ------------------------------------------------------------------
    // 8. Marcas de eliminação
    // ------------------------------------------------------------------

    #[Test]
    public function cancellation_marks_are_never_created_or_touched(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        // Uma marca antiga, de uma eliminação à mão a 12/11.
        $this->inTenant($this->organization, fn () => app(DeleteLesson::class)->execute($this->lessonAt('2026-11-12'), $this->teacher));
        $marksBefore = $this->marks();
        $this->assertCount(1, $marksBefore);
        $this->summary('2026-10-15', 'P1');
        $this->exception('2026-10-15', '2026-10-22');

        $plan = $this->runTeacher();

        $this->assertSame(1, $plan->totals['retired']);
        $this->assertSame(1, $plan->totals['shifted']);
        $this->assertSame($marksBefore, $this->marks());
    }

    // ------------------------------------------------------------------
    // 9. Passado e futuro
    // ------------------------------------------------------------------

    #[Test]
    public function a_past_empty_lesson_before_taught_ones_leaves_a_gap_and_a_future_one_does_not(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-05');
        $this->markTaught('2026-10-08');
        $this->markTaught('2026-10-22');
        $taughtBefore = $this->taughtNumbers();
        // Hoje é 15/10: 01/10 é passado, 05/11 é futuro.
        $this->exception('2026-10-01', '2026-10-01');
        $this->exception('2026-11-05', '2026-11-05');

        $plan = $this->runTeacher();

        $this->assertSame(2, $plan->totals['retired']);
        $this->assertSame($taughtBefore, $this->taughtNumbers());
        $this->assertSame(['2026-10-08' => 2, '2026-10-15' => 3, '2026-10-22' => 4, '2026-10-29' => 5], $this->numberByDay());
        $this->assertSame([1], array_values($plan->gaps)[0]);
    }

    // ------------------------------------------------------------------
    // 10. Idempotência e auditoria
    // ------------------------------------------------------------------

    #[Test]
    public function running_it_twice_does_nothing_the_second_time_and_audits_only_the_first(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->summary('2026-10-15', 'Texto secreto do sumário');
        $this->exception('2026-10-15', '2026-10-22');
        $this->markTaught('2026-10-08');
        $plan = $this->preview($this->teacherScope());
        $this->execute($this->teacherScope(), $plan->token);
        $after = $this->world();

        $again = $this->preview($this->teacherScope());
        $this->assertSame(0, $again->totals['retired']);
        $this->assertSame(0, $again->totals['shifted']);
        $this->execute($this->teacherScope(), $again->token);

        $this->assertSame($after, $this->world());
        $events = AuditEvent::withoutGlobalScopes()->where('event', 'lesson.calendar_reconciled')->get();
        $this->assertCount(1, $events);
        $properties = $events[0]->properties;
        $this->assertSame('teacher', $properties['scope']);
        $this->assertSame(1, $properties['retired']);
        $this->assertSame(1, $properties['shifted']);
        $this->assertSame($this->teacher->id, $events[0]->causer_id);
        $this->assertStringNotContainsString('secreto', (string) json_encode($properties));
        $this->assertArrayHasKey('gaps', $properties);
        $this->assertArrayHasKey('exception_ids', $properties);
    }

    // ------------------------------------------------------------------
    // 11. A cadeia do planeamento nunca atravessa uma aula protegida
    // ------------------------------------------------------------------

    #[Test]
    public function a_reviewed_summary_further_down_the_chain_keeps_the_planned_lesson_for_review(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->summary('2026-10-15', 'P1');
        $reviewed = $this->summary('2026-10-22', 'Revisto');
        $this->inTenant($this->organization, fn () => $reviewed->forceFill(['reviewed_at' => now(), 'reviewed_by' => $this->teacher->id])->save());
        $this->exception('2026-10-15', '2026-10-15');

        $plan = $this->runTeacher();

        $this->assertSame(0, $plan->totals['shifted']);
        $this->assertSame(1, $plan->totals['flagged']);
        $this->assertSame(['protected_lesson_after'], $plan->classes[0]['lessons'][0]['reasons']);
        $this->assertSame('P1', $this->summaryRow('2026-10-15')->content);
        $row = $this->summaryRow('2026-10-22');
        $this->assertSame('Revisto', $row->content);
        $this->assertNotNull($row->reviewed_at, 'A revisão não se perde.');
        $this->assertSame($this->teacher->id, $row->reviewed_by);
        $this->assertSame(9, $this->lessonCount());
    }

    #[Test]
    public function a_provenance_only_summary_row_down_the_chain_is_never_overwritten(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->summary('2026-10-15', 'P1');
        // Uma linha sem texto, só com a impressão digital da sequência aplicada.
        $this->summary('2026-10-22', '', ['sequence_content_hash' => str_repeat('a', 64)]);
        $this->exception('2026-10-15', '2026-10-15');

        $plan = $this->runTeacher();

        $this->assertSame(0, $plan->totals['shifted']);
        $this->assertSame(['protected_lesson_after'], $plan->classes[0]['lessons'][0]['reasons']);
        $this->assertSame('P1', $this->summaryRow('2026-10-15')->content);
        $row = $this->summaryRow('2026-10-22');
        $this->assertSame('', $row->content);
        $this->assertSame(str_repeat('a', 64), $row->sequence_content_hash);
    }

    /**
     * O Natal esquecido lançado em JANEIRO: a aula de 07/01 já foi dada — tem
     * sumário e faltas, mas ninguém a fechou. O planeamento de 24/12 não pode
     * descer por cima dela (nem empurrar o sumário real para 14/01).
     */
    #[Test]
    public function a_forgotten_christmas_entered_in_january_never_pushes_the_planning_over_lessons_already_given(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-08 18:00:00', 'Europe/Lisbon'));
        $this->thursdaySlot();
        $this->materialize('2026-12-01', '2027-01-31');
        $this->summary('2026-12-24', 'P_NATAL');
        $this->summary('2027-01-07', 'O que se deu a 7');
        $given = $this->lessonAt('2027-01-07');
        $enrollment = $this->enroll('Ana Silva');
        $this->inTenant($this->organization, fn () => LessonAttendance::create([
            'lesson_id' => $given->id, 'enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'status' => AttendanceStatus::Absent,
        ]));
        $this->exception('2026-12-21', '2027-01-03');

        $plan = $this->runTeacher();

        // 31/12 (vazia) sai; 24/12 fica para revisão, com o seu planeamento.
        $this->assertSame(1, $plan->totals['retired']);
        $this->assertSame(0, $plan->totals['shifted']);
        $this->assertSame(1, $plan->totals['flagged']);
        $this->assertSame('P_NATAL', $this->summaryRow('2026-12-24')->content);
        $this->assertSame('O que se deu a 7', $this->summaryRow('2027-01-07')->content);
        $this->assertNull($this->summaryAtRowOrNull('2027-01-14 09:30:00'));
        $this->assertSame(1, LessonAttendance::withoutGlobalScopes()->where('lesson_id', $given->id)->count());
    }

    #[Test]
    public function an_open_lesson_already_in_the_past_is_not_a_destination_even_when_empty(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        // Hoje é 15/10 às 10:00: a aula de 08/10 está no passado, por fechar e vazia.
        $this->summary('2026-10-01', 'P1');
        $this->exception('2026-10-01', '2026-10-01');

        $plan = $this->runTeacher();

        $this->assertSame(0, $plan->totals['shifted']);
        $this->assertSame(['protected_lesson_after'], $plan->classes[0]['lessons'][0]['reasons']);
        $this->assertSame('P1', $this->summaryRow('2026-10-01')->content);
        $this->assertNull($this->summaryAtRowOrNull('2026-10-08 09:30:00'));
    }

    // ------------------------------------------------------------------
    // 12. A lição partilhada é tudo ou nada, também na execução
    // ------------------------------------------------------------------

    #[Test]
    public function a_planned_t1_whose_t2_partner_stays_is_neither_shifted_nor_retired(): void
    {
        $this->splitSlots();
        $this->materialize('2026-10-05', '2026-11-30');
        $this->summaryAt('2026-10-12 09:30:00', 'PT1');
        $this->summaryAt('2026-10-13 09:30:00', 'PT2');
        // T2 de 20/10 fechada: a T2 de 13/10 fica C (`closed_lesson_after`), e a lição de 12-13/10 não sai.
        $this->markTaughtAt('2026-10-20 09:30:00');
        $this->exception('2026-10-12', '2026-10-13');
        $before = $this->lessonCount();

        $plan = $this->runTeacher();

        $this->assertSame(['retired' => 0, 'shifted' => 0, 'awaiting_teacher' => 0, 'flagged' => 2], $plan->totals);
        $reasons = array_column($plan->classes[0]['lessons'], 'reasons', 'starts_at');
        $this->assertSame(['shared_unit'], $reasons[$this->lessonAtTime('2026-10-12 09:30:00')->starts_at->toIso8601String()]);
        $this->assertSame(['closed_lesson_after'], $reasons[$this->lessonAtTime('2026-10-13 09:30:00')->starts_at->toIso8601String()]);
        $this->assertSame('PT1', $this->summaryAtRow('2026-10-12 09:30:00')->content, 'O planeamento de T1 não desceu.');
        $this->assertNull($this->summaryAtRowOrNull('2026-10-19 09:30:00'));
        $this->assertSame($before, $this->lessonCount());
        $this->assertFalse($plan->changesAnything());
    }

    // ------------------------------------------------------------------
    // 13. O token é determinístico quando o plano materializa aulas
    // ------------------------------------------------------------------

    #[Test]
    public function two_planned_lessons_with_nothing_materialized_after_give_the_same_token_in_preview_and_execute(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-10-29');
        $this->summary('2026-10-22', 'P22');
        $this->summary('2026-10-29', 'P29');
        $this->exception('2026-10-22', '2026-10-29');

        $preview = $this->preview($this->teacherScope());
        $this->assertSame($preview->token, $this->preview($this->teacherScope())->token);
        $executed = $this->execute($this->teacherScope(), $preview->token);

        $this->assertSame($preview->toArray(), $executed->toArray());
        $this->assertSame(2, $executed->totals['shifted']);
        $this->assertSame(['2026-11-05' => 'P22', '2026-11-12' => 'P29'], $this->contentByDay());
        // As aulas que o plano criou não têm ulid no JSON: não existiam depois da pré-visualização.
        foreach ($executed->classes[0]['lessons'] as $lesson) {
            foreach ($lesson['moves'] as $move) {
                $this->assertNull($move['to']);
                $this->assertTrue($move['created_from_timetable']);
            }
        }
    }

    #[Test]
    public function one_planned_lesson_materializing_its_destination_gives_the_same_token_even_if_ids_move_on(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-10-22');
        $this->summary('2026-10-22', 'P22');
        $this->exception('2026-10-22', '2026-10-22');

        $preview = $this->preview($this->teacherScope());
        // O auto-incremento não volta atrás no ROLLBACK do MySQL: a execução
        // nunca recebe o id que a pré-visualização viu. Em SQLite simula-se.
        $this->advanceLessonIds();

        $executed = $this->execute($this->teacherScope(), $preview->token);

        $this->assertSame($preview->token, $executed->token);
        $this->assertSame(1, $executed->totals['shifted']);
        $this->assertSame(['2026-10-29' => 'P22'], $this->contentByDay());
    }

    // ------------------------------------------------------------------
    // 14. O limite de planeamentos por execução
    // ------------------------------------------------------------------

    #[Test]
    public function planned_lessons_beyond_the_batch_limit_wait_for_the_next_run_in_order(): void
    {
        config(['lapis.calendar_reconciliation_max_planned' => 1]);
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->summary('2026-10-15', 'P15');
        $this->summary('2026-10-22', 'P22');
        $this->exception('2026-10-15', '2026-10-22');

        $first = $this->runTeacher();

        // A mais recente desce já; a mais antiga fica para a execução seguinte.
        $this->assertSame(1, $first->totals['shifted']);
        $this->assertSame(1, $first->totals['flagged']);
        $this->assertSame(['batch_limit'], collect($first->classes[0]['lessons'])->firstWhere('kind', 'C')['reasons']);
        $this->assertSame('P15', $this->summaryRow('2026-10-15')->content);
        $this->assertSame(['2026-10-15' => 'P15', '2026-10-29' => 'P22'], $this->contentByDay());

        $second = $this->runTeacher();

        // E a ordem do planeamento mantém-se: P15 antes de P22.
        $this->assertSame(1, $second->totals['shifted']);
        $this->assertSame(['2026-10-29' => 'P15', '2026-11-05' => 'P22'], $this->contentByDay());
    }

    // ------------------------------------------------------------------
    // 15. A cadeia salta SÓ o que a operação retira
    // ------------------------------------------------------------------

    /**
     * O Carnaval (29/10) tem uma aula C com planeamento — número de lição
     * indicado à mão, Pc. Saltá-la (como se fosse ser retirada) punha P15 em
     * 22/10, Q22 em 05/11 e Q05 em 12/11: Pc ficava ANTES de Q22, em silêncio.
     * A aula C num dia não letivo que fica é um elo não simples: a B de 15/10
     * passa a C (`protected_lesson_after`) e nada se move.
     */
    #[Test]
    public function a_review_lesson_on_a_holiday_down_the_chain_is_never_skipped(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->summary('2026-10-15', 'P15');
        $this->summary('2026-10-22', 'Q22');
        $this->summary('2026-10-29', 'Pc');
        $this->inTenant($this->organization, fn () => Lesson::query()->where('starts_at', '2026-10-29 09:30:00')->update(['lesson_number' => 5, 'lesson_number_manual' => true]));
        $this->summary('2026-11-05', 'Q05');
        $this->exception('2026-10-15', '2026-10-15');
        $this->exception('2026-10-29', '2026-10-29');
        $before = $this->contentByDay();
        $planned = $this->lessonAt('2026-10-15')->starts_at->toIso8601String();
        $review = $this->lessonAt('2026-10-29')->starts_at->toIso8601String();

        $plan = $this->runTeacher();

        $this->assertSame(0, $plan->totals['shifted']);
        $reasons = array_column($plan->classes[0]['lessons'], 'reasons', 'starts_at');
        $this->assertSame(['protected_lesson_after'], $reasons[$planned]);
        $this->assertSame(['manual_number'], $reasons[$review]);
        $this->assertSame($before, $this->contentByDay(), 'Nenhum planeamento mudou de aula.');
    }

    /**
     * A aula no feriado que É retirada na mesma operação (uma A) continua a ser
     * saltada: o planeamento desce por cima dela.
     */
    #[Test]
    public function an_empty_lesson_retired_in_the_same_run_is_still_skipped_by_the_chain(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->summary('2026-10-15', 'P15');
        $this->summary('2026-10-22', 'Q22');
        $this->summary('2026-11-05', 'Q05');
        $this->exception('2026-10-15', '2026-10-15');
        $this->exception('2026-10-29', '2026-10-29');

        $plan = $this->runTeacher();

        $this->assertSame(['retired' => 1, 'shifted' => 1, 'awaiting_teacher' => 0, 'flagged' => 0], $plan->totals);
        $this->assertSame(['2026-10-22' => 'P15', '2026-11-05' => 'Q22', '2026-11-12' => 'Q05'], $this->contentByDay());
    }

    // ------------------------------------------------------------------
    // 16. Materializar para um público não faz outro saltar uma ocorrência
    // ------------------------------------------------------------------

    /**
     * A turma inteira (W) à quarta às 09:30; o grupo G2 à segunda às 09:30 e à
     * quarta às 11:00. Feriado de 12 a 14/10: a B de W (14/10) e a B de G2
     * (12/10). A fase 1 desce W primeiro: o planeamento de W vai para 21/10 e
     * o de 21/10 para a quarta 28/10, que se materializa — e com ela nasce a
     * G2 de quarta 28/10, vazia. Materializando SÓ esse dia, o planeamento de
     * G2 aterrava nessa quarta e saltava a segunda 26/10, a próxima ocorrência
     * válida de G2 (e o token não dava por nada: as duas são «novas»).
     */
    #[Test]
    public function materializing_a_day_for_one_audience_never_makes_another_skip_its_next_occurrence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Lisbon'));
        $g2 = $this->makeGroup('G2');
        $year = ['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'];
        $this->makeSlot($year + ['day_of_week' => 3, 'starts_at' => '09:30', 'ends_at' => '10:20']);
        $this->makeSlot($year + ['class_group_id' => $g2->id, 'day_of_week' => 1, 'starts_at' => '09:30', 'ends_at' => '10:20']);
        $this->makeSlot($year + ['class_group_id' => $g2->id, 'day_of_week' => 3, 'starts_at' => '11:00', 'ends_at' => '11:50']);
        $this->materialize('2026-10-05', '2026-10-25');
        $this->summaryAt('2026-10-14 09:30:00', 'PW');
        $this->summaryAt('2026-10-21 09:30:00', 'QW');
        $this->summaryAt('2026-10-12 09:30:00', 'PG');
        $this->summaryAt('2026-10-19 09:30:00', 'QG19');
        $this->summaryAt('2026-10-21 11:00:00', 'QG21');
        $this->exception('2026-10-12', '2026-10-14');

        $preview = $this->preview($this->teacherScope());
        $this->assertSame($preview->token, $this->preview($this->teacherScope())->token);
        $plan = $this->execute($this->teacherScope(), $preview->token);

        $this->assertSame(2, $plan->totals['shifted']);
        $this->assertSame('PW', $this->summaryAtRow('2026-10-21 09:30:00')->content);
        $this->assertSame('QW', $this->summaryAtRow('2026-10-28 09:30:00')->content);
        $this->assertSame('PG', $this->summaryAtRow('2026-10-19 09:30:00')->content);
        $this->assertSame('QG19', $this->summaryAtRow('2026-10-21 11:00:00')->content);
        $this->assertSame('QG21', $this->summaryAtRow('2026-10-26 09:30:00')->content, 'O planeamento de G2 vai para a segunda 26/10.');
        $this->assertNull($this->summaryAtRowOrNull('2026-10-28 11:00:00'), 'A quarta 28/10 de G2 não recebe nada.');
        $this->assertSame($g2->id, $this->lessonAtTime('2026-10-26 09:30:00')->class_group_id);
    }

    // ------------------------------------------------------------------
    // 17. A pendência fica pela ordem das aulas
    // ------------------------------------------------------------------

    /**
     * O horário acaba a 05/11 e 05/11 já tem Q. Duas B (22 e 29/10): a fase 1
     * desce primeiro a de 29/10 (a pendência fica «Q») e depois a de 22/10 (a
     * pendência passa a ter também P29). P29 vem ANTES de Q nas aulas: a
     * pendência é «P29» seguido de «Q», e não o contrário.
     */
    #[Test]
    public function two_planned_lessons_overflowing_the_timetable_leave_the_pending_plan_in_lesson_order(): void
    {
        $this->thursdaySlot('2026-11-05');
        $this->materialize('2026-10-01', '2026-11-05');
        $this->summary('2026-10-22', 'P22');
        $this->summary('2026-10-29', 'P29');
        $this->summary('2026-11-05', 'Q');
        $this->exception('2026-10-22', '2026-10-29');

        $plan = $this->runTeacher();

        $this->assertSame(2, $plan->totals['shifted']);
        $this->assertSame(['2026-11-05' => 'P22'], $this->contentByDay());
        $pending = LessonPlan::withoutGlobalScopes()->get();
        $this->assertCount(1, $pending);
        $this->assertSame("P29\n\nQ", $pending[0]->planned_summary);
        $this->assertSame($this->lessonAt('2026-11-05')->id, $pending[0]->lesson_id);
    }

    // ------------------------------------------------------------------
    // 18. A simulação e a execução escolhem o mesmo destino
    // ------------------------------------------------------------------

    /**
     * Uma aula JÁ existente na próxima ocorrência do tempo de G2, mas com outro
     * grupo (o grupo do tempo mudou depois de ela nascer). Não é de G2: a
     * simulação conta-a como ocupada, e a execução também não a usa — o
     * planeamento vai para a ocorrência seguinte. Antes, a execução usava-a
     * como destino, fora da previsão, e a pré-visualização e a execução
     * recusavam sempre.
     */
    #[Test]
    public function an_existing_lesson_of_another_group_on_the_next_occurrence_is_never_a_destination(): void
    {
        $g1 = $this->makeGroup('G1');
        $g2 = $this->makeGroup('G2');
        $slot = $this->makeSlot(['class_group_id' => $g2->id, 'day_of_week' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->materialize('2026-10-05', '2026-10-25');
        $other = $this->makeLesson(['class_group_id' => $g1->id, 'recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-10-26 09:30:00']);
        $this->summaryAt('2026-10-12 09:30:00', 'PG');
        $this->summaryAt('2026-10-19 09:30:00', 'QG');
        $this->exception('2026-10-12', '2026-10-12');

        $plan = $this->runTeacher();

        $this->assertSame(1, $plan->totals['shifted']);
        $this->assertSame('PG', $this->summaryAtRow('2026-10-19 09:30:00')->content);
        $this->assertSame('QG', $this->summaryAtRow('2026-11-02 09:30:00')->content);
        $this->assertNull($this->summaryAtRowOrNull('2026-10-26 09:30:00'));
        $this->assertSame($g1->id, Lesson::withoutGlobalScopes()->findOrFail($other->id)->class_group_id);
    }

    /**
     * A próxima ocorrência da T2 (20/10) não pode nascer: há uma aula da turma
     * inteira à mesma hora (a materialização salta-a). A simulação vê o mesmo
     * conflito e a T2 fica sem destino (C) — e, com ela, a T1 da mesma lição.
     * Antes, a simulação contava com 20/10, a T1 descia e a T2 ficava sem
     * destino: a pré-visualização e a execução recusavam sempre.
     */
    #[Test]
    public function a_destination_the_timetable_cannot_create_is_foreseen_and_the_shared_lesson_stays(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Lisbon'));
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $key = (string) Str::ulid();
        $this->makeSlot(['class_group_id' => $t1->id, 'day_of_week' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key]);
        $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 2, 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-20', 'split_lesson_key' => $key]);
        $this->materialize('2026-10-05', '2026-10-18');
        $this->makeLesson(['origin' => LessonOrigin::Manual, 'starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:00:00']);
        $this->summaryAt('2026-10-12 09:30:00', 'PT1');
        $this->summaryAt('2026-10-13 09:30:00', 'PT2');
        $this->exception('2026-10-12', '2026-10-13');
        $before = $this->contentByDay();

        $plan = $this->runTeacher();

        $reasons = array_column($plan->classes[0]['lessons'], 'reasons', 'starts_at');
        $this->assertSame(['no_destination'], $reasons[$this->lessonAtTime('2026-10-13 09:30:00')->starts_at->toIso8601String()]);
        $this->assertSame(['shared_unit'], $reasons[$this->lessonAtTime('2026-10-12 09:30:00')->starts_at->toIso8601String()]);
        $this->assertFalse($plan->changesAnything());
        $this->assertSame($before, $this->contentByDay());
    }

    /**
     * A turma inteira ganha a partir de 22/10 um tempo à quinta às 09:30, por
     * cima do da T2: ao materializar nasce primeiro a da turma inteira (id
     * menor) e a da T2 nunca nasce. Até à 3.ª revisão a simulação contava com
     * 22/10 para a T2, a execução descia a T1, ficava sem destino para a T2 e
     * desfazia tudo (o caminho de recurso, que continua coberto pelo §23). Agora
     * a própria classificação simula a materialização do dia
     * (NonTeachingDayConflicts::births): a T2 é C desde o início, a T1 também
     * (a lição não se parte), e a pré-visualização e a execução dão o mesmo
     * plano.
     */
    #[Test]
    public function when_the_execution_cannot_keep_the_simulated_destination_the_shift_is_undone_and_reclassified(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Lisbon'));
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $key = (string) Str::ulid();
        // O tempo da turma inteira primeiro (id menor: nasce primeiro ao materializar).
        $this->makeSlot(['day_of_week' => 4, 'starts_on' => '2026-10-22', 'ends_on' => '2027-06-30']);
        $this->makeSlot(['class_group_id' => $t1->id, 'day_of_week' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key]);
        $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 4, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key]);
        $this->materialize('2026-10-05', '2026-10-18');
        $this->summaryAt('2026-10-12 09:30:00', 'PT1');
        $this->summaryAt('2026-10-15 09:30:00', 'PT2');
        $this->exception('2026-10-12', '2026-10-15');
        $before = $this->contentByDay();

        $first = $this->preview($this->teacherScope());
        $this->assertSame($first->token, $this->preview($this->teacherScope())->token);
        $plan = $this->execute($this->teacherScope(), $first->token);

        $reasons = array_column($plan->classes[0]['lessons'], 'reasons', 'starts_at');
        $this->assertSame(['no_destination'], $reasons[$this->lessonAtTime('2026-10-15 09:30:00')->starts_at->toIso8601String()]);
        $this->assertSame(['shared_unit'], $reasons[$this->lessonAtTime('2026-10-12 09:30:00')->starts_at->toIso8601String()]);
        $this->assertSame(0, $plan->totals['shifted']);
        $this->assertSame($before, $this->contentByDay(), 'O PT1 não desceu sozinho.');
    }

    // ------------------------------------------------------------------
    // 19. A materialização da fase 1 apaga uma A fora de vigência
    // ------------------------------------------------------------------

    /**
     * O tempo de quinta passou a acabar a 22/10 (revisto sem abrir as semanas):
     * as aulas a partir de 29/10 estão fora de vigência, e uma nova versão do
     * tempo começa em novembro. A B de 22/10 desce pelas aulas cheias até
     * materializar 03/12 — e essa materialização retira as aulas vazias fora de
     * vigência, incluindo a A de 29/10 que a fase 2 ia retirar. Uma A que já
     * não existe conta como retirada: antes, era uma recusa permanente.
     */
    #[Test]
    public function an_empty_lesson_already_removed_by_the_materialization_counts_as_retired(): void
    {
        $old = $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->inTenant($this->organization, fn () => $old->forceFill(['ends_on' => '2026-10-22'])->save());
        $this->makeSlot(['day_of_week' => 4, 'starts_on' => '2026-11-01', 'ends_on' => '2027-06-30']);
        $this->summary('2026-10-22', 'P22');

        foreach (['2026-11-05', '2026-11-12', '2026-11-19', '2026-11-26'] as $day) {
            $this->summary($day, 'Q'.$day);
        }

        $this->exception('2026-10-22', '2026-10-29');

        $plan = $this->runTeacher();

        $this->assertSame(['retired' => 1, 'shifted' => 1, 'awaiting_teacher' => 0, 'flagged' => 0], $plan->totals);
        $this->assertNull($this->inTenant($this->organization, fn () => Lesson::query()->where('starts_at', '2026-10-29 09:30:00')->first()));
        $this->assertSame('P22', $this->summaryRow('2026-11-05')->content);
        $this->assertSame('Q2026-11-26', $this->summaryRow('2026-12-03')->content);
    }

    // ------------------------------------------------------------------
    // 20. O token não cai com a atividade normal da organização
    // ------------------------------------------------------------------

    /**
     * Entre a pré-visualização e o clique, um colega grava o primeiro sumário
     * de uma aula de ANTES do primeiro dia afetado (Preparação → Preparada) e
     * outro sumário depois dele, numa aula que não está em cadeia nenhuma; e,
     * entre a pré-visualização do responsável e a gravação, outra turma sem
     * aulas nos dias da exceção abre uma semana. Nada disto muda o plano: o
     * token continua válido.
     */
    #[Test]
    public function activity_that_does_not_change_the_plan_does_not_invalidate_the_token(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->exception('2026-10-22', '2026-10-22');
        $plan = $this->preview($this->teacherScope());

        $earlier = $this->lessonAt('2026-10-08');
        $this->inTenant($this->organization, fn () => app(SaveLessonSummary::class)->execute($earlier, ['content' => 'Sumário de um colega'], $this->colleague(teaches: true)));
        $this->assertSame(LessonStatus::Prepared, $this->lessonAt('2026-10-08')->status);

        $later = $this->lessonAt('2026-11-12');
        $this->inTenant($this->organization, fn () => app(SaveLessonSummary::class)->execute($later, ['content' => 'Outro sumário'], $this->teacher));

        $executed = $this->execute($this->teacherScope(), $plan->token);
        $this->assertSame(1, $executed->totals['retired']);

        $other = $this->inTenant($this->organization, function (): SchoolClass {
            $other = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id, 'label' => '8.º B']);
            $other->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $other;
        });
        $this->thursdaySlot(null, $other, 2);
        $change = CalendarExceptionChange::create(['type' => AcademicCalendarExceptionType::Holiday->value, 'title' => 'Greve', 'starts_on' => '2026-11-19', 'ends_on' => '2026-11-19']);
        $ownerScope = CalendarReconciliationScope::forOrganizationOwner($this->year());
        $owner = $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => $this->action()->preview($ownerScope, $this->teacher, $change));
        $this->assertSame(1, $owner->totals['retired']);

        $this->materialize('2026-11-01', '2026-11-30', $other);

        $done = $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => $this->action()->execute($ownerScope, $owner->token, $this->teacher, $change));
        $this->assertSame(1, $done->totals['retired']);
    }

    /**
     * O que MUDA o plano continua a invalidar: uma aula a partir do primeiro
     * dia afetado que fecha, e faltas lançadas numa aula afetada.
     */
    #[Test]
    public function relevant_changes_still_invalidate_the_token(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->exception('2026-10-22', '2026-10-22');

        $plan = $this->preview($this->teacherScope());
        $this->markTaught('2026-11-05');
        $this->assertStale($plan->token);

        $plan = $this->preview($this->teacherScope());
        $enrollment = $this->enroll('Ana Silva');
        $this->inTenant($this->organization, fn () => LessonAttendance::create([
            'lesson_id' => $this->lessonAt('2026-10-22')->id, 'enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'status' => AttendanceStatus::Absent,
        ]));
        $this->assertStale($plan->token);
        $this->assertSame(9, $this->lessonCount());
    }

    /**
     * O responsável grava uma exceção RETROATIVA (08/10); entre a
     * pré-visualização e a gravação, a aula de hoje (22/10, Lição 4 — fora dos
     * dias que o responsável prende) é marcada como lecionada. A execução nunca
     * lhe muda o número: recusa, e a Lição 4 continua a 4. (A mesma corrida a
     * MEIO da execução, com o retrato do InnoDB, está em
     * CalendarReconciliationMysqlConcurrencyTest.)
     */
    #[Test]
    public function a_lesson_taught_after_the_owner_preview_keeps_its_number(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', 'Europe/Lisbon'));
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->markTaught('2026-10-01');
        $scope = CalendarReconciliationScope::forOrganizationOwner($this->year());
        $change = CalendarExceptionChange::create(['type' => AcademicCalendarExceptionType::Holiday->value, 'title' => 'Greve retroativa', 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-08']);
        $plan = $this->preview($scope, $change);
        $this->assertSame(1, $plan->totals['retired']);

        $this->markTaught('2026-10-22');

        try {
            $this->execute($scope, $plan->token, $change);
            $this->fail('O plano mudou: devia recusar.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan_token', $exception->errors());
        }

        $this->assertSame(['2026-10-01' => 1, '2026-10-22' => 4], $this->taughtNumbers());

        // Com um plano novo, a lecionada fica com a Lição 4 e a lacuna passa para a 3.
        $plan = $this->preview($scope, $change);
        $this->execute($scope, $plan->token, $change);
        $this->assertSame(['2026-10-01' => 1, '2026-10-15' => 2, '2026-10-22' => 4, '2026-10-29' => 5], array_slice($this->numberByDay(), 0, 4, true));
        $this->assertSame([3], array_values($plan->gaps)[0]);
    }

    // ------------------------------------------------------------------
    // 21. A classificação vê o destino que a materialização não cria
    // ------------------------------------------------------------------

    /**
     * O cenário de `when_the_execution_cannot_keep_the_simulated_destination…`:
     * a classificação SÓ EM LEITURA — a do aviso e da página da aula — já sabe
     * que a T2 de 22/10 não nasce (o tempo da turma inteira, de id menor, faz
     * nascer primeiro a sua aula). Antes, o aviso contava duas B «corrigíveis»
     * que a pré-visualização dava como C, e pedia a correção para sempre.
     */
    #[Test]
    public function the_read_only_classification_already_knows_a_destination_the_timetable_will_not_create(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Lisbon'));
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $key = (string) Str::ulid();
        $this->makeSlot(['day_of_week' => 4, 'starts_on' => '2026-10-22', 'ends_on' => '2027-06-30']);
        $this->makeSlot(['class_group_id' => $t1->id, 'day_of_week' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key]);
        $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 4, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key]);
        $this->materialize('2026-10-05', '2026-10-18');
        $this->summaryAt('2026-10-12 09:30:00', 'PT1');
        $this->summaryAt('2026-10-15 09:30:00', 'PT2');
        $this->exception('2026-10-12', '2026-10-15');

        $rows = $this->inTenant($this->organization, fn (): array => app(NonTeachingDayConflicts::class)->classify([$this->schoolClass], $this->year()));
        $preview = $this->preview($this->teacherScope());

        $kinds = fn (array $lessons): array => array_combine(
            array_map(fn (array $lesson): string => CarbonImmutable::parse($lesson['starts_at'] ?? $lesson['lesson']->starts_at)->toIso8601String(), $lessons),
            array_map(fn (array $lesson): string => $lesson['kind'], $lessons),
        );

        $this->assertSame(['C', 'C'], array_values($kinds($rows)));
        $this->assertSame($kinds($rows), $kinds($preview->classes[0]['lessons']));
        $this->assertSame(['no_destination'], $rows[1]['reasons']);
        $this->assertSame(['shared_unit'], $rows[0]['reasons']);
    }

    /**
     * Com uma colisão em CADEIA — a aula da turma inteira (id menor) faz nascer
     * a sua; um segundo tempo da turma inteira que se cruza com ela não nasce,
     * e por isso NÃO impede a T2 de um terceiro tempo que só se cruza com ele —,
     * a classificação conta com a T2, e a execução cria-a mesmo.
     */
    #[Test]
    public function a_slot_blocked_by_an_earlier_one_does_not_block_a_later_one_in_the_classification(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Lisbon'));
        $t2 = $this->makeGroup('T2');
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '08:00', 'ends_at' => '09:00', 'starts_on' => '2026-10-22', 'ends_on' => '2027-06-30']);
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '08:30', 'ends_at' => '09:30', 'starts_on' => '2026-10-22', 'ends_on' => '2027-06-30']);
        $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 4, 'starts_at' => '09:15', 'ends_at' => '10:00', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->materialize('2026-10-05', '2026-10-18');
        $this->summaryAt('2026-10-15 09:15:00', 'PT2');
        $this->exception('2026-10-15', '2026-10-15');

        $plan = $this->runTeacher();

        $this->assertSame(1, $plan->totals['shifted']);
        $this->assertSame('PT2', $this->summaryAtRow('2026-10-22 09:15:00')->content);
        $this->assertNull($this->inTenant($this->organization, fn () => Lesson::query()->where('starts_at', '2026-10-22 08:30:00')->first()));
    }

    // ------------------------------------------------------------------
    // 22. Onde fica cada planeamento no fim (o resultado, não a cascata)
    // ------------------------------------------------------------------

    /**
     * Duas B (22 e 29/10), 05/11 com Q05 e 12/11 vazia. A execução desce
     * primeiro a de 29/10 (P29 → 05/11, Q05 → 12/11) e depois a de 22/10
     * (P22 → 05/11, P29 → 12/11, Q05 → 19/11). Os passos dizem «29/10 → 05/11»,
     * mas P29 ACABA em 12/11: o resultado diz isso, e diz à parte que o Q05,
     * que já lá estava, avança de 05/11 para 19/11. E é o que fica gravado.
     */
    #[Test]
    public function the_plan_says_where_each_planning_ends_up_and_what_already_there_moves_on(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->summary('2026-10-22', 'P22');
        $this->summary('2026-10-29', 'P29');
        $this->summary('2026-11-05', 'Q05');
        $this->exception('2026-10-22', '2026-10-29');

        $plan = $this->runTeacher();

        $at = fn (string $day): string => CarbonImmutable::parse($day.' 09:30:00')->toIso8601String();
        $this->assertSame([
            ['origin' => 'retired', 'from_starts_at' => $at('2026-10-22'), 'to_starts_at' => $at('2026-11-05'), 'new_lesson' => false, 'pending' => false],
            ['origin' => 'retired', 'from_starts_at' => $at('2026-10-29'), 'to_starts_at' => $at('2026-11-12'), 'new_lesson' => false, 'pending' => false],
            ['origin' => 'advanced', 'from_starts_at' => $at('2026-11-05'), 'to_starts_at' => $at('2026-11-19'), 'new_lesson' => false, 'pending' => false],
        ], $plan->classes[0]['planning']);
        $this->assertSame(['2026-11-05' => 'P22', '2026-11-12' => 'P29', '2026-11-19' => 'Q05'], $this->contentByDay());
    }

    /** Quando o horário acaba: o planeamento que já estava na última aula fica lá, como pendência. */
    #[Test]
    public function the_plan_says_which_planning_stays_as_a_pending_plan(): void
    {
        $this->thursdaySlot('2026-11-05');
        $this->materialize('2026-10-01', '2026-11-05');
        $this->summary('2026-10-29', 'P29');
        $this->summary('2026-11-05', 'Q');
        $this->exception('2026-10-29', '2026-10-29');

        $plan = $this->runTeacher();

        $at = fn (string $day): string => CarbonImmutable::parse($day.' 09:30:00')->toIso8601String();
        $this->assertSame([
            ['origin' => 'retired', 'from_starts_at' => $at('2026-10-29'), 'to_starts_at' => $at('2026-11-05'), 'new_lesson' => false, 'pending' => false],
            ['origin' => 'advanced', 'from_starts_at' => $at('2026-11-05'), 'to_starts_at' => $at('2026-11-05'), 'new_lesson' => false, 'pending' => true],
        ], $plan->classes[0]['planning']);
        $this->assertSame('Q', LessonPlan::withoutGlobalScopes()->sole()->planned_summary);
    }

    /**
     * «Precisa de revisão», contado por razão e sem conteúdo — com o nome do
     * outro grupo de uma lição partilhada (o responsável vê-o sem abrir aulas).
     */
    #[Test]
    public function the_review_count_says_why_and_names_the_other_group_of_a_shared_lesson(): void
    {
        $this->splitSlots();
        $this->materialize('2026-10-05', '2026-10-25');
        $this->markTaughtAt('2026-10-13 09:30:00');
        $change = CalendarExceptionChange::create(['type' => AcademicCalendarExceptionType::Holiday->value, 'title' => 'Greve', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-12']);

        $plan = $this->preview(CalendarReconciliationScope::forOrganizationOwner($this->year()), $change);

        $this->assertSame([['reasons' => ['shared_unit'], 'partners' => ['T2'], 'count' => 1]], $plan->classes[0]['review']);
    }

    // ------------------------------------------------------------------
    // 23. Quando a execução não cumpre a simulação (os caminhos de recurso)
    // ------------------------------------------------------------------

    /**
     * A SEGUNDA VOLTA DESLOCA DE FACTO. Três B (T1, T2 da mesma lição; G, de
     * outro grupo). Na primeira volta — e SÓ nela — aparece, já depois da
     * classificação, uma aula da turma inteira à hora da T2 de 22/10 (um
     * acontecimento que a simulação não podia ver): a T1 já tinha descido, a T2
     * fica sem destino. A volta é desfeita até ao ponto de gravação — a T1 que
     * nasceu, a G de 20/10 e a própria aula da turma inteira desaparecem —, a T2
     * e a T1 passam a C e a segunda volta desloca a G. Nada sobra da primeira
     * volta, nada se duplica, e a pré-visualização e a execução dão o mesmo
     * token.
     */
    #[Test]
    public function a_second_round_after_an_undone_one_really_shifts_the_others_and_leaves_nothing_behind(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Lisbon'));
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $g = $this->makeGroup('G');
        $key = (string) Str::ulid();
        $this->makeSlot(['class_group_id' => $t1->id, 'day_of_week' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key]);
        $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 4, 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-22', 'split_lesson_key' => $key]);
        $this->makeSlot(['class_group_id' => $g->id, 'day_of_week' => 2, 'starts_at' => '11:00', 'ends_at' => '11:50', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->materialize('2026-10-05', '2026-10-18');
        $this->summaryAt('2026-10-12 09:30:00', 'PT1');
        $this->summaryAt('2026-10-15 09:30:00', 'PT2');
        $this->summaryAt('2026-10-13 11:00:00', 'PG');
        $this->exception('2026-10-12', '2026-10-15');

        $arm = $this->onFirstRound(fn () => $this->makeLesson(['origin' => LessonOrigin::Manual, 'starts_at' => '2026-10-22 09:00:00', 'ends_at' => '2026-10-22 10:00:00']));

        $arm();
        $first = $this->preview($this->teacherScope());
        $arm();
        $this->assertSame($first->token, $this->preview($this->teacherScope())->token);
        $arm();
        $plan = $this->execute($this->teacherScope(), $first->token);

        $this->assertSame(['retired' => 0, 'shifted' => 1, 'awaiting_teacher' => 0, 'flagged' => 2], $plan->totals);
        $reasons = array_column($plan->classes[0]['lessons'], 'reasons', 'starts_at');
        $this->assertSame(['no_destination'], $reasons[CarbonImmutable::parse('2026-10-15 09:30:00')->toIso8601String()]);
        $this->assertSame(['shared_unit'], $reasons[CarbonImmutable::parse('2026-10-12 09:30:00')->toIso8601String()]);
        $this->assertSame([
            '2026-10-12' => 'PT1', '2026-10-15' => 'PT2', '2026-10-20' => 'PG',
        ], $this->contentByDay());
        // Nada da primeira volta: nem a aula da turma inteira, nem a T2 de 22/10.
        $this->assertSame(0, $this->inTenant($this->organization, fn (): int => Lesson::query()->where('starts_at', '>=', '2026-10-22 00:00:00')->where('starts_at', '<', '2026-10-23 00:00:00')->count()));
        $this->assertNull($this->inTenant($this->organization, fn () => Lesson::query()->where('starts_at', '2026-10-13 11:00:00')->first()), 'A G retirada.');
        $this->assertSame(3, LessonSummary::withoutGlobalScopes()->count(), 'Nenhum planeamento duplicado.');
    }

    /**
     * O DESTINO REAL É UMA AULA EXISTENTE QUE A SIMULAÇÃO NÃO PREVIU — aqui, a
     * aula do grupo G de 27/10 passa a ser da turma inteira depois da
     * classificação (só na primeira volta). Nunca se escreve nela: a B passa a
     * C (`protected_lesson_after`), tudo o que a volta escreveu é desfeito, e a
     * pré-visualização e a execução coincidem.
     */
    #[Test]
    public function an_existing_destination_the_simulation_did_not_foresee_is_never_written(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-10-29');
        $g = $this->makeGroup('G');
        $other = $this->makeLesson(['class_group_id' => $g->id, 'origin' => LessonOrigin::Manual, 'starts_at' => '2026-10-27 09:30:00']);
        $this->summary('2026-10-22', 'P22');
        $this->summary('2026-10-29', 'Q29');
        $this->exception('2026-10-22', '2026-10-22');
        $before = $this->contentByDay();

        $arm = $this->onFirstRound(fn () => Lesson::withoutGlobalScopes()->whereKey($other->id)->update(['class_group_id' => null]));

        $arm();
        $first = $this->preview($this->teacherScope());
        $arm();
        $plan = $this->execute($this->teacherScope(), $first->token);

        $this->assertSame(['protected_lesson_after'], $plan->classes[0]['lessons'][0]['reasons']);
        $this->assertFalse($plan->changesAnything());
        $this->assertSame($before, $this->contentByDay());
        $this->assertSame($g->id, Lesson::withoutGlobalScopes()->findOrFail($other->id)->class_group_id);
    }

    /**
     * O DESTINO REAL É UMA AULA QUE NASCEU AGORA, MAS JÁ NO PASSADO (a B é de um
     * feriado que já passou): `foreseen()` recusa-a — o sumário de uma aula que
     * já devia ter acontecido é o que se passou, não o que se planeou.
     */
    #[Test]
    public function a_new_destination_already_in_the_past_is_never_written(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-10-29');
        $this->inTenant($this->organization, fn () => $this->lessonAt('2026-10-15')->delete());
        $this->summary('2026-10-08', 'P08');
        $this->exception('2026-10-08', '2026-10-08');
        $before = $this->contentByDay();

        $arm = $this->onFirstRound(fn () => $this->makeLesson(['origin' => LessonOrigin::Manual, 'starts_at' => '2026-10-14 09:30:00']));

        $arm();
        $first = $this->preview($this->teacherScope());
        $arm();
        $plan = $this->execute($this->teacherScope(), $first->token);

        $this->assertSame(['protected_lesson_after'], $plan->classes[0]['lessons'][0]['reasons']);
        $this->assertSame($before, $this->contentByDay());
        $this->assertNull($this->inTenant($this->organization, fn () => Lesson::query()->where('starts_at', '2026-10-14 09:30:00')->first()));
    }

    // ------------------------------------------------------------------
    // 24. Volume: sem trabalho quadrático
    // ------------------------------------------------------------------

    /**
     * A T2 só encontra destino em janeiro: de 22/10 a 31/12 a aula da turma
     * inteira (id menor) nasce primeiro à mesma hora. A execução percorre essas
     * dez quintas — e materializa CADA DIA UMA VEZ, em intervalos seguidos, em
     * vez de voltar a materializar desde 16/10 por cada quinta que não serve.
     */
    #[Test]
    public function a_long_run_of_occurrences_that_never_come_to_life_materializes_each_day_once(): void
    {
        $t2 = $this->makeGroup('T2');
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '09:00', 'ends_at' => '10:00', 'starts_on' => '2026-10-22', 'ends_on' => '2026-12-31']);
        $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 4, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->materialize('2026-10-01', '2026-10-18');
        $this->summaryAt('2026-10-15 09:30:00', 'PT2');
        $this->exception('2026-10-15', '2026-10-15');

        /** @var ArrayObject<int, array{0: string, 1: string}> $ranges */
        $ranges = new ArrayObject;
        $this->app->bind(MaterializeLessonsForRange::class, fn ($app): MaterializeLessonsForRange => new class($app->make(LessonConflicts::class), $app->make(LessonNumbering::class), $app->make(ReconcileLessonsWithSlotValidity::class), $app->make(ClassArchivalWindow::class), $ranges) extends MaterializeLessonsForRange
        {
            /** @param  ArrayObject<int, array{0: string, 1: string}>  $ranges */
            public function __construct(LessonConflicts $conflicts, LessonNumbering $numbering, ReconcileLessonsWithSlotValidity $reconcile, ClassArchivalWindow $archivalWindow, private readonly ArrayObject $ranges)
            {
                parent::__construct($conflicts, $numbering, $reconcile, $archivalWindow);
            }

            public function execute(SchoolClass $class, CarbonImmutable $from, CarbonImmutable $to, User $actor): SupportCollection
            {
                $this->ranges->append([$from->toDateString(), $to->toDateString()]);

                return parent::execute($class, $from, $to, $actor);
            }
        });

        $plan = $this->runTeacher();

        $this->assertSame(1, $plan->totals['shifted']);
        $this->assertSame('PT2', $this->summaryAtRow('2027-01-07 09:30:00')->content);

        // A execução (a pré-visualização fez o mesmo): doze intervalos seguidos, sem sobreposição.
        $all = $ranges->getArrayCopy();
        $execution = array_slice($all, (int) (count($all) / 2));
        $this->assertCount(12, $execution);
        $this->assertSame(['2026-10-16', '2026-10-22'], $execution[0]);

        for ($index = 1; $index < count($execution); $index++) {
            $this->assertSame(CarbonImmutable::parse($execution[$index - 1][1])->addDay()->toDateString(), $execution[$index][0], 'Cada dia materializa-se uma vez.');
        }
    }

    /**
     * A classificação procura no horário só as ocorrências de que as B
     * precisam (uma por B), e não as 151 de antes — o ano todo, consulta a
     * consulta, em cada pedido do aviso.
     */
    #[Test]
    public function the_classification_reads_only_as_many_timetable_occurrences_as_planned_lessons(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-10-29');
        $this->summary('2026-10-22', 'P22');
        $this->summary('2026-10-29', 'Q29');
        $this->exception('2026-10-22', '2026-10-22');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $rows = $this->inTenant($this->organization, fn (): array => app(NonTeachingDayConflicts::class)->classify([$this->schoolClass], $this->year()));

        $this->assertSame('B', $rows[0]['kind']);
        $this->assertLessThan(30, $queries, 'A classificação leu o horário do ano inteiro.');
    }

    // ------------------------------------------------------------------
    // Paridade da regra de dia não letivo
    // ------------------------------------------------------------------

    #[Test]
    public function the_classification_reads_the_same_days_as_the_other_non_teaching_rules(): void
    {
        foreach (range(1, 7) as $weekday) {
            $this->thursdaySlot(null, null, $weekday);
        }

        $this->materialize('2026-10-05', '2026-10-25');
        $this->exception('2026-10-07', '2026-10-07');
        $this->exception('2026-10-12', '2026-10-14');
        $this->exception('2026-10-16', '2026-10-19');

        $rows = $this->inTenant($this->organization, fn (): array => app(NonTeachingDayConflicts::class)->classify([$this->schoolClass], $this->year()));

        $days = array_values(array_unique(array_map(fn (array $row): string => $row['lesson']->starts_at->format('Y-m-d'), $rows)));
        $this->assertSame([
            '2026-10-07', '2026-10-12', '2026-10-13', '2026-10-14',
            '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19',
        ], $days);
        $this->assertSame(array_fill(0, count($rows), 'A'), array_column($rows, 'kind'));
    }

    // ------------------------------------------------------------------
    // Ajudantes
    // ------------------------------------------------------------------

    private function action(): ReconcileLessonsWithCalendarExceptions
    {
        return app(ReconcileLessonsWithCalendarExceptions::class);
    }

    private function year(): AcademicYear
    {
        return $this->inTenant($this->organization, fn (): AcademicYear => AcademicYear::query()->findOrFail($this->schoolClass->academic_year_id));
    }

    private function teacherScope(): CalendarReconciliationScope
    {
        return CalendarReconciliationScope::forTeacher($this->teacher, $this->year());
    }

    private function preview(CalendarReconciliationScope $scope, ?CalendarExceptionChange $change = null): CalendarReconciliationPlan
    {
        return $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => $this->action()->preview($scope, $this->teacher, $change));
    }

    private function execute(CalendarReconciliationScope $scope, string $token, ?CalendarExceptionChange $change = null): CalendarReconciliationPlan
    {
        return $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => $this->action()->execute($scope, $token, $this->teacher, $change));
    }

    private function runTeacher(): CalendarReconciliationPlan
    {
        $plan = $this->preview($this->teacherScope());

        return $this->execute($this->teacherScope(), $plan->token);
    }

    /**
     * Uma escrita feita DENTRO do ponto de gravação da fase 1 — já depois da
     * classificação — na primeira volta de cada pré-visualização ou execução,
     * e só nela: o acontecimento que a simulação não podia prever. `$arm()`
     * prepara a chamada seguinte. Como a volta é desfeita até ao ponto de
     * gravação, a escrita desaparece com ela.
     *
     * @return callable(): void
     */
    private function onFirstRound(callable $write): callable
    {
        $state = ['armed' => false, 'base' => 0];

        Event::listen(TransactionBeginning::class, function () use (&$state, $write): void {
            if ($state['armed'] && DB::transactionLevel() === $state['base'] + 2) {
                $state['armed'] = false;
                $this->inTenant($this->organization, $write);
            }
        });

        return function () use (&$state): void {
            $state['armed'] = true;
            $state['base'] = DB::transactionLevel();
        };
    }

    private function assertStale(string $token): void
    {
        try {
            $this->execute($this->teacherScope(), $token);
            $this->fail('O plano devia estar desatualizado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan_token', $exception->errors());
        }
    }

    private function expectAuthorization(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Devia ter sido recusado.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function thursdaySlot(?string $endsOn = null, ?SchoolClass $class = null, int $weekday = 4): RecurringLessonSlot
    {
        return $this->makeSlot([
            'class_id' => ($class ?? $this->schoolClass)->id,
            'day_of_week' => $weekday,
            'starts_at' => '09:30',
            'ends_at' => '10:20',
            'starts_on' => '2026-09-01',
            'ends_on' => $endsOn ?? '2027-06-30',
        ]);
    }

    /**
     * @return array{0: RecurringLessonSlot, 1: RecurringLessonSlot} T1 à segunda, T2 à terça, a mesma lição
     */
    private function splitSlots(): array
    {
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $key = (string) Str::ulid();
        $base = ['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key];

        return [
            $this->makeSlot($base + ['class_group_id' => $t1->id, 'day_of_week' => 1]),
            $this->makeSlot($base + ['class_group_id' => $t2->id, 'day_of_week' => 2]),
        ];
    }

    private function materialize(string $from, string $to, ?SchoolClass $class = null): void
    {
        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $class ?? $this->schoolClass,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
            $this->teacher,
        ));
    }

    private function exception(string $from, string $to): AcademicCalendarException
    {
        return $this->inTenant($this->organization, fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)
            ->for($this->year())
            ->create([
                'type' => AcademicCalendarExceptionType::Holiday,
                'title' => 'Exceção '.$from,
                'starts_on' => $from,
                'ends_on' => $to,
            ]));
    }

    /**
     * Avança o auto-incremento de `lessons` sem deixar linha nenhuma — o que o
     * ROLLBACK do MySQL faz sozinho (não devolve os ids que a transação
     * revertida gastou). Em SQLite a sequência é transacional e é preciso
     * simulá-lo; em MySQL já aconteceu na pré-visualização.
     */
    private function advanceLessonIds(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("UPDATE sqlite_sequence SET seq = seq + 50 WHERE name = 'lessons'");
        }
    }

    private function exceptionRow(AcademicCalendarException $exception): AcademicCalendarException
    {
        return AcademicCalendarException::withoutGlobalScopes()->findOrFail($exception->id);
    }

    /** @param  array<string, mixed>  $extra */
    private function summary(string $day, string $content, array $extra = []): LessonSummary
    {
        return $this->summaryAt($day.' 09:30:00', $content, $extra);
    }

    /** @param  array<string, mixed>  $extra */
    private function summaryAt(string $startsAt, string $content, array $extra = []): LessonSummary
    {
        $lesson = $this->lessonAtTime($startsAt);

        return $this->inTenant($this->organization, fn (): LessonSummary => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => $content] + $extra));
    }

    private function lessonAt(string $day): Lesson
    {
        return $this->lessonAtTime($day.' 09:30:00');
    }

    private function lessonAtTime(string $startsAt): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::query()->where('starts_at', $startsAt)->firstOrFail());
    }

    private function summaryRow(string $day): LessonSummary
    {
        return $this->summaryAtRow($day.' 09:30:00');
    }

    private function summaryAtRow(string $startsAt): LessonSummary
    {
        return $this->summaryAtRowOrNull($startsAt) ?? throw new \RuntimeException('Sem sumário em '.$startsAt);
    }

    private function summaryAtRowOrNull(string $startsAt): ?LessonSummary
    {
        return $this->inTenant($this->organization, fn (): ?LessonSummary => LessonSummary::query()
            ->whereHas('lesson', fn ($lesson) => $lesson->where('starts_at', $startsAt))->first());
    }

    private function markTaught(string $day, ?SchoolClass $class = null): void
    {
        $this->inTenant($this->organization, fn () => Lesson::query()
            ->where('class_id', ($class ?? $this->schoolClass)->id)
            ->where('starts_at', $day.' 09:30:00')
            ->update(['status' => LessonStatus::Taught->value, 'outcome' => LessonOutcome::Taught->value]));
    }

    private function markTaughtAt(string $startsAt): void
    {
        $this->inTenant($this->organization, fn () => Lesson::query()->where('starts_at', $startsAt)
            ->update(['status' => LessonStatus::Taught->value, 'outcome' => LessonOutcome::Taught->value]));
    }

    private function assertDeleteIsRefused(string $day): void
    {
        try {
            $this->inTenant($this->organization, fn () => app(DeleteLesson::class)->execute($this->lessonAt($day), $this->teacher));
            $this->fail('DeleteLesson devia recusar.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lesson_number', $exception->errors());
        }
    }

    /** @return list<Lesson> */
    private function lessonRows(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()->orderBy('starts_at')->orderBy('id')->get()->all());
    }

    /** @return list<string> o dia do mês de cada aula, por ordem cronológica */
    private function days(): array
    {
        return array_map(fn (Lesson $lesson): string => $lesson->starts_at->format('d'), $this->lessonRows());
    }

    private function lessonCount(): int
    {
        return count($this->lessonRows());
    }

    /** @return list<int|null> */
    private function numbers(): array
    {
        return array_map(fn (Lesson $lesson): ?int => $lesson->lesson_number, $this->lessonRows());
    }

    /** @return array<string, int|null> */
    private function numberByDay(): array
    {
        $map = [];

        foreach ($this->lessonRows() as $lesson) {
            $map[$lesson->starts_at->format('Y-m-d')] = $lesson->lesson_number;
        }

        return $map;
    }

    /** @return array<string, int|null> */
    private function taughtNumbers(): array
    {
        $map = [];

        foreach ($this->lessonRows() as $lesson) {
            if ($lesson->isClosed()) {
                $map[$lesson->starts_at->format('Y-m-d')] = $lesson->lesson_number;
            }
        }

        return $map;
    }

    /** @return list<int|null> */
    private function classNumbers(SchoolClass $class): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('class_id', $class->id)->orderBy('starts_at')->pluck('lesson_number')
            ->map(fn (mixed $number): ?int => $number === null ? null : (int) $number)->all());
    }

    /** @return list<string> */
    private function contents(): array
    {
        return $this->inTenant($this->organization, fn (): array => LessonSummary::query()->pluck('content')->all());
    }

    /** @return array<string, string> */
    private function contentByDay(): array
    {
        $map = [];

        foreach (LessonSummary::withoutGlobalScopes()->get() as $summary) {
            $map[Lesson::withoutGlobalScopes()->findOrFail($summary->lesson_id)->starts_at->format('Y-m-d')] = $summary->content;
        }

        ksort($map);

        return $map;
    }

    private function marksCount(): int
    {
        return CancelledLessonOccurrence::withoutGlobalScopes()->count();
    }

    /** @return list<array<string, mixed>> */
    private function marks(): array
    {
        return CancelledLessonOccurrence::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function lessonSnapshot(): array
    {
        return Lesson::withoutGlobalScopes()->orderBy('id')->get()->mapWithKeys(
            fn (Lesson $lesson): array => [$lesson->id => $lesson->getAttributes()],
        )->all();
    }

    /** O mundo inteiro que a ação pode tocar, para provar que nada foi escrito. */
    private function world(): string
    {
        return (string) json_encode([
            $this->lessonSnapshot(),
            LessonSummary::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            LessonPlan::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            AcademicCalendarException::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            $this->marks(),
            AuditEvent::withoutGlobalScopes()->where('event', 'lesson.calendar_reconciled')->count(),
        ]);
    }

    private function colleague(bool $teaches): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization, ['joined_at' => now()]);

        if ($teaches) {
            $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($user, ['role' => 'co_teacher']));
        }

        return $user;
    }
}
