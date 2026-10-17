<?php

namespace App\Services\Import\Backup;

use App\Models\Organization;
use App\Models\TimetableActivity;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Collection;

/**
 * Schema v17 — the plan tier for `timetable_activities` (the exporter's own
 * non-teaching activities: direção de turma, apoio, reuniões,
 * docs/backup-schema.md).
 *
 * LIKE THE NOTEBOOK, THIS IS PERSONAL TO ONE TEACHER. An activity belongs to
 * the timetable of the account that wrote it, so the author is checked FIRST,
 * before any lookup in the destination: a row signed by anyone but the account
 * confirming the import would place an activity in somebody else's timetable.
 *
 * The class is only a reference. A row whose class cannot be restored in this
 * same plan is NOT refused — the activity (its title and hours are the fact)
 * is restored with no class, and that is the whole cost.
 *
 * NEVER OVERWRITES and NEVER DUPLICATES: the same `ulid` in this organization
 * is `existing` (or `conflict` when it differs, or belongs to someone else);
 * the same activity restored earlier under a fresh ulid (the source ulid
 * belonged to another organization) is recognised by its natural key and is
 * `existing` too. Plan rows never carry `title`, `label` or `name` — the
 * preview labels rows by those; only a `new` row carries it, as
 * `activity_title`, because the writer needs it.
 */
class BuildTimetableActivitiesPlan
{
    use ResolvesBackupReferences;

    /**
     * @param  array<int, array<string, mixed>>  $activitiesIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @return array{rows: array{timetable_activities: array<int, array<string, mixed>>}}
     */
    public function build(array $activitiesIn, Organization $destination, User $actor, Collection $classesByUlid): array
    {
        $ulids = collect($activitiesIn)->pluck('ulid');

        $existingByUlid = $ulids->isEmpty()
            ? collect()
            : TimetableActivity::query()
                ->where('organization_id', $destination->getKey())
                ->whereIn('ulid', $ulids)
                ->get()
                ->keyBy('ulid');
        $elsewhere = $this->ulidOrganizationsElsewhere(TimetableActivity::class, $ulids, $destination->getKey());
        $naturalKeys = $this->naturalKeyIndex($actor, $destination);

        $rows = collect($activitiesIn)->map(function (array $row) use ($actor, $classesByUlid, $existingByUlid, $elsewhere, $naturalKeys): array {
            $ulid = $row['ulid'];

            // 1. The author first — before anything about the destination.
            $authorId = $this->resolveAuthor($row['author_email'] ?? null, $actor);

            if ($authorId === null) {
                return ['ulid' => $ulid, 'classification' => 'invalid', 'reason' => $this->t('Esta outra atividade foi escrita por outra conta e não é restaurada: cada professor tem o seu horário.')];
            }

            // 2. The same ulid already in this organization.
            $existing = $existingByUlid->get($ulid);

            if ($existing !== null) {
                if ((int) $existing->user_id !== (int) $authorId) {
                    // Neutral and decided without comparing content: no oracle.
                    return ['ulid' => $ulid, 'classification' => 'invalid', 'reason' => $this->t('Esta outra atividade não pode ser restaurada nesta conta.')];
                }

                $diverges = $this->naturalKey($existing->toArray() + ['class_ulid' => null]) !== $this->naturalKey($row)
                    || $existing->kind->value !== $row['kind'];

                return [
                    'ulid' => $ulid, 'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey(),
                ];
            }

            // 3. The same activity under a fresh ulid (idempotent clone).
            $match = $naturalKeys->get($this->naturalKey($row));

            if ($match !== null) {
                return ['ulid' => $ulid, 'classification' => 'existing', 'reason' => null, 'existing_id' => $match];
            }

            // 4. New. The class is a reference only: kept when it can be
            // resolved in this plan, dropped otherwise.
            $class = $row['class_ulid'] === null ? null : $classesByUlid->get($row['class_ulid']);
            $classUlid = $class !== null && in_array($class['classification'], ['new', 'existing'], true)
                ? $row['class_ulid']
                : null;

            return [
                'ulid' => $ulid, 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $elsewhere->has($ulid),
                'user_id' => $authorId,
                'class_ulid' => $classUlid,
                'kind' => $row['kind'],
                'activity_title' => $row['title'],
                'day_of_week' => $row['day_of_week'],
                'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'],
                'starts_on' => $row['starts_on'],
                'ends_on' => $row['ends_on'],
                'numbering_enabled' => $row['numbering_enabled'] ?? false,
                'numbering_start' => $row['numbering_start'] ?? 1,
                'notice' => null,
            ];
        })->values()->all();

        return ['rows' => ['timetable_activities' => $rows]];
    }

    /**
     * The actor's own activities keyed by what makes one the same as another,
     * in ONE query for the whole plan.
     *
     * @return Collection<string, int>
     */
    private function naturalKeyIndex(User $actor, Organization $destination): Collection
    {
        return TimetableActivity::query()
            ->where('organization_id', $destination->getKey())
            ->where('user_id', $actor->getKey())
            ->get()
            ->mapWithKeys(fn (TimetableActivity $activity): array => [
                $this->naturalKey([
                    'kind' => $activity->kind->value,
                    'title' => $activity->title,
                    'day_of_week' => $activity->day_of_week,
                    'starts_at' => $activity->starts_at,
                    'ends_at' => $activity->ends_at,
                    'starts_on' => $activity->starts_on?->toDateString(),
                    'ends_on' => $activity->ends_on?->toDateString(),
                ]) => $activity->getKey(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function naturalKey(array $row): string
    {
        $starts = $row['starts_on'] ?? null;
        $ends = $row['ends_on'] ?? null;

        return (string) json_encode([
            (string) ($row['kind'] ?? ''),
            (string) ($row['title'] ?? ''),
            (int) ($row['day_of_week'] ?? 0),
            substr((string) ($row['starts_at'] ?? ''), 0, 5),
            substr((string) ($row['ends_at'] ?? ''), 0, 5),
            $starts === null ? null : substr((string) $starts, 0, 10),
            $ends === null ? null : substr((string) $ends, 0, 10),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
