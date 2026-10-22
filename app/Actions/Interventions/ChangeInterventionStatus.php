<?php

namespace App\Actions\Interventions;

use App\Models\Intervention;
use App\Models\InterventionStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Interventions\InterventionAuditProperties;

/**
 * A mudança de estado de UMA intervenção — o mecanismo que o botão individual
 * («Marcar em curso», «Concluir», «Suspender», «Reabrir») sempre usou, extraído
 * para que as operações em lote o reutilizem em vez de o copiarem.
 *
 * Duas fases, de propósito. `transition()` escreve a linha (e, se pedido, o
 * acompanhamento) e devolve o estado anterior; `audit()` regista o evento. O
 * pedido individual corre a primeira dentro da sua transação e a segunda
 * depois de esta confirmar, como sempre fez; o lote corre as duas dentro da
 * mesma transação, para que ou muda tudo e fica tudo registado, ou nada.
 *
 * Não autoriza nada: quem chama já decidiu que este utilizador pode alterar
 * esta turma (Gate `update` sobre a turma).
 */
class ChangeInterventionStatus
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @return InterventionStatus O estado em que a intervenção estava.
     */
    public function transition(
        Intervention $intervention,
        InterventionStatus $status,
        User $user,
        ?string $effectiveness = null,
        ?string $notes = null,
    ): InterventionStatus {
        $was = $intervention->status;

        $intervention->fill([
            'status' => $status,
            // Stamp the conclusion date the moment it is concluded; clear it
            // when it reopens, so a reopened intervention does not carry a
            // date saying it ended.
            'concluded_on' => $status === InterventionStatus::Concluded ? now()->toDateString() : null,
            // A closed intervention has nothing left to review. The date is
            // cleared rather than left to sit in the pending list forever —
            // and it is the teacher's to set again if they reopen it.
            'review_on' => $status->isOpen() ? $intervention->review_on : null,
        ])->save();

        // An appraisal given while concluding is a follow-up like any other,
        // dated today and kept in the history. It never overwrites an
        // earlier one (§28).
        if ($effectiveness !== null || $notes !== null) {
            $intervention->reviews()->create([
                'reviewed_on' => now()->toDateString(),
                'effectiveness' => $effectiveness,
                'notes' => $notes,
                'reviewed_by' => $user->getKey(),
            ]);
        }

        return $was;
    }

    /**
     * REOPENING IS ITS OWN EVENT, and the previous conclusion is not erased
     * from the trail by it (§44).
     *
     * @param  array<string, mixed>  $extra  Propriedades a somar às habituais (o lote diz aqui que o foi).
     */
    public function audit(
        Intervention $intervention,
        InterventionStatus $was,
        InterventionStatus $status,
        User $user,
        array $extra = [],
    ): void {
        $intervention->refresh();

        $this->audit->record(
            $was === InterventionStatus::Concluded && $status->isOpen()
                ? 'intervention.reopened'
                : 'intervention.status_changed',
            $intervention,
            $user,
            summary: __('Intervenção «:title» — :class', [
                'title' => $intervention->displayTitle(),
                'class' => $intervention->schoolClass->label,
            ]),
            properties: [
                'from' => $was->value,
                'to' => $status->value,
                ...$extra,
                ...InterventionAuditProperties::base($intervention),
            ],
        );
    }
}
