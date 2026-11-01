<?php

namespace App\Services\Import\Backup;

use App\Models\LessonOutcomeRectification;
use App\Models\Organization;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Schema v21 — the plan tier for `lesson_outcome_rectifications`: the history of the
 * corrections the exporting teacher made to the outcome of their OWN lessons.
 *
 * APPEND-ONLY, NEVER OVERWRITTEN. A row is `new` only when its lesson is restorable
 * in this same plan (`new` ∪ `existing`) and no row with the same identity is already
 * there. The identity is the `ulid` in this organization; under a fresh ulid (the
 * backup restored twice, or the ulid taken elsewhere) the same correction is
 * recognised by its lesson, its moment and its motive — so a re-import never
 * duplicates a rectification.
 *
 * The author is never claimed: `rectified_by` is the account confirming the import
 * only when the backup's `rectified_by_email` is literally that account's; otherwise
 * it stays empty and the row is restored all the same (the history is a fact about
 * the lesson, not a precondition to it).
 *
 * Plan rows carry no motive and no values: only a `new` row carries what the writer needs.
 */
class BuildLessonOutcomeRectificationsPlan
{
    use ResolvesBackupReferences;

    /**
     * @param  array<int, array<string, mixed>>  $rectificationsIn
     * @param  array<int, array<string, mixed>>  $lessonRows  the plan rows of `lessons`
     * @return array{rows: array{lesson_outcome_rectifications: array<int, array<string, mixed>>}}
     */
    public function build(array $rectificationsIn, Organization $destination, User $actor, array $lessonRows): array
    {
        $restorableLessons = collect($lessonRows)
            ->filter(fn (array $row): bool => in_array($row['classification'], ['new', 'existing'], true))
            ->pluck('ulid')
            ->flip();
        // The lessons that ALREADY exist in the destination (by the id the lessons plan resolved,
        // which also recognises a lesson restored under a fresh ulid): the only ones that can
        // already hold this correction.
        $existingLessonIds = collect($lessonRows)
            ->filter(fn (array $row): bool => $row['classification'] === 'existing' && isset($row['existing_id']))
            ->mapWithKeys(fn (array $row): array => [$row['ulid'] => (int) $row['existing_id']]);

        $ulids = collect($rectificationsIn)->pluck('ulid');

        $existingUlids = $ulids->isEmpty()
            ? collect()
            : LessonOutcomeRectification::query()
                ->where('organization_id', $destination->getKey())
                ->whereIn('ulid', $ulids)
                ->pluck('ulid')
                ->flip();
        $elsewhere = $this->ulidOrganizationsElsewhere(LessonOutcomeRectification::class, $ulids, $destination->getKey());
        $recognised = $this->recognisedByIdentity($existingLessonIds->values()->all(), $destination);

        $rows = collect($rectificationsIn)->map(function (array $row) use ($actor, $restorableLessons, $existingLessonIds, $existingUlids, $elsewhere, $recognised): array {
            $ulid = $row['ulid'];

            if ($existingUlids->has($ulid)) {
                return ['ulid' => $ulid, 'classification' => 'existing', 'reason' => null];
            }

            if (! $restorableLessons->has($row['lesson_ulid'])) {
                return ['ulid' => $ulid, 'classification' => 'invalid', 'reason' => $this->t('A aula desta correção não é restaurada, por isso a correção também não.')];
            }

            $lessonId = $existingLessonIds->get($row['lesson_ulid']);

            if ($lessonId !== null && $recognised->has($this->identity($lessonId, $row['rectified_at'], $row['motive']))) {
                return ['ulid' => $ulid, 'classification' => 'existing', 'reason' => null];
            }

            return [
                'ulid' => $ulid, 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $elsewhere->has($ulid),
                'lesson_ulid' => $row['lesson_ulid'],
                'rectified_by' => $this->resolveAuthor($row['rectified_by_email'] ?? null, $actor),
                'rectified_at' => $row['rectified_at'],
                'kind' => $row['kind'],
                'previous' => $row['previous'],
                'current' => $row['current'],
                'motive' => $row['motive'],
            ];
        })->values()->all();

        return ['rows' => ['lesson_outcome_rectifications' => $rows]];
    }

    /**
     * The corrections the destination already holds for these lessons: same lesson, same
     * moment, same motive — whatever ulid they carry.
     *
     * @param  list<int>  $lessonIds
     * @return Collection<string, true>
     */
    private function recognisedByIdentity(array $lessonIds, Organization $destination): Collection
    {
        if ($lessonIds === []) {
            return collect();
        }

        return LessonOutcomeRectification::query()
            ->where('organization_id', $destination->getKey())
            ->whereIn('lesson_id', $lessonIds)
            ->get()
            ->mapWithKeys(fn (LessonOutcomeRectification $existing): array => [
                $this->identity((int) $existing->lesson_id, $existing->rectified_at->toIso8601String(), $existing->motive) => true,
            ]);
    }

    private function identity(int $lessonId, string $rectifiedAt, string $motive): string
    {
        return $lessonId.'|'.Carbon::parse($rectifiedAt)->utc()->format('Y-m-d H:i:s').'|'.$motive;
    }
}
