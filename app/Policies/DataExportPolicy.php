<?php

namespace App\Policies;

use App\Models\DataExport;
use App\Models\User;

/**
 * The standard `BelongsToOrganization` tenant scope already keeps this row
 * out of reach outside the organization it was generated in — same as every
 * other model in the app. `requested_by` is the second, narrower check on
 * top of that: even another member of the SAME organization (the owner
 * included) may never fetch someone else's export.
 */
class DataExportPolicy
{
    public function download(User $user, DataExport $export): bool
    {
        return $export->requested_by === $user->getKey() && $export->isReady() && ! $export->isExpired();
    }
}
