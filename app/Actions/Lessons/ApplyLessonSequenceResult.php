<?php

namespace App\Actions\Lessons;

/**
 * The outcome of one ApplyLessonSequence run — enough for the controller to
 * build a clear flash message without a second query.
 */
final readonly class ApplyLessonSequenceResult
{
    /**
     * @param  list<string>  $appliedLessonUlids
     */
    public function __construct(
        public int $applied,
        public int $skipped,
        public array $appliedLessonUlids,
    ) {}
}
