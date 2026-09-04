<?php

namespace App\Support\Assessment;

use App\Models\AcademicPeriod;
use RuntimeException;

/**
 * A refused evaluation-sheet snapshot, and the reason in words a teacher can
 * act on.
 *
 * Every message names the real boundary rather than saying «data inválida»,
 * because the fix is always a different date and the teacher needs to know
 * which one. The boundary is read from the period's own starts_on/ends_on and
 * never inferred from its name — a school that calls its periods something
 * else must not be silently mis-validated (§6).
 */
class EvaluationSheetException extends RuntimeException
{
    public static function periodOutsideClass(): self
    {
        return new self('Este período não pertence ao ano letivo desta turma.');
    }

    public static function beforePeriodStart(AcademicPeriod $period): self
    {
        return new self("A data de referência é anterior ao início de «{$period->label}», que começa a "
            .$period->starts_on->format('d/m/Y').'.');
    }

    public static function afterPeriodEnd(AcademicPeriod $period): self
    {
        return new self("A data de referência é posterior ao fim de «{$period->label}», que termina a "
            .$period->ends_on->format('d/m/Y').'.');
    }

    public static function inTheFuture(): self
    {
        return new self('Não é possível guardar uma pauta com uma data de referência futura.');
    }
}
