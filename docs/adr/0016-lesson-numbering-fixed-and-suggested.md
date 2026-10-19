# ADR-0016 — Lesson numbers are either fixed or suggested, and only suggestions are recalculated

## Status

Accepted — 2026-10-15 (0.169.0). The paragraph on lessons covering several lições is superseded by [ADR-0017](0017-one-lesson-one-number.md) (0.172.0): one occurrence, one number; `lesson_number_end` is legacy.

## Context

Until 0.167.0 `lessons.lesson_number` was entirely derived by `LessonNumbering`: the position of the lesson unit in the class's chronology, starting at 1. A teacher who starts using Lapispro in the middle of the year therefore saw «Lição 1» on a lesson that was really the eleventh, and could only fix it by inventing earlier lessons. There was also no way to say that one occurrence (a 90-minute block, for example) counts as two lições.

Three facts of the existing model constrain any change:

- **Open lessons move with their content.** When the teacher is absent, `ShiftLessonPlanning` moves the planned summary one position forward and the absent lesson leaves the sequence; the following lessons take the previous numbers. A number frozen on every lesson that has a summary would break this.
- **Taught history is never renumbered** in normal operation. Operations that would change it are refused (`guardTaughtHistory`), except the controlled correction `lapis:renumber-lessons --apply`.
- **Retroactive materialization** (opening a week before lessons already taught) could not fit the new lessons before history and fell back to «largest number + 1», producing numbers out of chronological order.

## Decision

The sequence scope stays the `SchoolClass` (class × subject × academic year). Lesson units (whole class, or T1/T2 linked by `lesson_unit_key`) stay as they are.

A unit is **fixed** when it has a number set by the teacher (`lesson_number_manual = true`) or when it is closed history (taught or class activity) and already numbered. A fixed unit never changes number by derivation. Every other unit is **suggested** and is derived from its nearest fixed neighbours:

1. With no fixed unit in the class, suggestions run forward from 1 — exactly the previous rule.
2. Before the first fixed unit, suggestions run **backwards** from it (the lesson before a «Lição 11» is suggested as 10).
3. Elsewhere, a suggestion is the end of the previous numbered unit + 1, skipping numbers already taken; if no free number is left before the next fixed unit, the lesson stays without a number instead of receiving a wrong one. A lesson that already had a free number keeps it rather than lose it.

A lesson can cover several lições: `lesson_number_end` holds the end of the interval (NULL = a single lição), and the next suggestion continues after the end.

A manual change is explicit and checked on the server before anything is written: overlapping another fixed number is refused with a message naming the lesson; any change to the number of another lesson that already has a record (a summary or a closed occurrence) is listed and must be confirmed; an incoherent order relative to the neighbours is reported and left as it is — other lessons are never renumbered to fix it. A teacher absence hands a manual number to the next suggested unit, so the absence produces no jump.

Strict operations (delete, insert, outcome, schedule reconciliation) keep today's guard: they are refused when they would change the number that taught history currently has in the positional chain. The guard is taken as a snapshot before the operation writes, so a class that the teacher deliberately left incoherent is not blocked forever. Materialization never fails and no longer uses «largest + 1».

## Consequences

- A class where nobody set a number behaves as before, with one deliberate difference: a lesson materialized before taught history, with no free number, now stays without a number instead of taking one out of order.
- Manual numbers survive re-saving, materialization, insertion, deletion, reconciliation, marking as taught, the historical correction command and backup round-trips (backup schema 18 carries `lesson_number_end` and `lesson_number_manual`).
- There is no second numbering mechanism: `lesson_number` remains the single effective number read by screens, reports and exports.
- No new personal data is stored; audit events hold ids and numbers only.
