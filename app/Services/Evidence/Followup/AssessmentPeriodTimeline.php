<?php

namespace App\Services\Evidence\Followup;

use App\Models\AcademicPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The assessment periods of ONE academic year, as the follow-up reads them:
 * which period a date belongs to, and which days each period really owns.
 *
 * Periods are configured freely (AcademicYearRequest checks neither gaps nor
 * overlaps), so this never assumes they tile the year:
 *
 *  - a date in a gap, or before the first / after the last period, belongs to
 *    NO period — never to «the year» (decisão do dono do produto, 2026-10-12);
 *  - a date inside two overlapping periods belongs to the FIRST by sequence —
 *    the convention the calendar already uses (AcademicYearCalendarController)
 *    — so one record is never counted in two periods. `intervalsFor()` is that
 *    rule turned into date ranges for SQL.
 *
 * Period status is not consulted: every period is created as draft and no
 * action ever advances it, so it says nothing about which one is current.
 */
final class AssessmentPeriodTimeline
{
    /**
     * @param  Collection<int, AcademicPeriod>  $periods  ordered by sequence
     */
    private function __construct(private readonly Collection $periods) {}

    public static function forYear(int $academicYearId): self
    {
        return new self(
            AcademicPeriod::query()
                ->where('academic_year_id', $academicYearId)
                ->orderBy('sequence')
                ->orderBy('id')
                ->get(),
        );
    }

    /**
     * @param  Collection<int, AcademicPeriod>  $periods
     */
    public static function fromPeriods(Collection $periods): self
    {
        return new self($periods->sortBy([['sequence', 'asc'], ['id', 'asc']])->values());
    }

    public function isEmpty(): bool
    {
        return $this->periods->isEmpty();
    }

    /**
     * @return Collection<int, AcademicPeriod>
     */
    public function periods(): Collection
    {
        return $this->periods;
    }

    public function find(int $periodId): ?AcademicPeriod
    {
        return $this->periods->first(fn (AcademicPeriod $period): bool => $period->id === $periodId);
    }

    public function findByUlid(string $ulid): ?AcademicPeriod
    {
        return $this->periods->first(fn (AcademicPeriod $period): bool => $period->ulid === $ulid);
    }

    /**
     * The period a pedagogical date belongs to — the first by sequence whose
     * whole-day range contains it — or null.
     */
    public function periodOn(CarbonInterface $date): ?AcademicPeriod
    {
        $day = $date->format('Y-m-d');

        return $this->periods->first(
            fn (AcademicPeriod $period): bool => $period->starts_on->format('Y-m-d') <= $day && $day <= $period->ends_on->format('Y-m-d'),
        );
    }

    /**
     * The days a period really owns, as inclusive [from, to] date pairs: its
     * own range minus every range of a period with a lower sequence. Usually
     * one pair; none when an earlier period covers it entirely.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function intervalsFor(AcademicPeriod $period): array
    {
        $intervals = [[CarbonImmutable::parse($period->starts_on->format('Y-m-d')), CarbonImmutable::parse($period->ends_on->format('Y-m-d'))]];

        foreach ($this->periods as $earlier) {
            if ($earlier->id === $period->id) {
                break;
            }

            $earlierFrom = CarbonImmutable::parse($earlier->starts_on->format('Y-m-d'));
            $earlierTo = CarbonImmutable::parse($earlier->ends_on->format('Y-m-d'));
            $remaining = [];

            foreach ($intervals as [$from, $to]) {
                if ($earlierTo->lt($from) || $earlierFrom->gt($to)) {
                    $remaining[] = [$from, $to];

                    continue;
                }

                if ($from->lt($earlierFrom)) {
                    $remaining[] = [$from, $earlierFrom->subDay()];
                }

                if ($to->gt($earlierTo)) {
                    $remaining[] = [$earlierTo->addDay(), $to];
                }
            }

            $intervals = $remaining;
        }

        return $intervals;
    }

    /**
     * Pairs of period labels whose ranges overlap — shown as a warning, since
     * a date there only counts in the first of them.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function overlaps(): array
    {
        $overlaps = [];
        $periods = $this->periods->values();

        foreach ($periods as $index => $period) {
            foreach ($periods->slice($index + 1) as $later) {
                if ($period->starts_on->format('Y-m-d') <= $later->ends_on->format('Y-m-d')
                    && $later->starts_on->format('Y-m-d') <= $period->ends_on->format('Y-m-d')) {
                    $overlaps[] = [$period->label, $later->label];
                }
            }
        }

        return $overlaps;
    }

    /**
     * Whether every day of [from, to] belongs to some period.
     */
    public function covers(CarbonInterface $from, CarbonInterface $to): bool
    {
        $uncovered = [[CarbonImmutable::parse($from->format('Y-m-d')), CarbonImmutable::parse($to->format('Y-m-d'))]];

        foreach ($this->periods as $period) {
            $periodFrom = CarbonImmutable::parse($period->starts_on->format('Y-m-d'));
            $periodTo = CarbonImmutable::parse($period->ends_on->format('Y-m-d'));
            $remaining = [];

            foreach ($uncovered as [$gapFrom, $gapTo]) {
                if ($periodTo->lt($gapFrom) || $periodFrom->gt($gapTo)) {
                    $remaining[] = [$gapFrom, $gapTo];

                    continue;
                }

                if ($gapFrom->lt($periodFrom)) {
                    $remaining[] = [$gapFrom, $periodFrom->subDay()];
                }

                if ($gapTo->gt($periodTo)) {
                    $remaining[] = [$periodTo->addDay(), $gapTo];
                }
            }

            $uncovered = $remaining;
        }

        return $uncovered === [];
    }
}
