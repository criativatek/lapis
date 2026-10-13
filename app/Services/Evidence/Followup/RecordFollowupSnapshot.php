<?php

namespace App\Services\Evidence\Followup;

use App\Models\AcademicPeriod;
use App\Models\RecordFollowupCategory;
use App\Models\SchoolClass;

/**
 * The counts a write found BEFORE it wrote — see RecordFollowupAlerts.
 */
final class RecordFollowupSnapshot
{
    /**
     * @param  list<array{enrollment_id: int, category: RecordFollowupCategory, period: AcademicPeriod, window: FollowupWindow, before: int}>  $keys
     */
    public function __construct(
        public readonly SchoolClass $class,
        public readonly array $keys,
        public readonly bool $outsidePeriods,
    ) {}
}
