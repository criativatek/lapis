<?php

namespace App\Actions\Lessons;

use App\Models\AcademicCalendarException;
use App\Models\AcademicYear;
use App\Models\CancelledLessonOccurrence;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
use App\Models\LessonSummary;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Lessons\LessonNumbering;
use App\Services\Lessons\NonTeachingDayConflicts;
use App\Services\Lessons\ShiftLessonPlanning;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Uma exceção do calendário (feriado, interrupção, dia não letivo) criada,
 * editada ou eliminada DEPOIS de as aulas estarem materializadas: o que
 * acontece às aulas que já lá estavam (Fatia 2 da reconciliação).
 *
 * Até aqui nada — por desenho: a materialização só impede aulas NOVAS
 * (MaterializeLessonsForRange). Esta ação é o caminho seguro, com
 * pré-visualização, para as aulas existentes, e tem regras que não se
 * negoceiam:
 *
 *  1. NADA DO QUE JÁ ACONTECEU SE TOCA. Nenhuma aula lecionada ou fechada muda
 *     de número; nenhum sumário, plano, preparação, sequência aplicada ou
 *     assiduidade (rascunhos incluídos) é apagado ou alterado em silêncio. A
 *     classificação A/B/C é de NonTeachingDayConflicts — e é FINAL: inclui a
 *     cadeia que o planeamento de cada B percorre, a lição partilhada (tudo ou
 *     nada) e o âmbito. Tudo se decide ANTES da primeira escrita; só A e B se
 *     executam.
 *  2. NUNCA SE CRIA UMA CancelledLessonOccurrence. Essa marca é permanente e
 *     quer dizer «eliminada à mão pelo professor»; retirar uma aula por causa
 *     de um feriado é só apagar a linha. Se a exceção desaparecer ou encurtar,
 *     abrir a semana recria a aula — a verificação de existência da
 *     materialização evita duplicados. As marcas antigas nem se contam como
 *     tocadas: não se mexe nelas.
 *  3. ACEITAM-SE LACUNAS na numeração quando é a única forma de preservar os
 *     números históricos: a renumeração é a de
 *     LessonNumbering::resequenceAcceptingGaps (as abertas fecham o buraco, as
 *     fechadas ficam como estão) e as lacunas que sobram são devolvidas no
 *     plano e registadas na auditoria.
 *
 * UM SÓ PLANO, COMO EM PostponeLessonForCalendarActivity. Para saber o destino
 * de cada planeamento é preciso deslocar de verdade (e, às vezes, materializar
 * a próxima ocorrência do horário), por isso `preview()` corre o plano numa
 * transação SEMPRE revertida e `execute()` corre-o na sua própria transação e
 * SÓ ENTÃO compara o `plan_token`: se diferir, a exceção reverte tudo o que o
 * plano escreveu — incluindo a própria alteração da exceção, no modo
 * hipotético. A pré-visualização e a execução são, por construção, o mesmo
 * código.
 *
 * BLOQUEIOS: PRIMEIRO, E SEMPRE PELA MESMA ORDEM. A execução — e a
 * pré-visualização do PROFESSOR, que desloca planeamento numa transação
 * revertida e por isso escreve tanto quanto ela — bloqueiam ANTES de qualquer
 * leitura simples e antes de qualquer escrita, por esta ordem:
 *
 *   1. o ano letivo (FOR UPDATE);
 *   2. as turmas do professor (`class_teachers`, LOCK IN SHARE MODE — só no
 *      âmbito do professor) e as turmas do âmbito (FOR UPDATE);
 *   3. as exceções do ano (FOR UPDATE);
 *   4. as aulas das turmas no intervalo que a operação pode tocar (FOR
 *      UPDATE, por id — ver `lockLessons`);
 *   5. só depois: sumários e planos (FOR UPDATE, de aulas já presas), e as
 *      escritas;
 *   6. na renumeração (fase 3), as RESTANTES aulas de cada turma renumerada,
 *      do início ao fim do ano (FOR UPDATE, por id —
 *      LessonNumbering::resequenceAcceptingGaps), lidas com leituras
 *      CORRENTES; e a guarda final do histórico, com LOCK IN SHARE MODE nas
 *      mesmas aulas (já presas). A turma está presa desde o passo 2, por isso
 *      ninguém que prenda turma → aulas fica à espera em sentido contrário; e
 *      quem prende uma aula só (SaveLessonSummary, MarkLessonAsTaught) prende
 *      depois os filhos DELA, nunca outra aula.
 *
 * PORQUE É QUE A RENUMERAÇÃO LÊ «AGORA» E NÃO DO RETRATO: o intervalo do
 * passo 4 é estreito (o responsável só prende os dias da exceção; o professor,
 * do primeiro dia de exceção em diante), e MarkLessonAsTaught fecha uma aula
 * prendendo só a aula. Uma aula de hoje marcada como lecionada depois da
 * primeira leitura simples estava aberta no retrato — e a renumeração,
 * calculada sobre ele, dava-lhe um número novo por cima de uma lecionada.
 * Prender o ano inteiro de todas as turmas logo no início fechava a porta, mas
 * à custa de bloquear todos os professores da organização durante a
 * pré-visualização; prender (e reler) só as turmas que se renumeram, na fase
 * 3, chega.
 *
 * O QUE ESTA ORDEM NÃO COBRE: as chaves estrangeiras. Inserir uma aula, um
 * sumário ou um evento de auditoria pede um bloqueio partilhado na linha-pai
 * (`organizations`, `users`, `recurring_lesson_slots`, `classes`) — e há
 * caminhos que prendem a organização antes do ano (ClassService,
 * RemoveOrganizationMember, LeaveOrganization, AssignClassTeacher,
 * ExecuteDataImport, StudentEnrollmentService). Um deadlock (1213) continua
 * possível, por isso: o ponto de gravação da fase 1 deixa subir a exceção
 * ORIGINAL com o nível de transações certo (`abandonSavepoint`), e a execução
 * repete-se até DEADLOCK_ATTEMPTS vezes (ver `execute`).
 *
 * É a ordem de quem escreve nas aulas: RecordLessonOutcome, DeleteLesson,
 * SetLessonNumber e a materialização prendem a turma e depois as aulas;
 * SaveLessonSummary e SaveLessonAttendanceDraft prendem a aula e depois o
 * sumário. Tomar um sumário antes da sua aula, ou uma aula antes da turma,
 * invertia a ordem de um deles e abria a porta a um deadlock (1213) que podia
 * calhar ao professor que está só a gravar um sumário. Em InnoDB (REPEATABLE
 * READ) o retrato de uma transação fixa-se na primeira leitura não bloqueante,
 * por isso bloquear primeiro faz também o resto do plano ler o estado gravado
 * mais recente — um rascunho de faltas gravado entre a pré-visualização e a
 * confirmação muda o token, e nunca é apagado em cascata.
 *
 * A PRÉ-VISUALIZAÇÃO DO RESPONSÁVEL não toma bloqueios iniciais: só classifica
 * e retira (numa transação revertida) as A do intervalo da exceção que grava,
 * sem nunca deslocar planeamento nem materializar. Mas, antes de retirar,
 * prende as TURMAS dessas A (FOR UPDATE, por id): a retirada prende a aula
 * (e depois o seu sumário e plano, `retire`) e a renumeração prende as aulas
 * da turma — e RecordLessonOutcome prende turma → aula → aulas seguintes. Sem
 * a turma primeiro, cada um ficava com aulas de que o outro precisava.
 *
 * O `plan_token` cobre o ESTADO DE ENTRADA e o efeito. Entrada: as exceções do
 * ano (id, datas, tipo, título, `updated_at`), o pedido de alteração, e, por
 * turma COM AULAS AFETADAS, o estado das aulas a partir do primeiro dia
 * afetado (números, fecho, grupo) e o histórico numerado de antes — sem o
 * estado de preparação, que muda sempre que alguém grava o primeiro sumário de
 * uma aula qualquer (ver `classStates`); por aula afetada, os seus factos e o
 * hash do sumário/plano. Efeito: classificação, deslocamentos (origem, destino,
 * hash do que viaja), retiradas, lacunas e renumerações. Hashes, nunca texto.
 * Os factos incluem o conteúdo (e não só `updated_at`) porque os testes — e a
 * vida real — gravam duas vezes no mesmo segundo. O que muda no sumário de uma
 * aula da cadeia muda o efeito (o hash do que viaja, ou o fim da cadeia), por
 * isso também invalida.
 *
 * QUANDO A EXECUÇÃO NÃO CUMPRE A SIMULAÇÃO. A classificação simula a cadeia de
 * cada B só em leitura; a execução desloca de verdade. Se uma B não encontrar
 * destino, ou se o destino real for uma aula que a simulação não previu (ou já
 * no passado), o deslocamento das B é DESFEITO (até um ponto de gravação
 * tomado antes da primeira escrita), essa B passa a C e a classificação volta
 * a estabilizar (NonTeachingDayConflicts::resettleWithReview) — a lição
 * partilhada sai inteira ou não sai, e as cadeias das outras B deixam de
 * contar com ela como retirada. Depois repete-se. É determinístico: a
 * pré-visualização e a execução chegam ao mesmo plano, e nunca ficam as duas
 * a recusar para sempre.
 *
 * LIMITE CONHECIDO: a materialização (ReconcileLessonsWithSlotValidity, lá
 * dentro) retira aulas vazias fora da vigência do seu tempo. Uma A que já não
 * existe na fase de retirada conta como retirada; uma aula da cadeia que
 * desaparece faz a B passar a C pelo caminho acima.
 *
 * O TOKEN É DETERMINÍSTICO MESMO QUANDO O PLANO MATERIALIZA AULAS. Uma aula
 * que o plano cria tem um ULID aleatório e um id de auto-incremento — que o
 * InnoDB não devolve no ROLLBACK, por isso a execução nunca recebe o id que a
 * pré-visualização viu. No token, e no JSON, uma aula criada pelo plano é
 * `new:<turma>:<tempo>:<início>` — o que ela É, e não os números que calhou
 * ter.
 *
 * Auditoria: `lesson.calendar_reconciled`, só contagens, ids de exceção e
 * lacunas — nunca conteúdo de sumários, planos ou notas.
 *
 * @phpstan-import-type ConflictRow from NonTeachingDayConflicts
 */
class ReconcileLessonsWithCalendarExceptions
{
    private const TIMEZONE = 'Europe/Lisbon';

    /** Quantas vezes a execução tenta depois de ser a vítima de um deadlock (ver `execute`). */
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        protected NonTeachingDayConflicts $conflicts,
        protected ShiftLessonPlanning $planning,
        protected LessonNumbering $numbering,
        protected AuditLog $audit,
    ) {}

    /**
     * O plano, sem gravar nada (o mesmo que `preview()`).
     */
    public function plan(CalendarReconciliationScope $scope, User $actor, ?CalendarExceptionChange $change = null): CalendarReconciliationPlan
    {
        return $this->preview($scope, $actor, $change);
    }

    /**
     * O que a ação FARIA: corre numa transação que reverte sempre. A do
     * professor toma os MESMOS bloqueios iniciais da execução, pela mesma ordem
     * (desloca e materializa, como ela); a do responsável não toma nenhum (ver
     * o topo).
     */
    public function preview(CalendarReconciliationScope $scope, User $actor, ?CalendarExceptionChange $change = null): CalendarReconciliationPlan
    {
        $this->authorize($scope, $actor);

        DB::beginTransaction();

        try {
            return $this->compute($scope, $change, $actor, locking: $scope->shiftsPlanning());
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Executa o plano — e só ele: se o estado mudou desde a pré-visualização o
     * token difere, nada fica escrito e o professor revê.
     *
     * @throws ValidationException quando o token não coincide
     * @throws AuthorizationException quando o ator não pode pedir este âmbito
     */
    public function execute(CalendarReconciliationScope $scope, string $planToken, User $actor, ?CalendarExceptionChange $change = null): CalendarReconciliationPlan
    {
        $this->authorize($scope, $actor);

        // UM DEADLOCK (1213) REPETE-SE, ATÉ DEADLOCK_ATTEMPTS VEZES. É seguro:
        // tudo o que a execução faz está dentro da transação (as aulas, a
        // exceção no modo hipotético, a auditoria; o único efeito fora dela, o
        // aviso «sem lugar» de LessonNumbering, só é escrito depois do commit), e
        // cada tentativa recalcula o plano E o token sob bloqueios novos — se o
        // outro lado mudou alguma coisa que conta, a tentativa recusa como
        // obsoleta (422), nunca executa um plano velho. Só quando esta é a
        // transação de fora (nível 0 depois da falha): um deadlock desfaz a
        // transação inteira, e a de quem chama não se repete daqui. Uma espera
        // esgotada (1205) NÃO se repete: já esperou `innodb_lock_wait_timeout`.
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->executeOnce($scope, $planToken, $actor, $change);
            } catch (Throwable $exception) {
                if ($attempt < self::DEADLOCK_ATTEMPTS && DB::transactionLevel() === 0 && self::isDeadlock($exception)) {
                    continue;
                }

                throw $exception;
            }
        }
    }

    /**
     * 1213, «Deadlock found» — o InnoDB já desfez a transação inteira.
     */
    private static function isDeadlock(Throwable $exception): bool
    {
        if ($exception instanceof DeadlockException) {
            return true;
        }

        return $exception instanceof QueryException
            && ((int) ($exception->errorInfo[1] ?? 0) === 1213 || str_contains($exception->getMessage(), 'Deadlock found'));
    }

    private function executeOnce(CalendarReconciliationScope $scope, string $planToken, User $actor, ?CalendarExceptionChange $change): CalendarReconciliationPlan
    {
        return DB::transaction(function () use ($scope, $planToken, $actor, $change): CalendarReconciliationPlan {
            // O token recalcula-se AQUI, depois dos bloqueios que `compute`
            // toma antes de ler seja o que for.
            $plan = $this->compute($scope, $change, $actor, locking: true);

            // Todas as recusas AQUI, de dentro da transação: a exceção desfaz o
            // que o plano acabou de escrever.
            if (! hash_equals($plan->token, $planToken)) {
                throw $this->stale($scope);
            }

            if ($plan->changesAnything() || $change !== null) {
                // SÓ CONTAGENS, IDS DE EXCEÇÃO E LACUNAS: nunca o texto de um
                // sumário, de um plano ou de uma nota.
                $this->audit->record(
                    'lesson.calendar_reconciled',
                    AcademicYear::query()->whereKey($scope->year->getKey())->firstOrFail(),
                    $actor,
                    'Aulas reconciliadas com o calendário.',
                    [
                        'scope' => $plan->scopeKind,
                        'operation' => $plan->operation,
                        'exception_ids' => $plan->exceptionIds,
                        'retired' => $plan->totals['retired'],
                        'shifted' => $plan->totals['shifted'],
                        'awaiting_teacher' => $plan->totals['awaiting_teacher'],
                        'flagged' => $plan->totals['flagged'],
                        'gaps' => $plan->gaps,
                        'freed_days' => count($plan->freedDays),
                    ],
                );
            }

            return $plan;
        });
    }

    /**
     * Escreve (dentro da transação de quem chama) e descreve o que escreveu.
     */
    private function compute(CalendarReconciliationScope $scope, ?CalendarExceptionChange $change, User $actor, bool $locking): CalendarReconciliationPlan
    {
        // ---- Os bloqueios, ANTES de qualquer leitura simples (ver o topo).
        $year = $this->scopedYear($scope, $locking);
        $classes = $this->scopedClasses($scope, $year, $locking);

        $exceptionsQuery = AcademicCalendarException::query()
            ->where('academic_year_id', $year->getKey())
            ->orderBy('id');

        if ($locking) {
            $exceptionsQuery->lockForUpdate();
        }

        /** @var Collection<int, AcademicCalendarException> $before */
        $before = $exceptionsQuery->get();

        if ($locking) {
            $this->lockLessons($scope, $year, $classes, $before, $change);
        }

        // ---- O ESTADO DE ENTRADA, antes de qualquer escrita.
        $input = [
            'v' => 3,
            'scope' => [$scope->kind, $scope->teacher?->getKey(), $year->getKey(), $scope->from?->toDateString(), $scope->to?->toDateString()],
            'exceptions' => $before->map(fn (AcademicCalendarException $exception): array => [
                $exception->getKey(),
                $exception->type->value,
                $exception->title,
                $exception->starts_on->toDateString(),
                $exception->ends_on->toDateString(),
                $exception->updated_at?->toJSON(),
            ])->all(),
            'change' => $change?->fingerprint(),
        ];

        // Tudo o que tiver id acima deste nasceu durante o plano (ver o topo).
        $maxLessonId = (int) Lesson::query()->max('id');
        $ref = fn (Lesson $lesson): string => (int) $lesson->getKey() > $maxLessonId
            ? 'new:'.$lesson->class_id.':'.($lesson->recurring_lesson_slot_id ?? '-').':'.CarbonImmutable::instance($lesson->starts_at)->toIso8601String()
            : (string) $lesson->ulid;
        $isNew = fn (Lesson $lesson): bool => (int) $lesson->getKey() > $maxLessonId;

        // OS NÚMEROS DE ANTES, numa só leitura e antes de qualquer escrita —
        // incluindo a materialização colateral da fase 1, que também numera:
        // por turma (as lacunas), por aula (o que «muda de número» e a guarda
        // do histórico) e o histórico (fechadas e numeradas) do retrato.
        $numbersBefore = [];
        $numberByLesson = [];
        $historyBefore = [];

        foreach (Lesson::query()->whereIn('class_id', $classes->modelKeys())->whereNotNull('lesson_number')
            ->get(['id', 'class_id', 'lesson_number', 'outcome', 'status']) as $lesson) {
            $id = (int) $lesson->getKey();
            $numberByLesson[$id] = (int) $lesson->lesson_number;
            $numbersBefore[(int) $lesson->class_id][] = (int) $lesson->lesson_number;

            if ($lesson->isClosedAndNumbered()) {
                $historyBefore[$id] = (int) $lesson->class_id;
            }
        }

        foreach ($numbersBefore as $classId => $numbers) {
            $numbers = array_values(array_unique($numbers));
            sort($numbers);
            $numbersBefore[$classId] = $numbers;
        }

        $daysBefore = $this->nonTeachingDays($year, $before);

        // O MODO HIPOTÉTICO: a exceção muda aqui dentro, e a classificação lê-a.
        $affected = $change?->apply($year);

        /** @var Collection<int, AcademicCalendarException> $after */
        $after = AcademicCalendarException::query()->where('academic_year_id', $year->getKey())->orderBy('id')->get();
        $daysAfter = $this->nonTeachingDays($year, $after);
        $freedDays = array_values(array_diff($daysBefore, $daysAfter));

        // ---- A CLASSIFICAÇÃO FINAL, toda antes da primeira escrita nas aulas.
        $rows = $this->classify($scope, $change, $affected, $classes, $year);

        $input['lessons'] = array_map(fn (array $row): array => [$row['kind'], $row['reasons'], $this->facts($row['lesson'])], $rows);
        $input['classes'] = $this->classStates($classes, $rows);

        $detail = $scope->shiftsPlanning();

        // ---- Fase 1: o planeamento das B desce (a última de cada público primeiro).
        $moves = [];
        $pendings = [];
        $shifted = [];

        if ($scope->shiftsPlanning()) {
            // Se a execução não cumprir a simulação, a B passa a C, a
            // classificação estabiliza outra vez e a fase repete-se a partir
            // do ponto de gravação (ver o topo). Cada volta passa pelo menos
            // uma B a C: termina.
            while (true) {
                $outer = DB::transactionLevel();
                DB::beginTransaction();

                try {
                    $phase = $this->shiftPlanned($rows, $actor, $ref, $isNew);
                } catch (Throwable $exception) {
                    // SOBE SEMPRE A EXCEÇÃO ORIGINAL (ver `abandonSavepoint`).
                    $this->abandonSavepoint($outer);

                    throw $exception;
                }

                if ($phase['review'] === null) {
                    DB::commit();
                    ['moves' => $moves, 'pendings' => $pendings, 'shifted' => $shifted] = $phase;

                    break;
                }

                DB::rollBack();
                $rows = $this->conflicts->resettleWithReview($rows, $phase['review'], $classes, $year);
            }
        }

        // ---- Fase 2: retirar as A e as B que desceram. Sem marca, e só o que
        // continua a não perder nada; uma recusa aqui desfaz TUDO (nunca uma
        // B deslocada que fica de pé, nem metade de uma lição partilhada: a
        // classificação final já só deixa aqui lições inteiras).
        $retired = [];
        $retiredByClass = [];
        $executed = [];

        // A PRÉ-VISUALIZAÇÃO DO RESPONSÁVEL não tomou bloqueios iniciais, mas
        // vai prender aulas (retirar, renumerar). A TURMA PRIMEIRO, como
        // RecordLessonOutcome (turma → aula → aulas seguintes, FOR UPDATE):
        // prender as aulas sem a turma deixava este processo com aulas que o
        // outro varre enquanto esperava por uma aula que o outro já tem — 1213.
        // Só as turmas com aulas a retirar, por id.
        if (! $locking) {
            $toRetire = [];

            foreach ($rows as $row) {
                if ($row['kind'] === NonTeachingDayConflicts::RETIRABLE || isset($shifted[(int) $row['lesson']->getKey()])) {
                    $toRetire[(int) $row['lesson']->class_id] = true;
                }
            }

            if ($toRetire !== []) {
                SchoolClass::query()->whereIn('id', array_keys($toRetire))->orderBy('id')->lockForUpdate()->pluck('id');
            }
        }

        foreach ($rows as $row) {
            $id = (int) $row['lesson']->getKey();

            if (! isset($shifted[$id]) && $row['kind'] !== NonTeachingDayConflicts::RETIRABLE) {
                continue;
            }

            if (! $this->retire($id)) {
                throw $this->stale($scope);
            }

            $executed[$id] = true;
            $retired[] = $row['lesson']->ulid;
            $retiredByClass[(int) $row['lesson']->class_id] = true;
        }

        // ---- Fase 3: renumerar só as abertas, aceitando lacunas.
        $gaps = [];
        $renumbered = [];

        foreach (array_keys($retiredByClass) as $classId) {
            $changes = [];

            foreach ($this->numbering->resequenceAcceptingGaps($classId) as $lessonId => $number) {
                $changes[$lessonId > $maxLessonId ? $ref(Lesson::query()->findOrFail($lessonId)) : (string) $lessonId] = $number;
            }

            $renumbered[$classId] = $changes;
            $newGaps = $this->newGaps($numbersBefore[$classId] ?? [], $this->numbersOf($classId));

            if ($newGaps !== []) {
                $gaps[$classId] = $newGaps;
            }
        }

        $this->assertHistoryIntact(array_keys($retiredByClass), $historyBefore, $numberByLesson);

        // ---- O plano: as contagens dizem o que ACONTECEU, e nada mais.
        $classRows = $this->classRows($rows, $classes, $shifted, $executed, $gaps, $this->renumberedCounts($rows, $numberByLesson, $maxLessonId), $moves, $pendings, $detail);
        $existing = $this->existingOnFreedDays($classes, $freedDays, $detail, $ref, $isNew);

        $totals = ['retired' => 0, 'shifted' => 0, 'awaiting_teacher' => 0, 'flagged' => 0];

        foreach ($rows as $row) {
            $totals[$this->bucket($row, $shifted, $executed)]++;
        }

        $exceptionIds = [];

        foreach ($rows as $row) {
            $exceptionIds = array_merge($exceptionIds, $row['exception_ids']);
        }

        $exceptionIds = array_values(array_unique($exceptionIds));
        sort($exceptionIds);

        $effect = [
            'rows' => array_map(fn (array $row): array => [$row['lesson']->ulid, $row['kind'], $row['reasons']], $rows),
            'retired' => $retired,
            'moves' => $moves,
            'pendings' => $pendings,
            'gaps' => $gaps,
            'renumbered' => $renumbered,
            'freed' => $freedDays,
            'existing' => $existing['refs'],
        ];

        return new CalendarReconciliationPlan(
            token: hash('sha256', (string) json_encode([$input, $effect])),
            scopeKind: $scope->kind,
            operation: $change?->operation,
            exceptionIds: $exceptionIds,
            classes: $classRows,
            totals: $totals,
            gaps: $gaps,
            freedDays: $freedDays,
            existingOnFreedDays: ['count' => $existing['count'], 'lessons' => $existing['lessons']],
        );
    }

    /**
     * A fase 1: o planeamento de cada B desce pela cadeia, da ÚLTIMA B de cada
     * público para a primeira. Escreve dentro do ponto de gravação de quem
     * chama; na primeira B em que a execução não cumpre a simulação, pára e
     * devolve-a em `review` (índice => motivo) — quem chama desfaz tudo o que
     * esta fase escreveu e volta a classificar.
     *
     * @param  list<ConflictRow>  $rows
     * @param  callable(Lesson): string  $ref
     * @param  callable(Lesson): bool  $isNew
     * @return array{moves: array<int, list<array<string, mixed>>>, pendings: array<int, array<string, mixed>>, shifted: array<int, true>, review: array<int, string>|null}
     */
    private function shiftPlanned(array $rows, User $actor, callable $ref, callable $isNew): array
    {
        // As aulas existentes que as cadeias classificadas podem tocar — e só
        // essas.
        $allowed = [];

        foreach ($rows as $row) {
            foreach ($row['chain'] as $id) {
                $allowed[$id] = true;
            }
        }

        // A cadeia salta SÓ o que esta operação retira (as A e B de agora).
        $retiring = NonTeachingDayConflicts::retiring($rows);
        $planned = array_keys(array_filter($rows, fn (array $row): bool => $row['kind'] === NonTeachingDayConflicts::PLANNED));

        usort($planned, fn (int $a, int $b): int => [$rows[$a]['lesson']->class_id, $rows[$a]['lesson']->class_group_id ?? 0, $rows[$b]['lesson']->starts_at->getTimestamp(), $rows[$b]['lesson']->getKey()]
            <=> [$rows[$b]['lesson']->class_id, $rows[$b]['lesson']->class_group_id ?? 0, $rows[$a]['lesson']->starts_at->getTimestamp(), $rows[$a]['lesson']->getKey()]);

        // A materialização cobre o intervalo desde o dia seguinte à PRIMEIRA B
        // da turma (e nunca antes de hoje: uma ocorrência no passado nunca é
        // destino) até à ocorrência pedida — e cada turma só materializa uma
        // vez cada dia (ver ShiftLessonPlanning::fromRetiredLesson).
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $materializeFrom = [];

        foreach ($planned as $index) {
            $lesson = $rows[$index]['lesson'];
            $day = CarbonImmutable::instance($lesson->starts_at)->setTimezone(self::TIMEZONE)->startOfDay()->addDay();
            $day = $day->lessThan($today) ? $today : $day;
            $classId = (int) $lesson->class_id;

            if (! isset($materializeFrom[$classId]) || $day->lessThan($materializeFrom[$classId])) {
                $materializeFrom[$classId] = $day;
            }
        }

        $moves = [];
        $pendings = [];
        $shifted = [];

        foreach ($planned as $index) {
            $id = (int) $rows[$index]['lesson']->getKey();
            $classId = (int) $rows[$index]['lesson']->class_id;

            /** @var Lesson $lesson */
            $lesson = Lesson::query()->lockForUpdate()->findOrFail($id);
            $trace = $this->planning->fromRetiredLesson($lesson, $actor, $retiring, $materializeFrom[$classId] ?? null);

            if ($trace['materialized_through'] !== null) {
                $next = CarbonImmutable::parse($trace['materialized_through'], self::TIMEZONE)->addDay()->startOfDay();

                if (! isset($materializeFrom[$classId]) || $next->greaterThan($materializeFrom[$classId])) {
                    $materializeFrom[$classId] = $next;
                }
            }

            if ($trace['stranded']) {
                // A classificação previa um destino e o horário não o deu.
                return ['moves' => [], 'pendings' => [], 'shifted' => [], 'review' => [$index => 'no_destination']];
            }

            $destinations = array_map(fn (array $move): Lesson => $move['to'], $trace['moves']);

            if ($trace['pending_on'] !== null) {
                $destinations[] = $trace['pending_on'];
            }

            foreach ($destinations as $to) {
                if (! $this->foreseen($to, $isNew, $allowed)) {
                    // Um destino que a simulação não previu (uma aula
                    // existente fora da cadeia, ou uma aula nascida agora já
                    // no passado): nunca se escreve nele.
                    return ['moves' => [], 'pendings' => [], 'shifted' => [], 'review' => [$index => 'protected_lesson_after']];
                }
            }

            foreach ($trace['moves'] as $move) {
                /** @var Lesson $to */
                $to = $move['to'];

                $moves[$id][] = [
                    'from' => $ref($move['from']),
                    'to' => $ref($to),
                    'to_starts_at' => $to->starts_at->toIso8601String(),
                    'new_lesson' => $isNew($to),
                    'fields' => $this->fields($move['values']),
                    'hash' => $this->valuesHash($move['values']),
                ];
            }

            if ($trace['pending_on'] !== null) {
                $pendings[$id] = [
                    'on' => $ref($trace['pending_on']),
                    'new_lesson' => $isNew($trace['pending_on']),
                    'fields' => $this->fields($trace['pending_values'] ?? []),
                    'hash' => $this->valuesHash($trace['pending_values'] ?? []),
                ];
            }

            $shifted[$id] = true;
        }

        return ['moves' => $moves, 'pendings' => $pendings, 'shifted' => $shifted, 'review' => null];
    }

    /**
     * Uma aula EXISTENTE só recebe planeamento se a classificação a previu na
     * cadeia de uma B; uma que o plano criou agora só se ainda estiver por
     * acontecer (a classificação nunca conta com um destino no passado).
     *
     * @param  callable(Lesson): bool  $isNew
     * @param  array<int, true>  $allowed
     */
    private function foreseen(Lesson $lesson, callable $isNew, array $allowed): bool
    {
        if ($isNew($lesson)) {
            return $lesson->starts_at->greaterThan(CarbonImmutable::now());
        }

        return isset($allowed[(int) $lesson->getKey()]);
    }

    /**
     * A classificação final, no âmbito pedido.
     *
     * O RESPONSÁVEL SÓ TOCA NOS DIAS DA EXCEÇÃO QUE ALTERA: criar ou alterar uma
     * exceção classifica (e retira) apenas as aulas do intervalo NOVO dessa
     * exceção — gravar uma exceção não retira, de passagem, as aulas vazias de
     * outra que foi gravada antes «sem alterar as aulas». Eliminar não retira
     * nada (só liberta dias). Sem pedido de alteração, o âmbito é o ano todo.
     * O professor (regularizar) vê sempre todas as exceções do ano.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @return list<ConflictRow>
     */
    private function classify(CalendarReconciliationScope $scope, ?CalendarExceptionChange $change, ?AcademicCalendarException $affected, Collection $classes, AcademicYear $year): array
    {
        $from = $scope->from;
        $to = $scope->to;

        if (! $scope->shiftsPlanning() && $change !== null) {
            if ($affected === null) {
                return [];
            }

            $startsOn = CarbonImmutable::parse($affected->starts_on->toDateString(), self::TIMEZONE);
            $endsOn = CarbonImmutable::parse($affected->ends_on->toDateString(), self::TIMEZONE);
            $from = $from === null || $startsOn->greaterThan($from) ? $startsOn : $from;
            $to = $to === null || $endsOn->lessThan($to) ? $endsOn : $to;
        }

        return $this->conflicts->classify($classes, $year, $from, $to, $scope->shiftsPlanning());
    }

    /**
     * Quem pede — recusa antes de ler seja o que for, e antes da transação.
     *
     * @throws AuthorizationException
     */
    private function authorize(CalendarReconciliationScope $scope, User $actor): void
    {
        $tenant = app(CurrentOrganization::class);

        if (! $tenant->isResolved()) {
            throw new AuthorizationException(__('Sem organização ativa.'));
        }

        if ($scope->kind === CalendarReconciliationScope::TEACHER) {
            // O professor só reconcilia as SUAS turmas: o âmbito é dele.
            if ($scope->teacher === null || (int) $scope->teacher->getKey() !== (int) $actor->getKey()) {
                throw new AuthorizationException(__('Só podes reconciliar as tuas turmas.'));
            }
        } elseif (! $actor->owns($tenant->get())) {
            throw new AuthorizationException(__('Só o responsável da organização pode reconciliar todas as turmas.'));
        }
    }

    /**
     * O ano, lido já sob o filtro da organização (com bloqueio na execução):
     * um ano de outra organização nem existe aqui.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function scopedYear(CalendarReconciliationScope $scope, bool $locking): AcademicYear
    {
        $tenant = app(CurrentOrganization::class);
        $query = AcademicYear::query()->whereKey($scope->year->getKey());

        if ($locking) {
            $query->lockForUpdate();
        }

        /** @var AcademicYear|null $year */
        $year = $query->first();

        if ($year === null || (int) $year->organization_id !== (int) $tenant->get()->getKey()) {
            throw new AuthorizationException(__('Este ano letivo não pertence a esta organização.'));
        }

        if (! $year->isEditable()) {
            throw ValidationException::withMessages([
                'academic_year' => __('Um ano letivo encerrado ou arquivado já não se altera.'),
            ]);
        }

        return $year;
    }

    /**
     * @return Collection<int, SchoolClass>
     */
    private function scopedClasses(CalendarReconciliationScope $scope, AcademicYear $year, bool $locking): Collection
    {
        $query = SchoolClass::query()
            ->where('academic_year_id', $year->getKey())
            ->notArchived()
            ->orderBy('id');

        if ($scope->kind === CalendarReconciliationScope::TEACHER && $scope->teacher !== null) {
            if ($locking) {
                // SEM SUBCONSULTA na execução: num SELECT … FOR UPDATE, uma
                // subconsulta (o `whereHas` de `taughtBy`) é uma leitura
                // simples — e fixava o retrato da transação antes de as aulas
                // estarem bloqueadas. As turmas do professor leem-se primeiro,
                // à parte, com bloqueio partilhado.
                $ids = DB::table('class_teachers')
                    ->where('user_id', $scope->teacher->getKey())
                    ->sharedLock()
                    ->pluck('class_id')
                    ->all();

                $query->whereIn('id', $ids);
            } else {
                $query->taughtBy($scope->teacher);
            }
        }

        if ($locking) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * As aulas das turmas do âmbito no intervalo que a operação pode tocar, com
     * FOR UPDATE — ainda ANTES de qualquer leitura simples da transação.
     * SaveLessonAttendanceDraft e SaveLessonSummary bloqueiam a aula antes de
     * escrever: com estas linhas presas, esperam por esta transação; o que
     * gravaram antes já é visível às leituras que se seguem.
     *
     * O INTERVALO, E NÃO O ANO TODO:
     *
     *  - RESPONSÁVEL, com uma alteração: só os dias da exceção alterada, antes
     *    e depois da alteração — de `min(início antigo, início novo)` até
     *    `max(fim antigo, fim novo)`. É lá que estão as A que retira (as do
     *    intervalo novo) e as aulas dos dias que liberta (informativas). Não há
     *    cadeia: o responsável nunca desloca planeamento. ELIMINAR não prende
     *    aula nenhuma — não retira nada. (A renumeração que se segue escreve
     *    noutras aulas da turma, mas a turma já está presa, e é ela que
     *    serializa quem numera.)
     *  - RESPONSÁVEL, sem alteração: do primeiro ao último dia de exceção.
     *  - PROFESSOR: do primeiro dia de exceção do ano (ou do início da janela
     *    pedida) ATÉ AO FIM DO ANO, porque a cadeia de uma B pode descer até à
     *    última aula do ano — e é em todas essas aulas que pode escrever.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, AcademicCalendarException>  $exceptions  as exceções de antes da alteração
     */
    private function lockLessons(CalendarReconciliationScope $scope, AcademicYear $year, Collection $classes, Collection $exceptions, ?CalendarExceptionChange $change): void
    {
        if ($classes->isEmpty()) {
            return;
        }

        $range = $scope->shiftsPlanning()
            ? $this->teacherLockRange($scope, $year, $exceptions, $change)
            : $this->ownerLockRange($exceptions, $change);

        if ($range === null) {
            return;
        }

        [$start, $end] = $range;

        $query = Lesson::query()
            ->whereIn('class_id', $classes->modelKeys())
            ->where('starts_at', '>=', $start);

        if ($end !== null) {
            $query->where('starts_at', '<', $end);
        }

        $query->orderBy('id')->lockForUpdate()->pluck('id');
    }

    /**
     * @param  Collection<int, AcademicCalendarException>  $exceptions
     * @return array{0: CarbonImmutable, 1: null}|null
     */
    private function teacherLockRange(CalendarReconciliationScope $scope, AcademicYear $year, Collection $exceptions, ?CalendarExceptionChange $change): ?array
    {
        $dates = $exceptions->map(fn (AcademicCalendarException $exception): string => $exception->starts_on->toDateString())->all();
        $changeStart = $change?->attributes['starts_on'] ?? null;

        if (is_string($changeStart) && $changeStart !== '') {
            $dates[] = substr($changeStart, 0, 10);
        }

        if ($dates === []) {
            return null;
        }

        $first = CarbonImmutable::parse(min($dates), self::TIMEZONE)->startOfDay();

        if ($scope->from !== null && $scope->from->setTimezone(self::TIMEZONE)->startOfDay()->greaterThan($first)) {
            $first = $scope->from->setTimezone(self::TIMEZONE)->startOfDay();
        }

        $yearStart = CarbonImmutable::parse($year->starts_on->toDateString(), self::TIMEZONE)->startOfDay();

        return [$first->lessThan($yearStart) ? $yearStart : $first, null];
    }

    /**
     * @param  Collection<int, AcademicCalendarException>  $exceptions
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function ownerLockRange(Collection $exceptions, ?CalendarExceptionChange $change): ?array
    {
        if ($change === null) {
            $starts = $exceptions->map(fn (AcademicCalendarException $exception): string => $exception->starts_on->toDateString())->all();
            $ends = $exceptions->map(fn (AcademicCalendarException $exception): string => $exception->ends_on->toDateString())->all();

            return $starts === [] || $ends === [] ? null : $this->dayRange(min($starts), max($ends));
        }

        if ($change->operation === CalendarExceptionChange::DELETE) {
            return null;
        }

        $old = $change->exceptionId === null ? null : $exceptions->firstWhere('id', $change->exceptionId);
        $starts = [];
        $ends = [];

        if ($old instanceof AcademicCalendarException) {
            $starts[] = $old->starts_on->toDateString();
            $ends[] = $old->ends_on->toDateString();
        }

        $newStart = $change->attributes['starts_on'] ?? null;
        $newEnd = $change->attributes['ends_on'] ?? null;

        if (is_string($newStart) && $newStart !== '') {
            $starts[] = substr($newStart, 0, 10);
        }

        if (is_string($newEnd) && $newEnd !== '') {
            $ends[] = substr($newEnd, 0, 10);
        }

        if ($starts === [] || $ends === []) {
            return null;
        }

        return $this->dayRange(min($starts), max($ends));
    }

    /**
     * [meia-noite de Lisboa do primeiro dia, meia-noite do dia seguinte ao último).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dayRange(string $first, string $last): array
    {
        return [
            CarbonImmutable::parse($first, self::TIMEZONE)->startOfDay(),
            CarbonImmutable::parse(max($first, $last), self::TIMEZONE)->addDay()->startOfDay(),
        ];
    }

    private function stale(CalendarReconciliationScope $scope): ValidationException
    {
        return ValidationException::withMessages([
            'plan_token' => $scope->shiftsPlanning()
                ? __('O calendário ou as aulas mudaram desde a pré-visualização. Revê e confirma de novo.')
                : __('O calendário ou as aulas mudaram desde a pré-visualização. Reveja e confirme de novo.'),
        ]);
    }

    /**
     * Em que contagem cai uma linha — o que ACONTECEU, não o que se previa.
     *
     * @param  ConflictRow  $row
     * @param  array<int, true>  $shifted
     * @param  array<int, true>  $executed
     * @return 'retired'|'shifted'|'awaiting_teacher'|'flagged'
     */
    private function bucket(array $row, array $shifted, array $executed): string
    {
        $id = (int) $row['lesson']->getKey();

        if (isset($shifted[$id], $executed[$id])) {
            return 'shifted';
        }

        if (isset($executed[$id])) {
            return 'retired';
        }

        return $row['kind'] === NonTeachingDayConflicts::PLANNED ? 'awaiting_teacher' : 'flagged';
    }

    /**
     * Apaga UMA aula já vazia, sem marca de eliminação. Verifica outra vez —
     * com as linhas relidas COM BLOQUEIO — que não perde nada.
     *
     * SEMPRE A AULA PRIMEIRO, depois o sumário, o plano e as faltas: a ordem
     * de SaveLessonSummary e SaveLessonAttendanceDraft. Também na
     * pré-visualização do responsável, que não toma bloqueios iniciais — apagar
     * o sumário antes de prender a aula atualiza a aula (`summary_version`)
     * DEPOIS do sumário, e invertia a ordem de quem grava um sumário.
     *
     * UMA AULA QUE JÁ NÃO EXISTE conta como retirada: dentro da mesma operação
     * só a materialização da fase 1 (ReconcileLessonsWithSlotValidity) a podia
     * ter apagado — e só apaga aulas vazias. Com as aulas presas desde o
     * início, ninguém de fora o pode ter feito na execução.
     */
    private function retire(int $lessonId): bool
    {
        /** @var Lesson|null $lesson */
        $lesson = Lesson::query()->lockForUpdate()->find($lessonId);

        if ($lesson === null) {
            return true;
        }

        // Cada linha filha lida à parte, e com FOR UPDATE: um `withExists`
        // dentro de um SELECT … FOR UPDATE é uma subconsulta, e em InnoDB uma
        // subconsulta sem cláusula própria lê do retrato — não veria um
        // rascunho de faltas gravado depois dele, que `cascadeOnDelete` apagava.
        /** @var LessonSummary|null $summary */
        $summary = $lesson->summary()->lockForUpdate()->first();
        /** @var LessonPlan|null $plan */
        $plan = $lesson->plan()->lockForUpdate()->first();
        $hasAttendances = $lesson->attendances()->select('id')->lockForUpdate()->first() !== null;

        if ($lesson->isClosed()
            || $lesson->lesson_number_manual
            || $lesson->lesson_number_end !== null
            || $lesson->attendance_recorded_at !== null
            || $hasAttendances
            || ($plan !== null && trim((string) $plan->planned_summary) !== '')
            || ($summary !== null && $this->summaryCarries($summary))) {
            return false;
        }

        // Linhas vazias (sem texto, sem proveniência) saem primeiro: as
        // chaves estrangeiras são `restrictOnDelete`.
        $summary?->delete();
        $plan?->delete();
        $lesson->delete();

        return true;
    }

    private function summaryCarries(LessonSummary $summary): bool
    {
        foreach (['content', 'resources', 'homework', 'private_notes'] as $field) {
            $value = $summary->getAttribute($field);

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return $summary->lesson_sequence_id !== null
            || $summary->lesson_sequence_item_id !== null
            || $summary->sequence_content_hash !== null
            || $summary->reviewed_at !== null
            || $summary->reviewed_by !== null;
    }

    /**
     * Desfaz o ponto de gravação da fase 1 depois de uma falha — e deixa o
     * contador de transações no nível certo, para quem chama relançar a
     * exceção ORIGINAL.
     *
     * Num deadlock (1213) o InnoDB desfaz a TRANSAÇÃO INTEIRA, e os pontos de
     * gravação desaparecem com ela: `ROLLBACK TO SAVEPOINT` falhava com 1305
     * («SAVEPOINT trans2 does not exist»), o contador ficava em 2 e era ESSE
     * erro que subia — a pré-visualização dava 500 com uma mensagem que não
     * dizia nada, e a execução nunca via o deadlock para o repetir. Se voltar
     * ao ponto de gravação falhar, já não há transação nenhuma: o nível passa
     * a 0 (um ROLLBACK sem transação ativa é inofensivo). Uma espera esgotada
     * (1205) só desfaz a instrução: o ponto de gravação existe e volta-se a ele.
     */
    private function abandonSavepoint(int $outer): void
    {
        try {
            DB::rollBack($outer);
        } catch (Throwable) {
            DB::rollBack(0);
        }
    }

    /**
     * A GUARDA FINAL DO HISTÓRICO, com uma LEITURA CORRENTE (LOCK IN SHARE
     * MODE) das turmas em que esta operação escreveu números: nenhuma aula
     * fechada e numerada tem agora um número diferente do de antes da operação.
     *
     * Duas fontes, porque o retrato não chega: as fechadas do retrato
     * (`$historyBefore`) continuam lá e com o mesmo número; e uma aula fechada
     * DEPOIS do retrato — MarkLessonAsTaught prende só a aula, nunca a turma —
     * também tem o número de antes. Só esta operação muda números destas
     * turmas (as outras escritas de números prendem a turma, que está presa),
     * por isso o número do retrato É o de antes da operação.
     *
     * @param  list<int>  $classIds  as turmas em que a operação escreveu
     * @param  array<int, int>  $historyBefore  lesson_id => turma, das fechadas e numeradas do retrato
     * @param  array<int, int>  $numberByLesson  lesson_id => número, do retrato
     *
     * @throws ValidationException
     */
    private function assertHistoryIntact(array $classIds, array $historyBefore, array $numberByLesson): void
    {
        if ($classIds === []) {
            return;
        }

        $now = Lesson::query()->whereIn('class_id', $classIds)->orderBy('id')->sharedLock()
            ->get(['id', 'class_id', 'lesson_number', 'outcome', 'status'])->keyBy('id');
        $touched = array_flip($classIds);
        $refuse = fn (): ValidationException => ValidationException::withMessages([
            'lesson_number' => __('Esta operação mudaria o número de uma aula já fechada. O histórico não é renumerado.'),
        ]);

        foreach ($historyBefore as $id => $classId) {
            if (! isset($touched[$classId])) {
                continue;
            }

            /** @var Lesson|null $lesson */
            $lesson = $now->get($id);

            if ($lesson === null || (int) $lesson->lesson_number !== $numberByLesson[$id]) {
                throw $refuse();
            }
        }

        foreach ($now as $id => $lesson) {
            if ($lesson->isClosedAndNumbered() && $lesson->lesson_number !== null
                && isset($numberByLesson[$id]) && (int) $lesson->lesson_number !== $numberByLesson[$id]) {
                throw $refuse();
            }
        }
    }

    /**
     * Por turma com linhas no plano, as aulas que JÁ EXISTIAM e cujo número
     * mudou — comparado com os números de ANTES DA OPERAÇÃO, e não com os de
     * depois da materialização colateral da fase 1 (que também numera). Uma
     * aula que o plano cria não «muda»: nasce com o seu.
     *
     * @param  list<ConflictRow>  $rows
     * @param  array<int, int>  $numberByLesson
     * @return array<int, int> turma => quantas
     */
    private function renumberedCounts(array $rows, array $numberByLesson, int $maxLessonId): array
    {
        $classIds = [];

        foreach ($rows as $row) {
            $classIds[(int) $row['lesson']->class_id] = true;
        }

        if ($classIds === []) {
            return [];
        }

        $counts = [];

        foreach (Lesson::query()->whereIn('class_id', array_keys($classIds))->where('id', '<=', $maxLessonId)
            ->get(['id', 'class_id', 'lesson_number']) as $lesson) {
            $before = $numberByLesson[(int) $lesson->getKey()] ?? null;
            $now = $lesson->lesson_number === null ? null : (int) $lesson->lesson_number;

            if ($before !== $now) {
                $counts[(int) $lesson->class_id] = ($counts[(int) $lesson->class_id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return list<int>
     */
    private function numbersOf(int $classId): array
    {
        return array_values(Lesson::query()->where('class_id', $classId)->whereNotNull('lesson_number')
            ->pluck('lesson_number')->map(fn ($n): int => (int) $n)->unique()->sort()->all());
    }

    /**
     * Os números que ficam SEM aula por causa desta operação: o que falta entre
     * 1 e o maior número, agora, e não faltava antes. Lacunas que já existiam
     * não são desta operação.
     *
     * @param  list<int>  $before
     * @param  list<int>  $after
     * @return list<int>
     */
    private function newGaps(array $before, array $after): array
    {
        $holes = fn (array $numbers): array => $numbers === [] ? [] : array_values(array_diff(range(1, max($numbers)), $numbers));

        return array_values(array_diff($holes($after), $holes($before)));
    }

    /**
     * O estado de cada turma COM AULAS AFETADAS que condiciona a
     * classificação e a segurança — o que faz uma materialização, um fecho de
     * aula ou uma renumeração concorrentes invalidarem o token mesmo quando não
     * tocam numa aula afetada.
     *
     * SÓ O QUE É RELEVANTE, para que o token não caia com a atividade normal
     * da organização entre a pré-visualização e o clique:
     *
     *  - só as turmas com linhas no plano (uma semana aberta noutra turma não
     *    muda nada aqui);
     *  - das aulas a partir do primeiro dia afetado: existência, início, grupo,
     *    número (e se é indicado à mão) e se está FECHADA — e não o estado de
     *    preparação, que muda sempre que alguém grava o primeiro sumário de uma
     *    aula (Preparação → Preparada). O que o sumário de uma aula da cadeia
     *    muda já entra no efeito (o hash do que viaja, o fim da cadeia, a
     *    classificação);
     *  - das aulas anteriores, só o histórico numerado (fechadas e com número),
     *    de onde se calculam as lacunas;
     *  - as marcas de eliminação a partir do primeiro dia afetado (decidem que
     *    ocorrências do horário a cadeia pode materializar).
     *
     * @param  iterable<SchoolClass>  $classes
     * @param  list<ConflictRow>  $rows
     * @return list<array<int, mixed>>
     */
    private function classStates(iterable $classes, array $rows): array
    {
        $first = [];

        foreach ($rows as $row) {
            $classId = (int) $row['lesson']->class_id;
            $day = CarbonImmutable::instance($row['lesson']->starts_at)->setTimezone(self::TIMEZONE)->startOfDay();

            if (! isset($first[$classId]) || $day->lessThan($first[$classId])) {
                $first[$classId] = $day;
            }
        }

        $states = [];

        foreach ($classes as $class) {
            $classId = (int) $class->getKey();

            if (! isset($first[$classId])) {
                continue;
            }

            $from = $first[$classId];
            $affected = [];
            $history = [];

            foreach (Lesson::query()->where('class_id', $classId)->orderBy('id')
                ->get(['id', 'starts_at', 'lesson_number', 'lesson_number_manual', 'lesson_number_end', 'outcome', 'status', 'class_group_id']) as $lesson) {
                if ($lesson->starts_at->greaterThanOrEqualTo($from)) {
                    $affected[] = [
                        $lesson->getKey(),
                        $lesson->starts_at->format('Y-m-d H:i:s'),
                        $lesson->lesson_number,
                        (bool) $lesson->lesson_number_manual,
                        $lesson->lesson_number_end,
                        $lesson->isClosed(),
                        $lesson->class_group_id,
                    ];
                } elseif ($lesson->isClosedAndNumbered()) {
                    $history[] = [$lesson->getKey(), $lesson->lesson_number, $lesson->lesson_number_end];
                }
            }

            $states[] = [
                $classId,
                $class->archived_at?->toJSON(),
                hash('sha256', (string) json_encode([$affected, $history])),
                CancelledLessonOccurrence::query()->where('class_id', $classId)->where('occurs_at', '>=', $from)->count(),
            ];
        }

        return $states;
    }

    /**
     * Os factos de uma aula afetada, para o token. O conteúdo entra como hash.
     *
     * @return array<int, mixed>
     */
    private function facts(Lesson $lesson): array
    {
        /** @var LessonSummary|null $summary */
        $summary = $lesson->summary;
        /** @var LessonPlan|null $plan */
        $plan = $lesson->plan;

        return [
            $lesson->getKey(),
            $lesson->outcome instanceof LessonOutcome ? $lesson->outcome->value : null,
            $lesson->status->value,
            $lesson->updated_at?->toJSON(),
            $lesson->lesson_number,
            (bool) $lesson->lesson_number_manual,
            $lesson->lesson_number_end,
            $lesson->lesson_unit_key,
            $lesson->summary_version,
            $lesson->attendance_recorded_at?->toJSON(),
            (bool) $lesson->getAttribute('attendances_exists'),
            $summary === null ? null : hash('sha256', (string) json_encode($summary->only(['content', 'resources', 'homework', 'private_notes', 'lesson_sequence_id', 'lesson_sequence_item_id', 'sequence_content_hash', 'reviewed_at', 'reviewed_by']))),
            $plan === null ? null : hash('sha256', (string) $plan->planned_summary),
        ];
    }

    /**
     * Os dias (`Y-m-d`) que as exceções declaram não letivos, dentro do ano.
     *
     * @param  iterable<AcademicCalendarException>  $exceptions
     * @return list<string>
     */
    private function nonTeachingDays(AcademicYear $year, iterable $exceptions): array
    {
        $first = $year->starts_on->toDateString();
        $last = $year->ends_on->toDateString();
        $days = [];

        foreach ($exceptions as $exception) {
            $from = max($exception->starts_on->toDateString(), $first);
            $to = min($exception->ends_on->toDateString(), $last);

            for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
                $days[$day->toDateString()] = true;
            }
        }

        $days = array_keys($days);
        sort($days);

        return $days;
    }

    /**
     * As aulas que já existem nos dias que voltam a ser letivos (informativo:
     * nenhuma é apagada, e as que faltam nascem ao abrir as semanas).
     *
     * @param  iterable<SchoolClass>  $classes
     * @param  list<string>  $freedDays
     * @param  callable(Lesson): string  $ref
     * @param  callable(Lesson): bool  $isNew
     * @return array{count: int, lessons: list<array<string, mixed>>|null, refs: list<string>|int}
     */
    private function existingOnFreedDays(iterable $classes, array $freedDays, bool $detail, callable $ref, callable $isNew): array
    {
        if ($freedDays === []) {
            return ['count' => 0, 'lessons' => $detail ? [] : null, 'refs' => $detail ? [] : 0];
        }

        $ids = [];

        foreach ($classes as $class) {
            $ids[] = $class->getKey();
        }

        $start = CarbonImmutable::parse($freedDays[0], self::TIMEZONE)->startOfDay();
        $end = CarbonImmutable::parse($freedDays[count($freedDays) - 1], self::TIMEZONE)->addDay()->startOfDay();
        $wanted = array_flip($freedDays);

        $lessons = Lesson::query()->whereIn('class_id', $ids)
            ->where('starts_at', '>=', $start)->where('starts_at', '<', $end)
            ->orderBy('starts_at')->orderBy('id')->get()
            ->filter(fn (Lesson $lesson): bool => isset($wanted[CarbonImmutable::instance($lesson->starts_at)->setTimezone(self::TIMEZONE)->toDateString()]))
            ->values();

        return [
            'count' => $lessons->count(),
            'lessons' => $detail
                ? array_values($lessons->map(fn (Lesson $lesson): array => [
                    // Uma aula que o plano criou não existe depois da
                    // pré-visualização: sem ulid.
                    'ulid' => $isNew($lesson) ? null : $lesson->ulid,
                    'class_id' => $lesson->class_id,
                    'starts_at' => $lesson->starts_at->toIso8601String(),
                    'closed' => $lesson->isClosed(),
                ])->all())
                : null,
            'refs' => $detail ? array_values($lessons->map(fn (Lesson $lesson): string => $ref($lesson))->all()) : $lessons->count(),
        ];
    }

    /**
     * Uma entrada por turma com conflitos. Sem `lessons` (NULL) no âmbito do
     * responsável: vê contagens por turma, nunca o conteúdo.
     *
     * @param  list<ConflictRow>  $rows
     * @param  iterable<SchoolClass>  $classes
     * @param  array<int, true>  $shifted
     * @param  array<int, true>  $executed
     * @param  array<int, list<int>>  $gaps
     * @param  array<int, int>  $renumbered  turma => aulas já existentes que mudaram de número (ver `renumberedCounts`)
     * @param  array<int, list<array<string, mixed>>>  $moves
     * @param  array<int, array<string, mixed>>  $pendings
     * @return list<array<string, mixed>>
     */
    private function classRows(array $rows, iterable $classes, array $shifted, array $executed, array $gaps, array $renumbered, array $moves, array $pendings, bool $detail): array
    {
        $labels = [];

        foreach ($classes as $class) {
            $labels[(int) $class->getKey()] = $class->label;
        }

        $result = [];
        $review = [];

        foreach ($rows as $row) {
            $lesson = $row['lesson'];
            $classId = (int) $lesson->class_id;
            $id = (int) $lesson->getKey();

            $result[$classId] ??= [
                'class_id' => $classId,
                'class_label' => $labels[$classId] ?? '',
                'retired' => 0,
                'shifted' => 0,
                'awaiting_teacher' => 0,
                'flagged' => 0,
                // As aulas por dar JÁ EXISTENTES que mudam de número, contra os
                // números de antes da operação.
                'renumbered' => $renumbered[$classId] ?? 0,
                'gaps' => $gaps[$classId] ?? [],
                'review' => [],
                'planning' => $detail ? [] : null,
                'lessons' => $detail ? [] : null,
            ];

            $bucket = $this->bucket($row, $shifted, $executed);
            $result[$classId][$bucket]++;

            // «Precisa de revisão», CONTADO POR RAZÃO (só códigos e nomes de
            // grupos — nunca conteúdo): o responsável vê porquê sem ver aulas.
            if ($bucket === 'flagged') {
                $partners = in_array('shared_unit', $row['reasons'], true) ? $this->partnerGroups($lesson) : [];
                $key = implode(',', $row['reasons']).'|'.implode(',', $partners);
                $review[$classId][$key] ??= ['reasons' => $row['reasons'], 'partners' => $partners, 'count' => 0];
                $review[$classId][$key]['count']++;
            }

            if ($detail) {
                $pending = $pendings[$id] ?? null;

                $result[$classId]['lessons'][] = [
                    'ulid' => $lesson->ulid,
                    'starts_at' => $lesson->starts_at->toIso8601String(),
                    'kind' => $row['kind'],
                    'reasons' => $row['reasons'],
                    'retired' => isset($executed[$id]),
                    'moves' => array_map(fn (array $move): array => [
                        // Uma aula que o plano criou não existe depois da
                        // pré-visualização: sem ulid nem ligação.
                        'to' => $move['new_lesson'] ? null : $move['to'],
                        'to_starts_at' => $move['to_starts_at'],
                        'created_from_timetable' => $move['new_lesson'],
                        'fields' => $move['fields'],
                    ], $moves[$id] ?? []),
                    'pending' => $pending !== null,
                    'pending_on' => $pending === null || $pending['new_lesson'] ? null : $pending['on'],
                ];
            }
        }

        foreach ($review as $classId => $entries) {
            $result[$classId]['review'] = array_values($entries);
        }

        if ($detail) {
            foreach ($this->planningResults($rows, $shifted, $moves, $pendings) as $classId => $planning) {
                if (isset($result[$classId])) {
                    $result[$classId]['planning'] = $planning;
                }
            }
        }

        return array_values($result);
    }

    /**
     * Os nomes dos OUTROS grupos da lição partilhada desta aula (os que
     * continuam a ter aula) — para o responsável perceber «partilhada com
     * quem» sem abrir aula nenhuma. Só nomes de grupos.
     *
     * @return list<string>
     */
    private function partnerGroups(Lesson $lesson): array
    {
        if ($lesson->lesson_unit_key === null || $lesson->class_group_id === null) {
            return [];
        }

        $labels = Lesson::query()
            ->where('lesson_unit_key', $lesson->lesson_unit_key)
            ->whereKeyNot($lesson->getKey())
            ->whereNotNull('class_group_id')
            ->where('class_group_id', '!=', $lesson->class_group_id)
            ->with('classGroup')
            ->get()
            ->map(fn (Lesson $sibling): ?string => $sibling->classGroup?->label)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return array_values(array_map('strval', $labels));
    }

    /**
     * ONDE FICA CADA PLANEAMENTO NO FIM — o resultado, e não a cascata de
     * passos (`moves`). Por turma, uma entrada por planeamento que se mexe:
     *
     *  - `retired`: o planeamento de uma aula retirada (uma B), e a aula onde
     *    acaba — que pode não ser a do primeiro passo: uma B anterior, descida
     *    depois, empurra-o mais uma aula;
     *  - `advanced`: o planeamento que JÁ ESTAVA numa aula seguinte e avança
     *    para dar lugar ao que chega.
     *
     * Reconstruído pelos passos, pela ordem da execução (a última B de cada
     * público primeiro): cada passo leva o que está na aula de origem para a de
     * destino, e o que lá estava segue no passo seguinte — ou fica como
     * planeamento pendente na última aula, quando o horário acaba.
     *
     * @param  list<ConflictRow>  $rows
     * @param  array<int, true>  $shifted  pela ordem da execução
     * @param  array<int, list<array<string, mixed>>>  $moves
     * @param  array<int, array<string, mixed>>  $pendings
     * @return array<int, list<array{origin: string, from_starts_at: string, to_starts_at: string|null, new_lesson: bool, pending: bool}>>
     */
    private function planningResults(array $rows, array $shifted, array $moves, array $pendings): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['lesson']->getKey()] = $row['lesson'];
        }

        /** @var array<string, int> $holder aula (ref) => planeamento que lá está agora */
        $holder = [];
        // Cada planeamento: de onde vem (`$origins`, fixo) e onde está agora
        // (`$places`, reescrito a cada passo).
        /** @var list<array{class_id: int, origin: string, from_starts_at: string}> $origins */
        $origins = [];
        /** @var array<int, array{to_starts_at: string, new_lesson: bool, pending: bool}> $places */
        $places = [];

        foreach (array_keys($shifted) as $id) {
            $origin = $byId[$id] ?? null;
            $steps = $moves[$id] ?? [];

            if ($origin === null || $steps === []) {
                continue;
            }

            $originRef = (string) $origin->ulid;
            $carry = $holder[$originRef] ?? null;
            unset($holder[$originRef]);

            if ($carry === null) {
                $origins[] = ['class_id' => (int) $origin->class_id, 'origin' => 'retired', 'from_starts_at' => $origin->starts_at->toIso8601String()];
                $carry = count($origins) - 1;
            }

            foreach ($steps as $index => $step) {
                $to = (string) $step['to'];
                $last = $index === count($steps) - 1;
                // O que estava no destino segue no passo seguinte (que parte
                // dele) — ou fica como pendência, depois do último passo.
                $displaces = $last ? isset($pendings[$id]) : ((string) $steps[$index + 1]['from']) === $to;
                $displaced = null;

                if ($displaces) {
                    $displaced = $holder[$to] ?? null;

                    if ($displaced === null) {
                        $origins[] = ['class_id' => (int) $origin->class_id, 'origin' => 'advanced', 'from_starts_at' => (string) $step['to_starts_at']];
                        $displaced = count($origins) - 1;
                    }
                }

                $holder[$to] = $carry;
                $places[$carry] = ['to_starts_at' => (string) $step['to_starts_at'], 'new_lesson' => (bool) $step['new_lesson'], 'pending' => false];

                if ($displaced === null) {
                    $carry = null;

                    break;
                }

                $carry = $displaced;
            }

            // O horário acabou: o último planeamento fica como pendência na
            // última aula da cadeia (a do último passo).
            if ($carry !== null && isset($pendings[$id])) {
                $lastStep = $steps[count($steps) - 1];
                $places[$carry] = ['to_starts_at' => (string) $lastStep['to_starts_at'], 'new_lesson' => (bool) $lastStep['new_lesson'], 'pending' => true];
            }
        }

        $result = [];

        foreach ($origins as $key => $origin) {
            $place = $places[$key] ?? null;

            if ($place === null || ($origin['origin'] === 'advanced' && ! $place['pending'] && $place['to_starts_at'] === $origin['from_starts_at'])) {
                continue;
            }

            $result[$origin['class_id']][] = [
                'origin' => $origin['origin'],
                'from_starts_at' => $origin['from_starts_at'],
                'to_starts_at' => $place['to_starts_at'],
                'new_lesson' => $place['new_lesson'],
                'pending' => $place['pending'],
            ];
        }

        foreach ($result as $classId => $list) {
            usort($list, fn (array $a, array $b): int => [$a['origin'] === 'retired' ? 0 : 1, $a['from_starts_at']] <=> [$b['origin'] === 'retired' ? 0 : 1, $b['from_starts_at']]);
            $result[$classId] = $list;
        }

        return $result;
    }

    /**
     * @param  array<string, string|int|null>  $values
     * @return list<string>
     */
    private function fields(array $values): array
    {
        return array_values(array_filter(
            ['content', 'resources', 'homework', 'private_notes'],
            fn (string $field): bool => ($values[$field] ?? null) !== null,
        ));
    }

    /**
     * @param  array<string, string|int|null>  $values
     */
    private function valuesHash(array $values): string
    {
        ksort($values);

        return hash('sha256', (string) json_encode($values));
    }
}
