# Membership lifecycle — Fatia 4

How a person enters and leaves an institutional organization without the
organization's pedagogical work being touched. Retention/backup/export
policy lives separately in [data-lifecycle.md](data-lifecycle.md); this
document is the operational side: who can do what, and what happens to the
data when they do it.

## The rule everything else follows

**Turmas não pertencem ao utilizador. Pertencem à organização.** Leaving or
being removed changes who currently has operational responsibility for a
class — never what already happened. A departure:

- detaches the person's `organization_memberships` row;
- detaches their `class_teachers` rows for that organization's classes only;
- never touches `classes`, `students`, `enrollments`, `evidence_records`,
  `classifications`, `instruments`, `self_assessments`, `interventions`, or
  any `reports` row;
- never rewrites a historical `created_by` / `overridden_by` / `confirmed_by`
  / `causer_id` column anywhere — those FKs are `restrictOnDelete()` on
  purpose (see `docs/domain-model.md`), and stay pointed at the person who
  actually did the thing, forever.

## Leaving (`App\Actions\Organizations\LeaveOrganization`)

A member of an institutional organization who is **not** its owner can leave
it (`POST /organizations/leave`, no `module:institution_admin` gate — this is
available on every plan, not a governance feature). The owner cannot leave
through this or any other path; the action refuses with *"Antes de sair,
transfira a responsabilidade da organização para outro membro."* Guards are
re-checked inside a `DB::transaction()` with the organization row locked, to
close the window between "is this still true" and "make it so."

## Removal (`App\Actions\Organizations\RemoveOrganizationMember`)

The owner removes another member from the Equipa page
(`DELETE /team/members`, owner-only). Same guards as leaving, plus: the
target cannot be the owner (removal is never a disguised way to demote
yourself), and the target must belong to the SAME organization the acting
owner currently owns — a cross-organization id is rejected by the same
`MembershipException` path as every other invalid target, not a bare 404.

## Ownership transfer (`App\Actions\Organizations\TransferOrganizationOwnership`)

The owner transfers `organizations.owner_id` to another current member
(`POST /team/members/transfer-ownership`). Both remain members afterward —
nothing is detached. The organization row is locked for the update, and if
the transferred organization is the caller's own currently-resolved tenant,
`CurrentOrganization` is refreshed in-process so the new authority applies
within the SAME request/response — a redirect back to the Equipa page
immediately reflects who can and cannot act, without waiting for a fresh
request.

## Class reassignment — derived, not stored

A class "needing reassignment" is not a column. It is
`SchoolClass::query()->needingReassignment()`:
`whereDoesntHave('teachers')->whereIn('status', [Preparation, Active])`.
Zero rows in `class_teachers` for a non-archived, non-closed class **is** the
state — nothing to migrate, nothing that can drift out of sync with the
pivot table it's derived from. There is no record of who taught the class
before reassignment: `class_teachers` rows are hard-detached with no history
table, and reconstructing "previous teacher" from audit events would be
fragile, so the reassignment page doesn't try.

The owner assigns a current member to an orphaned class from "Turmas a
Reatribuir" (`GET /classes/reassignment`, `POST
/classes/reassignment/{class}/assign`) — attaches one `class_teachers` row
with `role: 'owner'`, the same role `ClassService::create()` already gives a
class's creator. The target must be a member of the class's own organization;
a class that already has a teacher cannot be reassigned through this path
(it exists specifically to resolve orphaned classes, not to manage
co-teaching generally).

## Impersonation

Every mutating action in this fatia — leave, remove, transfer, reassign —
refuses while `session('impersonator_id')` is set, via the shared
`App\Http\Controllers\Concerns\RefusesDuringImpersonation` trait (extracted
from the check `TeamController` already had for invitations). Support acting
on someone's behalf must never look, to the rest of the school, like that
person's own decision.

## Exporting ("Exportar os meus dados")

`App\Actions\DataExports\GenerateDataExport` builds a ZIP synchronously —
there is no queue infrastructure in production use yet, and one teacher's own
classes are a small, bounded dataset. Content is always the requesting
user's own `class_teachers` scope, the exact same access `SchoolClassPolicy`
already grants — an institutional owner receives additional owner-only rows
(team roster, the audit events `AuditEvent::scopeVisibleTo` already lets
them see) but **never** a colleague's pedagogical data. See
[data-lifecycle.md](data-lifecycle.md) for what the export deliberately
excludes and how long the generated file lives.

## What this fatia does not do

No intermediate roles, no change to `class_teachers.role`'s three values, no
resolution of duplicate students, no rewrite of assessment formulas or
results, no institutional account closure flow (only the retention *boundary
logic* for one, in `App\Support\Retention\ClosureRetention`, unused until
that flow exists).
