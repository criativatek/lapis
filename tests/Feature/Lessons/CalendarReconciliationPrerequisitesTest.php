<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\DeleteLesson;
use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Actions\Lessons\RecordLessonOutcome;
use App\Actions\Lessons\ReviseRecurringLessonSlot;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
use App\Models\CancelledLessonOccurrence;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\TeacherAbsenceReason;
use App\Models\User;
use App\Services\Lessons\LessonNumbering;
use App\Services\Lessons\ScheduleOccurrences;
use App\Services\Lessons\ShiftLessonPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * PRÉ-REQUISITO 0 da reconciliação do calendário: testes de CARACTERIZAÇÃO.
 *
 * Nada aqui corrige código de produção. Cada teste prova, com asserções
 * exatas, o comportamento que existe HOJE (v0.185.0) nos pontos em que a
 * Fatia 2 vai mexer. Quando o comportamento de hoje é um defeito, o nome do
 * teste di-lo (`..._currently_...`) e a asserção descreve o defeito tal como
 * ele é: quem corrigir o defeito falha este teste de propósito e tem de o
 * reescrever.
 *
 * O RELÓGIO ESTÁ FIXO (setUp). Há testes-bomba de relógio neste projeto —
 * ends_on = now()->subDay(), starts_on ?? created_at — e as datas deste
 * ficheiro são absolutas (outubro de 2026). Calendário de referência:
 * quintas 01, 08, 15, 22, 29; segundas 05, 12, 19, 26; terças 06, 13, 20, 27.
 */
class CalendarReconciliationPrerequisitesTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    // ------------------------------------------------------------------
    // 1. Ressurreição de uma ocorrência eliminada após revisão do horário
    // ------------------------------------------------------------------

    /** Controlo: sem revisão, a marca de eliminação impede o renascimento. */
    #[Test]
    public function a_deleted_occurrence_stays_deleted_while_the_slot_is_not_revised(): void
    {
        $slotA = $this->weeklySlot(4);
        $this->materialize('2026-10-12', '2026-10-18');
        $this->deleteLessonAt('2026-10-15 09:30:00');

        $this->materialize('2026-10-12', '2026-10-18');

        $this->assertSame([], $this->lessonStarts());
        $marks = $this->cancellations();
        $this->assertCount(1, $marks);
        $this->assertSame($slotA->id, $marks[0]['slot']);
        $this->assertSame('2026-10-15 09:30:00', $marks[0]['occurs_at']);
    }

    /**
     * O DEFEITO: a marca é chaveada por (turma, tempo, início) e uma revisão
     * versionada cria um tempo NOVO (outro id) no mesmo dia e hora. A marca
     * continua a apontar para o tempo A, a materialização consulta (B, início)
     * e não a encontra: a aula eliminada RESSUSCITA ao abrir a semana.
     */
    #[Test]
    public function a_deleted_occurrence_is_currently_resurrected_by_a_slot_revision_effective_on_or_before_it(): void
    {
        $slotA = $this->weeklySlot(4);
        $this->materialize('2026-10-12', '2026-10-18');
        $this->deleteLessonAt('2026-10-15 09:30:00');

        $slotB = $this->revise($slotA, '2026-10-08');
        $this->assertNotSame($slotA->id, $slotB->id);
        $this->materialize('2026-10-12', '2026-10-18');

        $lessons = $this->lessonSlots();
        $this->assertSame([['2026-10-15 09:30:00', $slotB->id]], $lessons);
        // A marca antiga continua exatamente onde estava, presa ao tempo A.
        $marks = $this->cancellations();
        $this->assertCount(1, $marks);
        $this->assertSame($slotA->id, $marks[0]['slot']);
    }

    /** Variante: a revisão só vale DEPOIS da data eliminada — não a afeta. */
    #[Test]
    public function a_deleted_occurrence_is_not_affected_by_a_revision_effective_after_it(): void
    {
        $slotA = $this->weeklySlot(4);
        $this->materialize('2026-10-12', '2026-10-18');
        $this->deleteLessonAt('2026-10-15 09:30:00');

        $slotB = $this->revise($slotA, '2026-10-22');
        $this->materialize('2026-10-12', '2026-10-18');
        $this->assertSame([], $this->lessonStarts());

        $this->materialize('2026-10-19', '2026-10-25');
        $this->assertSame([['2026-10-22 09:30:00', $slotB->id]], $this->lessonSlots());
    }

    // ------------------------------------------------------------------
    // 2. Visibilidade dos motivos entre professores da mesma turma
    // ------------------------------------------------------------------

    /**
     * As aulas são da TURMA, e não do professor: `lessons` não tem coluna de
     * professor (só `created_by`/`outcome_recorded_by`), a LessonPolicy deixa
     * ver/editar qualquer professor da turma, e quem não leciona a turma
     * recebe 403.
     */
    #[Test]
    public function lessons_belong_to_the_class_and_every_teacher_of_the_class_reaches_them(): void
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00']);
        $colleague = $this->colleague(teaches: true);
        $outsider = $this->colleague(teaches: false);

        $this->assertSame($this->teacher->id, $lesson->created_by);

        $this->actingAsMember($colleague)->get("/lessons/{$lesson->ulid}")->assertOk();
        $this->actingAsMember($colleague)->get('/lessons?week=2026-10-12')->assertOk();
        $rows = $this->props($this->actingAsMember($colleague)->get('/lessons?week=2026-10-12'))['lessons'];
        $this->assertSame([$lesson->ulid], array_column($rows, 'ulid'));

        $this->actingAsMember($outsider)->get("/lessons/{$lesson->ulid}")->assertForbidden();
        $this->assertSame(
            [],
            $this->props($this->actingAsMember($outsider)->get('/lessons?week=2026-10-12'))['lessons'],
        );
    }

    /**
     * O professor Y vê, na PÁGINA DA AULA, o resultado, o motivo e a nota que
     * X registou. Na semana, no dashboard e na pesquisa de sumários chega só o
     * resultado (`outcome`/`outcome_label`): o motivo e a nota NUNCA viajam
     * nessas props.
     */
    #[Test]
    public function the_reason_and_note_of_an_absence_reach_the_colleague_only_on_the_lesson_page(): void
    {
        $absent = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00']);
        $activity = $this->makeLesson(['starts_at' => '2026-10-15 11:30:00']);
        $colleague = $this->colleague(teaches: true);

        $this->asTeacher()
            ->post("/lessons/{$absent->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasNoErrors();
        $this->asTeacher()
            ->post("/lessons/{$activity->ulid}/outcome", ['outcome' => 'class_external_activity', 'note' => 'Visita de estudo'])
            ->assertSessionHasNoErrors();

        // Para a pesquisa ter o que encontrar, depois de o planeamento descer.
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $absent->id, 'content' => 'Teorema de Pitagoras revisto.',
        ]));
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $activity->id, 'content' => 'Teorema de Pitagoras revisto.',
        ]));

        // Página da aula: tudo chega a Y.
        $show = $this->props($this->actingAsMember($colleague)->get("/lessons/{$absent->ulid}"))['lesson'];
        $this->assertSame('teacher_absent', $show['outcome']);
        $this->assertSame(TeacherAbsenceReason::Training->label(), $show['outcome_reason_label']);
        $this->assertNull($show['outcome_note']);
        $showActivity = $this->props($this->actingAsMember($colleague)->get("/lessons/{$activity->ulid}"))['lesson'];
        $this->assertSame('class_external_activity', $showActivity['outcome']);
        $this->assertSame('Visita de estudo', $showActivity['outcome_note']);

        // Semana: resultado sim, motivo/nota não.
        $week = $this->props($this->actingAsMember($colleague)->get('/lessons?week=2026-10-12'));
        $byUlid = array_column($week['lessons'], null, 'ulid');
        $this->assertSame('teacher_absent', $byUlid[$absent->ulid]['outcome']);
        $this->assertSame('class_external_activity', $byUlid[$activity->ulid]['outcome']);
        $this->assertSame([], $this->pathsEndingIn($week, ['outcome_reason', 'outcome_reason_label', 'outcome_note']));

        // Dashboard (hoje = 2026-10-15): idem.
        $dashboard = $this->props($this->actingAsMember($colleague)->get('/dashboard'));
        $this->assertSame([], $this->pathsEndingIn($dashboard, ['outcome_reason', 'outcome_reason_label', 'outcome_note']));
        $this->assertContains(
            'teacher_absent',
            $this->valuesAtKey($dashboard, 'outcome'),
            'O resultado (sem motivo) chega ao dashboard de Y.',
        );

        // Pesquisa de sumários: idem.
        $search = $this->actingAsMember($colleague)->getJson('/lessons/search?q=pitagoras&states=taught,prepared,other')->assertOk()->json();
        $this->assertSame([], $this->pathsEndingIn($search, ['outcome_reason', 'outcome_reason_label', 'outcome_note']));
        $found = array_column($search['results'], 'outcome', 'ulid');
        $this->assertEquals(
            [$absent->ulid => 'teacher_absent', $activity->ulid => 'class_external_activity'],
            $found,
            'A pesquisa devolve o resultado das duas aulas fechadas (estado «other»), sem motivo nem nota.',
        );
    }

    // ------------------------------------------------------------------
    // 3. Invariantes de numeração histórica
    // ------------------------------------------------------------------

    /**
     * (a) A recusa de DeleteLesson sobre uma aula aberta ANTERIOR a uma
     * lecionada não deixa rasto: a aula fica, a marca de eliminação não é
     * criada (a transação reverte) e os números ficam como estavam.
     * (A recusa em si já é provada em DeleteLessonTest.)
     */
    #[Test]
    public function a_refused_deletion_before_a_taught_lesson_leaves_no_trace(): void
    {
        $slot = $this->weeklySlot(4);
        $early = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-10-01 09:30:00', 'lesson_number' => 1]);
        $taught = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00', 'lesson_number' => 2, 'status' => LessonStatus::Taught]);

        try {
            $this->inTenant($this->organization, fn () => app(DeleteLesson::class)->execute($early, $this->teacher));
            $this->fail('A eliminação devia ter sido recusada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lesson_number', $exception->errors());
        }

        $this->assertSame([], $this->cancellations());
        $this->assertDatabaseHas('lessons', ['id' => $early->id]);
        $this->assertSame([1, 2], $this->numbers([$early, $taught]));
    }

    /** (b) Retirar abertas POSTERIORES à lecionada não altera os números de trás. */
    #[Test]
    public function deleting_open_lessons_after_a_taught_one_never_changes_earlier_numbers(): void
    {
        $taught = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'lesson_number' => 1, 'status' => LessonStatus::Taught]);
        $a = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $b = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00']);
        $c = $this->makeLesson(['starts_at' => '2026-10-22 09:30:00']);
        $this->resequence();
        $this->assertSame([1, 2, 3, 4], $this->numbers([$taught, $a, $b, $c]));

        $this->deleteViaAction($c);
        $this->assertSame([1, 2, 3], $this->numbers([$taught, $a, $b]));

        $this->deleteViaAction($b);
        $this->assertSame([1, 2], $this->numbers([$taught, $a]));
    }

    /**
     * (c) Uma aberta vazia num dia de exceção JÁ CONSOME número («Lição N»):
     * LessonNumbering.sequence() conta-a, e a seguinte desloca-se. A
     * materialização não a retira (a guarda só impede aulas NOVAS).
     */
    #[Test]
    public function an_empty_open_lesson_on_an_exception_day_currently_consumes_a_lesson_number(): void
    {
        $slot = $this->weeklySlot(4);
        $a = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-10-01 09:30:00']);
        $b = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-10-08 09:30:00']);
        $c = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-10-15 09:30:00']);
        $this->exception('2026-10-08', '2026-10-08');

        $this->resequence();
        $this->assertSame([1, 2, 3], $this->numbers([$a, $b, $c]));

        $this->materialize('2026-10-05', '2026-10-11');
        $this->assertDatabaseHas('lessons', ['id' => $b->id]);
        $this->assertSame([1, 2, 3], $this->numbers([$a, $b, $c]));

        // Retirada a do dia de exceção, a seguinte fecha o buraco.
        $this->deleteViaAction($b);
        $this->assertSame([1, 2], $this->numbers([$a, $c]));
    }

    /**
     * (d) Retirada uma aula anterior, as abertas posteriores derivam para trás
     * e fecham o buraco; a lecionada (fixa) e o que a antecede não se mexem.
     */
    #[Test]
    public function removing_an_earlier_open_lesson_closes_the_gap_only_among_the_open_ones(): void
    {
        $taught = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'lesson_number' => 1, 'status' => LessonStatus::Taught]);
        $a = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $b = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00']);
        $c = $this->makeLesson(['starts_at' => '2026-10-22 09:30:00']);
        $this->resequence();
        $this->assertSame([1, 2, 3, 4], $this->numbers([$taught, $a, $b, $c]));

        $changes = $this->deleteViaAction($a);

        $this->assertSame([1, 2, 3], $this->numbers([$taught, $b, $c]));
        $this->assertSame([$b->id => 2, $c->id => 3], $changes);
    }

    // ------------------------------------------------------------------
    // 4. Grupos desdobrados e turmas de apoio
    // ------------------------------------------------------------------

    /** (a) Exceção num dia: nenhuma aula nasce, para nenhum dos grupos. */
    #[Test]
    public function an_exception_stops_new_lessons_for_every_group_of_a_split_class(): void
    {
        [$t1, $t2] = $this->splitSlots();
        $this->exception('2026-10-05', '2026-10-06');

        $this->materialize('2026-10-05', '2026-10-11');

        $this->assertSame([], $this->lessonStarts());

        // Controlo: só a segunda em exceção — T2 (terça) nasce, T1 não.
        $this->exceptionRows()->delete();
        $this->exception('2026-10-05', '2026-10-05');
        $this->materialize('2026-10-05', '2026-10-11');
        $this->assertSame([['2026-10-06 09:30:00', $t2->id]], $this->lessonSlots());
        $this->assertNotContains($t1->id, array_column($this->lessonSlots(), 1));
    }

    /** (a) As aulas de T1 e T2 que já existiam sobrevivem à exceção posterior. */
    #[Test]
    public function existing_lessons_of_both_groups_survive_an_exception_declared_afterwards(): void
    {
        [$t1, $t2] = $this->splitSlots();
        $this->materialize('2026-10-05', '2026-10-11');
        $this->assertCount(2, $this->lessonStarts());

        $this->exception('2026-10-05', '2026-10-06');
        $this->materialize('2026-10-05', '2026-10-11');

        $this->assertSame(
            [['2026-10-05 09:30:00', $t1->id], ['2026-10-06 09:30:00', $t2->id]],
            $this->lessonSlots(),
        );
    }

    /**
     * (b) O planeamento desce só dentro do mesmo PÚBLICO: o de T1 vai para as
     * abertas de T1, nunca para T2 nem para a turma inteira; e a turma inteira
     * só para a turma inteira.
     */
    #[Test]
    public function planning_shifts_only_within_the_same_audience(): void
    {
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $l1 = $this->makeLesson(['class_group_id' => $t1->id, 'starts_at' => '2026-10-05 09:30:00']);
        $l2 = $this->makeLesson(['class_group_id' => $t1->id, 'starts_at' => '2026-10-12 09:30:00']);
        $m1 = $this->makeLesson(['class_group_id' => $t2->id, 'starts_at' => '2026-10-06 09:30:00']);
        $m2 = $this->makeLesson(['class_group_id' => $t2->id, 'starts_at' => '2026-10-13 09:30:00']);
        $w1 = $this->makeLesson(['starts_at' => '2026-10-07 09:30:00']);
        $w2 = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);

        $ids = fn (Lesson $lesson): array => $this->inTenant($this->organization, fn (): array => DB::transaction(
            fn (): array => app(ShiftLessonPlanning::class)->openLessonsAfter($lesson)->modelKeys(),
        ));

        $this->assertSame([$l2->id], $ids($l1));
        $this->assertSame([$m2->id], $ids($m1));
        $this->assertSame([$w2->id], $ids($w1));

        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $l1->id, 'content' => 'Plano de T1']));
        $this->inTenant($this->organization, function () use ($l1): void {
            DB::transaction(fn () => app(ShiftLessonPlanning::class)->from($l1->refresh(), $this->teacher));
        });

        $this->assertSame(
            [$l2->id],
            LessonSummary::withoutGlobalScopes()->where('content', 'Plano de T1')->pluck('lesson_id')->all(),
        );
    }

    /**
     * (c) Uma turma de apoio é uma SchoolClass própria: sequência de
     * numeração própria, e materializa e consulta o horário como qualquer
     * turma — as exceções letivas também a atingem.
     */
    #[Test]
    public function a_support_class_is_just_another_class_with_its_own_numbering_sequence(): void
    {
        $support = $this->inTenant($this->organization, function (): SchoolClass {
            $support = SchoolClass::factory()->recycle($this->organization)->support()->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'label' => 'Apoio 7.º A',
            ]);
            $support->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $support;
        });
        $this->assertTrue($support->is_support_class);
        $this->assertNotSame($this->schoolClass->id, $support->id);

        $this->weeklySlot(4);
        $this->weeklySlot(4, $support);

        $this->materialize('2026-10-01', '2026-10-31');
        $this->materialize('2026-10-01', '2026-10-31', $support);

        $this->assertSame([1, 2, 3, 4, 5], $this->classNumbers($this->schoolClass));
        $this->assertSame([1, 2, 3, 4, 5], $this->classNumbers($support));

        $between = fn (SchoolClass $class): array => array_map(
            fn (array $o): string => $o['starts_at']->format('Y-m-d'),
            $this->inTenant($this->organization, fn (): array => app(ScheduleOccurrences::class)->between(
                $class, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'),
            )),
        );
        $this->assertSame($between($this->schoolClass), $between($support));

        $this->exception('2026-10-15', '2026-10-15');
        $this->assertSame(['2026-10-01', '2026-10-08', '2026-10-22', '2026-10-29'], $between($support));
        $this->assertSame(['2026-10-01', '2026-10-08', '2026-10-22', '2026-10-29'], $between($this->schoolClass));
    }

    // ------------------------------------------------------------------
    // 5. Planeamento sobre dia de exceção
    // ------------------------------------------------------------------

    /**
     * A CADEIA CRUA CONTINUA A SER A DE SEMPRE nos fluxos existentes
     * (`from()`, que RecordLessonOutcome e PostponeLessonForCalendarActivity
     * usam): uma aula ABERTA que cai num dia de exceção ainda por regularizar
     * continua a ser a aula seguinte, e o planeamento aterra nela (15/10). Só a
     * reconciliação com o calendário pede `skip` — e só com as aulas que ELA
     * vai retirar na mesma operação, que não podem ser destino
     * (`fromRetiredLesson`). Não há «saltar os dias não letivos»: uma aula num
     * feriado que não vai ser retirada continua na cadeia.
     *
     * Uma versão anterior desta fatia mudava o comportamento por omissão para
     * saltar esses dias; isso invertia a ordem do planeamento em «professor
     * ausente» quando o dia saltado já tinha planeamento (ver o teste
     * seguinte), e foi revertido.
     */
    #[Test]
    public function the_raw_chain_still_includes_an_open_lesson_on_an_exception_day_and_only_reconciliation_skips_it(): void
    {
        $closed = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $onException = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00']);
        $after = $this->makeLesson(['starts_at' => '2026-10-22 09:30:00']);
        $this->exception('2026-10-15', '2026-10-15');
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $closed->id, 'content' => 'Planeamento a mover']));

        $raw = $this->inTenant($this->organization, fn (): array => DB::transaction(
            fn (): array => app(ShiftLessonPlanning::class)->openLessonsAfter($closed)->modelKeys(),
        ));
        $this->assertSame([$onException->id, $after->id], $raw, 'Por omissão, a cadeia crua de sempre.');

        $skipping = $this->inTenant($this->organization, fn (): array => DB::transaction(
            fn (): array => app(ShiftLessonPlanning::class)->openLessonsAfter($closed, skip: [$onException->id => true])->modelKeys(),
        ));
        $this->assertSame([$after->id], $skipping);

        $trace = $this->inTenant($this->organization, fn (): array => DB::transaction(
            fn (): array => app(ShiftLessonPlanning::class)->from($closed->refresh(), $this->teacher),
        ));

        $this->assertSame(1, $trace['shifted']);
        $this->assertFalse($trace['materialized']);
        $this->assertSame($onException->id, $trace['moves'][0]['to']->id);
        $this->assertSame(
            [$onException->id],
            LessonSummary::withoutGlobalScopes()->where('content', 'Planeamento a mover')->pluck('lesson_id')->all(),
        );
    }

    /**
     * «Professor ausente» a 15/10 com a cadeia 22/10 (feriado lançado depois,
     * POR REGULARIZAR, com o planeamento P_H) e 29/10: a ordem do planeamento
     * mantém-se — P0 passa para 22/10 e P_H para 29/10. Saltar o 22/10 aqui
     * punha P0 em 29/10 e deixava P_H para trás, ANTES dele.
     */
    #[Test]
    public function teacher_absent_keeps_the_planning_order_with_an_unregularised_holiday_on_the_way(): void
    {
        $absent = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00']);
        $holiday = $this->makeLesson(['starts_at' => '2026-10-22 09:30:00']);
        $next = $this->makeLesson(['starts_at' => '2026-10-29 09:30:00']);
        $this->inTenant($this->organization, function () use ($absent, $holiday): void {
            LessonSummary::create(['lesson_id' => $absent->id, 'content' => 'P0']);
            LessonSummary::create(['lesson_id' => $holiday->id, 'content' => 'P_H']);
        });
        $this->exception('2026-10-22', '2026-10-22');

        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute($absent->refresh(), LessonOutcome::TeacherAbsent, $this->teacher, TeacherAbsenceReason::Training));

        $this->assertSame(
            [$holiday->id => 'P0', $next->id => 'P_H'],
            LessonSummary::withoutGlobalScopes()->orderBy('lesson_id')->pluck('content', 'lesson_id')->all(),
        );
    }

    /**
     * Contraste: quando NÃO há aula aberta gravada, o destino materializado
     * vem de ScheduleOccurrences::between e salta o dia de exceção.
     */
    #[Test]
    public function a_materialized_planning_destination_skips_the_exception_day(): void
    {
        $slot = $this->weeklySlot(4);
        $closed = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-10-08 09:30:00']);
        $this->exception('2026-10-15', '2026-10-15');
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $closed->id, 'content' => 'Planeamento a mover']));

        $trace = $this->inTenant($this->organization, fn (): array => DB::transaction(
            fn (): array => app(ShiftLessonPlanning::class)->from($closed->refresh(), $this->teacher),
        ));

        $this->assertTrue($trace['materialized']);
        $this->assertSame(
            '2026-10-22 09:30:00',
            $trace['moves'][0]['to']->starts_at->format('Y-m-d H:i:s'),
        );
    }

    // ------------------------------------------------------------------
    // 6. Paridade da regra «dia não letivo»
    // ------------------------------------------------------------------

    /**
     * Um sábado e um domingo dentro do intervalo, fronteiras inclusivas dos
     * dois lados, um dia isolado: o ciclo de MaterializeLessonsForRange, o de
     * ScheduleOccurrences::between() e isNonTeachingDateIn() excluem as
     * MESMAS datas.
     */
    #[Test]
    public function the_three_non_teaching_day_rules_agree_on_single_days_ranges_and_weekends(): void
    {
        $this->everyDaySlots();
        $this->exception('2026-10-07', '2026-10-07');   // dia isolado (quarta)
        $this->exception('2026-10-12', '2026-10-14');   // segunda a quarta
        $this->exception('2026-10-16', '2026-10-19');   // sexta a segunda, com sáb e dom dentro

        $expected = [
            '2026-10-07', '2026-10-12', '2026-10-13', '2026-10-14',
            '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19',
        ];

        $this->assertSame($expected, $this->excludedByMaterialize('2026-10-05', '2026-10-25'));
        $this->assertSame($expected, $this->excludedByBetween('2026-10-05', '2026-10-25'));
        $this->assertSame($expected, $this->excludedByPoint('2026-10-05', '2026-10-25'));
    }

    /** As fronteiras da JANELA pedida coincidem com as da exceção: o último dia entra. */
    #[Test]
    public function the_three_rules_agree_when_the_requested_window_ends_exactly_on_the_exception_edges(): void
    {
        $this->everyDaySlots();
        $this->exception('2026-10-12', '2026-10-14');

        foreach ([['2026-10-14', '2026-10-14', ['2026-10-14']], ['2026-10-12', '2026-10-12', ['2026-10-12']], ['2026-10-11', '2026-10-11', []], ['2026-10-15', '2026-10-15', []]] as [$from, $to, $expected]) {
            $this->assertSame($expected, $this->excludedByMaterialize($from, $to), "materialize {$from}");
            $this->assertSame($expected, $this->excludedByBetween($from, $to), "between {$from}");
            $this->assertSame($expected, $this->excludedByPoint($from, $to), "point {$from}");
        }
    }

    /**
     * Colunas `date` guardadas como «Y-m-d 00:00:00» (e, no SQLite, até com
     * hora): as três regras leem o mesmo dia.
     */
    #[Test]
    public function the_three_rules_agree_when_the_date_columns_are_stored_with_a_time_part(): void
    {
        $this->everyDaySlots();
        $first = $this->exception('2026-10-21', '2026-10-22');
        $second = $this->exception('2026-10-26', '2026-10-27');
        DB::table('academic_calendar_exceptions')->where('id', $first->id)->update([
            'starts_on' => '2026-10-21 00:00:00', 'ends_on' => '2026-10-22 00:00:00',
        ]);
        DB::table('academic_calendar_exceptions')->where('id', $second->id)->update([
            'starts_on' => '2026-10-26 00:00:00', 'ends_on' => '2026-10-27 23:59:59',
        ]);

        $expected = ['2026-10-21', '2026-10-22', '2026-10-26', '2026-10-27'];

        $this->assertSame($expected, $this->excludedByMaterialize('2026-10-19', '2026-10-29'));
        $this->assertSame($expected, $this->excludedByBetween('2026-10-19', '2026-10-29'));
        $this->assertSame($expected, $this->excludedByPoint('2026-10-19', '2026-10-29'));
    }

    // ------------------------------------------------------------------
    // Ajudantes
    // ------------------------------------------------------------------

    private function weeklySlot(int $weekday, ?SchoolClass $class = null, string $startsAt = '09:30'): RecurringLessonSlot
    {
        $class ??= $this->schoolClass;

        return $this->makeSlot([
            'class_id' => $class->id,
            'day_of_week' => $weekday,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes(50)->format('H:i'),
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
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

    private function everyDaySlots(): void
    {
        foreach (range(1, 7) as $weekday) {
            $this->weeklySlot($weekday);
        }
    }

    private function revise(RecurringLessonSlot $slot, string $effectiveFrom): RecurringLessonSlot
    {
        return $this->inTenant($this->organization, fn (): RecurringLessonSlot => app(ReviseRecurringLessonSlot::class)->execute($slot, $effectiveFrom, [
            'class_group_id' => null,
            'day_of_week' => $slot->day_of_week,
            'starts_at' => '09:30',
            'ends_at' => '10:20',
            'ends_on' => null,
        ]));
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
            ->for($this->inTenant($this->organization, fn (): AcademicYear => $this->schoolClass->academicYear()->firstOrFail()))
            ->create([
                'type' => AcademicCalendarExceptionType::Holiday,
                'title' => 'Exceção '.$from,
                'starts_on' => $from,
                'ends_on' => $to,
            ]));
    }

    /** @return Builder<AcademicCalendarException> */
    private function exceptionRows()
    {
        return AcademicCalendarException::withoutGlobalScopes();
    }

    private function resequence(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));
    }

    /**
     * @return array<int, int|null>
     */
    private function deleteViaAction(Lesson $lesson): array
    {
        return $this->inTenant($this->organization, fn (): array => app(DeleteLesson::class)->execute($lesson, $this->teacher));
    }

    private function deleteLessonAt(string $startsAt): void
    {
        $lesson = $this->inTenant($this->organization, fn (): Lesson => Lesson::query()->where('starts_at', $startsAt)->firstOrFail());
        $this->deleteViaAction($lesson);
    }

    /** @return list<string> */
    private function lessonStarts(): array
    {
        return array_column($this->lessonSlots(), 0);
    }

    /** @return list<array{0: string, 1: int|null}> */
    private function lessonSlots(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->orderBy('starts_at')->orderBy('id')->get()
            ->map(fn (Lesson $lesson): array => [$lesson->starts_at->format('Y-m-d H:i:s'), $lesson->recurring_lesson_slot_id])
            ->all());
    }

    /** @return list<array{slot: int, occurs_at: string}> */
    private function cancellations(): array
    {
        return $this->inTenant($this->organization, fn (): array => CancelledLessonOccurrence::query()
            ->orderBy('occurs_at')->get()
            ->map(fn (CancelledLessonOccurrence $mark): array => [
                'slot' => $mark->recurring_lesson_slot_id,
                'occurs_at' => $mark->occurs_at->format('Y-m-d H:i:s'),
            ])->all());
    }

    /**
     * @param  list<Lesson>  $lessons
     * @return list<int|null>
     */
    private function numbers(array $lessons): array
    {
        return $this->inTenant($this->organization, fn (): array => array_map(
            fn (Lesson $lesson): ?int => Lesson::query()->whereKey($lesson->getKey())->value('lesson_number'),
            $lessons,
        ));
    }

    /** @return list<int|null> */
    private function classNumbers(SchoolClass $class): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('class_id', $class->id)->orderBy('starts_at')->pluck('lesson_number')
            ->map(fn (mixed $number): ?int => $number === null ? null : (int) $number)->all());
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

    private function actingAsMember(User $user): self
    {
        return $this->actingAs($user)->withSession(['organization_id' => $this->organization->id]);
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        return AssertableInertia::fromTestResponse($response)->toArray()['props'];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $names
     * @return list<string> os caminhos cujo último segmento é um dos nomes
     */
    private function pathsEndingIn(array $data, array $names, string $prefix = ''): array
    {
        $found = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (in_array((string) $key, $names, true)) {
                $found[] = $path;
            }

            if (is_array($value)) {
                $found = array_merge($found, $this->pathsEndingIn($value, $names, $path));
            }
        }

        return $found;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<mixed>
     */
    private function valuesAtKey(array $data, string $name): array
    {
        $values = [];

        foreach ($data as $key => $value) {
            if ((string) $key === $name && ! is_array($value)) {
                $values[] = $value;
            }

            if (is_array($value)) {
                $values = array_merge($values, $this->valuesAtKey($value, $name));
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function allDates(string $from, string $to): array
    {
        $dates = [];

        for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /** @return list<string> */
    private function excludedByMaterialize(string $from, string $to): array
    {
        $this->inTenant($this->organization, fn () => Lesson::query()->delete());
        $this->inTenant($this->organization, fn () => CancelledLessonOccurrence::query()->delete());
        $this->materialize($from, $to);
        $created = array_map(fn (string $startsAt): string => substr($startsAt, 0, 10), $this->lessonStarts());

        return array_values(array_diff($this->allDates($from, $to), $created));
    }

    /** @return list<string> */
    private function excludedByBetween(string $from, string $to): array
    {
        $dates = array_map(
            fn (array $o): string => $o['starts_at']->format('Y-m-d'),
            $this->inTenant($this->organization, fn (): array => app(ScheduleOccurrences::class)->between(
                $this->schoolClass, CarbonImmutable::parse($from), CarbonImmutable::parse($to),
            )),
        );

        return array_values(array_diff($this->allDates($from, $to), $dates));
    }

    /** @return list<string> */
    private function excludedByPoint(string $from, string $to): array
    {
        $year = $this->inTenant($this->organization, fn (): AcademicYear => $this->schoolClass->academicYear()->firstOrFail());

        return array_values(array_filter(
            $this->allDates($from, $to),
            fn (string $date): bool => $this->inTenant($this->organization, fn (): bool => app(ScheduleOccurrences::class)->isNonTeachingDateIn($year, $date)),
        ));
    }
}
