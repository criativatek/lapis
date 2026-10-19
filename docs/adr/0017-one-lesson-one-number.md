# ADR-0017 — One lesson occurrence corresponds to a single lesson number

## Status

Accepted — 2026-10-19 (0.172.0). Supersedes the «a lesson can cover several lições» paragraph of [ADR-0016](0016-lesson-numbering-fixed-and-suggested.md); the rest of ADR-0016 stands.

## Context

0.169.0 added «N.º da lição» with a checkbox «Esta aula corresponde a mais do que uma lição» and a second input «até à lição». It stored `lessons.lesson_number_end`, and the derivation (`LessonNumbering::project()`) treated the whole interval as occupied and continued from its end: a fixed unit «12–13» made the next suggested unit start at 14.

Teachers read the checkbox differently. A real case: a teacher at Lição 12 ticked it because the same summary would also serve the next class; the summary became «Lições 12–13» and the next real occurrence in the timetable was suggested as Lição 14, where the teacher expected 13. Reusing content across consecutive lessons is already served by «Basear na aula anterior» (Matriz V3-16-009), which copies the summary text into a new, independent occurrence.

## Decision

A lesson occurrence corresponds to exactly one lesson number. Consecutive occurrences with the same content are two lessons (12 and 13) that share content by reusing the summary, never one record holding several numbers.

- The checkbox and the «até à lição» input are removed. «N.º da lição» stays visible and editable, with the same suggestion and manual-override rules as ADR-0016.
- No write path creates a new interval. The save and preview requests accept the legacy end key only when empty (what pre-0.172.0 screens send by default) and refuse any value with a validation error on that key. It is refused rather than ignored, so a teacher on a stale tab learns that the save did not do what they asked.
- `lesson_number_end` stays in the schema as **legacy**. Existing values are neither removed nor rewritten, and are still displayed («Lições 12–13») in lists, reports, exports and backups. They no longer take part in numbering: a fixed unit occupies only its `lesson_number`, the next suggestion continues from that number, manual conflicts and order warnings compare numbers only, and the positional chain used by the history guard restarts after the number.
- A legacy interval disappears only through an explicit change to that lesson's number (setting a number, or «Repor numeração automática»), which leaves a single number. Editing the field of such a lesson says so on screen.
- A legacy end is never copied to another row: it stays on the lesson that has it, and only while that lesson keeps its number. A T2 lesson that joins a legacy «12–13» unit later gets Lição 12. A teacher absence on a manual lesson hands only the number on to the next suggested unit, and in a split class (T1/T2) the unit key carries the number and the manual mark but not the legacy end.

## Consequences

- In a class with a legacy «12–13», the next free lesson is suggested as 13, so the history can show «Lições 12–13» followed by «Lição 13». This is deliberate: history is not renumbered, and the next lesson follows what the teacher meant. The teacher can collapse the legacy record by editing its number.
- Taught lessons already numbered after a legacy interval under the old rule (12–13, then 14) keep their numbers. Fixed units are never recalculated, and the history guard simply stops treating them as positionally coherent.
- Backup restore (`FindLessonNumberCollisions`) still compares fixed intervals, legacy ends included, when restoring into a class that already has lessons. That is stricter than the live rule but can only refuse, never lose data. It is left unchanged here and noted as residual debt.
- No migration and no backup schema change: backup v19 still carries `lesson_number_end`, so legacy values survive a round-trip.
