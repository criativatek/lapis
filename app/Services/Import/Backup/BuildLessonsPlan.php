<?php

namespace App\Services\Import\Backup;

use App\Models\CancelledLessonOccurrence;
use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonPlan;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\RecurringLessonSlot;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Collection;

/**
 * The lessons/attendance tier of the import plan (schema_version 9,
 * docs/backup-schema.md, "Aulas e assiduidade"). Split out of
 * `BuildImportPlan` for the same reason `BuildPedagogicalRecordsPlan` is —
 * a single file this size makes PHPStan's own memory footprint too large to
 * check reliably — and, like it, runs LAST: nothing here feeds
 * `BuildResultsProgression`/`ClassResultsCalculator`.
 *
 * Dependency order matches the collections themselves (§ backup-schema.md):
 * class_groups → class_group_memberships → recurring_lesson_slots →
 * cancelled_lesson_occurrences → lessons → lesson_summaries → lesson_plans →
 * lesson_attendances. Every stage below only needs classes/enrollments
 * `BuildImportPlan` has already resolved, plus whatever this tier resolved
 * one stage earlier.
 *
 * THE ONE RULE THAT IS NOT LIKE THE OTHER DOMAINS: an existing lesson with no
 * recorded attendance (`attendance_recorded_at` still NULL, a draft) never
 * silently absorbs a backup that DOES carry recorded attendance for the same
 * lesson. That would rewrite a live draft into someone else's frozen
 * snapshot without anyone deciding to. It is a `conflict`, never merged
 * (§13.3 — the teacher decides), and every attendance row that belongs to
 * that lesson is `invalid` alongside it — a conflicting lesson never gets
 * some of its attendance restored and not the rest.
 *
 * SAME-CLASS INTEGRITY (docs/backup-schema.md — every row below promises
 * it). A membership's group and enrollment, an attendance line's enrollment
 * and lesson, a lesson's group and schedule slot, a slot's own group, a
 * cancelled occurrence's slot and group — every one of these pairs must
 * belong to the SAME class, or the row is `invalid`, never written. This is
 * checked by an IDENTITY, not by a raw ulid string comparison, because a
 * `new` reference (identified by the SOURCE ulid it will be created under)
 * and an `existing` one (identified by its REAL destination class id,
 * fetched from the database — never trusted from what the backup merely
 * claims for it) are not otherwise comparable. See `classIdentityOfRow()`.
 */
class BuildLessonsPlan
{
    use ResolvesBackupReferences;

    /**
     * @param  array<int, array<string, mixed>>  $classGroupsIn
     * @param  array<int, array<string, mixed>>  $membershipsIn
     * @param  array<int, array<string, mixed>>  $slotsIn
     * @param  array<int, array<string, mixed>>  $cancelledIn
     * @param  array<int, array<string, mixed>>  $lessonsIn
     * @param  array<int, array<string, mixed>>  $summariesIn
     * @param  array<int, array<string, mixed>>  $plansIn
     * @param  array<int, array<string, mixed>>  $attendancesIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @return array{rows: array<string, array<int, array<string, mixed>>>}
     */
    public function build(
        array $classGroupsIn,
        array $membershipsIn,
        array $slotsIn,
        array $cancelledIn,
        array $lessonsIn,
        array $summariesIn,
        array $plansIn,
        array $attendancesIn,
        Organization $destination,
        User $actor,
        Collection $classesByUlid,
        Collection $enrollmentsByUlid,
    ): array {
        $enrollmentRealClassIds = $this->enrollmentRealClassIds($enrollmentsByUlid);

        $groupRows = $this->classifyClassGroups($classGroupsIn, $destination, $classesByUlid);
        $groupsByUlid = collect($groupRows)->keyBy('ulid');

        $membershipRows = $this->classifyMemberships($membershipsIn, $destination, $classesByUlid, $groupsByUlid, $enrollmentsByUlid, $enrollmentRealClassIds);

        $slotRows = $this->classifySlots($slotsIn, $destination, $classesByUlid, $groupsByUlid);
        $slotsByUlid = collect($slotRows)->keyBy('ulid');

        $cancelledRows = $this->classifyCancelledOccurrences($cancelledIn, $destination, $actor, $classesByUlid, $groupsByUlid, $slotsByUlid);

        $lessonRows = $this->classifyLessons($lessonsIn, $destination, $actor, $classesByUlid, $groupsByUlid, $slotsByUlid);
        $lessonsByUlid = collect($lessonRows)->keyBy('ulid');

        $summaryRows = $this->classifySummaries($summariesIn, $destination, $actor, $lessonsByUlid);
        $planRows = $this->classifyPlans($plansIn, $destination, $actor, $lessonsByUlid);
        $attendanceRows = $this->classifyAttendances($attendancesIn, $destination, $actor, $classesByUlid, $lessonsByUlid, $enrollmentsByUlid, $enrollmentRealClassIds);

        return [
            'rows' => [
                'class_groups' => $groupRows,
                'class_group_memberships' => $membershipRows,
                'recurring_lesson_slots' => $slotRows,
                'cancelled_lesson_occurrences' => $cancelledRows,
                'lessons' => $lessonRows,
                'lesson_summaries' => $summaryRows,
                'lesson_plans' => $planRows,
                'lesson_attendances' => $attendanceRows,
            ],
        ];
    }

    /**
     * A comparable identity for the class a plan row's OWN class field
     * resolves to — the real destination id when that class is already
     * `existing`, or the shared SOURCE ulid when it is still `new` and has
     * no id yet. Two references sharing this identity are guaranteed to be
     * the same class; anything else — including comparing an `id` against
     * a `ulid` — is a genuine mismatch.
     *
     * @param  array<string, mixed>  $classRow
     * @return array{kind: 'id', id: int}|array{kind: 'ulid', ulid: string}
     */
    private function classIdentityFromClassRow(array $classRow): array
    {
        return $classRow['classification'] === 'existing' && isset($classRow['existing_id'])
            ? ['kind' => 'id', 'id' => (int) $classRow['existing_id']]
            : ['kind' => 'ulid', 'ulid' => $classRow['ulid']];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @return array{kind: 'id', id: int}|array{kind: 'ulid', ulid: string}|null
     */
    private function classIdentityFromUlid(?string $classUlid, Collection $classesByUlid): ?array
    {
        if ($classUlid === null) {
            return null;
        }

        $classRow = $classesByUlid->get($classUlid);

        return $classRow === null ? null : $this->classIdentityFromClassRow($classRow);
    }

    /**
     * The identity of a `new`/`existing` group/slot/lesson row — the REAL
     * destination class when the row already matched a database row
     * (`real_class_id`, set by the caller from the actual model it
     * matched — NEVER trusting what the backup merely claims for it), or
     * the class this same run would create it under otherwise (`new`,
     * resolved through `classesByUlid`). Callers must only invoke this
     * once the row's own resolvability (`new`/`existing`) has already been
     * checked — a `conflict`/`invalid` row has no safe identity and is not
     * expected here.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @return array{kind: 'id', id: int}|array{kind: 'ulid', ulid: string}|null
     */
    private function classIdentityOfRow(array $row, Collection $classesByUlid): ?array
    {
        if (in_array($row['classification'], ['existing', 'conflict'], true)) {
            return isset($row['real_class_id']) ? ['kind' => 'id', 'id' => (int) $row['real_class_id']] : null;
        }

        if ($row['classification'] === 'new') {
            return $this->classIdentityFromUlid($row['class_ulid'] ?? null, $classesByUlid);
        }

        return null;
    }

    /**
     * The same identity, for an enrollment row — `BuildImportPlan`'s own
     * `classifyEnrollments()` row shape has no `real_class_id` field to
     * reuse (it belongs to a different collaborator), so the real class of
     * an `existing` enrollment is looked up here instead, from
     * `enrollmentRealClassIds()`.
     *
     * @param  array<string, mixed>  $enrollmentRow
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<int, int>  $enrollmentRealClassIds
     * @return array{kind: 'id', id: int}|array{kind: 'ulid', ulid: string}|null
     */
    private function classIdentityOfEnrollment(array $enrollmentRow, Collection $classesByUlid, Collection $enrollmentRealClassIds): ?array
    {
        if (in_array($enrollmentRow['classification'], ['existing', 'conflict'], true)) {
            $classId = isset($enrollmentRow['existing_id']) ? $enrollmentRealClassIds->get((int) $enrollmentRow['existing_id']) : null;

            return $classId === null ? null : ['kind' => 'id', 'id' => (int) $classId];
        }

        if ($enrollmentRow['classification'] === 'new') {
            return $this->classIdentityFromUlid($enrollmentRow['class_ulid'] ?? null, $classesByUlid);
        }

        return null;
    }

    /**
     * @param  array{kind: 'id', id: int}|array{kind: 'ulid', ulid: string}|null  $left
     * @param  array{kind: 'id', id: int}|array{kind: 'ulid', ulid: string}|null  $right
     */
    private function sameClass(?array $left, ?array $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        if ($left['kind'] === 'id' && $right['kind'] === 'id') {
            return $left['id'] === $right['id'];
        }

        return $left['kind'] === 'ulid' && $right['kind'] === 'ulid' && $left['ulid'] === $right['ulid'];
    }

    /**
     * The REAL destination class of every `existing`/`conflict` enrollment
     * this tier could reference, batched once — never a query per row.
     *
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @return Collection<int, int>
     */
    private function enrollmentRealClassIds(Collection $enrollmentsByUlid): Collection
    {
        $ids = $enrollmentsByUlid->pluck('existing_id')->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : Enrollment::query()->whereIn('id', $ids)->pluck('class_id', 'id');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyClassGroups(array $rowsIn, Organization $destination, Collection $classesByUlid): array
    {
        $ulids = collect($rowsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(ClassGroup::class, $ulids, $destination);
        $classIds = $classesByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $classIds->isEmpty() ? collect() : ClassGroup::query()->where('organization_id', $destination->getKey())->whereIn('class_id', $classIds)->get()
            ->keyBy(fn (ClassGroup $group): string => "{$group->class_id}:{$group->label}");

        return collect($rowsIn)->map(function (array $row) use ($classesByUlid, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->label !== $row['label'];

                return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey(), 'real_class_id' => $existing->class_id];
            }

            $class = $classesByUlid->get($row['class_ulid']);
            $classResolvable = $class !== null && in_array($class['classification'], ['new', 'existing'], true);

            if (! $classResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A turma deste grupo não pode ser restaurada.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $class['classification'] === 'existing') {
                $match = $byBusinessKey->get("{$class['existing_id']}:{$row['label']}");

                if ($match !== null) {
                    $diverges = $match->label !== $row['label'];

                    return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey(), 'real_class_id' => $match->class_id];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'class_ulid' => $row['class_ulid'], 'label' => $row['label'],
                'position' => $row['position'], 'archived_at' => $row['archived_at'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $groupsByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  Collection<int, int>  $enrollmentRealClassIds
     * @return array<int, array<string, mixed>>
     */
    private function classifyMemberships(array $rowsIn, Organization $destination, Collection $classesByUlid, Collection $groupsByUlid, Collection $enrollmentsByUlid, Collection $enrollmentRealClassIds): array
    {
        $ulids = collect($rowsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(ClassGroupMembership::class, $ulids, $destination);
        $groupIds = $groupsByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $groupIds->isEmpty() ? collect() : ClassGroupMembership::query()->where('organization_id', $destination->getKey())->whereIn('class_group_id', $groupIds)->get()
            ->keyBy(fn (ClassGroupMembership $membership): string => "{$membership->class_group_id}:{$membership->enrollment_id}:{$membership->effective_from->toDateString()}");

        return collect($rowsIn)->map(function (array $row) use ($classesByUlid, $groupsByUlid, $enrollmentsByUlid, $enrollmentRealClassIds, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->effective_until?->toDateString() !== $row['effective_until'];

                return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey()];
            }

            $group = $groupsByUlid->get($row['class_group_ulid']);
            $groupResolvable = $group !== null && in_array($group['classification'], ['new', 'existing'], true);
            $enrollment = $enrollmentsByUlid->get($row['enrollment_ulid']);
            $enrollmentResolvable = $enrollment !== null && in_array($enrollment['classification'], ['new', 'existing'], true);

            if (! $groupResolvable || ! $enrollmentResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('O grupo ou a inscrição desta pertença não podem ser restaurados.')];
            }

            $groupIdentity = $this->classIdentityOfRow($group, $classesByUlid);
            $enrollmentIdentity = $this->classIdentityOfEnrollment($enrollment, $classesByUlid, $enrollmentRealClassIds);

            if (! $this->sameClass($groupIdentity, $enrollmentIdentity)) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('O grupo e a inscrição desta pertença não pertencem à mesma turma.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $group['classification'] === 'existing' && $enrollment['classification'] === 'existing') {
                $match = $byBusinessKey->get("{$group['existing_id']}:{$enrollment['existing_id']}:{$row['effective_from']}");

                if ($match !== null) {
                    $diverges = $match->effective_until?->toDateString() !== $row['effective_until'];

                    return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'class_group_ulid' => $row['class_group_ulid'], 'enrollment_ulid' => $row['enrollment_ulid'],
                'effective_from' => $row['effective_from'], 'effective_until' => $row['effective_until'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $groupsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifySlots(array $rowsIn, Organization $destination, Collection $classesByUlid, Collection $groupsByUlid): array
    {
        $ulids = collect($rowsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(RecurringLessonSlot::class, $ulids, $destination);
        $classIds = $classesByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $classIds->isEmpty() ? collect() : RecurringLessonSlot::query()->where('organization_id', $destination->getKey())->whereIn('class_id', $classIds)->get()
            ->keyBy(fn (RecurringLessonSlot $slot): string => "{$slot->class_id}:".($slot->class_group_id ?? 'null').":{$slot->day_of_week}:{$slot->starts_at}:{$slot->ends_at}");

        return collect($rowsIn)->map(function (array $row) use ($classesByUlid, $groupsByUlid, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->starts_at !== $row['starts_at'] || $existing->ends_at !== $row['ends_at']
                    || $existing->starts_on?->toDateString() !== $row['starts_on'] || $existing->ends_on?->toDateString() !== $row['ends_on'];

                return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey(), 'real_class_id' => $existing->class_id];
            }

            $class = $classesByUlid->get($row['class_ulid']);
            $classResolvable = $class !== null && in_array($class['classification'], ['new', 'existing'], true);
            $group = $row['class_group_ulid'] !== null ? $groupsByUlid->get($row['class_group_ulid']) : null;
            $groupResolvable = $row['class_group_ulid'] === null || ($group !== null && in_array($group['classification'], ['new', 'existing'], true));

            if (! $classResolvable || ! $groupResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A turma ou o grupo deste tempo do horário não podem ser restaurados.')];
            }

            if ($group !== null) {
                $classIdentity = $this->classIdentityFromClassRow($class);
                $groupIdentity = $this->classIdentityOfRow($group, $classesByUlid);

                if (! $this->sameClass($classIdentity, $groupIdentity)) {
                    return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('O grupo deste tempo do horário não pertence à mesma turma.')];
                }
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $class['classification'] === 'existing' && ($group === null || $group['classification'] === 'existing')) {
                $match = $byBusinessKey->get("{$class['existing_id']}:".($group['existing_id'] ?? 'null').":{$row['day_of_week']}:{$row['starts_at']}:{$row['ends_at']}");

                if ($match !== null) {
                    $diverges = $match->starts_on?->toDateString() !== $row['starts_on'] || $match->ends_on?->toDateString() !== $row['ends_on'];

                    return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey(), 'real_class_id' => $match->class_id];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'class_ulid' => $row['class_ulid'], 'class_group_ulid' => $row['class_group_ulid'],
                'day_of_week' => $row['day_of_week'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'],
                'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'],
                'split_lesson_key' => $row['split_lesson_key'],
            ];
        })->values()->all();
    }

    /**
     * No ulid of its own — a child row with a natural key, the same shape
     * `profile_version_domains` already has. Never a `conflict`: the key
     * itself (class, slot, instant) IS the whole row, so a match is always
     * the same fact restated, and a non-match is always genuinely new.
     *
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $groupsByUlid
     * @param  Collection<string, array<string, mixed>>  $slotsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyCancelledOccurrences(array $rowsIn, Organization $destination, User $actor, Collection $classesByUlid, Collection $groupsByUlid, Collection $slotsByUlid): array
    {
        $classIds = $classesByUlid->pluck('existing_id')->filter();
        $existing = $classIds->isEmpty() ? collect() : CancelledLessonOccurrence::query()->where('organization_id', $destination->getKey())->whereIn('class_id', $classIds)->get()
            ->keyBy(fn (CancelledLessonOccurrence $occurrence): string => "{$occurrence->class_id}:{$occurrence->recurring_lesson_slot_id}:{$occurrence->occurs_at->toIso8601String()}");

        return collect($rowsIn)->map(function (array $row) use ($classesByUlid, $groupsByUlid, $slotsByUlid, $actor, $existing): array {
            $class = $classesByUlid->get($row['class_ulid']);
            $classResolvable = $class !== null && in_array($class['classification'], ['new', 'existing'], true);
            $slot = $slotsByUlid->get($row['recurring_lesson_slot_ulid']);
            $slotResolvable = $slot !== null && in_array($slot['classification'], ['new', 'existing'], true);
            $group = $row['class_group_ulid'] !== null ? $groupsByUlid->get($row['class_group_ulid']) : null;
            $groupResolvable = $row['class_group_ulid'] === null || ($group !== null && in_array($group['classification'], ['new', 'existing'], true));

            if (! $classResolvable || ! $slotResolvable || ! $groupResolvable) {
                return ['ulid' => null, 'classification' => 'invalid', 'reason' => $this->t('A turma, o grupo ou o tempo do horário desta ocorrência cancelada não podem ser restaurados.')];
            }

            $classIdentity = $this->classIdentityFromClassRow($class);
            $slotIdentity = $this->classIdentityOfRow($slot, $classesByUlid);

            if (! $this->sameClass($classIdentity, $slotIdentity)) {
                return ['ulid' => null, 'classification' => 'invalid', 'reason' => $this->t('O tempo do horário desta ocorrência cancelada não pertence à mesma turma.')];
            }

            if ($group !== null) {
                $groupIdentity = $this->classIdentityOfRow($group, $classesByUlid);

                if (! $this->sameClass($classIdentity, $groupIdentity)) {
                    return ['ulid' => null, 'classification' => 'invalid', 'reason' => $this->t('O grupo desta ocorrência cancelada não pertence à mesma turma.')];
                }
            }

            if ($class['classification'] === 'existing' && $slot['classification'] === 'existing') {
                $match = $existing->get("{$class['existing_id']}:{$slot['existing_id']}:{$row['occurs_at']}");

                if ($match !== null) {
                    return ['ulid' => null, 'classification' => 'existing', 'reason' => null, 'existing_id' => $match->getKey()];
                }
            }

            $authorId = $this->resolveAuthor($row['cancelled_by_email'], $actor);

            return [
                'ulid' => null, 'classification' => 'new', 'reason' => null,
                'class_ulid' => $row['class_ulid'], 'class_group_ulid' => $row['class_group_ulid'],
                'recurring_lesson_slot_ulid' => $row['recurring_lesson_slot_ulid'], 'occurs_at' => $row['occurs_at'],
                'cancelled_by' => $authorId,
                'author_unresolved' => $authorId === null,
                'notice' => $authorId === null ? $this->unresolvedAuthorNotice('desta ocorrência cancelada') : null,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $groupsByUlid
     * @param  Collection<string, array<string, mixed>>  $slotsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyLessons(array $rowsIn, Organization $destination, User $actor, Collection $classesByUlid, Collection $groupsByUlid, Collection $slotsByUlid): array
    {
        $ulids = collect($rowsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(Lesson::class, $ulids, $destination);
        $classIds = $classesByUlid->pluck('existing_id')->filter();
        $candidates = $classIds->isEmpty() ? collect() : Lesson::query()->where('organization_id', $destination->getKey())->whereIn('class_id', $classIds)->get();

        return collect($rowsIn)->map(function (array $row) use ($classesByUlid, $groupsByUlid, $slotsByUlid, $actor, $lookups, $candidates): array {
            $existing = $lookups['existing']->get($row['ulid']);
            $backupHasAttendance = $row['attendance_recorded_at'] !== null;

            if ($existing !== null) {
                return $this->classifyLessonAgainstMatch($row, $existing, $existing->getKey());
            }

            $class = $classesByUlid->get($row['class_ulid']);
            $classResolvable = $class !== null && in_array($class['classification'], ['new', 'existing'], true);
            $group = $row['class_group_ulid'] !== null ? $groupsByUlid->get($row['class_group_ulid']) : null;
            $groupResolvable = $row['class_group_ulid'] === null || ($group !== null && in_array($group['classification'], ['new', 'existing'], true));
            $slot = $row['recurring_lesson_slot_ulid'] !== null ? $slotsByUlid->get($row['recurring_lesson_slot_ulid']) : null;
            $slotResolvable = $row['recurring_lesson_slot_ulid'] === null || ($slot !== null && in_array($slot['classification'], ['new', 'existing'], true));
            $authorId = $this->resolveAuthor($row['created_by_email'], $actor);
            $attendanceAuthorId = $this->resolveAuthor($row['attendance_recorded_by_email'], $actor);
            $outcomeAuthorId = $this->resolveAuthor($row['outcome_recorded_by_email'] ?? null, $actor);

            if (! $classResolvable || ! $groupResolvable || ! $slotResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A turma, o grupo ou o tempo do horário desta aula não podem ser restaurados.')];
            }

            $classIdentity = $this->classIdentityFromClassRow($class);

            if ($group !== null && ! $this->sameClass($classIdentity, $this->classIdentityOfRow($group, $classesByUlid))) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('O grupo desta aula não pertence à mesma turma.')];
            }

            if ($slot !== null && ! $this->sameClass($classIdentity, $this->classIdentityOfRow($slot, $classesByUlid))) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('O tempo do horário desta aula não pertence à mesma turma.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $class['classification'] === 'existing'
                && ($group === null || $group['classification'] === 'existing') && ($slot === null || $slot['classification'] === 'existing')) {
                $match = $slot !== null
                    ? $candidates->first(fn (Lesson $lesson): bool => $lesson->class_id === $class['existing_id'] && $lesson->recurring_lesson_slot_id === $slot['existing_id'] && $lesson->starts_at->toIso8601String() === $row['starts_at'])
                    : $candidates->first(fn (Lesson $lesson): bool => $lesson->class_id === $class['existing_id'] && $lesson->recurring_lesson_slot_id === null
                        && $lesson->class_group_id === ($group['existing_id'] ?? null) && $lesson->starts_at->toIso8601String() === $row['starts_at']);

                if ($match !== null) {
                    return $this->classifyLessonAgainstMatch($row, $match, $match->getKey());
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'class_ulid' => $row['class_ulid'], 'class_group_ulid' => $row['class_group_ulid'],
                'recurring_lesson_slot_ulid' => $row['recurring_lesson_slot_ulid'],
                'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'lesson_number' => $row['lesson_number'],
                'lesson_unit_key' => $row['lesson_unit_key'],
                'status' => $row['status'], 'attendance_recorded_at' => $row['attendance_recorded_at'],
                'outcome' => $row['outcome'] ?? null, 'outcome_reason' => $row['outcome_reason'] ?? null,
                'outcome_note' => $row['outcome_note'] ?? null, 'outcome_recorded_at' => $row['outcome_recorded_at'] ?? null,
                'outcome_recorded_by' => $outcomeAuthorId,
                'attendance_recorded_by' => $attendanceAuthorId,
                'created_by' => $authorId,
                'author_unresolved' => $authorId === null,
                'notice' => $authorId === null ? $this->unresolvedAuthorNotice('desta aula') : null,
                'has_attendance' => $backupHasAttendance,
            ];
        })->values()->all();
    }

    /**
     * The rule that makes a `Lesson` different from every other ulid-matched
     * row in this importer: an existing DRAFT (no recorded attendance yet)
     * never silently absorbs a backup that already consolidated it. Any
     * other divergence (starts_at, status) still uses the ordinary
     * conflict-on-divergence rule every other domain uses.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function classifyLessonAgainstMatch(array $row, Lesson $match, int $matchId): array
    {
        $matchHasAttendance = $match->attendance_recorded_at !== null;
        $backupHasAttendance = $row['attendance_recorded_at'] !== null;

        if (! $matchHasAttendance && $backupHasAttendance) {
            return [
                'ulid' => $row['ulid'], 'classification' => 'conflict',
                'reason' => $this->t('Esta aula já existe como rascunho por registar, e o backup traz assiduidade já consolidada — não é substituída automaticamente.'),
                'existing_id' => $matchId, 'real_class_id' => $match->class_id,
            ];
        }

        $diverges = $match->starts_at->toIso8601String() !== $row['starts_at'] || $match->status->value !== $row['status']
            || ($match->outcome->value ?? ($match->status->value === 'taught' ? 'taught' : null)) !== ($row['outcome'] ?? null);

        return [
            'ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing',
            'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $matchId,
            'has_attendance' => $matchHasAttendance, 'real_class_id' => $match->class_id,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $lessonsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifySummaries(array $rowsIn, Organization $destination, User $actor, Collection $lessonsByUlid): array
    {
        $ulids = collect($rowsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(LessonSummary::class, $ulids, $destination);
        $lessonIds = $lessonsByUlid->pluck('existing_id')->filter();
        $byLessonId = $lessonIds->isEmpty() ? collect() : LessonSummary::query()->where('organization_id', $destination->getKey())->whereIn('lesson_id', $lessonIds)->get()->keyBy('lesson_id');

        return collect($rowsIn)->map(function (array $row) use ($lessonsByUlid, $actor, $lookups, $byLessonId): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->content !== $row['content'];

                return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey()];
            }

            $lesson = $lessonsByUlid->get($row['lesson_ulid']);
            $lessonResolvable = $lesson !== null && in_array($lesson['classification'], ['new', 'existing'], true);

            if (! $lessonResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A aula deste sumário não pode ser restaurada.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $lesson['classification'] === 'existing') {
                $match = $byLessonId->get($lesson['existing_id']);

                if ($match !== null) {
                    $diverges = $match->content !== $row['content'];

                    return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }
            }

            $reviewerId = $this->resolveAuthor($row['reviewed_by_email'], $actor);

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'lesson_ulid' => $row['lesson_ulid'], 'content' => $row['content'],
                'private_notes' => $row['private_notes'], 'resources' => $row['resources'], 'homework' => $row['homework'],
                'reviewed_at' => $row['reviewed_at'], 'reviewed_by' => $reviewerId,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $lessonsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyPlans(array $rowsIn, Organization $destination, User $actor, Collection $lessonsByUlid): array
    {
        $ulids = collect($rowsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(LessonPlan::class, $ulids, $destination);
        $lessonIds = $lessonsByUlid->pluck('existing_id')->filter();
        $byLessonId = $lessonIds->isEmpty() ? collect() : LessonPlan::query()->where('organization_id', $destination->getKey())->whereIn('lesson_id', $lessonIds)->get()->keyBy('lesson_id');

        return collect($rowsIn)->map(function (array $row) use ($lessonsByUlid, $actor, $lookups, $byLessonId): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->planned_summary !== $row['planned_summary'];

                return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey()];
            }

            $lesson = $lessonsByUlid->get($row['lesson_ulid']);
            $lessonResolvable = $lesson !== null && in_array($lesson['classification'], ['new', 'existing'], true);
            $authorId = $this->resolveAuthor($row['created_by_email'], $actor);

            if (! $lessonResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A aula desta planificação não pode ser restaurada.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $lesson['classification'] === 'existing') {
                $match = $byLessonId->get($lesson['existing_id']);

                if ($match !== null) {
                    $diverges = $match->planned_summary !== $row['planned_summary'];

                    return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'lesson_ulid' => $row['lesson_ulid'], 'planned_summary' => $row['planned_summary'],
                'created_by' => $authorId,
                'author_unresolved' => $authorId === null,
                'notice' => $authorId === null ? $this->unresolvedAuthorNotice('desta planificação') : null,
            ];
        })->values()->all();
    }

    /**
     * `enrollment_id` is resolved through the ENROLLMENT, never taken from
     * the file — same discipline `WriteLessons`/`WritePedagogicalRecords`
     * apply everywhere else. Status/timing validity (present-on-a-recorded-
     * lesson only, absent-only-on-a-draft) was already checked at the
     * validation layer where enum/shape checks belong; this stage only
     * checks what depends on OTHER rows in this same backup: whether the
     * lesson and enrollment actually resolve, and whether the lesson stayed
     * a plain `new`/`existing` row rather than becoming a `conflict`.
     *
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $lessonsByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  Collection<int, int>  $enrollmentRealClassIds
     * @return array<int, array<string, mixed>>
     */
    private function classifyAttendances(array $rowsIn, Organization $destination, User $actor, Collection $classesByUlid, Collection $lessonsByUlid, Collection $enrollmentsByUlid, Collection $enrollmentRealClassIds): array
    {
        $ulids = collect($rowsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(LessonAttendance::class, $ulids, $destination);
        $lessonIds = $lessonsByUlid->pluck('existing_id')->filter();
        $byNaturalKey = $lessonIds->isEmpty() ? collect() : LessonAttendance::query()->where('organization_id', $destination->getKey())->whereIn('lesson_id', $lessonIds)->get()
            ->keyBy(fn (LessonAttendance $attendance): string => "{$attendance->lesson_id}:{$attendance->enrollment_id}");

        return collect($rowsIn)->map(function (array $row) use ($classesByUlid, $lessonsByUlid, $enrollmentsByUlid, $enrollmentRealClassIds, $actor, $lookups, $byNaturalKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->status->value !== $row['status'];

                return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey()];
            }

            $lesson = $lessonsByUlid->get($row['lesson_ulid']);
            $lessonIsNew = $lesson !== null && $lesson['classification'] === 'new';
            $lessonIsExisting = $lesson !== null && $lesson['classification'] === 'existing';
            $enrollment = $enrollmentsByUlid->get($row['enrollment_ulid']);
            $enrollmentResolvable = $enrollment !== null && in_array($enrollment['classification'], ['new', 'existing'], true);

            if (! $enrollmentResolvable || (! $lessonIsNew && ! $lessonIsExisting)) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A aula ou a inscrição desta linha de assiduidade não podem ser restauradas.')];
            }

            $lessonIdentity = $this->classIdentityOfRow($lesson, $classesByUlid);
            $enrollmentIdentity = $this->classIdentityOfEnrollment($enrollment, $classesByUlid, $enrollmentRealClassIds);

            if (! $this->sameClass($lessonIdentity, $enrollmentIdentity)) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A inscrição desta linha de assiduidade não pertence à turma desta aula.')];
            }

            // Só o rascunho ainda por consolidar aceita novas linhas — uma
            // aula que já é INSTANTÂNEO nesta organização (§13.3, "o
            // professor decide") não ganha linhas que ela própria nunca
            // escreveu; ver o docblock da classe.
            if ($lessonIsExisting) {
                $match = $byNaturalKey->get("{$lesson['existing_id']}:{$enrollment['existing_id']}");

                if ($match !== null) {
                    $diverges = $match->status->value !== $row['status'];

                    return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }

                return ['ulid' => $row['ulid'], 'classification' => 'conflict', 'reason' => $this->t('Esta aula já existe com outra assiduidade — a linha não é acrescentada a um instantâneo já consolidado.')];
            }

            // Uma linha «presente» só é um facto legítimo depois de a
            // assiduidade ter sido consolidada — antes disso o rascunho só
            // regista faltas (ver o docblock desta classe e
            // lesson_attendances_status_check). `$lesson` está garantido
            // `new` neste ponto (o ramo `existing` já devolveu acima), pelo
            // que `attendance_recorded_at` vem sempre desta mesma linha do
            // backup.
            if ($row['status'] === 'present' && $lesson['attendance_recorded_at'] === null) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('Uma linha «presente» só é válida numa aula cuja assiduidade já foi consolidada.')];
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'lesson_ulid' => $row['lesson_ulid'], 'enrollment_ulid' => $row['enrollment_ulid'], 'status' => $row['status'],
                'updated_by' => $this->resolveAuthor($row['updated_by_email'], $actor),
            ];
        })->values()->all();
    }
}
