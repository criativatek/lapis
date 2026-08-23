<?php

namespace App\Services\Assessment;

use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\ResultState;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\CorrectionWorkflowException;
use App\Support\Assessment\ScoreExceedsMaximumException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saves a batch of grid cells (§12.4).
 *
 * The grid sends only the cells the teacher touched, never the whole sheet — so
 * two teachers marking different columns of the same class do not overwrite each
 * other. A cell cleared back to empty deletes its row rather than storing a zero:
 * the absence of a row IS "por avaliar" (§4.4).
 */
class RecordScores
{
    public function __construct(protected AuditLog $audit) {}

    /**
     * @param  list<array{enrollment_id: int, instrument_item_id: int, result_state: string, lock_version?: int, points_earned?: float|null, scale_level_id?: int|null, state_reason?: string|null}>  $cells
     */
    public function save(Instrument $instrument, array $cells, User $actor): RecordScoresResult
    {
        // Scoring only opens once a grid is prepared, and closes again once
        // its correction is completed — enforced here rather than only in the
        // grid, since hiding the inputs is presentation, not access control.
        // A draft grid in particular has to stay outside this window: it may
        // still hold incomplete structure (null cotações, no domains), which
        // is exactly why it isn't "prepared" yet. Completed keeps its own,
        // more specific message ("reabra a correção") — the closed-and-done
        // case is not the same instruction as "prepare it first".
        if ($instrument->status === InstrumentStatus::Completed) {
            throw CorrectionWorkflowException::correctionIsClosed();
        }

        if (! in_array($instrument->status, [InstrumentStatus::Prepared, InstrumentStatus::InCorrection], true)) {
            throw CorrectionWorkflowException::notReadyForScoring($instrument->status->label());
        }

        // Validated before the transaction opens, not inside it: a rejected
        // batch must write nothing at all, matching how other multi-row
        // rules in this app are enforced (e.g. InstrumentBuilder::guard()).
        $this->guardAgainstScoresAboveMaximum($instrument, $cells);

        return DB::transaction(function () use ($instrument, $cells, $actor): RecordScoresResult {
            $written = 0;
            $versions = [];
            $stale = [];

            foreach ($cells as $cell) {
                $state = ResultState::from($cell['result_state']);
                $score = StudentItemScore::query()
                    ->where('instrument_item_id', $cell['instrument_item_id'])
                    ->where('enrollment_id', $cell['enrollment_id'])
                    ->lockForUpdate()
                    ->first();
                $currentVersion = $score === null ? 0 : $score->lock_version;

                // A caller that never sends a version — every internal writer
                // predating this check: seeders, imports, and any future one
                // that has no browser tab to conflict with — asked for no
                // staleness protection and gets the old unconditional write.
                // Only a request that names a version (the grid's own
                // "Guardar", which always does) can be rejected as stale.
                if (array_key_exists('lock_version', $cell) && $currentVersion !== $cell['lock_version']) {
                    $stale[] = [
                        'enrollment_id' => $cell['enrollment_id'],
                        'instrument_item_id' => $cell['instrument_item_id'],
                        'result_state' => $score?->result_state->value ?? ResultState::Pending->value,
                        'points_earned' => $score?->points_earned === null ? null : (float) $score->points_earned,
                        'state_reason' => $score?->state_reason,
                        'lock_version' => $currentVersion,
                    ];

                    continue;
                }

                // "Pending with no value" is the empty cell — remove the row so the
                // grid returns to genuinely having no data, not a stored blank.
                if ($state === ResultState::Pending) {
                    $deleted = $score?->delete() === true ? 1 : 0;

                    $written += $deleted;
                    $versions[] = [
                        'enrollment_id' => $cell['enrollment_id'],
                        'instrument_item_id' => $cell['instrument_item_id'],
                        'lock_version' => 0,
                    ];

                    continue;
                }

                // Only an assessed cell may carry a number. The model guard and the
                // database CHECK both refuse otherwise; this keeps the payload honest
                // so a stale client cannot smuggle a value onto an absence.
                $carries = $state->carriesValue();

                $score ??= new StudentItemScore([
                    'instrument_item_id' => $cell['instrument_item_id'],
                    'enrollment_id' => $cell['enrollment_id'],
                ]);
                $score->fill([
                    'instrument_id' => $instrument->id,
                    'result_state' => $state,
                    'points_earned' => $carries ? ($cell['points_earned'] ?? null) : null,
                    'scale_level_id' => $carries ? ($cell['scale_level_id'] ?? null) : null,
                    'state_reason' => $cell['state_reason'] ?? null,
                    'assessed_at' => $carries ? Carbon::now() : null,
                    'assessed_by' => $carries ? $actor->getKey() : null,
                    'lock_version' => $currentVersion + 1,
                ])->save();

                $written++;
                $versions[] = [
                    'enrollment_id' => $cell['enrollment_id'],
                    'instrument_item_id' => $cell['instrument_item_id'],
                    'lock_version' => $score->lock_version,
                ];
            }

            // Marking anything moves a prepared instrument into correction, so the
            // dashboard's "avaliações por corrigir" reflects reality without the
            // teacher having to set a status by hand.
            if ($written > 0 && $instrument->status->value === 'prepared') {
                $instrument->update(['status' => 'in_correction']);
            }

            if ($stale !== []) {
                $this->audit->record(
                    'scores.stale_write_rejected',
                    $instrument,
                    $actor,
                    count($stale).' célula(s) rejeitada(s) por escrita obsoleta.',
                    [
                        'cells' => array_map(fn (array $cell) => [
                            'enrollment_id' => $cell['enrollment_id'],
                            'instrument_item_id' => $cell['instrument_item_id'],
                        ], $stale),
                    ],
                );
            }

            return new RecordScoresResult($written, $versions, $stale);
        });
    }

    /**
     * A question's own points_possible is a hard ceiling — is_bonus only
     * excuses an item from the denominator (§4.2), it never raises what a
     * single question can itself be worth.
     *
     * @param  list<array{enrollment_id: int, instrument_item_id: int, result_state: string, lock_version?: int, points_earned?: float|null, scale_level_id?: int|null, state_reason?: string|null}>  $cells
     */
    protected function guardAgainstScoresAboveMaximum(Instrument $instrument, array $cells): void
    {
        $itemsById = $instrument->items()->get(['id', 'code', 'points_possible'])->keyBy('id');

        foreach ($cells as $cell) {
            $state = ResultState::from($cell['result_state']);
            $pointsEarned = $cell['points_earned'] ?? null;

            if (! $state->carriesValue() || $pointsEarned === null) {
                continue;
            }

            $item = $itemsById->get($cell['instrument_item_id']);

            if ($item !== null && (float) $pointsEarned > (float) $item->points_possible) {
                throw ScoreExceedsMaximumException::make(
                    $item->code,
                    (string) $pointsEarned,
                    (string) $item->points_possible,
                );
            }
        }
    }
}
