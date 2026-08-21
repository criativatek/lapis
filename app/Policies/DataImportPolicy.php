<?php

namespace App\Policies;

use App\Models\DataImport;
use App\Models\User;

/**
 * Who may work on a backup restore session.
 *
 * Creating one is member-level, matching SchoolClassPolicy::create() — a
 * restore is, in substance, bulk class/student/enrollment creation, and
 * this app has never gated that behind ownership. The owner gains no new
 * authority by importing: classes an institutional import creates land
 * unassigned (§24), exactly like any class created by hand with nobody
 * teaching it yet — the owner reassigns them the same way either way.
 *
 * Tenant isolation already keeps another organization's import out of
 * reach. `requested_by` is the second, narrower check on top of that — the
 * same rule DataExportPolicy applies: even another member of the SAME
 * organization, owner included, may never act on someone else's import.
 */
class DataImportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DataImport $import): bool
    {
        return $import->requested_by === $user->getKey();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function confirm(User $user, DataImport $import): bool
    {
        return $import->requested_by === $user->getKey() && $import->status->canBeConfirmed();
    }

    public function cancel(User $user, DataImport $import): bool
    {
        return $import->requested_by === $user->getKey() && $import->status->isOpen();
    }
}
