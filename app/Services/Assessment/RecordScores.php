<?php

namespace App\Services\Assessment;

use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\ResultState;
use App\Models\StudentItemScore;
use App\Models\User;
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
    /**
     * @param  list<array{enrollment_id: int, instrument_item_id: int, result_state: string, points_earned?: float|null, scale_level_id?: int|null, state_reason?: string|null}>  $cells
     * @return int Number of cells written or removed.
     */
    public function save(Instrument $instrument, array $cells, User $actor): int
    {
        // A closed correction is read-only, and that is enforced here rather
        // than only in the grid: hiding the inputs is presentation, not access
        // control. Reopening is the explicit way back.
        if ($instrument->status === InstrumentStatus::Completed) {
            throw CorrectionWorkflowException::correctionIsClosed();
        }

        // Validated before the transaction opens, not inside it: a rejected
        // batch must write nothing at all, matching how other multi-row
        // rules in this app are enforced (e.g. InstrumentBuilder::guard()).
        $this->guardAgainstScoresAboveMaximum($instrument, $cells);

        return DB::transaction(function () use ($instrument, $cells, $actor): int {
            $written = 0;

            foreach ($cells as $cell) {
                $state = ResultState::from($cell['result_state']);

                // "Pending with no value" is the empty cell — remove the row so the
                // grid returns to genuinely having no data, not a stored blank.
                if ($state === ResultState::Pending) {
                    $deleted = StudentItemScore::where('instrument_item_id', $cell['instrument_item_id'])
                        ->where('enrollment_id', $cell['enrollment_id'])
                        ->delete();

                    $written += $deleted;

                    continue;
                }

                // Only an assessed cell may carry a number. The model guard and the
                // database CHECK both refuse otherwise; this keeps the payload honest
                // so a stale client cannot smuggle a value onto an absence.
                $carries = $state->carriesValue();

                StudentItemScore::updateOrCreate(
                    [
                        'instrument_item_id' => $cell['instrument_item_id'],
                        'enrollment_id' => $cell['enrollment_id'],
                    ],
                    [
                        'instrument_id' => $instrument->id,
                        'result_state' => $state,
                        'points_earned' => $carries ? ($cell['points_earned'] ?? null) : null,
                        'scale_level_id' => $carries ? ($cell['scale_level_id'] ?? null) : null,
                        'state_reason' => $cell['state_reason'] ?? null,
                        'assessed_at' => $carries ? Carbon::now() : null,
                        'assessed_by' => $carries ? $actor->getKey() : null,
                    ],
                );

                $written++;
            }

            // Marking anything moves a prepared instrument into correction, so the
            // dashboard's "avaliações por corrigir" reflects reality without the
            // teacher having to set a status by hand.
            if ($written > 0 && $instrument->status->value === 'prepared') {
                $instrument->update(['status' => 'in_correction']);
            }

            return $written;
        });
    }

    /**
     * A question's own points_possible is a hard ceiling — is_bonus only
     * excuses an item from the denominator (§4.2), it never raises what a
     * single question can itself be worth.
     *
     * @param  list<array{enrollment_id: int, instrument_item_id: int, result_state: string, points_earned?: float|null, scale_level_id?: int|null, state_reason?: string|null}>  $cells
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
