<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * A write refused because the self-assessment is in read-only mode
 * (SelfAssessmentWrites). Its own type so that a caller can tell it from a
 * failure: a restore refused for this reason is not a failed restore, and the
 * uploaded backup must survive it.
 */
final class SelfAssessmentReadOnlyException extends RuntimeException
{
    public static function restore(): self
    {
        return new self(SelfAssessmentWrites::restoreRefusal());
    }
}
