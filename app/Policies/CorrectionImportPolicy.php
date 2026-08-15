<?php

namespace App\Policies;

use App\Models\CorrectionImport;
use App\Models\CorrectionImportStatus;
use App\Models\User;

/**
 * Who may work on an import.
 *
 * Tenant isolation is already handled by the global scope — a query for another
 * organization's import simply finds nothing. What this adds is the class rule
 * the rest of the app already keeps: an import belongs to a class, and a teacher
 * only touches their own classes (§23). Knowing a ULID is not authorisation.
 *
 * The entitlement is checked separately, by the route's `module:` middleware and
 * again inside ImportCorrectionGrid. This answers "is this yours?"; that answers
 * "is your plan allowed to?". Both have to say yes, and neither substitutes for
 * the other.
 */
class CorrectionImportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CorrectionImport $import): bool
    {
        return $this->teaches($user, $import);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Changing the decisions in the wizard. Only while the import is still a
     * conversation: once it is imported, cancelled or failed, its record is
     * history and history does not get edited.
     */
    public function update(User $user, CorrectionImport $import): bool
    {
        return $this->teaches($user, $import) && $import->status->isOpen();
    }

    public function confirm(User $user, CorrectionImport $import): bool
    {
        return $this->teaches($user, $import) && $import->status === CorrectionImportStatus::Ready;
    }

    public function delete(User $user, CorrectionImport $import): bool
    {
        return $this->teaches($user, $import) && $import->status->isOpen();
    }

    protected function teaches(User $user, CorrectionImport $import): bool
    {
        return $import->schoolClass->teachers()->whereKey($user->getKey())->exists();
    }
}
