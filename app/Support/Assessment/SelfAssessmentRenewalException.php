<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * Thrown when a new self-assessment cannot be started with the class's current
 * criteria: there is nothing behind to renew, the class has no profile, or the
 * self-assessment already uses what the class uses.
 */
class SelfAssessmentRenewalException extends RuntimeException
{
    public static function alreadyCurrent(): self
    {
        return new self(__('Esta autoavaliação já usa os critérios atuais da turma.'));
    }

    public static function nothingToRenew(): self
    {
        return new self(__('Ainda não há autoavaliações guardadas com os critérios anteriores: a autoavaliação acompanha a turma sem ser preciso iniciar uma nova.'));
    }

    /** The self-assessment is read-only (containment mode): nothing is started. */
    public static function readOnly(): self
    {
        return new self(SelfAssessmentWrites::refusal());
    }

    public static function withoutProfile(): self
    {
        return new self(__('A turma não tem perfil de avaliação associado. Associe um perfil na página da turma.'));
    }
}
