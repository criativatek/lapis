<?php

namespace App\Support\Lessons;

use App\Models\Lesson;
use App\Models\User;

/**
 * O que um utilizador pode ler do RESULTADO de uma aula além do próprio
 * resultado — UMA PERGUNTA, UM SÍTIO (PR-0, privacidade).
 *
 * O resultado («Professor ausente») é operacional e vê-o toda a turma. Mas a
 * categoria da ausência é um dado sobre um professor concreto: só chega a quem
 * a registou. Um colega da mesma turma — incluindo um observador — vê que a
 * aula não se deu, nunca porquê.
 *
 * O TITULAR é quem registou o resultado (`outcome_recorded_by`), e só enquanto
 * ainda tem acesso à turma (quem chama já autorizou `view`, que exige ser
 * professor da turma). Sem autor conhecido — registo antigo, restauro cujo
 * email não se resolveu — o motivo só chega ao único membro da turma: com
 * mais ninguém na turma, não há a quem o esconder, e o professor não perde a
 * leitura do que é seu por causa de um restauro.
 *
 * Isto governa a APRESENTAÇÃO. O backup continua a levar o motivo (o validador
 * exige-o numa ausência do professor), e a exportação é do próprio utilizador.
 */
final class LessonOutcomeDetails
{
    /**
     * @return array{outcome_reason_label: string|null, outcome_reason_reserved: bool}
     */
    public static function for(Lesson $lesson, User $viewer): array
    {
        $reason = $lesson->outcome_reason;

        if ($reason === null) {
            return ['outcome_reason_label' => null, 'outcome_reason_reserved' => false];
        }

        if (! self::mayReadReason($lesson, $viewer)) {
            return ['outcome_reason_label' => null, 'outcome_reason_reserved' => true];
        }

        return ['outcome_reason_label' => $reason->label(), 'outcome_reason_reserved' => false];
    }

    public static function mayReadReason(Lesson $lesson, User $viewer): bool
    {
        if (! $lesson->schoolClass->teachers()->whereKey($viewer->getKey())->exists()) {
            return false;
        }

        if ($lesson->outcome_recorded_by !== null) {
            return (int) $lesson->outcome_recorded_by === (int) $viewer->getKey();
        }

        return $lesson->schoolClass->teachers()->count() === 1;
    }
}
