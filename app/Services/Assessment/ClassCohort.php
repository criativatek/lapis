<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SubjectParticipation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * O RESOLVER ÚNICO de «que alunos entram na análise desta disciplina» — a
 * pergunta que doze sítios diferentes voltavam a fazer cada um à sua maneira
 * (BuildClassElements, ClassResultsCalculator, BuildResultsProgression,
 * MigrateClassProfile, ClassificationController, EvidenceController,
 * InterventionController, ClassReportSource, ClassAttendanceSummary,
 * MatchSourceStudents, MatchRosterToEnrollments, BuildImportPreview). Cada
 * repetição era uma oportunidade de as duas versões um dia discordarem.
 *
 * ESTE RESOLVER NÃO FILTRA MERAMENTE — RÓTULA. Ver o docblock de
 * `ClassCohortResult` para a razão completa: um aluno que não frequenta esta
 * disciplina não deixou de existir na turma, e um ecrã que o apagasse estaria
 * a mentir sobre ele tal como `BuildResultsProgression::enrollmentsOf()` já
 * documenta para a matrícula em geral. Por isso este serviço devolve os DOIS
 * grupos (`ClassCohortResult::attending()`/`notAttending()`) e cada consumidor
 * escolhe: excluir (avaliação, denominadores) ou rotular (grelhas, listas).
 *
 * A RESPOSTA É SEMPRE A UMA DATA (`$on`), nunca implicitamente «hoje». Uma
 * análise de novembro continua a incluir como não-frequentante um aluno cuja
 * janela de não-frequência abriu em janeiro seguinte — porque em novembro
 * essa janela ainda não existia. O passado nunca é reescrito.
 */
class ClassCohort
{
    /**
     * A turma nesta disciplina, na data pedida (hoje, por omissão).
     *
     * DUAS CONSULTAS, NUNCA UMA POR ALUNO: as matrículas — com o MESMO eager
     * load e a MESMA ordenação por `class_number` que os sítios existentes já
     * usavam (BuildClassElements::for(), ClassResultsCalculator::forScope())
     * — e depois as janelas de não-frequência em vigor nessa data, num só
     * `whereIn`.
     */
    public static function for(SchoolClass $class, ?string $on = null): ClassCohortResult
    {
        $on ??= CarbonImmutable::today(config('app.timezone'))->toDateString();

        $enrollments = self::enrollmentsFor($class);

        if ($enrollments->isEmpty()) {
            /** @var Collection<int, SubjectParticipation> $noParticipations */
            $noParticipations = collect();

            return new ClassCohortResult($enrollments, $noParticipations);
        }

        $participations = SubjectParticipation::query()
            ->whereIn('enrollment_id', $enrollments->keys())
            ->inVigorOn($on)
            ->get()
            ->keyBy(fn (SubjectParticipation $participation): int => (int) $participation->enrollment_id);

        return new ClassCohortResult($enrollments, $participations);
    }

    /**
     * SÓ AS MATRÍCULAS — sem consultar janela nenhuma. Para quem só precisa de
     * `->all()` (a lista completa, elemento a elemento decide a elegibilidade
     * por outra via, como `BuildClassElements`/`ClassResultsCalculator` desde a
     * decisão do product owner das duas perguntas): pedir `for()` para depois
     * ignorar `attending()`/`notAttending()` pagaria a consulta das janelas em
     * vigor NUM DIA que nem é usado, quando `windowsFor()` já vai carregar TODAS
     * as janelas da turma a seguir.
     *
     * MESMO eager load e MESMA ordenação por `class_number` que `for()` — é a
     * mesma consulta, só que sem a segunda.
     *
     * @return Collection<int, Enrollment>
     */
    public static function enrollmentsFor(SchoolClass $class): Collection
    {
        return Enrollment::query()
            ->where('class_id', $class->getKey())
            ->with('student.identity')
            ->orderBy('class_number')
            ->get()
            ->keyBy(fn (Enrollment $enrollment): int => (int) $enrollment->getKey());
    }

    /**
     * As janelas de não-frequência em vigor numa data, para um conjunto de
     * matrículas JÁ CARREGADO (M6).
     *
     * PARA QUEM PRECISA DE VÁRIAS DATAS DA MESMA TURMA: `for()` volta a
     * carregar as matrículas (com `student.identity`) a cada chamada, o que
     * `BuildResultsProgression::participationsByPeriod()` pagava uma vez por
     * período do ano. Esta entrada só faz a segunda consulta — as janelas —
     * e deixa a primeira (as matrículas) a cargo de quem já as tem em mão.
     *
     * @param  Collection<int, int>  $enrollmentIds
     * @return Collection<int, SubjectParticipation> indexadas por enrollment_id
     */
    public static function participationsOn(Collection $enrollmentIds, string $on): Collection
    {
        if ($enrollmentIds->isEmpty()) {
            return collect();
        }

        return SubjectParticipation::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->inVigorOn($on)
            ->get()
            ->keyBy(fn (SubjectParticipation $participation): int => (int) $participation->enrollment_id);
    }

    /**
     * TODAS as janelas de não-frequência de uma turma nesta disciplina, para
     * responder «este aluno frequentava a disciplina NESTA data concreta?»
     * para uma data QUALQUER — a pergunta que `for()`/`participationsOn()`
     * não respondem, porque as duas só carregam as janelas em vigor numa
     * única data escolhida de antemão.
     *
     * DECISÃO DO PRODUCT OWNER (regra das duas perguntas): uma evidência
     * (instrumento, item) não é decidida pelo cohort à data de referência do
     * período/balanço — é decidida pela data DELA PRÓPRIA (`applied_on`). Uma
     * suspensão que só abre depois nunca apaga retroativamente evidência
     * produzida enquanto o aluno ainda frequentava. Isto é essa segunda
     * pergunta, respondida com UMA SÓ CONSULTA para a turma inteira — nunca
     * uma por (aluno, elemento).
     *
     * RECEBE OS IDS, NÃO A TURMA (mesmo desenho que `participationsOn()`):
     * quem já pediu `enrollmentsFor()`/`for()` não paga outra vez a consulta
     * das matrículas só para lhe passar os ids a esta.
     *
     * @param  Collection<int, int>  $enrollmentIds
     * @return Collection<int, Collection<int, SubjectParticipation>> todas as janelas destas matrículas, agrupadas por enrollment_id
     */
    public static function windowsFor(Collection $enrollmentIds): Collection
    {
        if ($enrollmentIds->isEmpty()) {
            /** @var Collection<int, Collection<int, SubjectParticipation>> $none */
            $none = collect();

            return $none;
        }

        /** @var Collection<int, Collection<int, SubjectParticipation>> */
        return collect(
            SubjectParticipation::query()
                ->whereIn('enrollment_id', $enrollmentIds)
                ->get()
                ->groupBy(fn (SubjectParticipation $participation): int => (int) $participation->enrollment_id)
                ->all(),
        );
    }

    /**
     * «Esta matrícula frequentava a disciplina NESTA data?», lida sobre o
     * resultado de `windowsFor()` — nunca uma consulta nova por chamada, para
     * que percorrer todos os elementos de uma turma não vire uma consulta por
     * (aluno, elemento).
     *
     * SEM JANELA EM VIGOR NESSA DATA = A FREQUENTAR NESSA DATA (mesmo
     * princípio de `SubjectParticipation::scopeInVigorOn()`/`ClassCohortResult`,
     * só que a uma data arbitrária em vez da data única do cohort).
     *
     * @param  Collection<int, Collection<int, SubjectParticipation>>  $windows  o resultado de `windowsFor()`
     */
    public static function wasAttendingOn(Collection $windows, int $enrollmentId, string $on): bool
    {
        $enrollmentWindows = $windows->get($enrollmentId);

        if ($enrollmentWindows === null || $enrollmentWindows->isEmpty()) {
            return true;
        }

        foreach ($enrollmentWindows as $window) {
            if ($window->effective_from->toDateString() > $on) {
                continue;
            }

            if ($window->effective_until === null || $window->effective_until->toDateString() >= $on) {
                return false;
            }
        }

        return true;
    }

    /**
     * A DATA A QUE UM PERÍODO SE LÊ (M4/H5): `min(hoje, $period->ends_on)`.
     *
     * UM PERÍODO FECHADO LÊ-SE NO SEU FIM: as janelas de não-frequência em
     * vigor nessa altura são as que decidem quem frequentou aquele período,
     * ponto final — o passado nunca é reescrito pela data de hoje.
     *
     * UM PERÍODO EM CURSO LÊ-SE HOJE, porque o futuro ainda não aconteceu: se
     * `ends_on` é 1 de dezembro e hoje é 20 de novembro, uma janela que só
     * abre a 1 de dezembro ainda não existe e não pode já marcar um aluno como
     * não-frequentante do período inteiro.
     *
     * UM SÓ SÍTIO PARA ESTA REGRA — usado em todos os pontos que respondem à
     * pergunta (B), «quem conta no balanço deste período»:
     * `ClassificationController`, `SelfAssessmentReading::forClassPeriod()` e
     * `BuildResultsProgression::participationsByPeriod()` (de onde sai o
     * rótulo que `BuildClassStatistics` usa). Um só sítio para que nenhum
     * deles a repita à sua maneira e um dia discorde dos outros.
     *
     * QUEM NÃO APARECE AQUI, E PORQUÊ. `BuildClassElements` e
     * `ClassResultsCalculator` deixaram de chamar este método: respondem à
     * pergunta (A), que se decide pela data DE CADA ELEMENTO
     * (`wasAttendingOn()`), não por um cohort a uma data de referência.
     * `MigrateClassProfile` também não o usa, e documenta no sítio porque não
     * tem um período único a que se ancorar.
     */
    public static function asOfPeriod(AcademicPeriod $period): string
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $endsOn = CarbonImmutable::parse($period->ends_on);

        if ($today->lessThanOrEqualTo($endsOn)) {
            return $today->toDateString();
        }

        return $endsOn->toDateString();
    }
}
