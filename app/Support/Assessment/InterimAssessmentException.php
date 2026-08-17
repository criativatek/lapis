<?php

namespace App\Support\Assessment;

use App\Models\AcademicPeriod;
use RuntimeException;

/**
 * A refused interim assessment, and the reason in words a teacher can act on.
 *
 * Every message names the real boundary rather than saying «data inválida»,
 * because the fix is always a different date and the teacher needs to know
 * which one.
 */
class InterimAssessmentException extends RuntimeException
{
    public static function periodOutsideClass(): self
    {
        return new self('Este período não pertence ao ano letivo desta turma.');
    }

    public static function beforePeriodStart(AcademicPeriod $period): self
    {
        return new self("A data é anterior ao início de «{$period->label}», que começa a "
            .$period->starts_on->format('d/m/Y').'.');
    }

    public static function afterPeriodEnd(AcademicPeriod $period): self
    {
        return new self("A data é posterior ao fim de «{$period->label}», que termina a "
            .$period->ends_on->format('d/m/Y').'.');
    }

    public static function inTheFuture(): self
    {
        return new self('Não é possível guardar uma avaliação intercalar numa data futura.');
    }
}
