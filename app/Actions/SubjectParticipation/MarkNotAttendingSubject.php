<?php

namespace App\Actions\SubjectParticipation;

use App\Models\Enrollment;
use App\Models\SubjectParticipation;
use App\Models\SubjectParticipationReason;
use App\Models\SubjectParticipationState;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «O João não frequenta Português a partir de 15 de novembro — vai a PLNM.»
 *
 * ABRE UMA JANELA, NÃO TOCA EM `enrollments`. O aluno continua inscrito na
 * `Enrollment` (turma × disciplina) tal como estava — `status` continua
 * `active` — porque não deixou a turma; deixou apenas de ser avaliado nesta
 * disciplina durante este período. Ver o docblock de `subject_participations`.
 *
 * LOCK, RE-CHECK, ACT, dentro de uma transação, como MoveClassGroupMembership.
 */
class MarkNotAttendingSubject
{
    public function __construct(
        protected SubjectParticipationRules $rules,
        protected AuditLog $auditLog,
    ) {}

    public function execute(
        Enrollment $enrollment,
        SubjectParticipationReason $reason,
        string $effectiveFrom,
        ?string $reasonDetail = null,
        ?string $note = null,
    ): SubjectParticipation {
        return DB::transaction(function () use ($enrollment, $reason, $effectiveFrom, $reasonDetail, $note): SubjectParticipation {
            $class = $enrollment->schoolClass()->firstOrFail();
            $academicYear = $class->academicYear()->firstOrFail();

            $this->rules->assertWithinAcademicYear(
                $effectiveFrom,
                $academicYear->starts_on->toDateString(),
                $academicYear->ends_on->toDateString(),
            );

            $current = $this->rules->participationInVigor($enrollment, $effectiveFrom);

            if ($current !== null) {
                // CORRIGIR NÃO É MUDAR, pela mesma razão que
                // MoveClassGroupMembership já documenta para as pertenças a
                // grupos: quando a data pedida é exactamente o dia em que a
                // janela atual começou, não há duas épocas a separar — o
                // professor está a corrigir o motivo (ou o detalhe, ou a
                // nota) com que essa janela sempre devia ter começado. A linha
                // é reescrita no sítio em vez de fechada e reaberta, que
                // produziria duas janelas a dizer a mesma coisa em vez de uma.
                if ($current->effective_from->toDateString() === $effectiveFrom) {
                    $current->update([
                        'reason' => $reason,
                        'reason_detail' => $reasonDetail,
                        'note' => $note,
                    ]);

                    $this->auditLog->record(
                        'subject_participation.marked_not_attending',
                        $current,
                        summary: __('Registo de não-frequência corrigido.'),
                        properties: [
                            'enrollment_id' => $enrollment->getKey(),
                            'effective_from' => $effectiveFrom,
                            'reason' => $reason->value,
                        ],
                    );

                    return $current;
                }

                throw ValidationException::withMessages([
                    'effective_from' => __('Este aluno já não frequenta esta disciplina nessa data.'),
                ]);
            }

            $this->rules->assertNothingStartsOnOrAfter($enrollment, $effectiveFrom);

            $participation = SubjectParticipation::create([
                'enrollment_id' => $enrollment->getKey(),
                'state' => SubjectParticipationState::NotAttending,
                'reason' => $reason,
                'reason_detail' => $reasonDetail,
                'note' => $note,
                'effective_from' => $effectiveFrom,
                'effective_until' => null,
            ]);

            $this->auditLog->record(
                'subject_participation.marked_not_attending',
                $participation,
                summary: __('Aluno marcado como não frequentando a disciplina.'),
                properties: [
                    'enrollment_id' => $enrollment->getKey(),
                    'effective_from' => $effectiveFrom,
                    'reason' => $reason->value,
                ],
            );

            return $participation;
        });
    }
}
