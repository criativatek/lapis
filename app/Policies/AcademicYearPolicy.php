<?php

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\User;

/**
 * Authorization for academic years.
 *
 * The organization scope already guarantees a user only ever loads rows from
 * their own organization — an AcademicYear from another organization cannot be
 * resolved at all.
 *
 * READING is for every member: choosing a year is part of ordinary work
 * (creating a class, an instrument, a profile).
 *
 * WRITING is the owner's (Fatia 1, §16 of the multi-user security brief). The
 * academic calendar is shared structure — every class, instrument and result
 * in the organization hangs off it — so letting any member reshape it would
 * let one teacher's edit move the ground under a colleague's already-published
 * classifications. On a personal organization owner_id is the only user there
 * is, so nothing changes for the case this app is mostly used in today.
 *
 * The pedagogical layer stays on top of the ownership layer, not instead of
 * it: a closed year is read-only for the owner too.
 */
class AcademicYearPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AcademicYear $academicYear): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->ownsCurrentOrganization();
    }

    public function update(User $user, AcademicYear $academicYear): bool
    {
        return $user->ownsCurrentOrganization() && $academicYear->isEditable();
    }

    public function delete(User $user, AcademicYear $academicYear): bool
    {
        // Draft years only. Once active, results may hang off it; deletion then
        // is a destructive operation that needs its own audited flow (§22.5).
        return $user->ownsCurrentOrganization() && $academicYear->status->value === 'draft';
    }
}
