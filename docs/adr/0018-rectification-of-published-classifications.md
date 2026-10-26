# ADR-0018 — Rectification of published classifications

## Status

Accepted — 2026-10-25. The backup authorship question is closed (see "Historical authorship in a restore").

## Context

Publication closed a classification: `ClassificationStatus` said a published decision "only changes by being superseded", and nothing implemented that. A teacher who published a wrong grade had no way to fix it short of editing the database. The schema was ready (`superseded_by_id`, a unique index allowing one live row per cell), the behaviour was not.

## Decision

**A published classification is never edited; it is substituted.** `RectifyClassification` writes a NEW row, already published, and marks the old one `superseded`. The chain is `rectifies_id` (new → old) and `superseded_by_id` (old → new). The proposal and its snapshot are copied to the new row as they were; the reason (mandatory, a note when "Outro"), author and time are recorded on it, and an audit event is written. Only the decision changes: no score, instrument, scale, criterion or profile is touched, and the accumulated classification is not recalculated.

**Order of the writes.** The table refuses both natural orders: marking the old row superseded first breaks the pointer CHECK (`superseded` requires a pointer), and inserting the new row first breaks the "one live row per cell" index. So, in one transaction: (a) the old row gets a TEMPORARY pointer to itself (satisfies the CHECK, leaves the index); (b) the new row is inserted; (c) the old row's pointer is moved to the new row. The self-pointer never outlives the transaction.

**Transaction and lock.** The old row is read with `lockForUpdate` and everything is decided on it: status, `lock_version` against the one the screen showed, access, confirmation. Two people rectifying at once serialise, and the second finds the row already superseded and is told to reload. The service runs as the classification's own organization, whatever the current tenant is.

**Who may rectify** depends on `Organization.type` of the organization the classification belongs to, never the user's current one:

| | Open (draft/open year and period) | Closed (year or period closed/archived) |
|---|---|---|
| Personal | the teacher of the class | the teacher of the class, with extra confirmation |
| Institutional | the teacher of the class | the head of the institution only, with extra confirmation (even if they do not teach the class) |

Closed rectifications always require an extra confirmation. The server decides everything; the client never learns organization types, only `allowed`, `needs_confirmation` and a reason when blocked.

**"Under review" does not block.** An element under review blocks publication, but rectifying is how a review ends in a different grade.

**Backup v20** carries both rows of a chain (`rectifies_ulid`, `superseded_by_ulid`, reason, note, time). Restore validates chains as a whole, and the unit of refusal is the cell (student × period × scope): a chain missing a link, a cell with no live row or two, a cycle, a pointer outside the cell, or an unpublished rectification takes the whole cell out, each row named in "Pontos a rever". A chain is never partly restored. Restore then writes oldest to newest using the same (a)(b)(c) sequence.

**Historical authorship in a restore.** The `classifications_rectification_complete_check` requires `rectifies_id`, `rectification_reason` and `rectified_at` together or not at all, and does NOT require `rectified_by` (nullable, FK to users, restrict). `RectifyClassification` still requires an actor and always writes it as `rectified_by`; it never accepts a null one. A restore sets `rectified_by` to whatever the existing author rule resolves from the backup's email (the importer only if the email really is theirs) and otherwise leaves it NULL: the chain, values, dates, reasons and notes are preserved in full, and nothing is ever attributed to the account that imports, which did not rectify anything. `confirmed_by` and `overridden_by` of restored rows follow the same rule. The history shows "Autor original não identificado" for a rectification row with no author (it never falls back to the confirmer); the import preview says how many rectifications arrive that way without naming anyone; the `data_import.completed` audit event keeps the importer as `causer` and carries `rectifications_without_original_author`, which is also added to `unresolved_author_count`. The backup stays at version 20, with no new column.

Alternatives rejected: attributing to the importer (falsifies the audit trail: the importer is recorded as having done what they did not do); a "system" user (a false authorship as well); refusing the chain or restoring only the live row (both lose history); a boolean `rectifier_unidentified` column (considered, rejected as unnecessary: a NULL author already says it). Future, cross-cutting improvement: associate the backup's authors with members of the destination organization by email, for every kind of record, not only rectifications.

## Consequences

- Every reader already looked at the live row (`whereNull('superseded_by_id')` or status not `superseded`); `RectifiedValueReadersTest` pins that each one returns the new value once.
- The history of a cell is complete, and the replaced value is always recoverable.
- Anything communicated outside Lapispro (Inovar, printed pautas, PDFs) is not corrected; the dialog and help warn the teacher to update those by hand.

## Risks and what was left out

- The self-pointer relies on the transaction; a crash mid-way rolls back, but code that reads outside transactions on MySQL must never see it (it cannot, under InnoDB isolation).
- Closing a period does not freeze classifications, and there is no approval flow: a closed rectification needs only the confirmation (and, in an institution, the head).
- **The former gap is closed** (see the addendum): the head of an institution reaches the classifications of a class they do not teach through their own page, not through the teacher's screens, which stay closed to them.

## Addendum — the head's «Retificações» page

The head of an institution may rectify a closed period for a class they do not teach; to make that usable the product owner approved a **dedicated, minimal page** rather than opening the teacher's screens.

- **What it is.** `GET /retificacoes` lists the classes that have published classifications in a *closed* period (per period: published and rectified counts); `GET /classes/{class}/retificacoes/{period}` shows the table. A menu entry «Retificações» (group «Instituição», `institution_admin` plus `owner_only`) leads there. The abilities are `viewRectifications` (organization: institutional and owned by the user) and `SchoolClassPolicy::reviewRectifications` (class).
- **`view` was not widened.** `SchoolClassPolicy::view` is still «only my classes». Nothing a head could not already reach became reachable; the page has its own controller and its own props.
- **Allowlist.** The server sends, per student: class number, name, the live published decision with its publication date, and the rectification state (`allowed`, `needs_confirmation`, `blocked_reason`, `lock_version`, `count`). No averages, proposals, self-assessment, instruments, scores, observations, attendance or photographs; a test walks the Inertia props and fails on any other key. Rectifying and the history keep using `classifications.rectify` and `classifications.rectification`, whose policies are unchanged.
- **Closed only.** An open period is the teacher's: it is absent from the list and its page answers 404, without a hint that anything exists. «Closed» is decided in one place (`RectificationContext::closed`), shared with the rectification policy.
- **Audit.** Opening the table of a class the viewer does not teach records `classification.rectifications_viewed` (user, class, period). A head who teaches the class, a partial reload and a prefetch record nothing.
- **A class without a teacher and with an open period** cannot be rectified by the head: the period is still the (absent) teacher's. The way out is to assign a teacher («Turmas a Reatribuir»), not a new exception.
- **What stayed out:** the gap noted above no longer exists. Still out: an approval flow, opening any other teacher-only screen to the head, and rectifying an open period.
