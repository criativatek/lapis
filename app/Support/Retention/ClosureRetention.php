<?php

namespace App\Support\Retention;

use Carbon\CarbonInterface;

/**
 * Pure boundary helpers for a hypothetical account/organization closure
 * timestamp. No schema exists yet for a real closure-request flow — these
 * functions take the timestamp as a parameter so they are ready to be wired
 * up when that flow is built. Nothing here reads or writes any model.
 *
 * The boundary is strictly less-than: a closure exactly at the configured
 * number of days is no longer recoverable. Day 59 of a 60-day window is still
 * recoverable; day 60 is not.
 */
final class ClosureRetention
{
    public function __construct(private readonly RetentionPolicy $policy) {}

    public function isPersonalAccountRecoverable(CarbonInterface $closureRequestedAt, CarbonInterface $now): bool
    {
        return $closureRequestedAt->diffInDays($now, absolute: true) < $this->policy->personalAccountClosureDays();
    }

    public function isInstitutionalOrganizationRecoverable(CarbonInterface $closureRequestedAt, CarbonInterface $now): bool
    {
        return $closureRequestedAt->diffInDays($now, absolute: true) < $this->policy->institutionalClosureDays();
    }
}
