<?php

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\User;

/**
 * Authorization for academic years.
 *
 * The organization scope already guarantees a user only ever loads rows from
 * their own organization — an AcademicYear from another organization cannot be
 * resolved at all. These checks add the pedagogical layer on top: a closed or
 * archived year is read-only, so structure cannot be edited after the fact.
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
        return true;
    }

    public function update(User $user, AcademicYear $academicYear): bool
    {
        return $academicYear->isEditable();
    }

    public function delete(User $user, AcademicYear $academicYear): bool
    {
        // Draft years only. Once active, results may hang off it; deletion then
        // is a destructive operation that needs its own audited flow (§22.5).
        return $academicYear->status->value === 'draft';
    }
}
