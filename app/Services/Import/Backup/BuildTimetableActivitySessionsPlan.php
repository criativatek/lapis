<?php

namespace App\Services\Import\Backup;

use App\Models\Organization;
use App\Models\TimetableActivitySession;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Collection;

/**
 * Schema v19 — the plan tier for `timetable_activity_occurrences` (the exporter's
 * own per-session records of their «outras atividades», docs/backup-schema.md).
 *
 * LIKE THE ACTIVITIES THEMSELVES, THIS IS PERSONAL TO ONE TEACHER. The author
 * is checked FIRST, before any lookup in the destination: a row signed by
 * anyone but the account confirming the import would put text in somebody
 * else's timetable history.
 *
 * THE ACTIVITY IS A REFERENCE, resolved through the activities plan of THIS
 * file: a session whose activity is restorable (`new`, or already there) is
 * attached to it; one whose activity is not — it was deleted at the source, or
 * refused here — is restored as an orphan, identified by the copy it carries
 * (title, kind, hours). Nothing is lost, and nothing is guessed.
 *
 * NEVER OVERWRITES and NEVER DUPLICATES: the same `ulid` in this organization
 * is `existing` (or `conflict` when the record differs, or belongs to someone
 * else); the same session restored earlier under a fresh ulid is recognised by
 * its natural key — (activity, date) when attached, (title, date, start) for an
 * orphan — and is `existing` too. Plan rows never carry `title`, `label` or
 * `name`, nor the summary: only a `new` row carries what the writer needs.
 */
class BuildTimetableActivitySessionsPlan
{
    use ResolvesBackupReferences;

    /**
     * @param  array<int, array<string, mixed>>  $sessionsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  array<int, array<string, mixed>>  $activityRows  the plan rows of `timetable_activities`
     * @return array{rows: array{timetable_activity_occurrences: array<int, array<string, mixed>>}}
     */
    public function build(
        array $sessionsIn,
        Organization $destination,
        User $actor,
        Collection $classesByUlid,
        array $activityRows,
    ): array {
        $ulids = collect($sessionsIn)->pluck('ulid');

        $existingByUlid = $ulids->isEmpty()
            ? collect()
            : TimetableActivitySession::query()
                ->where('organization_id', $destination->getKey())
                ->whereIn('ulid', $ulids)
                ->get()
                ->keyBy('ulid');
        $elsewhere = $this->ulidOrganizationsElsewhere(TimetableActivitySession::class, $ulids, $destination->getKey());
        $naturalKeys = $this->naturalKeyIndex($actor, $destination);
        $activityIds = $this->restorableActivities($activityRows);

        $rows = collect($sessionsIn)->map(function (array $row) use ($actor, $classesByUlid, $existingByUlid, $elsewhere, $naturalKeys, $activityIds): array {
            $ulid = $row['ulid'];

            // 1. The author first — before anything about the destination.
            $authorId = $this->resolveAuthor($row['author_email'] ?? null, $actor);

            if ($authorId === null) {
                return ['ulid' => $ulid, 'classification' => 'invalid', 'reason' => $this->t('Esta sessão de outra atividade foi escrita por outra conta e não é restaurada: cada professor tem os seus registos.')];
            }

            // 2. The same ulid already in this organization.
            $existing = $existingByUlid->get($ulid);

            if ($existing !== null) {
                if ((int) $existing->user_id !== (int) $authorId) {
                    // Neutral and decided without comparing content: no oracle.
                    return ['ulid' => $ulid, 'classification' => 'invalid', 'reason' => $this->t('Esta sessão de outra atividade não pode ser restaurada nesta conta.')];
                }

                $diverges = $existing->occurs_on->toDateString() !== $row['occurs_on']
                    || substr($existing->starts_at, 0, 5) !== $row['starts_at']
                    || $existing->status->value !== $row['status']
                    || (string) $existing->summary !== (string) $row['summary'];

                return [
                    'ulid' => $ulid, 'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey(),
                ];
            }

            // 3. The same session under a fresh ulid (idempotent clone).
            $activityId = $row['activity_ulid'] === null ? null : ($activityIds[$row['activity_ulid']] ?? null);
            $match = $naturalKeys->get($this->naturalKey($activityId, $row));

            if ($match !== null) {
                return ['ulid' => $ulid, 'classification' => 'existing', 'reason' => null, 'existing_id' => $match];
            }

            // 4. New. The class is a reference only: kept when it can be
            // resolved in this plan, dropped otherwise. The activity is kept
            // (by its source ulid) only when it is restorable too.
            $class = $row['class_ulid'] === null ? null : $classesByUlid->get($row['class_ulid']);
            $classUlid = $class !== null && in_array($class['classification'], ['new', 'existing'], true)
                ? $row['class_ulid']
                : null;

            return [
                'ulid' => $ulid, 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $elsewhere->has($ulid),
                'user_id' => $authorId,
                'activity_ulid' => $activityId === null ? null : $row['activity_ulid'],
                'class_ulid' => $classUlid,
                'activity_kind' => $row['activity_kind'],
                'activity_title' => $row['activity_title'],
                'occurs_on' => $row['occurs_on'],
                'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'],
                'summary' => $row['summary'],
                'status' => $row['status'],
                'sequence_number' => $row['sequence_number'],
                'notice' => null,
            ];
        })->values()->all();

        return ['rows' => ['timetable_activity_occurrences' => $rows]];
    }

    /**
     * The source activity ulids this restore can attach a session to — those
     * that are `new` in this plan (an id will exist by the time the sessions
     * are written) or already there — mapped to a key. A `conflict` or
     * `invalid` activity is not restorable: its sessions become orphans.
     *
     * @param  array<int, array<string, mixed>>  $activityRows
     * @return array<string, int|string> source ulid → existing id, or `new`
     */
    private function restorableActivities(array $activityRows): array
    {
        $map = [];

        foreach ($activityRows as $row) {
            if ($row['classification'] === 'existing' && isset($row['existing_id'])) {
                $map[(string) $row['ulid']] = (int) $row['existing_id'];
            } elseif ($row['classification'] === 'new') {
                $map[(string) $row['ulid']] = 'new';
            }
        }

        return $map;
    }

    /**
     * The actor's own sessions keyed by what makes one the same as another, in
     * ONE query for the whole plan.
     *
     * @return Collection<string, int>
     */
    private function naturalKeyIndex(User $actor, Organization $destination): Collection
    {
        return TimetableActivitySession::query()
            ->where('organization_id', $destination->getKey())
            ->where('user_id', $actor->getKey())
            ->get()
            ->mapWithKeys(fn (TimetableActivitySession $session): array => [
                $this->naturalKey($session->timetable_activity_id, [
                    'activity_title' => $session->activity_title,
                    'occurs_on' => $session->occurs_on->toDateString(),
                    'starts_at' => $session->starts_at,
                ]) => $session->getKey(),
            ]);
    }

    /**
     * (activity, date) when attached to an activity; (title, date, start) for
     * an orphan. A source activity that will be `new` here has no sessions yet,
     * so its key never matches an existing one.
     *
     * @param  array<string, mixed>  $row
     */
    private function naturalKey(int|string|null $activityId, array $row): string
    {
        $date = substr((string) ($row['occurs_on'] ?? ''), 0, 10);

        if ($activityId !== null) {
            return (string) json_encode(['activity', $activityId, $date], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return (string) json_encode(
            ['orphan', (string) ($row['activity_title'] ?? ''), $date, substr((string) ($row['starts_at'] ?? ''), 0, 5)],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
