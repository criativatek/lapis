<?php

namespace App\Services;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodStatus;
use App\Models\AcademicYear;
use App\Models\CalculationSnapshot;
use App\Models\Classification;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\InterimAssessment;
use App\Models\Intervention;
use App\Models\ProfileVersionPeriod;
use App\Models\Report;
use App\Models\SelfAssessment;
use App\Support\AcademicYears\AcademicYearValidationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates an academic year together with its periods, in one
 * transaction (§24.2). A half-written year with missing periods is never left
 * behind if anything fails partway.
 */
class AcademicYearService
{
    /**
     * Every table with a RESTRICT foreign key to academic_periods.id, kept in
     * one place so "does this period have dependents" has exactly one list to
     * update if a tenth dependent table is ever added — the exact risk this
     * class's own history already flagged once, before periods ever carried
     * results (see the git history of syncPeriods() below).
     *
     * @var list<class-string>
     */
    private const DEPENDENT_MODELS = [
        CalculationSnapshot::class,
        Classification::class,
        ProfileVersionPeriod::class,
        Instrument::class,
        EvidenceRecord::class,
        SelfAssessment::class,
        Intervention::class,
        InterimAssessment::class,
        Report::class,
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $periods
     */
    public function create(array $attributes, array $periods): AcademicYear
    {
        return DB::transaction(function () use ($attributes, $periods): AcademicYear {
            $year = AcademicYear::create($attributes);
            $this->syncPeriods($year, $periods);

            return $year;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $periods
     */
    public function update(AcademicYear $year, array $attributes, array $periods): AcademicYear
    {
        return DB::transaction(function () use ($year, $attributes, $periods): AcademicYear {
            $year->update($attributes);
            $this->syncPeriods($year, $periods);

            return $year->refresh();
        });
    }

    /**
     * Diffs the submitted periods against what already exists, instead of
     * deleting and recreating every period on every save.
     *
     * A submitted period carrying an existing ulid is updated in place — its
     * database id, and anything that already hangs off it (snapshots,
     * instruments, classifications, ...) survive untouched. A submitted
     * period without a ulid is new. An existing period whose ulid stops being
     * submitted is a removal candidate: it is deleted only when nothing
     * depends on it yet (assertRemovable() below); otherwise the whole update
     * is rejected — and, since this runs inside the caller's DB::transaction,
     * nothing submitted alongside it is partially applied either.
     *
     * @param  list<array<string, mixed>>  $periods
     */
    protected function syncPeriods(AcademicYear $year, array $periods): void
    {
        $existing = $year->periods()->get()->keyBy('ulid');
        $submittedUlids = collect($periods)->pluck('ulid')->filter()->all();

        $toRemove = $existing->reject(
            fn (AcademicPeriod $period) => in_array($period->ulid, $submittedUlids, true),
        );

        // Guard every removal BEFORE anything is written — a rejected update
        // then never leaves a partial trail to roll back in the first place.
        foreach ($toRemove as $period) {
            $this->assertRemovable($period);
        }

        foreach ($toRemove as $period) {
            $period->delete();
        }

        $this->applySequences($year, $periods, $existing);

        // Every kept period is written to its real final state first...
        foreach ($periods as $periodData) {
            $existingPeriod = $this->existingFor($periodData, $existing);

            if ($existingPeriod !== null) {
                $existingPeriod->fill($this->periodAttributes($periodData));
                $existingPeriod->save();
            }
        }

        // ...and only then are brand-new periods created — by now every kept
        // period already holds its own final sequence, so a new period can
        // never collide with one that is still mid-move.
        foreach ($periods as $periodData) {
            if ($this->existingFor($periodData, $existing) === null) {
                // Periods start as draft; opening/closing them is a separate
                // action, not part of setting up the year's structure.
                $year->periods()->create([
                    ...$this->periodAttributes($periodData),
                    'status' => AcademicPeriodStatus::Draft->value,
                ]);
            }
        }
    }

    /**
     * `sequence` carries UNIQUE(academic_year_id, sequence). MySQL has no
     * deferred unique constraints, so writing a kept period's new sequence
     * straight away can collide with whatever currently holds that value —
     * classically, swapping two periods (A:1,B:2 -> B:1,A:2) would write A's
     * new sequence 2 while B still holds it.
     *
     * Every kept period whose sequence is actually changing is first parked
     * on a value that collides with NEITHER what any period currently holds
     * NOR what any submitted period will finally hold, and only then given
     * its real final value in syncPeriods() above. A period whose sequence
     * is not changing is left alone entirely, so a genuinely unchanged save
     * touches it not at all (Eloquent's own dirty tracking then issues it
     * zero queries).
     *
     * `sequence` is an unsignedTinyInteger (0-255) — unlike the wider columns
     * InstrumentBuilder parks with a large constant offset, there is no room
     * here to assume any fixed value is free, so the actual free value is
     * searched for instead.
     *
     * @param  list<array<string, mixed>>  $periods
     * @param  Collection<string, AcademicPeriod>  $existing
     */
    private function applySequences(AcademicYear $year, array $periods, Collection $existing): void
    {
        // Every value any period will occupy once this save is done — a kept
        // period's sequence must never be temporarily parked on one of these,
        // or the later write of that period's own real value would collide
        // with the still-parked one.
        $finalSequences = collect($periods)->pluck('sequence')->map(fn ($s) => (int) $s)->all();

        // What is actually on the table right now, after removals above but
        // before any of this pass's writes.
        $liveSequences = $year->periods()->pluck('sequence')->all();

        foreach ($periods as $periodData) {
            $existingPeriod = $this->existingFor($periodData, $existing);

            if ($existingPeriod === null || (int) $periodData['sequence'] === $existingPeriod->sequence) {
                continue;
            }

            $reserved = array_unique(array_merge($liveSequences, $finalSequences));
            $temp = 255;
            while (in_array($temp, $reserved, true)) {
                $temp--;
            }

            $liveSequences = array_values(array_diff($liveSequences, [$existingPeriod->sequence]));
            $liveSequences[] = $temp;

            $existingPeriod->update(['sequence' => $temp]);
        }
    }

    /**
     * @param  array<string, mixed>  $periodData
     * @param  Collection<string, AcademicPeriod>  $existing
     */
    private function existingFor(array $periodData, Collection $existing): ?AcademicPeriod
    {
        $ulid = $periodData['ulid'] ?? null;

        return $ulid === null ? null : $existing->get($ulid);
    }

    /**
     * The plain-column attributes shared by an updated existing period and a
     * freshly-created one, so the two branches in syncPeriods() never drift
     * on which fields a period carries. `status` is deliberately absent: a
     * period always starts Draft, and opening/closing an existing one is a
     * separate action, never a side effect of saving the year's structure.
     *
     * @param  array<string, mixed>  $periodData
     * @return array<string, mixed>
     */
    private function periodAttributes(array $periodData): array
    {
        return [
            'label' => $periodData['label'],
            'kind' => $periodData['kind'],
            'sequence' => $periodData['sequence'],
            'starts_on' => $periodData['starts_on'],
            'ends_on' => $periodData['ends_on'],
        ];
    }

    /**
     * Refuses to remove a period anything still points to. The FK is
     * RESTRICT on every table in DEPENDENT_MODELS, so this is what turns that
     * into a clear message instead of a raw database error.
     */
    private function assertRemovable(AcademicPeriod $period): void
    {
        if ($this->hasDependents($period)) {
            throw AcademicYearValidationException::cannotRemovePeriodWithDependents($period->label);
        }
    }

    /**
     * Whether anything in the nine RESTRICT-constrained tables above still
     * points at this period. KEEP DEPENDENT_MODELS IN SYNC if a tenth
     * dependent table is ever added — the same risk this class's docblock
     * already flagged once, before any of these tables existed.
     */
    private function hasDependents(AcademicPeriod $period): bool
    {
        foreach (self::DEPENDENT_MODELS as $model) {
            $exists = $model::query()
                // A soft-deleted row (EvidenceRecord, Intervention) still
                // physically exists and still trips the RESTRICT constraint
                // on DELETE, so it must count here exactly as a live row
                // would. Removing a global scope a given model never
                // registered is a harmless no-op, so this is safe to call
                // unconditionally for every model in the list above.
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->where('academic_period_id', $period->id)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }
}
