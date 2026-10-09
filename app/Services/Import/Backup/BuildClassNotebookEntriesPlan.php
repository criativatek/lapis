<?php

namespace App\Services\Import\Backup;

use App\Models\ClassNotebookEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Schema v14 — the plan tier for `class_notebook_entries` (the exporter's own
 * private notebook of a class, docs/backup-schema.md). Runs right after
 * classes and enrollments are classified, since every entry references a class.
 *
 * THE NOTEBOOK IS PRIVATE TO ITS AUTHOR, and that is what makes this tier
 * different from every other one. Elsewhere an author that cannot be resolved
 * is left empty and the row is imported anyway; here the author decides who
 * can read the text, so an entry without an author, or signed by anyone but
 * the account confirming the import, would be a transfer of private writing to
 * somebody else. The author is therefore checked FIRST, before any lookup in
 * the destination — nothing about what already exists there can be inferred
 * from the answer (no oracle).
 *
 * NEVER OVERWRITES and NEVER RESURRECTS: a destination entry that differs is a
 * `conflict`, and one the teacher deleted since the export stays deleted
 * (deleting was a decision; restoring must neither undo it nor create a
 * duplicate beside it).
 *
 * Plan rows NEVER carry the keys `title`, `label` or `name` — the preview
 * labels rows by those, and a notebook title must not leak into the screen or
 * into "Pontos a rever". Only a `new` row carries the text, as `entry_title`
 * and `entry_body`, because the writer needs it; `existing`, `conflict` and
 * `invalid` rows carry neither.
 */
class BuildClassNotebookEntriesPlan
{
    use ResolvesBackupReferences;

    /**
     * @param  array<int, array<string, mixed>>  $entriesIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @return array{rows: array{class_notebook_entries: array<int, array<string, mixed>>}}
     */
    public function build(array $entriesIn, Organization $destination, User $actor, Collection $classesByUlid): array
    {
        $ulids = collect($entriesIn)->pluck('ulid');

        $existingByUlid = $ulids->isEmpty()
            ? collect()
            : ClassNotebookEntry::withTrashed()
                ->where('organization_id', $destination->getKey())
                ->whereIn('ulid', $ulids)
                ->get()
                ->keyBy('ulid');
        $elsewhere = $this->ulidOrganizationsElsewhere(ClassNotebookEntry::class, $ulids, $destination->getKey());
        $cloneIndex = $this->cloneIndex($entriesIn, $classesByUlid, $destination, $actor);

        $rows = collect($entriesIn)->map(function (array $row) use ($actor, $classesByUlid, $existingByUlid, $elsewhere, $cloneIndex): array {
            $ulid = $row['ulid'];

            // 1. The author first — before anything about the destination.
            $authorId = $this->resolveAuthor($row['author_email'] ?? null, $actor);

            if ($authorId === null) {
                return ['ulid' => $ulid, 'classification' => 'invalid', 'reason' => $this->t('Este registo do caderno foi escrito por outra conta e não é restaurado: o caderno é privado de quem o escreve.')];
            }

            $class = $classesByUlid->get($row['class_ulid']);

            // 2. The same ulid already in this organization, trashed included.
            $existing = $existingByUlid->get($ulid);

            if ($existing !== null) {
                // Integers on both sides, whatever the driver hands back: a
                // string id here would turn the author's own entry into a
                // refusal.
                if ((int) $existing->author_id !== (int) $authorId) {
                    // Neutral on purpose and decided WITHOUT comparing content:
                    // otherwise the answer would tell whether somebody else's
                    // entry holds this exact text.
                    return ['ulid' => $ulid, 'classification' => 'invalid', 'reason' => $this->t('Este registo do caderno não pode ser restaurado nesta conta.')];
                }

                if ($existing->trashed()) {
                    return $this->deletedSinceExport($ulid, $existing->getKey());
                }

                $classAgrees = $class !== null
                    && in_array($class['classification'], ['existing', 'conflict'], true)
                    && isset($class['existing_id'])
                    && (int) $class['existing_id'] === (int) $existing->class_id;

                if (! $classAgrees) {
                    return [
                        'ulid' => $ulid, 'classification' => 'conflict', 'existing_id' => $existing->getKey(),
                        'reason' => $this->t('Já existe um registo do caderno com esta identidade, associado a outra turma.'),
                    ];
                }

                $diverges = $existing->title !== $row['title'] || $existing->body !== $row['body'] || $existing->is_pinned !== $row['is_pinned'];

                return [
                    'ulid' => $ulid, 'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey(),
                ];
            }

            // 3. The class has to be restorable in this same plan.
            if ($class === null || ! in_array($class['classification'], ['new', 'existing'], true)) {
                return ['ulid' => $ulid, 'classification' => 'invalid', 'reason' => $this->t('A turma deste registo do caderno não pode ser restaurada.')];
            }

            // 4. Idempotent clone: the same entry restored earlier under a
            // fresh ulid (the source ulid belonged to another organization).
            if ($class['classification'] === 'existing' && isset($class['existing_id']) && ($row['created_at'] ?? null) !== null) {
                $match = $cloneIndex->get($this->cloneKey((int) $class['existing_id'], $row['created_at'], $row['title'], $row['body']));

                if ($match !== null) {
                    return $match['trashed']
                        ? $this->deletedSinceExport($ulid, $match['id'])
                        : ['ulid' => $ulid, 'classification' => 'existing', 'reason' => null, 'existing_id' => $match['id']];
                }
            }

            // 5. New.
            return [
                'ulid' => $ulid, 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $elsewhere->has($ulid),
                'class_ulid' => $row['class_ulid'],
                'author_id' => $authorId,
                'entry_title' => $row['title'],
                'entry_body' => $row['body'],
                'is_pinned' => $row['is_pinned'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'edited_at' => $row['edited_at'],
                'notice' => null,
            ];
        })->values()->all();

        return ['rows' => ['class_notebook_entries' => $rows]];
    }

    /**
     * @return array{ulid: string, classification: string, reason: string, existing_id: int}
     */
    private function deletedSinceExport(string $ulid, int $existingId): array
    {
        return [
            'ulid' => $ulid, 'classification' => 'conflict', 'existing_id' => $existingId,
            'reason' => $this->t('Este registo foi eliminado do caderno depois da exportação e não é reposto.'),
        ];
    }

    /**
     * The actor's own destination entries that could be the same entry under a
     * different ulid, in ONE query for the whole plan: only classes that
     * already exist, only the actor's, trashed included. Keyed by
     * class + creation instant (to the second, in the app's time zone) + title
     * + text. When both a live and a deleted twin exist, the live one wins.
     *
     * @param  array<int, array<string, mixed>>  $entriesIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @return Collection<string, array{id: int, trashed: bool}>
     */
    private function cloneIndex(array $entriesIn, Collection $classesByUlid, Organization $destination, User $actor): Collection
    {
        $classIds = collect($entriesIn)
            ->map(fn (array $row): mixed => ($classesByUlid->get($row['class_ulid'])['classification'] ?? null) === 'existing'
                ? ($classesByUlid->get($row['class_ulid'])['existing_id'] ?? null)
                : null)
            ->filter()
            ->unique()
            ->values();

        if ($classIds->isEmpty()) {
            return collect();
        }

        $index = collect();

        ClassNotebookEntry::withTrashed()
            ->where('organization_id', $destination->getKey())
            ->where('author_id', $actor->getKey())
            ->whereIn('class_id', $classIds)
            ->whereNotNull('created_at')
            ->get()
            ->each(function (ClassNotebookEntry $entry) use ($index): void {
                $key = $this->cloneKey($entry->class_id, $entry->created_at, $entry->title, $entry->body);
                $current = $index->get($key);

                if ($current === null || ($current['trashed'] && ! $entry->trashed())) {
                    $index->put($key, ['id' => $entry->getKey(), 'trashed' => $entry->trashed()]);
                }
            });

        return $index;
    }

    private function cloneKey(int $classId, mixed $createdAt, ?string $title, string $body): string
    {
        $second = Carbon::parse($createdAt)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');

        return (string) json_encode([$classId, $second, $title, $body], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
