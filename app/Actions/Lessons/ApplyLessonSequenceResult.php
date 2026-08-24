<?php

namespace App\Actions\Lessons;

/**
 * The outcome of one ApplyLessonSequence run — enough for the controller to
 * build a clear flash message without a second query.
 *
 * Three distinct outcomes per sequence item, never collapsed into one
 * "applied" count: `applied` genuinely received at least one new field;
 * `preserved` was matched to an eligible lesson but nothing was actually
 * written — every field was either not selected, blank at the source, or
 * already non-blank at the destination, so existing content stayed exactly
 * as it was; `unavailable` had no eligible future lesson at all to receive
 * it. A message that only reported `applied` would silently hide the
 * "nothing changed" case behind a misleadingly successful count.
 */
final readonly class ApplyLessonSequenceResult
{
    /**
     * @param  list<string>  $appliedLessonUlids
     */
    public function __construct(
        public int $applied,
        public int $preserved,
        public int $unavailable,
        public array $appliedLessonUlids,
    ) {}
}
