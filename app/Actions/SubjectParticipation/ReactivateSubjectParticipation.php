<?php

namespace App\Actions\SubjectParticipation;

use App\Models\Enrollment;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «O João volta a frequentar Português a partir de 2 de janeiro.»
 *
 * FECHA A JANELA EM `data - 1`, NUNCA A APAGA — salvo o caso degenerado
 * abaixo. A partir de `data` o aluno volta aos fluxos normais de avaliação
 * desta disciplina porque deixa de haver linha em vigor
 * (`SubjectParticipation::scopeInVigorOn()`), e a janela antiga continua a
 * dizer, correctamente, que ele esteve fora entre duas datas — história que
 * não se apaga e dados de avaliação em que esta ação nunca toca.
 *
 * A DATA IGUAL AO INÍCIO É UM BECO SEM SAÍDA, e a solução é a mesma que
 * MoveClassGroupMembership já documenta para a sua correcção no sítio: fechar
 * uma janela no dia em que ela abriu produziria `effective_until <
 * effective_from`, que a CHECK da tabela recusa, e a única data que passaria
 * era o dia seguinte — o que deixaria escrito que o aluno esteve um dia fora
 * da disciplina, coisa que nunca aconteceu. Em vez disso a janela é apagada:
 * uma janela que nunca chegou a valer um dia não tem passado nenhum a
 * proteger.
 *
 * LOCK, RE-CHECK, ACT, dentro de uma transação.
 */
class ReactivateSubjectParticipation
{
    public function __construct(
        protected SubjectParticipationRules $rules,
        protected AuditLog $auditLog,
    ) {}

    public function execute(Enrollment $enrollment, string $effectiveFrom): void
    {
        DB::transaction(function () use ($enrollment, $effectiveFrom): void {
            $class = $enrollment->schoolClass()->firstOrFail();
            $academicYear = $class->academicYear()->firstOrFail();

            $this->rules->assertWithinAcademicYear(
                $effectiveFrom,
                $academicYear->starts_on->toDateString(),
                $academicYear->ends_on->toDateString(),
            );

            $current = $this->rules->participationInVigor($enrollment, $effectiveFrom);

            if ($current === null) {
                throw ValidationException::withMessages([
                    'effective_from' => __('Este aluno já frequenta esta disciplina nessa data.'),
                ]);
            }

            if ($current->effective_from->toDateString() === $effectiveFrom) {
                $current->delete();

                $this->auditLog->record(
                    'subject_participation.reactivated',
                    $enrollment,
                    summary: __('Registo de não-frequência anulado — nunca chegou a valer um dia.'),
                    properties: [
                        'enrollment_id' => $enrollment->getKey(),
                        'effective_from' => $effectiveFrom,
                    ],
                );

                return;
            }

            $this->rules->assertClosesAfterItStarted($current, $effectiveFrom);
            $closesOn = $this->rules->dayBefore($effectiveFrom);
            $current->update(['effective_until' => $closesOn]);

            $this->auditLog->record(
                'subject_participation.reactivated',
                $current,
                summary: __('Aluno volta a frequentar a disciplina.'),
                properties: [
                    'enrollment_id' => $enrollment->getKey(),
                    'effective_from' => $effectiveFrom,
                    'effective_until' => $closesOn,
                ],
            );
        });
    }
}
