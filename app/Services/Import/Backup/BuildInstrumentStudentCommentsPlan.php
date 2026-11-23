<?php

namespace App\Services\Import\Backup;

use App\Models\InstrumentStudentComment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Collection;

/**
 * Schema v23 — the plan tier for `instrument_student_comments` (the teacher's
 * private comment on a student in an element of assessment,
 * docs/backup-schema.md). Runs AFTER `BuildAssessmentDataPlan` has classified
 * `instruments` and the enrolments have been classified, since every comment
 * references one of each.
 *
 * Like a note, a comment has no identity of its own beyond its ulid and the
 * (instrument, enrolment) pair the destination table's unique index enforces.
 * A ulid match whose instrument or enrolment no longer agrees with the
 * destination row is always a `conflict`, never quietly repointed.
 *
 * NEVER OVERWRITES: a body that differs from what a ulid match, or an
 * (instrument, enrolment) match, already holds is `conflict` — what the teacher
 * has already written about a child stays exactly as it is.
 */
class BuildInstrumentStudentCommentsPlan
{
    use ResolvesBackupReferences;

    /**
     * @param  array<int, array<string, mixed>>  $commentsIn
     * @param  Collection<string, array<string, mixed>>  $instrumentsByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @return array{rows: array{instrument_student_comments: array<int, array<string, mixed>>}}
     */
    public function build(array $commentsIn, Organization $destination, User $actor, Collection $instrumentsByUlid, Collection $enrollmentsByUlid): array
    {
        $ulids = collect($commentsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(InstrumentStudentComment::class, $ulids, $destination);

        $instrumentIds = $instrumentsByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $instrumentIds->isEmpty()
            ? collect()
            : InstrumentStudentComment::query()->where('organization_id', $destination->getKey())
                ->whereIn('instrument_id', $instrumentIds)
                ->get()
                ->keyBy(fn (InstrumentStudentComment $comment): string => "{$comment->instrument_id}:{$comment->enrollment_id}");

        $rows = collect($commentsIn)->map(function (array $row) use ($instrumentsByUlid, $enrollmentsByUlid, $lookups, $byBusinessKey, $actor): array {
            $instrument = $instrumentsByUlid->get($row['instrument_ulid']);
            $enrollment = $enrollmentsByUlid->get($row['enrollment_ulid']);

            $instrumentResolvable = $instrument !== null && in_array($instrument['classification'], ['new', 'existing'], true);
            $enrollmentResolvable = $enrollment !== null && in_array($enrollment['classification'], ['new', 'existing'], true);

            $instrumentRealId = ($instrument !== null && $instrument['classification'] === 'existing' && isset($instrument['existing_id']))
                ? (int) $instrument['existing_id']
                : null;
            $enrollmentRealId = ($enrollment !== null && $enrollment['classification'] === 'existing' && isset($enrollment['existing_id']))
                ? (int) $enrollment['existing_id']
                : null;

            // The destination rows this backup row designates, even when the
            // instrument is itself a `conflict` (edited since the export).
            // Used ONLY to confirm that a comment which already exists by ulid
            // still belongs to the same pair; a new comment is never written
            // against a `conflict` instrument.
            $instrumentDestinationId = ($instrument !== null && in_array($instrument['classification'], ['existing', 'conflict'], true) && isset($instrument['existing_id']))
                ? (int) $instrument['existing_id']
                : null;
            $enrollmentDestinationId = ($enrollment !== null && in_array($enrollment['classification'], ['existing', 'conflict'], true) && isset($enrollment['existing_id']))
                ? (int) $enrollment['existing_id']
                : null;

            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                if ($instrumentDestinationId !== $existing->instrument_id || $enrollmentDestinationId !== $existing->enrollment_id) {
                    return [
                        'ulid' => $row['ulid'], 'classification' => 'conflict',
                        'reason' => $this->t('Já existe um comentário com esta identidade, associado a outro elemento de avaliação ou a outro aluno.'),
                        'existing_id' => $existing->getKey(),
                    ];
                }

                $diverges = $existing->body !== $row['body'];

                return [
                    'ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey(),
                ];
            }

            if (! $instrumentResolvable || ! $enrollmentResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('O elemento de avaliação ou o aluno deste comentário não pode ser restaurado.')];
            }

            // The unique (instrument, enrolment) index forbids a second
            // comment for the same pair: a colleague at the destination may
            // already have written theirs, under a different ulid.
            if ($instrumentRealId !== null && $enrollmentRealId !== null) {
                $match = $byBusinessKey->get("{$instrumentRealId}:{$enrollmentRealId}");

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
                'instrument_ulid' => $row['instrument_ulid'], 'enrollment_ulid' => $row['enrollment_ulid'], 'body' => $row['body'],
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
                'created_by' => $createdBy, 'updated_by' => $updatedBy,
                'author_unresolved' => $authorUnresolved,
                'notice' => $authorUnresolved ? $this->unresolvedAuthorNotice('deste comentário') : null,
            ];
        })->values()->all();

        return ['rows' => ['instrument_student_comments' => $rows]];
    }
}
