<?php

namespace App\Actions\SubjectParticipation;

use App\Models\Enrollment;
use App\Models\SubjectParticipation;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * As invariantes que TODAS as ações sobre a frequência de uma disciplina
 * partilham, ditas uma vez — o mesmo papel que ClassGroupMembershipRules já
 * desempenha para os grupos.
 *
 * As ações chamam-nas DENTRO da transação, depois do lock e antes de
 * escrever — a ordem que impede que duas mudanças simultâneas passem as duas.
 */
class SubjectParticipationRules
{
    /**
     * A data pedida ao professor tem de cair dentro do ano letivo da turma.
     *
     * Pela mesma razão que ClassGroupMembershipRules::assertWithinAcademicYear()
     * já documenta: PODE SER NO PASSADO — corrigir hoje que o aluno não
     * frequentou a disciplina desde novembro é corrigir o registo para a
     * realidade, e é a realidade que interessa.
     */
    public function assertWithinAcademicYear(string $effectiveFrom, string $startsOn, string $endsOn): void
    {
        if ($effectiveFrom < $startsOn || $effectiveFrom > $endsOn) {
            throw ValidationException::withMessages([
                'effective_from' => __('A data tem de ficar dentro do ano letivo da turma (:from a :to).', [
                    'from' => $startsOn,
                    'to' => $endsOn,
                ]),
            ]);
        }
    }

    /**
     * A janela de não-frequência em vigor desta inscrição, naquele dia — ou
     * null se nesse dia o aluno frequentava normalmente a disciplina.
     */
    public function participationInVigor(Enrollment $enrollment, string $date): ?SubjectParticipation
    {
        /** @var SubjectParticipation|null $participation */
        $participation = SubjectParticipation::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->inVigorOn($date)
            ->orderByDesc('effective_from')
            ->lockForUpdate()
            ->first();

        return $participation;
    }

    /**
     * Nenhuma janela desta inscrição pode começar NA data em que a mudança
     * entra em vigor, ou depois dela — a mesma razão que
     * ClassGroupMembershipRules::assertNothingStartsOnOrAfter() já documenta:
     * mexer numa janela intermédia obrigaria a reescrever as seguintes.
     */
    public function assertNothingStartsOnOrAfter(Enrollment $enrollment, string $effectiveFrom): void
    {
        $later = SubjectParticipation::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->whereDate('effective_from', '>=', $effectiveFrom)
            ->exists();

        if ($later) {
            throw ValidationException::withMessages([
                'effective_from' => __('Já existe um registo de frequência desta disciplina nessa data ou depois dela. Reveja o histórico do aluno primeiro.'),
            ]);
        }
    }

    /**
     * Uma janela não pode ser fechada no dia em que começou, nem antes: o
     * intervalo resultante seria `effective_until < effective_from`, que a
     * própria CHECK da tabela recusa.
     */
    public function assertClosesAfterItStarted(SubjectParticipation $participation, string $effectiveFrom): void
    {
        $startedOn = $participation->effective_from->toDateString();

        if ($effectiveFrom <= $startedOn) {
            throw ValidationException::withMessages([
                'effective_from' => __('A data tem de ser posterior ao início do registo atual (:date).', [
                    'date' => $startedOn,
                ]),
            ]);
        }
    }

    public function dayBefore(string $date): string
    {
        return CarbonImmutable::parse($date)->subDay()->toDateString();
    }
}
