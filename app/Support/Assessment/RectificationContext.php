<?php

namespace App\Support\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodStatus;
use App\Models\AcademicYear;
use App\Models\Classification;
use App\Models\ClassificationStatus;

/**
 * The part of the rectification rule that does not depend on WHICH classification
 * is asked about: whether the period is closed, whether the user teaches the
 * class, and what kind of organization owns it. Built once per class and period
 * by RectificationAccess::context(), so a list of students costs the same
 * handful of queries whatever its length.
 */
final class RectificationContext
{
    public function __construct(
        public readonly bool $closed,
        public readonly bool $teaches,
        public readonly bool $personal,
        public readonly bool $owns,
    ) {}

    /**
     * THE ONE DEFINITION of «closed» (spec §3): the academic year closed or
     * archived, OR the period closed or archived. Draft and open are both open.
     * The rectification rule and the «Retificações» page both read it from here.
     */
    public static function closed(AcademicYear $year, AcademicPeriod $period): bool
    {
        return ! $year->isEditable()
            || in_array($period->status, [AcademicPeriodStatus::Closed, AcademicPeriodStatus::Archived], true);
    }

    public function accessFor(Classification $classification): RectificationAccess
    {
        if (! self::isRectifiable($classification)) {
            return RectificationAccess::none();
        }

        return RectificationAccess::decide($this->closed, $this->teaches, $this->personal, $this->owns);
    }

    /** Only the live, published row of a cell can be rectified. */
    public static function isRectifiable(Classification $classification): bool
    {
        return $classification->status === ClassificationStatus::Published
            && $classification->superseded_by_id === null;
    }
}
