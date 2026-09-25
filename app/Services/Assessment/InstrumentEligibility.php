<?php

namespace App\Services\Assessment;

use App\Models\Instrument;
use App\Models\InstrumentStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE ONE DEFINITION of whether an instrument's data may feed averages,
 * classifications or official statistics — every other service (the engine's
 * caller, publication's under-review guard, readiness, displays, imports,
 * restore) asks this class rather than restating the rule.
 *
 * Three distinct notions, easy to conflate and each with its own method here:
 *
 *  - CONFIGURED to count (`isConfiguredToCount`): what the teacher set on the
 *    instrument, ignoring its status entirely. Used by displays that show the
 *    flag itself, not a computed eligibility.
 *  - CONTRIBUTES to averages (`contributesToAverages`): the actual gate into
 *    the calculation engine, publication and readiness. Configuration AND
 *    status both have to agree.
 *  - MAY SHOW working values (`mayShowWorkingValues`): whether the grid and
 *    per-instrument outcome may display provisional numbers while a
 *    correction is still in progress — independent of the classification
 *    filter above; a teacher watching a grid fill in mid-marking is not the
 *    same question as whether that instrument's numbers may feed a class
 *    average.
 *
 * THE RULES THIS ENCODES (decisões do proprietário, JANELA AG):
 *
 *  R1. An instrument may only feed averages/classifications once its
 *      correction is CONCLUDED — `InstrumentStatus::Completed` or `::Published`.
 *      NOT Archived, NOT Prepared/InCorrection/Draft/Cancelled. This part is
 *      SWITCHED OFF by default (`requiresConcluded()`, reading
 *      `lapis.assessment.averages_require_concluded_instruments`) because
 *      turning it on retroactively changes every already-calculated period
 *      result — a decision on retroactivity the owner has not made yet. While
 *      off, the legacy status gate (`InstrumentStatus::entersCalculation()`)
 *      applies unchanged.
 *  R2. A DIAGNOSTIC instrument (`purpose === 'diagnostic'`) NEVER feeds
 *      averages, regardless of its stored `counts_toward_classification`,
 *      status, import, restore or path. This part is ALWAYS on — every past
 *      diagnostic instrument was already stored as not counting, so this is
 *      historically inert.
 *  R3. Contributes to averages ⇔ concluded (per the switch) AND NOT
 *      diagnostic AND `counts_toward_classification = true`.
 */
class InstrumentEligibility
{
    public const DIAGNOSTIC = 'diagnostic';

    /**
     * Purpose is a pedagogical label the engine mostly ignores — except for
     * this one distinction, which is load-bearing (R2).
     */
    public function isDiagnostic(Instrument $instrument): bool
    {
        return $instrument->purpose === self::DIAGNOSTIC;
    }

    /**
     * What the teacher configured, ignoring status. A diagnostic instrument
     * is never configured to count, whatever its stored flag says — for a
     * display, this is the number that should be shown, never the raw
     * column (which R2 may not even have caught yet on an old row).
     */
    public function isConfiguredToCount(Instrument $instrument): bool
    {
        return (bool) $instrument->counts_toward_classification && ! $this->isDiagnostic($instrument);
    }

    /**
     * The switch (R1): whether an instrument must be CONCLUDED, rather than
     * merely in a state the engine reads, to count toward averages.
     */
    public function requiresConcluded(): bool
    {
        return (bool) config('lapis.assessment.averages_require_concluded_instruments');
    }

    /**
     * The status half of R3, respecting the switch.
     */
    public function statusContributes(InstrumentStatus $status): bool
    {
        return $this->requiresConcluded() ? $status->isConcluded() : $status->entersCalculation();
    }

    /**
     * R3 in full: the actual gate into averages, classifications and every
     * consumer that must agree on "what counted" (engine, publication,
     * readiness).
     */
    public function contributesToAverages(Instrument $instrument): bool
    {
        return $this->isConfiguredToCount($instrument) && $this->statusContributes($instrument->status);
    }

    /**
     * Whether the grid and per-instrument working values may show
     * provisional numbers while correcting — never gated by the
     * classification filter above; a teacher watching a grid fill in
     * mid-marking must keep seeing it even when the switch (R1) is on and
     * this instrument is not yet concluded.
     */
    public function mayShowWorkingValues(Instrument $instrument): bool
    {
        return $instrument->status->entersCalculation();
    }

    /**
     * A diagnostic instrument's OWN numbers (its engine outcome, grid,
     * stats) are never touched by R1/R3 — they are analysable once its own
     * correction is concluded, on the always-on isConcluded() gate, whatever
     * the averages switch says.
     */
    public function isDiagnosticAnalysable(Instrument $instrument): bool
    {
        return $this->isDiagnostic($instrument) && $instrument->status->isConcluded();
    }

    /**
     * The SQL form of contributesToAverages(), for querying instruments in
     * bulk rather than loading and filtering in PHP. Equivalent to applying
     * isConfiguredToCount() + statusContributes() to every row the query
     * would return.
     *
     * @param  Builder<Instrument>  $query
     * @return Builder<Instrument>
     */
    public function constrain(Builder $query): Builder
    {
        $statuses = array_values(array_filter(
            InstrumentStatus::cases(),
            fn (InstrumentStatus $status) => $this->statusContributes($status),
        ));

        return $query
            ->where('counts_toward_classification', true)
            ->where(fn (Builder $q) => $q->whereNull('purpose')->orWhere('purpose', '!=', self::DIAGNOSTIC))
            ->whereIn('status', array_map(fn (InstrumentStatus $status) => $status->value, $statuses));
    }
}
