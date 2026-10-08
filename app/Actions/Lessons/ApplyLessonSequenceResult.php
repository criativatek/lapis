<?php

namespace App\Actions\Lessons;

use Carbon\CarbonImmutable;

/**
 * The outcome of one ApplyLessonSequence run — enough for the controller to
 * build a clear flash message without a second query.
 *
 * `counts` has one entry per disposition (fill, update, unchanged, keep,
 * replace, preserve, closed, release, nothing_to_copy) plus `unplaced`, which
 * is always 0 in an executed run: an incomplete plan is refused before
 * anything is written. `applied` is what genuinely wrote content into a lesson
 * (fill + update + replace); `preserved` lessons were skipped without
 * consuming an item; `unavailable` is kept for the audit event's old
 * `items_skipped` key.
 */
final readonly class ApplyLessonSequenceResult
{
    /**
     * @param  list<string>  $appliedLessonUlids
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public int $applied,
        public int $preserved,
        public int $unavailable,
        public array $appliedLessonUlids,
        public array $counts,
        public CarbonImmutable $from,
    ) {}
}
