<?php

namespace App\Services\Import\Backup;

use App\Models\Organization;
use App\Models\ResultsAnalysisNote;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Collection;

/**
 * Schema v13 — the plan tier for `results_analysis_notes` (the teacher's own
 * observations for an instrument's Resultados tab, docs/backup-schema.md).
 * Runs AFTER `BuildAssessmentDataPlan` has classified `instruments`, since
 * every note references one.
 *
 * A note has no author-facing identity of its own beyond its ulid and the
 * (instrument, context_kind) pair the destination table's own unique index
 * enforces — there is no "business key" to fall back to the way a class or
 * an instrument has a label/title. That index is also why a note is never
 * REASSOCIATED to a different instrument on restore: a ulid match whose
 * instrument no longer agrees with the destination row's own
 * `instrument_id` is always a `conflict`, never quietly repointed.
 *
 * NEVER OVERWRITES: a body that differs from what a ulid match, or a
 * (instrument, context_kind) match, already holds is always `conflict` —
 * the teacher who already wrote their own observations keeps them exactly
 * as they are; the backup's copy is never merged in.
 */
class BuildResultsAnalysisNotesPlan
{
    use ResolvesBackupReferences;

    /**
     * @param  array<int, array<string, mixed>>  $notesIn
     * @param  Collection<string, array<string, mixed>>  $instrumentsByUlid
     * @return array{rows: array{results_analysis_notes: array<int, array<string, mixed>>}}
     */
    public function build(array $notesIn, Organization $destination, User $actor, Collection $instrumentsByUlid): array
    {
        $ulids = collect($notesIn)->pluck('ulid');
        $lookups = $this->ulidLookups(ResultsAnalysisNote::class, $ulids, $destination);

        $instrumentIds = $instrumentsByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $instrumentIds->isEmpty()
            ? collect()
            : ResultsAnalysisNote::query()->where('organization_id', $destination->getKey())
                ->whereIn('instrument_id', $instrumentIds)
                ->get()
                ->keyBy(fn (ResultsAnalysisNote $note): string => "{$note->instrument_id}:{$note->context_kind}");

        $rows = collect($notesIn)->map(function (array $row) use ($instrumentsByUlid, $lookups, $byBusinessKey, $actor): array {
            $instrument = $instrumentsByUlid->get($row['instrument_ulid']);
            $instrumentResolvable = $instrument !== null && in_array($instrument['classification'], ['new', 'existing'], true);
            $instrumentRealId = ($instrument !== null && $instrument['classification'] === 'existing' && isset($instrument['existing_id']))
                ? (int) $instrument['existing_id']
                : null;
            // The destination instrument this backup row designates, even
            // when that instrument is itself a `conflict` (edited since the
            // export — e.g. concluded). Used ONLY to confirm that a note
            // which already exists by ulid is still attached to it; a new
            // note is never written against a `conflict` instrument.
            $instrumentDestinationId = ($instrument !== null && in_array($instrument['classification'], ['existing', 'conflict'], true) && isset($instrument['existing_id']))
                ? (int) $instrument['existing_id']
                : null;

            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                // A ulid match whose instrument does not agree with the
                // destination row's own instrument_id is a reassociation —
                // never performed automatically (see class docblock).
                if ($instrumentDestinationId === null || $instrumentDestinationId !== $existing->instrument_id) {
                    return [
                        'ulid' => $row['ulid'], 'classification' => 'conflict',
                        'reason' => $this->t('Já existe uma observação com esta identidade, associada a outro elemento de avaliação.'),
                        'existing_id' => $existing->getKey(),
                    ];
                }

                $diverges = $existing->body !== $row['body'];

                return [
                    'ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey(),
                ];
            }

            if (! $instrumentResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('O elemento de avaliação desta observação não pode ser restaurado.')];
            }

            // The destination's unique index forbids two notes for the same
            // (instrument, context_kind) — checked ALWAYS when the
            // instrument is `existing`, not only for a note ulid seen
            // elsewhere: a colleague at the destination may already have
            // written their own observation for this same instrument,
            // under a completely different ulid.
            if ($instrumentRealId !== null) {
                $match = $byBusinessKey->get("{$instrumentRealId}:{$row['context_kind']}");

                if ($match !== null) {
                    $diverges = $match->body !== $row['body'];

                    return [
                        'ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing',
                        'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey(),
                    ];
                }
            }

            $createdByEmail = $row['created_by_email'] ?? null;
            $updatedByEmail = $row['updated_by_email'] ?? null;
            $createdBy = $this->resolveAuthor($createdByEmail, $actor);
            $updatedBy = $this->resolveAuthor($updatedByEmail, $actor);
            $authorUnresolved = $createdBy === null || ($updatedByEmail !== null && $updatedBy === null);

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'instrument_ulid' => $row['instrument_ulid'], 'context_kind' => $row['context_kind'], 'body' => $row['body'],
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
                'created_by' => $createdBy, 'updated_by' => $updatedBy,
                'author_unresolved' => $authorUnresolved,
                'notice' => $authorUnresolved ? $this->unresolvedAuthorNotice('desta observação') : null,
            ];
        })->values()->all();

        return ['rows' => ['results_analysis_notes' => $rows]];
    }
}
