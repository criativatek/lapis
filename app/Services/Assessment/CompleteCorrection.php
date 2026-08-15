<?php

namespace App\Services\Assessment;

use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\User;
use App\Support\Assessment\CorrectionWorkflowException;
use Illuminate\Support\Facades\DB;

/**
 * The teacher declaring that an instrument's correction is over — and undoing
 * that declaration.
 *
 * Saving and completing are different acts (§3.3): marking cells persists work,
 * completing states that the work is finished. Nothing here recalculates
 * anything — no score, allocation, total or qualitative rating is touched. It is
 * a workflow transition and nothing else, which is why an instrument's results
 * are byte-for-byte identical either side of it.
 *
 * Whether the correction MAY be closed is not decided here: InstrumentCompleteness
 * owns that rule, and the Avaliações page reads the same answer.
 */
class CompleteCorrection
{
    public function __construct(protected InstrumentCompleteness $completeness) {}

    public function complete(Instrument $instrument, User $teacher): Instrument
    {
        if ($instrument->status === InstrumentStatus::Cancelled) {
            throw CorrectionWorkflowException::cannotCompleteCancelled();
        }

        if ($instrument->status === InstrumentStatus::Completed) {
            throw CorrectionWorkflowException::alreadyCompleted();
        }

        if ($instrument->status !== InstrumentStatus::InCorrection) {
            throw CorrectionWorkflowException::notInCorrection($instrument->status->label());
        }

        $pending = $this->completeness->pendingCount($instrument);

        if ($pending > 0 || ! $this->completeness->for($instrument)['complete']) {
            throw CorrectionWorkflowException::stillPending(max($pending, 1));
        }

        return DB::transaction(function () use ($instrument, $teacher): Instrument {
            $instrument->update([
                'status' => InstrumentStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $teacher->getKey(),
            ]);

            return $instrument;
        });
    }

    /**
     * Back to being editable. The columns are cleared rather than kept as
     * history: they describe the completion currently in force, and a later
     * completion overwrites them with its own date and author.
     */
    public function reopen(Instrument $instrument, User $teacher): Instrument
    {
        if ($instrument->status === InstrumentStatus::Cancelled) {
            throw CorrectionWorkflowException::cannotReopenCancelled();
        }

        // Published is deliberately out of scope: results may already be out
        // there, and un-publishing is a decision this action must not take.
        if ($instrument->status !== InstrumentStatus::Completed) {
            throw CorrectionWorkflowException::notCompleted($instrument->status->label());
        }

        return DB::transaction(function () use ($instrument): Instrument {
            $instrument->update([
                'status' => InstrumentStatus::InCorrection,
                'completed_at' => null,
                'completed_by' => null,
            ]);

            return $instrument;
        });
    }
}
