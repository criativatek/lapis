<?php

namespace App\Services\Assessment;

use App\Models\CohortUniverse;
use App\Models\Enrollment;
use App\Models\SubjectParticipation;
use Illuminate\Support\Collection;

/**
 * O resultado de `ClassCohort::for()` — a turma nesta disciplina, numa data,
 * já separada entre quem frequenta e quem não frequenta.
 *
 * ESTE OBJETO NÃO FILTRA MERAMENTE — RÓTULA. `BuildResultsProgression::
 * enrollmentsOf()` já documenta a razão de fundo: «um ecrã de resultados que
 * respondesse omitindo o aluno estaria a dizer algo falso sobre ele». Um aluno
 * que não frequenta esta disciplina e que um ecrã apague para «—» passaria a
 * ler-se como «não avaliado» — o que é FALSO: ele É avaliado, só que a outra
 * disciplina (PLNM, por exemplo). «Não frequenta» não é «não participou», não
 * é uma falta, não é um zero, não é «sem classificação». É um facto diferente,
 * com a sua própria palavra.
 *
 * Por isso este objeto nunca decide por si «excluir» ou «mostrar»: entrega os
 * dois grupos (`attending()`/`notAttending()`) e cada consumidor escolhe pela
 * pergunta que está a fazer:
 * - EXCLUIR (instrumentos, grelhas de correção, denominadores, análise por
 *   domínio) — o aluno não gerou evidência nesta disciplina, e contá-lo daria
 *   outro número a todos os outros.
 * - RÓTULAR (grelha de resultados, listas de turma) — o aluno continua na
 *   lista, com o estado dito por palavras ao lado do seu nome.
 *
 * A RESPOSTA É SEMPRE A UMA DATA. `ClassCohort::for($class, $on)` responde «a
 * partir do que se sabia em `$on`», nunca «hoje» por defeito escondido — uma
 * leitura de novembro continua a incluir como não-frequentante um aluno cuja
 * janela abriu em janeiro seguinte só se ainda não tinha aberto em novembro; e
 * continua a mostrá-lo como frequentante em novembro mesmo que hoje já não
 * frequente. O passado nunca é reescrito.
 */
class ClassCohortResult
{
    /**
     * @param  Collection<int, Enrollment>  $enrollments  todas, ordem preservada, indexadas por enrollment_id
     * @param  Collection<int, SubjectParticipation>  $participations  janelas em vigor em `$on`, indexadas por enrollment_id
     */
    public function __construct(
        protected Collection $enrollments,
        protected Collection $participations,
    ) {}

    /**
     * Todas as matrículas, ordem preservada.
     *
     * @return Collection<int, Enrollment>
     */
    public function all(): Collection
    {
        return $this->enrollments->values();
    }

    /**
     * As matrículas que frequentam a disciplina nesta data — sem janela em vigor.
     *
     * @return Collection<int, Enrollment>
     */
    public function attending(): Collection
    {
        return $this->enrollments
            ->reject(fn (Enrollment $enrollment): bool => $this->participations->has((int) $enrollment->getKey()))
            ->values();
    }

    /**
     * As matrículas que NÃO frequentam a disciplina nesta data — com uma
     * janela em vigor.
     *
     * @return Collection<int, Enrollment>
     */
    public function notAttending(): Collection
    {
        return $this->enrollments
            ->filter(fn (Enrollment $enrollment): bool => $this->participations->has((int) $enrollment->getKey()))
            ->values();
    }

    /**
     * A janela em vigor desta matrícula, ou null se frequenta normalmente.
     */
    public function participationOf(Enrollment|int $enrollment): ?SubjectParticipation
    {
        $id = $enrollment instanceof Enrollment ? (int) $enrollment->getKey() : $enrollment;

        return $this->participations->get($id);
    }

    public function isAttending(Enrollment|int $enrollment): bool
    {
        return $this->participationOf($enrollment) === null;
    }

    /**
     * A coleção que o universo pedido representa — o ponto de entrada para um
     * consumidor que só sabe qual dos dois quer, sem repetir a condição.
     *
     * @return Collection<int, Enrollment>
     */
    public function forUniverse(CohortUniverse $universe): Collection
    {
        return match ($universe) {
            CohortUniverse::AttendingOnly => $this->attending(),
            CohortUniverse::AllClassStudents => $this->all(),
        };
    }

    /**
     * As proveniências distintas de quem não frequenta — «PLNM», por exemplo
     * — para um filtro ou um resumo que as queira listar. Nulos ficam de fora:
     * um motivo `Other` sem detalhe não tem nome a dar.
     *
     * @return array<int, string>
     */
    public function notAttendingOrigins(): array
    {
        return $this->participations
            ->pluck('reason_detail')
            ->filter(fn (?string $detail): bool => $detail !== null && $detail !== '')
            ->unique()
            ->values()
            ->all();
    }
}
