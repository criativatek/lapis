<?php

namespace App\Services\Evidence\Followup;

use App\Models\AcademicPeriod;
use App\Models\EvidenceRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHEN the follow-up looks: one assessment period (the only window that has
 * alerts, statuses and «Registado no Inovar»), or a custom date range (a
 * plain consultation — counts and dates, nothing to confirm).
 *
 * Both are whole days on the pedagogical date (`occurred_at`), never on when
 * the row was typed in.
 */
final class FollowupWindow
{
    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals  inclusive day pairs
     */
    private function __construct(
        private readonly ?AcademicPeriod $period,
        private readonly array $intervals,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {}

    public static function forPeriod(AcademicPeriod $period, AssessmentPeriodTimeline $timeline): self
    {
        return new self(
            $period,
            $timeline->intervalsFor($period),
            CarbonImmutable::parse($period->starts_on->format('Y-m-d')),
            CarbonImmutable::parse($period->ends_on->format('Y-m-d')),
        );
    }

    public static function forRange(CarbonInterface $from, CarbonInterface $to): self
    {
        $from = CarbonImmutable::parse($from->format('Y-m-d'));
        $to = CarbonImmutable::parse($to->format('Y-m-d'));

        return new self(null, [[$from, $to]], $from, $to);
    }

    public function period(): ?AcademicPeriod
    {
        return $this->period;
    }

    public function isPeriod(): bool
    {
        return $this->period !== null;
    }

    public function from(): CarbonImmutable
    {
        return $this->from;
    }

    public function to(): CarbonImmutable
    {
        return $this->to;
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function intervals(): array
    {
        return $this->intervals;
    }

    /**
     * Restricts a logbook query to the window, as half-open datetime ranges —
     * a record typed with a time on the last day still belongs to it.
     *
     * @param  Builder<EvidenceRecord>  $query
     */
    public function applyTo(Builder $query): void
    {
        if ($this->intervals === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $window): void {
            foreach ($this->intervals as [$from, $to]) {
                $window->orWhere(fn (Builder $interval) => $interval
                    ->where('evidence_records.occurred_at', '>=', $from->format('Y-m-d 00:00:00'))
                    ->where('evidence_records.occurred_at', '<', $to->addDay()->format('Y-m-d 00:00:00')));
            }
        });
    }

    public function contains(CarbonInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        foreach ($this->intervals as [$from, $to]) {
            if ($from->format('Y-m-d') <= $day && $day <= $to->format('Y-m-d')) {
                return true;
            }
        }

        return false;
    }

    /**
     * «1.º Período (15/09/2026 a 16/12/2026)» or «01/10/2026 a 15/11/2026».
     */
    public function label(): string
    {
        $dates = __(':from a :to', ['from' => $this->from->format('d/m/Y'), 'to' => $this->to->format('d/m/Y')]);

        return $this->period === null ? $dates : "{$this->period->label} ({$dates})";
    }

    /**
     * How the Inovar message names the window: «no 1.º Período», or «no
     * período de 01/10/2026 a 15/11/2026» for a custom range.
     */
    public function messagePhrase(): string
    {
        if ($this->period !== null) {
            return __('no :period', ['period' => $this->period->label]);
        }

        return __('no período de :from a :to', ['from' => $this->from->format('d/m/Y'), 'to' => $this->to->format('d/m/Y')]);
    }
}
