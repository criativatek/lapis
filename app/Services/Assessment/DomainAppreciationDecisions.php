<?php

namespace App\Services\Assessment;

use App\Models\ClassificationScope;
use App\Models\DomainAppreciationDecision;
use Illuminate\Support\Collection;

/**
 * A LEITURA das decisões por domínio — uma consulta por pauta, nunca uma por
 * célula.
 *
 * Uma turma de trinta alunos com cinco domínios são cento e cinquenta células;
 * perguntar linha a linha seria o N+1 que a Pauta não pode ter (§24). Aqui
 * pergunta-se uma vez pela turma inteira e devolve-se uma tabela em memória.
 *
 * DEVOLVE O QUE ESTÁ ESCRITO E MAIS NADA. Não resolve propostas, não sabe o que
 * o motor calculou e não decide o que mostrar: quem junta a decisão à proposta é
 * quem constrói o modelo de leitura, com as duas coisas à vista e cada uma no
 * seu lugar.
 */
class DomainAppreciationDecisions
{
    /**
     * As decisões vivas desta turma, neste momento, indexadas por matrícula e
     * domínio.
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, array<int, DomainAppreciationDecision>>
     */
    public function for(array $enrollmentIds, int $academicPeriodId, ClassificationScope $scope): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        /** @var Collection<int, DomainAppreciationDecision> $decisions */
        $decisions = DomainAppreciationDecision::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->where('academic_period_id', $academicPeriodId)
            ->where('scope', $scope)
            ->with('scaleLevel')
            ->get();

        $byEnrollment = [];

        foreach ($decisions as $decision) {
            $byEnrollment[(int) $decision->enrollment_id][(int) $decision->domain_id] = $decision;
        }

        return $byEnrollment;
    }
}
