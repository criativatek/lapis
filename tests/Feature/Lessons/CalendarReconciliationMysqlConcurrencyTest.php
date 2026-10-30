<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\CalendarExceptionChange;
use App\Actions\Lessons\CalendarReconciliationPlan;
use App\Actions\Lessons\CalendarReconciliationScope;
use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Actions\Lessons\ReconcileLessonsWithCalendarExceptions;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonSummary;
use ArrayObject;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConnectsToMysqlServer;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;
use Throwable;

/**
 * A RECONCILIAÇÃO COM O CALENDÁRIO CONTRA UM MySQL A SÉRIO — o que o SQLite não
 * sabe mostrar: o retrato (REPEATABLE READ) de uma transação InnoDB, os
 * bloqueios FOR UPDATE, os deadlocks e o auto-incremento que o ROLLBACK não
 * devolve.
 *
 *  1. Um rascunho de faltas gravado por OUTRA ligação a meio da execução —
 *     depois da pré-visualização, na primeira leitura simples da transação —
 *     nunca é apagado em cascata: ou a outra ligação espera pelos bloqueios
 *     (que a execução toma ANTES de qualquer leitura simples) e não grava, ou
 *     a execução vê-o e recusa. Nunca as duas coisas ao contrário.
 *  2. A ORDEM DOS BLOQUEIOS. A pré-visualização do responsável não toma
 *     bloqueios iniciais e, quando retira uma aula, prende a turma, depois a
 *     aula, depois o sumário; a do professor toma exatamente os bloqueios iniciais da
 *     execução, pela mesma ordem (ano, turmas, exceções, aulas) e antes da
 *     primeira leitura simples. E o intervalo de aulas que cada âmbito prende.
 *  3. Duas B sem aulas abertas depois: o plano materializa aulas (ULID
 *     aleatório, id de auto-incremento que o InnoDB não devolve) e, mesmo
 *     assim, a pré-visualização e a execução dão o MESMO token.
 *  4. SEM DEADLOCK com quem escreve nas aulas ao mesmo tempo: um SEGUNDO
 *     PROCESSO PHP (um processo não pode esperar pelo seu próprio bloqueio)
 *     toma o primeiro bloqueio de SaveLessonSummary (a aula) ou de
 *     RecordLessonOutcome (a turma), espera até o servidor mostrar a
 *     pré-visualização do professor À ESPERA dele, e só então acaba a ação
 *     verdadeira. Com a ordem antiga (aulas e sumários antes da turma, sem
 *     bloqueios iniciais) isto dava 1213.
 *
 * OPT-IN, como as outras garantias de MySQL (a primeira corrida migra e semeia
 * a aplicação inteira numa base própria, reutilizada depois):
 *
 *     CALENDAR_RECONCILE_MYSQL_SCRATCH=1 DB_HOST=127.0.0.1 DB_PORT=3341 DB_USERNAME=root \
 *         php artisan test --filter=CalendarReconciliationMysqlConcurrencyTest
 *
 * Os dados ficam GRAVADOS (a segunda ligação tem de os ver): cada teste cria a
 * sua própria organização, e tudo o que lê é dessa organização.
 */
class CalendarReconciliationMysqlConcurrencyTest extends TestCase
{
    use BuildsLessonFixtures, ConnectsToMysqlServer;

    private const DATABASE = 'lapis_calendar_reconcile_scratch';

    private const CONNECTION = 'calendar_reconcile_scratch';

    /** Os bloqueios iniciais da execução, pela ordem (ver ReconcileLessonsWithCalendarExceptions). */
    private const UP_FRONT = ['academic_years', 'class_teachers', 'classes', 'academic_calendar_exceptions', 'lessons'];

    protected function setUp(): void
    {
        parent::setUp();

        if (env('CALENDAR_RECONCILE_MYSQL_SCRATCH') !== '1') {
            $this->markTestSkipped('Defina CALENDAR_RECONCILE_MYSQL_SCRATCH=1 para provar a reconciliação com o calendário em MySQL.');
        }

        if (! $this->mysqlIsListening()) {
            $this->markTestSkipped('Não há MySQL à escuta em '.$this->host().':'.$this->port().'.');
        }

        $this->useScratchAsTheDefaultConnection();
        $this->ensureSchema();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    #[Test]
    public function an_attendance_draft_committed_by_another_session_during_execute_is_never_cascade_deleted(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->exception('2026-10-22', '2026-10-22');
        $target = $this->lessonAt('2026-10-22');
        $enrollment = $this->enroll('Ana Silva');

        $plan = $this->preview();
        $this->assertSame(1, $plan->totals['retired']);

        // A OUTRA SESSÃO: grava o rascunho e faz commit na PRIMEIRA leitura
        // simples da transação da execução — o pior momento para um retrato
        // REPEATABLE READ. Espera no máximo 1 s por um bloqueio.
        $other = DB::connection(self::CONNECTION.'_b');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $state = ['fired' => false, 'committed' => null, 'error' => null];

        DB::listen(function (QueryExecuted $query) use ($other, $target, $enrollment, &$state): void {
            if ($state['fired'] || $query->connectionName !== self::CONNECTION || DB::connection(self::CONNECTION)->transactionLevel() < 1) {
                return;
            }

            if (! self::isPlainSelect(strtolower(ltrim($query->sql)))) {
                return;
            }

            $state['fired'] = true;

            try {
                $other->table('lesson_attendances')->insert([
                    'ulid' => strtolower((string) Str::ulid()),
                    'organization_id' => $this->organization->id,
                    'lesson_id' => $target->id,
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $enrollment->student_id,
                    'status' => 'absent',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $state['committed'] = true;
            } catch (QueryException $exception) {
                $state['committed'] = false;
                $state['error'] = $exception->getMessage();
            }
        });

        try {
            $this->execute($plan->token);
            $outcome = 'executed';
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan_token', $exception->errors());
            $outcome = 'refused';
        }

        $this->assertTrue($state['fired'], 'A outra sessão chegou a tentar gravar.');

        // A REGRA: um rascunho que ficou gravado nunca é apagado.
        $drafts = $other->table('lesson_attendances')->where('lesson_id', $target->id)->count();

        if ($state['committed'] === true) {
            $this->assertSame(1, $drafts, 'O rascunho gravado foi apagado em cascata.');
            $this->assertSame('refused', $outcome);
            $this->assertTrue($other->table('lessons')->where('id', $target->id)->exists());
        } else {
            $this->assertSame(0, $drafts);
        }

        // E COMO A EXECUÇÃO BLOQUEIA AS AULAS ANTES DA PRIMEIRA LEITURA SIMPLES,
        // a outra sessão nem consegue gravar: espera pelo bloqueio da aula e
        // desiste (1205), e a execução retira a aula vazia.
        $this->assertFalse($state['committed'], 'A outra sessão gravou a meio da execução: as aulas não estavam bloqueadas. '.$state['error']);
        $this->assertStringContainsString('1205', (string) $state['error']);
        $this->assertSame('executed', $outcome);
    }

    /**
     * Só se contam as consultas DENTRO de uma transação: as que os ajudantes do
     * próprio teste fazem fora dela (o ano letivo de `year()`, por exemplo) não
     * são da ação — era uma dessas que este teste confundia com a primeira
     * leitura da execução. O ano lê-se antes, de qualquer forma.
     */
    #[Test]
    public function the_owner_preview_takes_no_up_front_locks_and_the_teacher_preview_takes_the_executions_in_the_same_order(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $this->exception('2026-10-22', '2026-10-22');
        // Uma linha de sumário VAZIA na aula que o responsável retira: apagá-la
        // atualiza a aula (`summary_version`). Tem de vir DEPOIS de prender a aula.
        $this->summary('2026-11-05', '');
        $year = $this->year();
        $change = CalendarExceptionChange::create(['type' => AcademicCalendarExceptionType::Holiday->value, 'title' => 'Greve', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05']);

        $owner = $this->recording(fn (): CalendarReconciliationPlan => $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => app(ReconcileLessonsWithCalendarExceptions::class)
            ->preview(CalendarReconciliationScope::forOrganizationOwner($year), $this->teacher, $change)));
        $teacherPreview = $this->recording(fn (): CalendarReconciliationPlan => $this->preview($year));
        $execution = $this->recording(fn (): CalendarReconciliationPlan => $this->execute($teacherPreview['result']->token, $year));

        // O RESPONSÁVEL: nenhum bloqueio INICIAL (antes da primeira leitura
        // simples) e nunca o ano, as exceções ou `class_teachers`. Para retirar,
        // a ordem de quem escreve nas aulas: a TURMA primeiro (RecordLessonOutcome
        // prende turma → aula → aulas seguintes; sem ela, os dois podiam ficar
        // cada um com aulas do outro), depois a aula, e só então o sumário dela
        // (a ordem de SaveLessonSummary).
        $this->assertSame(1, $owner['result']->totals['retired']);
        $this->assertSame([], $this->upFrontLocks($owner['queries']), 'A pré-visualização do responsável tomou bloqueios iniciais.');
        $this->assertSame([], array_values(array_filter(
            array_map(fn (array $query): ?string => self::lockedTable($query['sql']), $owner['queries']),
            fn (?string $table): bool => in_array($table, ['academic_years', 'class_teachers', 'academic_calendar_exceptions'], true),
        )), 'A pré-visualização do responsável prendeu o ano, as exceções ou as turmas do professor.');
        $this->assertNull($this->firstIndex($owner['queries'], fn (string $sql): bool => self::lockedTable($sql) === 'lessons' && str_contains($sql, '`starts_at` >=')), 'A pré-visualização do responsável prendeu um intervalo de aulas.');
        $classLock = $this->firstIndex($owner['queries'], fn (string $sql): bool => self::lockedTable($sql) === 'classes');
        $lessonLock = $this->firstIndex($owner['queries'], fn (string $sql): bool => self::lockedTable($sql) === 'lessons');
        $summaryTouch = $this->firstIndex($owner['queries'], fn (string $sql): bool => str_contains($sql, '`lesson_summaries`') && (str_contains($sql, 'for update') || str_starts_with($sql, 'delete')));
        $this->assertNotNull($classLock, 'A pré-visualização do responsável retirou sem prender a turma.');
        $this->assertNotNull($lessonLock);
        $this->assertNotNull($summaryTouch);
        $this->assertLessThan($lessonLock, $classLock, 'A aula foi presa antes da turma.');
        $this->assertLessThan($summaryTouch, $lessonLock, 'O sumário foi preso antes da aula.');

        // O PROFESSOR: a pré-visualização e a execução tomam os MESMOS
        // bloqueios iniciais, pela mesma ordem, antes da primeira leitura simples.
        $this->assertSame(1, $execution['result']->totals['retired']);

        foreach (['pré-visualização' => $teacherPreview, 'execução' => $execution] as $label => $run) {
            $this->assertSame(self::UP_FRONT, $this->upFrontLocks($run['queries']), 'Os bloqueios iniciais da '.$label.'.');
        }
    }

    /**
     * O INTERVALO DE AULAS PRESO: o responsável só prende os dias da exceção
     * que grava (e nada ao eliminar); o professor, do primeiro dia de exceção
     * até ao fim do ano (a cadeia).
     */
    #[Test]
    public function each_scope_locks_only_the_lessons_it_can_touch(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $existing = $this->exception('2026-10-08', '2026-10-08');
        $year = $this->year();
        $ownerScope = CalendarReconciliationScope::forOrganizationOwner($year);

        // Criar 05/11 a 12/11.
        $create = CalendarExceptionChange::create(['type' => AcademicCalendarExceptionType::Holiday->value, 'title' => 'Greve', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-12']);
        $plan = $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => app(ReconcileLessonsWithCalendarExceptions::class)->preview($ownerScope, $this->teacher, $create));
        $created = $this->recording(fn (): CalendarReconciliationPlan => $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => app(ReconcileLessonsWithCalendarExceptions::class)
            ->execute($ownerScope, $plan->token, $this->teacher, $create)));
        $this->assertSame(2, $created['result']->totals['retired']);
        $this->assertSame([['2026-11-05', '2026-11-13']], $this->lessonLockRanges($created['queries']));

        // Eliminar: nada a retirar, nenhuma aula presa.
        $delete = CalendarExceptionChange::delete($existing);
        $plan = $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => app(ReconcileLessonsWithCalendarExceptions::class)->preview($ownerScope, $this->teacher, $delete));
        $deleted = $this->recording(fn (): CalendarReconciliationPlan => $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => app(ReconcileLessonsWithCalendarExceptions::class)
            ->execute($ownerScope, $plan->token, $this->teacher, $delete)));
        $this->assertSame([], $this->lessonLockRanges($deleted['queries']));
        $this->assertNull($this->firstIndex($deleted['queries'], fn (string $sql): bool => self::lockedTable($sql) === 'lessons'));

        // O professor: do primeiro dia de exceção (05/11, a única que resta) em diante, sem fim.
        $token = $this->preview($year)->token;
        $teacher = $this->recording(fn (): CalendarReconciliationPlan => $this->execute($token, $year));
        $this->assertSame([['2026-11-05', null]], $this->lessonLockRanges($teacher['queries']));
    }

    #[Test]
    public function two_planned_lessons_that_materialize_their_destinations_keep_the_same_token_on_mysql(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-10-29');
        $this->summary('2026-10-22', 'P22');
        $this->summary('2026-10-29', 'P29');
        $this->exception('2026-10-22', '2026-10-29');

        $first = $this->preview();
        $second = $this->preview();
        $this->assertSame($first->token, $second->token, 'O ROLLBACK não devolve os ids: o token não pode depender deles.');

        $executed = $this->execute($first->token);

        $this->assertSame($first->toArray(), $executed->toArray());
        $this->assertSame(2, $executed->totals['shifted']);
    }

    /**
     * A pré-visualização do professor, com uma B que materializa o destino, e
     * um SaveLessonSummary noutro processo sobre uma aula da cadeia. O outro
     * processo prende a aula (o primeiro passo de SaveLessonSummary), espera
     * até o servidor mostrar a pré-visualização à espera dele, e grava o
     * sumário. Sem deadlock: a pré-visualização espera pela aula ANTES de
     * tocar no sumário dela.
     */
    #[Test]
    public function the_teacher_preview_and_a_concurrent_summary_save_never_deadlock(): void
    {
        $this->plannedLessonThatMaterializes();
        $chained = $this->lessonAt('2026-10-29');

        $child = $this->secondSession('summary', $chained->id);
        $preview = $this->previewWhileTheOtherSessionHoldsItsLock($child);
        $result = $this->finishSecondSession($child);

        $this->assertSame('done', $result['outcome'], json_encode($result) ?: '');
        $this->assertTrue($result['waited'], 'A pré-visualização nunca ficou à espera do outro processo: a concorrência não foi exercida. '.json_encode($result));
        $this->assertNull($preview['error'], 'A pré-visualização falhou: '.$preview['error']);
        $this->assertSame(1, $preview['plan']?->totals['shifted']);
        $this->assertSame('Q29 editado', $this->summaryRow('2026-10-29')->content);
    }

    /**
     * O mesmo com RecordLessonOutcome, que prende a TURMA e depois as aulas —
     * e a pré-visualização materializa (prende a turma) depois de deslocar.
     * Sem bloqueios iniciais, a pré-visualização prendia as aulas da cadeia e
     * esperava pela turma enquanto o outro processo, com a turma, esperava
     * pelas aulas: 1213.
     */
    #[Test]
    public function the_teacher_preview_and_a_concurrent_outcome_never_deadlock(): void
    {
        $this->plannedLessonThatMaterializes();
        // Com planeamento, para RecordLessonOutcome o fazer descer pelas aulas
        // abertas seguintes (FOR UPDATE) — entre elas a B que a
        // pré-visualização desloca.
        $this->summary('2026-10-08', 'P08');
        $past = $this->lessonAt('2026-10-08');

        $child = $this->secondSession('outcome', $past->id);
        $preview = $this->previewWhileTheOtherSessionHoldsItsLock($child);
        $result = $this->finishSecondSession($child);

        $this->assertSame('done', $result['outcome'], json_encode($result) ?: '');
        $this->assertTrue($result['waited'], 'A pré-visualização nunca ficou à espera do outro processo: a concorrência não foi exercida. '.json_encode($result));
        $this->assertNull($preview['error'], 'A pré-visualização falhou: '.$preview['error']);
        $this->assertSame(1, $preview['plan']?->totals['shifted']);
        $this->assertNotNull($this->lessonAt('2026-10-08')->outcome);
        $this->assertSame('P08', $this->summaryRow('2026-10-15')->content);
    }

    /**
     * NENHUMA AULA LECIONADA MUDA DE NÚMERO, NEM QUANDO É FECHADA A MEIO. O
     * responsável grava uma exceção RETROATIVA (08/10) com `reconcile`; a
     * execução só prende as aulas desse dia. Depois da sua primeira leitura
     * simples, OUTRA sessão marca como lecionada a aula de hoje (22/10, Lição
     * 4) — MarkLessonAsTaught prende só a aula — e faz commit. A renumeração,
     * calculada sobre o retrato, via-a aberta e dava-lhe a Lição 3, por cima de
     * uma lecionada; as duas verificações, lidas do mesmo retrato, não davam
     * por nada. Agora lê com bloqueio: ou recusa (o plano mudou), ou preserva o
     * número. Nunca o altera.
     */
    #[Test]
    public function a_lesson_marked_as_taught_by_another_session_during_execute_never_changes_number(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', 'Europe/Lisbon'));
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-11-30');
        $first = $this->lessonAt('2026-10-01');
        DB::table('lessons')->where('id', $first->id)->update(['status' => 'taught', 'outcome' => 'taught']);
        $today = $this->lessonAt('2026-10-22');
        $this->assertSame(4, $today->lesson_number);

        $year = $this->year();
        $scope = CalendarReconciliationScope::forOrganizationOwner($year);
        $change = CalendarExceptionChange::create(['type' => AcademicCalendarExceptionType::Holiday->value, 'title' => 'Greve retroativa', 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-08']);
        $plan = $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => app(ReconcileLessonsWithCalendarExceptions::class)->preview($scope, $this->teacher, $change));
        $this->assertSame(1, $plan->totals['retired']);

        $other = DB::connection(self::CONNECTION.'_b');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $state = ['fired' => false, 'committed' => null, 'error' => null];

        DB::listen(function (QueryExecuted $query) use ($other, $today, &$state): void {
            if ($state['fired'] || $query->connectionName !== self::CONNECTION || DB::connection(self::CONNECTION)->transactionLevel() < 1
                || ! self::isPlainSelect(strtolower(ltrim($query->sql)))) {
                return;
            }

            $state['fired'] = true;

            try {
                // O que MarkLessonAsTaught escreve, numa transação que só prende a aula.
                $other->transaction(function () use ($other, $today): void {
                    $other->table('lessons')->where('id', $today->id)->lockForUpdate()->first();
                    $other->table('lessons')->where('id', $today->id)->update([
                        'status' => 'taught', 'outcome' => 'taught', 'outcome_recorded_at' => now(), 'updated_at' => now(),
                    ]);
                });
                $state['committed'] = true;
            } catch (QueryException $exception) {
                $state['committed'] = false;
                $state['error'] = $exception->getMessage();
            }
        });

        try {
            $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => app(ReconcileLessonsWithCalendarExceptions::class)->execute($scope, $plan->token, $this->teacher, $change));
            $outcome = 'executed';
        } catch (ValidationException $exception) {
            $outcome = 'refused';
        }

        $this->assertTrue($state['fired']);
        $this->assertTrue($state['committed'], 'A outra sessão não chegou a fechar a aula: a corrida não foi exercida. '.$state['error']);

        $row = $other->table('lessons')->where('id', $today->id)->first();
        $this->assertSame('taught', $row->outcome);
        $this->assertSame(4, (int) $row->lesson_number, 'O número de uma aula lecionada mudou ('.$outcome.').');
        $this->assertSame(1, (int) $other->table('lessons')->where('id', $first->id)->value('lesson_number'));
    }

    /**
     * UM RASCUNHO DE FALTAS NUMA AULA FORA DE VIGÊNCIA, FORA DO INTERVALO
     * PRESO. O tempo de quinta passou a começar a 08/10: a aula de 01/10 está
     * fora de vigência e vazia. A B de 22/10 materializa o destino, e a
     * materialização retira as aulas vazias fora de vigência
     * (ReconcileLessonsWithSlotValidity). Depois da primeira leitura simples da
     * execução, OUTRA sessão lança um rascunho de faltas nessa aula de 01/10 (o
     * professor só prende de 22/10 em diante). O `withExists` daquela consulta
     * lia do retrato: a aula era apagada e o rascunho ia em cascata. Agora a
     * aula fica — e o rascunho com ela.
     */
    #[Test]
    public function an_attendance_draft_on_an_out_of_validity_lesson_outside_the_locked_range_is_never_cascade_deleted(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->materialize('2026-10-01', '2026-10-22');
        $this->inTenant($this->organization, fn () => $slot->forceFill(['starts_on' => '2026-10-08'])->save());
        $this->summary('2026-10-22', 'P22');
        $this->exception('2026-10-22', '2026-10-22');
        $early = $this->lessonAt('2026-10-01');
        $enrollment = $this->enroll('Ana Silva');

        $plan = $this->preview();
        $this->assertSame(1, $plan->totals['shifted']);

        $other = DB::connection(self::CONNECTION.'_b');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $state = ['fired' => false, 'committed' => null, 'error' => null];

        DB::listen(function (QueryExecuted $query) use ($other, $early, $enrollment, &$state): void {
            if ($state['fired'] || $query->connectionName !== self::CONNECTION || DB::connection(self::CONNECTION)->transactionLevel() < 1
                || ! self::isPlainSelect(strtolower(ltrim($query->sql)))) {
                return;
            }

            $state['fired'] = true;

            try {
                $other->table('lesson_attendances')->insert([
                    'ulid' => strtolower((string) Str::ulid()),
                    'organization_id' => $this->organization->id,
                    'lesson_id' => $early->id,
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $enrollment->student_id,
                    'status' => 'absent',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $state['committed'] = true;
            } catch (QueryException $exception) {
                $state['committed'] = false;
                $state['error'] = $exception->getMessage();
            }
        });

        try {
            $this->execute($plan->token);
        } catch (ValidationException) {
            // Recusar (o plano mudou) também respeita a regra; apagar o rascunho, não.
        }

        $this->assertTrue($state['committed'], 'A outra sessão não chegou a gravar: a corrida não foi exercida. '.$state['error']);
        $this->assertSame(1, $other->table('lesson_attendances')->where('lesson_id', $early->id)->count(), 'O rascunho foi apagado em cascata.');
        $this->assertTrue($other->table('lessons')->where('id', $early->id)->exists());
    }

    /**
     * UM DEADLOCK DENTRO DO PONTO DE GRAVAÇÃO DA FASE 1. O InnoDB desfaz a
     * transação inteira da vítima — e os pontos de gravação com ela. Simula-se
     * isso mesmo: na primeira escrita num sumário dentro do ponto de gravação,
     * a transação é desfeita no servidor e a instrução falha com 1213. Antes, o
     * `ROLLBACK TO SAVEPOINT` falhava com 1305, o contador ficava em 2 e era o
     * 1305 que subia (um 500 «SAVEPOINT trans2 does not exist»). Agora sobe o
     * 1213 original, sem transação nenhuma aberta, e nada fica escrito.
     */
    #[Test]
    public function a_deadlock_inside_the_savepoint_surfaces_as_itself_with_no_transaction_left(): void
    {
        $this->plannedLessonThatMaterializes();
        $year = $this->year();
        $before = $this->summaryRow('2026-10-22')->content;
        $deadlocks = $this->deadlockOnSummaryWrites(PHP_INT_MAX);

        try {
            $this->preview($year);
            $this->fail('A pré-visualização devia falhar.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Deadlock found', $exception->getMessage());
            $this->assertStringNotContainsString('SAVEPOINT', $exception->getMessage());
        }

        $this->assertSame(0, DB::connection(self::CONNECTION)->transactionLevel());
        $this->assertSame(1, $deadlocks['count']);
        $this->assertSame($before, $this->summaryRow('2026-10-22')->content);
    }

    /**
     * A EXECUÇÃO REPETE-SE DEPOIS DE UM DEADLOCK — recalculando o plano e o
     * token sob bloqueios novos —, e só até ao limite: um deadlock na primeira
     * tentativa não chega ao professor; deadlocks seguidos sobem como o 1213
     * original, sem transação aberta e sem nada escrito.
     */
    #[Test]
    public function the_execution_retries_after_a_deadlock(): void
    {
        $this->plannedLessonThatMaterializes();
        $year = $this->year();
        $token = $this->preview($year)->token;

        $deadlocks = $this->deadlockOnSummaryWrites(1);
        $plan = $this->execute($token, $year);

        $this->assertSame(1, $deadlocks['count']);
        $this->assertSame(1, $plan->totals['shifted']);
        $this->assertSame(0, DB::connection(self::CONNECTION)->transactionLevel());
        $this->assertSame('P22', $this->summaryRow('2026-10-29')->content);
    }

    #[Test]
    public function the_execution_gives_up_after_repeated_deadlocks_with_the_original_error(): void
    {
        $this->plannedLessonThatMaterializes();
        $year = $this->year();
        $token = $this->preview($year)->token;
        $before = $this->summaryRow('2026-10-22')->content;
        $always = $this->deadlockOnSummaryWrites(PHP_INT_MAX);

        try {
            $this->execute($token, $year);
            $this->fail('A execução devia desistir.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Deadlock found', $exception->getMessage());
        }

        $this->assertSame(3, $always['count']);
        $this->assertSame(0, DB::connection(self::CONNECTION)->transactionLevel());
        $this->assertSame($before, $this->summaryRow('2026-10-22')->content);
    }

    // ------------------------------------------------------------------
    // Ajudantes
    // ------------------------------------------------------------------

    /**
     * Um deadlock (1213) nas próximas `$times` escritas em `lesson_summaries`
     * feitas dentro do ponto de gravação da fase 1 (nível 2): a transação é
     * desfeita NO SERVIDOR — o que o InnoDB faz à vítima — e a instrução falha.
     *
     * @return ArrayObject<string, int>
     */
    private function deadlockOnSummaryWrites(int $times): ArrayObject
    {
        $state = new ArrayObject(['count' => 0, 'left' => $times]);

        DB::connection(self::CONNECTION)->beforeExecuting(function (string $sql) use ($state): void {
            $connection = DB::connection(self::CONNECTION);

            if ($state['left'] < 1 || $connection->transactionLevel() !== 2
                || preg_match('/^\s*(insert into|update|delete from) `lesson_summaries`/i', $sql) !== 1) {
                return;
            }

            $state['left']--;
            $state['count']++;
            $connection->getPdo()->exec('ROLLBACK');

            $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

            throw new QueryException(self::CONNECTION, $sql, [], $pdo);
        });

        return $state;
    }

    /** Quinta: B a 22/10 (feriado), 29/10 cheia, e mais nada — o destino materializa-se (05/11). */
    private function plannedLessonThatMaterializes(): void
    {
        $this->thursdaySlot();
        $this->materialize('2026-10-01', '2026-10-29');
        $this->summary('2026-10-22', 'P22');
        $this->summary('2026-10-29', 'Q29');
        $this->exception('2026-10-22', '2026-10-22');
    }

    /**
     * @param  array{process: resource, base: string, script: string}  $child
     * @return array{plan: CalendarReconciliationPlan|null, error: string|null}
     */
    private function previewWhileTheOtherSessionHoldsItsLock(array $child): array
    {
        // Um ficheiro, e não uma linha no stdout: em Windows, ler um pipe de
        // proc_open só volta quando o processo acaba.
        $deadline = microtime(true) + 60;

        while (! is_file($child['base'].'.locked') && microtime(true) < $deadline && proc_get_status($child['process'])['running']) {
            usleep(20_000);
        }

        $this->assertFileExists($child['base'].'.locked', 'O outro processo não chegou a prender a linha: '.@file_get_contents($child['base'].'.out').' '.@file_get_contents($child['base'].'.err'));

        DB::connection(self::CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 30');
        $year = $this->year();

        try {
            return ['plan' => $this->preview($year), 'error' => null];
        } catch (QueryException $exception) {
            return ['plan' => null, 'error' => $exception->getMessage()];
        } catch (Throwable $exception) {
            proc_terminate($child['process']);

            throw $exception;
        }
    }

    /**
     * @return array{process: resource, base: string, script: string}
     */
    private function secondSession(string $mode, int $lessonId): array
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'calendar-reconcile-child-');
        $script = $base.'.php';

        file_put_contents($script, <<<'PHP'
<?php
// Um segundo processo: as ações verdadeiras, a mesma base, outra sessão.
[$self, $root, $mode, $organizationId, $userId, $lessonId, $base] = $argv;

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET SESSION innodb_lock_wait_timeout = 30');
$poll = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'), getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'));
$organization = App\Models\Organization::query()->findOrFail((int) $organizationId);
$user = App\Models\User::query()->findOrFail((int) $userId);
$waited = false;

try {
    app(App\Support\Tenancy\CurrentOrganization::class)->runFor($organization, function () use ($mode, $lessonId, $user, $poll, $base, &$waited): void {
        DB::transaction(function () use ($mode, $lessonId, $user, $poll, $base, &$waited): void {
            $lesson = App\Models\Lesson::query()->findOrFail((int) $lessonId);

            // O PRIMEIRO bloqueio da ação verdadeira: a aula (SaveLessonSummary)
            // ou a turma (RecordLessonOutcome).
            if ($mode === 'summary') {
                App\Models\Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            } else {
                App\Models\SchoolClass::query()->whereKey($lesson->class_id)->lockForUpdate()->first();
            }

            file_put_contents($base.'.locked', '1');

            // O servidor, e não um sleep, diz quando a outra sessão está à espera.
            $deadline = microtime(true) + 20;

            while (microtime(true) < $deadline) {
                // `data_lock_waits` (performance_schema): em 8.0.43 a espera
                // aparece aqui mesmo quando `innodb_trx` ainda não a mostra.
                if ((int) $poll->query('SELECT COUNT(*) FROM performance_schema.data_lock_waits')->fetchColumn() > 0
                    || (int) $poll->query("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT'")->fetchColumn() > 0) {
                    $waited = true;

                    break;
                }

                usleep(50_000);
            }

            // E o resto da ação verdadeira, na mesma transação.
            if ($mode === 'summary') {
                app(App\Actions\Lessons\SaveLessonSummary::class)->execute($lesson, ['content' => 'Q29 editado'], $user);
            } else {
                app(App\Actions\Lessons\RecordLessonOutcome::class)->execute($lesson, App\Models\LessonOutcome::ClassExternalActivity, $user, null, 'Visita de estudo');
            }
        });
    });

    echo json_encode(['outcome' => 'done', 'waited' => $waited]);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'failed', 'waited' => $waited, 'exception' => $e::class, 'message' => $e->getMessage()]);
}
PHP);

        $env = array_merge(array_filter(getenv(), 'is_string'), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => $this->host(),
            'DB_PORT' => $this->port(),
            'DB_DATABASE' => self::DATABASE,
            'DB_USERNAME' => (string) env('DB_USERNAME', 'root'),
            'DB_PASSWORD' => (string) env('DB_PASSWORD', ''),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ]);

        $process = proc_open(
            [PHP_BINARY, $script, base_path(), $mode, (string) $this->organization->id, (string) $this->teacher->id, (string) $lessonId, $base],
            [1 => ['file', $base.'.out', 'w'], 2 => ['file', $base.'.err', 'w']],
            $pipes,
            base_path(),
            $env,
        );

        $this->assertIsResource($process, 'não foi possível arrancar o segundo processo');

        return ['process' => $process, 'base' => $base, 'script' => $script];
    }

    /**
     * @param  array{process: resource, base: string, script: string}  $child
     * @return array<string, mixed>
     */
    private function finishSecondSession(array $child): array
    {
        proc_close($child['process']);
        $out = (string) @file_get_contents($child['base'].'.out');
        $err = (string) @file_get_contents($child['base'].'.err');

        foreach (['', '.php', '.out', '.err', '.locked'] as $suffix) {
            @unlink($child['base'].$suffix);
        }

        $decoded = json_decode((string) $out, true);
        $this->assertIsArray($decoded, "o segundo processo não devolveu JSON. stdout: {$out} stderr: {$err}");

        return $decoded;
    }

    /**
     * Corre e grava as consultas DESTA ligação feitas dentro de uma transação.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return array{result: T, queries: list<array{sql: string, bindings: array<int, mixed>}>}
     */
    private function recording(callable $callback): array
    {
        $queries = [];
        $recording = true;

        DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
            if (! $recording || $query->connectionName !== self::CONNECTION || DB::connection(self::CONNECTION)->transactionLevel() < 1) {
                return;
            }

            $queries[] = ['sql' => strtolower(ltrim($query->sql)), 'bindings' => $query->bindings];
        });

        try {
            $result = $callback();
        } finally {
            $recording = false;
        }

        return ['result' => $result, 'queries' => $queries];
    }

    /**
     * As tabelas presas (FOR UPDATE ou LOCK IN SHARE MODE) antes da primeira
     * leitura simples, pela ordem.
     *
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $queries
     * @return list<string>
     */
    private function upFrontLocks(array $queries): array
    {
        $tables = [];

        foreach ($queries as $query) {
            if (self::isPlainSelect($query['sql'])) {
                break;
            }

            $table = self::lockedTable($query['sql']);

            if ($table !== null) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Os intervalos [início, fim) — dias locais — das aulas presas por intervalo.
     *
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $queries
     * @return list<array{0: string, 1: string|null}>
     */
    private function lessonLockRanges(array $queries): array
    {
        $ranges = [];

        foreach ($queries as $query) {
            if (self::lockedTable($query['sql']) !== 'lessons' || ! str_contains($query['sql'], '`starts_at` >=')) {
                continue;
            }

            $dates = [];

            foreach ($query['bindings'] as $binding) {
                if ($binding instanceof DateTimeInterface) {
                    $dates[] = CarbonImmutable::instance($binding)->setTimezone('Europe/Lisbon')->toDateString();
                }
            }

            $ranges[] = [$dates[0] ?? '?', str_contains($query['sql'], '`starts_at` <') ? ($dates[1] ?? '?') : null];
        }

        return $ranges;
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $queries
     * @param  callable(string): bool  $matches
     */
    private function firstIndex(array $queries, callable $matches): ?int
    {
        foreach ($queries as $index => $query) {
            if ($matches($query['sql'])) {
                return $index;
            }
        }

        return null;
    }

    /** A tabela de um SELECT bloqueante, ou NULL. */
    private static function lockedTable(string $sql): ?string
    {
        if (! str_starts_with($sql, 'select') || self::isPlainSelect($sql)) {
            return null;
        }

        return preg_match('/ from `([a-z_]+)`/', $sql, $match) === 1 ? $match[1] : null;
    }

    /** Uma leitura NÃO bloqueante — a que, em REPEATABLE READ, fixa o retrato da transação. */
    private static function isPlainSelect(string $sql): bool
    {
        return str_starts_with($sql, 'select')
            && ! str_contains($sql, 'for update')
            && ! str_contains($sql, 'lock in share mode')
            && ! str_contains($sql, 'for share');
    }

    private function preview(?AcademicYear $year = null): CalendarReconciliationPlan
    {
        $year ??= $this->year();

        return $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => app(ReconcileLessonsWithCalendarExceptions::class)
            ->preview(CalendarReconciliationScope::forTeacher($this->teacher, $year), $this->teacher));
    }

    private function execute(string $token, ?AcademicYear $year = null): CalendarReconciliationPlan
    {
        $year ??= $this->year();

        return $this->inTenant($this->organization, fn (): CalendarReconciliationPlan => app(ReconcileLessonsWithCalendarExceptions::class)
            ->execute(CalendarReconciliationScope::forTeacher($this->teacher, $year), $token, $this->teacher));
    }

    private function year(): AcademicYear
    {
        return $this->inTenant($this->organization, fn (): AcademicYear => AcademicYear::query()->findOrFail($this->schoolClass->academic_year_id));
    }

    private function thursdaySlot(): void
    {
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
    }

    private function materialize(string $from, string $to): void
    {
        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $this->schoolClass, CarbonImmutable::parse($from), CarbonImmutable::parse($to), $this->teacher,
        ));
    }

    private function exception(string $from, string $to): AcademicCalendarException
    {
        return $this->inTenant($this->organization, fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)->for($this->year())
            ->create(['type' => AcademicCalendarExceptionType::Holiday, 'title' => 'Exceção '.$from, 'starts_on' => $from, 'ends_on' => $to]));
    }

    private function lessonAt(string $day): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::query()
            ->where('class_id', $this->schoolClass->id)->where('starts_at', $day.' 09:30:00')->firstOrFail());
    }

    private function summary(string $day, string $content): void
    {
        $lesson = $this->lessonAt($day);
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => $content]));
    }

    private function summaryRow(string $day): LessonSummary
    {
        $lesson = $this->lessonAt($day);

        return $this->inTenant($this->organization, fn (): LessonSummary => LessonSummary::query()->where('lesson_id', $lesson->id)->firstOrFail());
    }

    private function useScratchAsTheDefaultConnection(): void
    {
        $this->serverPdo()->exec('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $base = [
            'driver' => 'mysql',
            'host' => $this->host(),
            'port' => $this->port(),
            'database' => self::DATABASE,
            'username' => (string) env('DB_USERNAME', 'root'),
            'password' => (string) env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ];

        Config::set('database.connections.'.self::CONNECTION, $base);
        Config::set('database.connections.'.self::CONNECTION.'_b', $base);
        Config::set('database.default', self::CONNECTION);

        DB::purge(self::CONNECTION);
        DB::purge(self::CONNECTION.'_b');
    }

    /** A aplicação inteira migrada e com os dados de referência — uma vez, e reutilizada. */
    private function ensureSchema(): void
    {
        /** @var Connection $scratch */
        $scratch = DB::connection(self::CONNECTION);

        if ($scratch->getSchemaBuilder()->hasTable('academic_calendar_exceptions') && $scratch->table('plans')->where('key', 'pro')->exists()) {
            return;
        }

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => self::CONNECTION, '--force' => true]), Artisan::output());
        $this->seed(ReferenceDataSeeder::class);
    }
}
