<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\User;

/**
 * Who may read, write, finish and export a report (§63, §64).
 *
 * BUILT ON THE PERMISSIONS THAT EXIST, NOT ON NEW ONES. This project has
 * exactly two distinctions in its schema: whether a user teaches a class
 * (`class_teachers`) and whether they own the organization
 * (`organizations.owner_id`). SchoolClassPolicy already reads the first;
 * OrganizationPolicy already reads the second. This uses both and invents
 * neither — no roles, no permission matrix, no «coordenador» that the database
 * has no column for (§78).
 *
 * READING follows the class. A teacher of the class may read reports about that
 * class and its students, whoever wrote them: they already see every grade in
 * it, and a report is a summary of what they can see.
 *
 * WRITING follows authorship. A report is a signed document with a named
 * author (§56) — a second teacher rewriting somebody else's draft and leaving
 * their name on it is the one outcome worth ruling out. Deriving a new report
 * from theirs is always available and is the honest way to disagree (§32).
 *
 * THE SCHOOL REPORT IS THE OWNER'S. It aggregates across colleagues, so it
 * needs an authority above a single teacher, and `owner_id` is the only one
 * this application has. Recorded as a debt in docs/adr rather than papered over
 * with an invented role.
 *
 * Tenant isolation is NOT enforced here. It is enforced one level down by the
 * global scope, which throws rather than leaking; a report from another
 * organization never reaches a policy method at all.
 */
class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Report $report): bool
    {
        if ($this->authored($user, $report)) {
            return true;
        }

        return match ($report->type) {
            ReportType::SchoolClass, ReportType::Student, ReportType::Records => $this->teachesSubjectOf($user, $report),
            ReportType::School => $this->ownsOrganization($user),
        };
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Editing content, characterising, regenerating a section, reordering.
     *
     * A finalized report is not updatable by anyone, including its author. That
     * is the whole meaning of finalizing (§37), and the model enforces it too —
     * this only makes the refusal a 403 instead of an exception.
     */
    public function update(User $user, Report $report): bool
    {
        return $report->isDraft() && $this->authored($user, $report);
    }

    public function finalize(User $user, Report $report): bool
    {
        return $report->isDraft() && $this->authored($user, $report);
    }

    /**
     * Deleting a DRAFT only.
     *
     * A finalized report is never deleted (§55). The project has no soft-delete
     * or archival pattern for signed documents, and inventing one — or worse,
     * hard-deleting — would destroy history that a school may be required to
     * keep. Until there is a decision about retention, the safe behaviour is to
     * refuse.
     */
    public function delete(User $user, Report $report): bool
    {
        return $report->isDraft() && $this->authored($user, $report);
    }

    /** Whoever may read it may take it away as a file. */
    public function export(User $user, Report $report): bool
    {
        return $this->view($user, $report);
    }

    /**
     * Deriving a new report from this one (§31, §32). Reading is enough: the
     * original is never touched, and what comes out is the reader's own draft.
     */
    public function derive(User $user, Report $report): bool
    {
        return $this->view($user, $report);
    }

    protected function authored(User $user, Report $report): bool
    {
        return (int) $report->created_by === (int) $user->getKey();
    }

    protected function teachesSubjectOf(User $user, Report $report): bool
    {
        $class = $report->schoolClass;

        return $class instanceof SchoolClass
            && $class->teachers()->whereKey($user->getKey())->exists();
    }

    protected function ownsOrganization(User $user): bool
    {
        return $user->ownsCurrentOrganization();
    }
}
