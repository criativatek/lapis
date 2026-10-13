<?php

namespace Tests\Unit\Evidence;

use App\Models\AcademicPeriod;
use App\Models\RecordFollowupCategory;
use App\Services\Evidence\Followup\AssessmentPeriodTimeline;
use App\Services\Evidence\Followup\FollowupWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which period a pedagogical date belongs to — never assumed: trimesters and
 * semesters as configured, gaps belong to no period, and an overlap only
 * counts in the first period by sequence.
 */
class AssessmentPeriodTimelineTest extends TestCase
{
    private function period(int $id, int $sequence, string $label, string $from, string $to): AcademicPeriod
    {
        $period = new AcademicPeriod(['label' => $label, 'sequence' => $sequence, 'starts_on' => $from, 'ends_on' => $to]);
        $period->id = $id;
        $period->ulid = 'period-'.$id;

        return $period;
    }

    private function trimesters(): AssessmentPeriodTimeline
    {
        return AssessmentPeriodTimeline::fromPeriods(collect([
            $this->period(3, 3, '3.º Período', '2027-04-12', '2027-06-15'),
            $this->period(1, 1, '1.º Período', '2026-09-14', '2026-12-16'),
            $this->period(2, 2, '2.º Período', '2027-01-04', '2027-03-26'),
        ]));
    }

    #[Test]
    public function a_date_belongs_to_the_trimester_that_contains_it_both_limits_included(): void
    {
        $timeline = $this->trimesters();

        $this->assertSame('1.º Período', $timeline->periodOn(CarbonImmutable::parse('2026-09-14'))?->label);
        $this->assertSame('1.º Período', $timeline->periodOn(CarbonImmutable::parse('2026-12-16 23:59:00'))?->label);
        $this->assertSame('2.º Período', $timeline->periodOn(CarbonImmutable::parse('2027-01-04'))?->label);
        $this->assertSame('3.º Período', $timeline->periodOn(CarbonImmutable::parse('2027-06-15'))?->label);
    }

    #[Test]
    public function a_date_in_a_holiday_gap_or_outside_the_year_belongs_to_no_period(): void
    {
        $timeline = $this->trimesters();

        $this->assertNull($timeline->periodOn(CarbonImmutable::parse('2026-12-23')));
        $this->assertNull($timeline->periodOn(CarbonImmutable::parse('2026-09-01')));
        $this->assertNull($timeline->periodOn(CarbonImmutable::parse('2027-07-01')));
        $this->assertFalse($timeline->covers(CarbonImmutable::parse('2026-12-10'), CarbonImmutable::parse('2027-01-10')));
        $this->assertTrue($timeline->covers(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));
    }

    #[Test]
    public function semesters_are_read_as_configured(): void
    {
        $timeline = AssessmentPeriodTimeline::fromPeriods(collect([
            $this->period(10, 1, '1.º Semestre', '2026-09-14', '2027-01-29'),
            $this->period(11, 2, '2.º Semestre', '2027-02-01', '2027-06-15'),
        ]));

        $this->assertSame('1.º Semestre', $timeline->periodOn(CarbonImmutable::parse('2027-01-29'))?->label);
        $this->assertSame('2.º Semestre', $timeline->periodOn(CarbonImmutable::parse('2027-02-01'))?->label);
        $this->assertNull($timeline->periodOn(CarbonImmutable::parse('2027-01-30')));
    }

    #[Test]
    public function an_overlap_counts_only_in_the_first_period_by_sequence(): void
    {
        $timeline = AssessmentPeriodTimeline::fromPeriods(collect([
            $this->period(1, 1, '1.º Período', '2026-09-14', '2026-12-20'),
            $this->period(2, 2, '2.º Período', '2026-12-15', '2027-03-26'),
        ]));

        $this->assertSame('1.º Período', $timeline->periodOn(CarbonImmutable::parse('2026-12-17'))?->label);
        $this->assertSame([['1.º Período', '2.º Período']], $timeline->overlaps());

        $second = $timeline->find(2);
        $this->assertNotNull($second);
        $intervals = $timeline->intervalsFor($second);
        $this->assertCount(1, $intervals);
        $this->assertSame('2026-12-21', $intervals[0][0]->format('Y-m-d'));
        $this->assertSame('2027-03-26', $intervals[0][1]->format('Y-m-d'));

        $window = FollowupWindow::forPeriod($second, $timeline);
        $this->assertFalse($window->contains(CarbonImmutable::parse('2026-12-17')));
        $this->assertTrue($window->contains(CarbonImmutable::parse('2026-12-21')));
    }

    #[Test]
    public function a_year_without_periods_has_no_period_for_any_date(): void
    {
        $timeline = AssessmentPeriodTimeline::fromPeriods(collect());

        $this->assertTrue($timeline->isEmpty());
        $this->assertNull($timeline->periodOn(CarbonImmutable::parse('2026-10-12')));
        $this->assertFalse($timeline->covers(CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-12')));
    }

    #[Test]
    public function the_window_names_itself_for_the_screen_and_for_the_message(): void
    {
        $timeline = $this->trimesters();
        $first = $timeline->find(1);
        $this->assertNotNull($first);

        $period = FollowupWindow::forPeriod($first, $timeline);
        $range = FollowupWindow::forRange(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $this->assertSame('1.º Período (14/09/2026 a 16/12/2026)', $period->label());
        $this->assertSame('no 1.º Período', $period->messagePhrase());
        $this->assertSame('01/10/2026 a 31/10/2026', $range->label());
        $this->assertSame('no período de 01/10/2026 a 31/10/2026', $range->messagePhrase());
    }

    #[Test]
    public function the_category_sql_expression_names_every_category(): void
    {
        foreach (RecordFollowupCategory::cases() as $category) {
            $this->assertStringContainsString("'{$category->value}'", RecordFollowupCategory::SQL_EXPRESSION);
        }
    }
}
