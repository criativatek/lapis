<?php

namespace Tests\Unit\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationEngine;
use App\Domain\Assessment\CalculationRule;
use App\Domain\Assessment\ScoreInput;
use App\Models\ResultState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The §26.2 battery. The engine is pure, so these are plain unit tests — no
 * database, no tenant. Every non-negotiable assessment rule is pinned here.
 */
class CalculationEngineTest extends TestCase
{
    protected CalculationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new CalculationEngine;
    }

    /**
     * @param  list<array{domain_id: int, allocation_percent: string}>  $allocations
     */
    protected function score(
        string $possible,
        ResultState $state,
        ?string $earned,
        array $allocations,
        bool $eligible = true,
        bool $isBonus = false,
        string $item = 'Q1',
    ): ScoreInput {
        return new ScoreInput(
            instrumentId: 1,
            itemCode: $item,
            pointsPossible: $possible,
            state: $state,
            pointsEarned: $earned,
            isBonus: $isBonus,
            eligible: $eligible,
            allocations: $allocations,
        );
    }

    protected function rule(string $absence = 'exclude_all_warn', string $roundingMode = 'half_up', int $scale = 0): CalculationRule
    {
        return new CalculationRule(absenceMode: $absence, roundingMode: $roundingMode, roundingScale: $scale);
    }

    #[Test]
    public function a_single_assessed_item_gives_its_percentage(): void
    {
        $outcome = $this->engine->calculate(
            [$this->score('10', ResultState::Assessed, '8', [['domain_id' => 1, 'allocation_percent' => '100']])],
            [1 => '100'],
            $this->rule(),
        );

        $this->assertSame('80.000000', $outcome->normalizedValue);
        $this->assertSame('80', $outcome->proposedValue);
        $this->assertSame(ResultState::Assessed->value, $outcome->resultState);
        $this->assertFalse($outcome->coverageWarning);
    }

    #[Test]
    public function weights_combine_multiple_domains(): void
    {
        // Leitura 60% at 90%, Gramática 40% at 50% → 0.9*60 + 0.5*40 = 74 → 74%.
        $outcome = $this->engine->calculate(
            [
                $this->score('10', ResultState::Assessed, '9', [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q1'),
                $this->score('10', ResultState::Assessed, '5', [['domain_id' => 2, 'allocation_percent' => '100']], item: 'Q2'),
            ],
            [1 => '60', 2 => '40'],
            $this->rule(),
        );

        $this->assertSame('74.000000', $outcome->normalizedValue);
        $this->assertSame('74', $outcome->proposedValue);
    }

    #[Test]
    public function a_question_split_60_40_across_two_domains_scenario_a2(): void
    {
        // One 40-point question at 30/40 (75%), allocated 60/40 to two domains.
        // Both domains read 75%, and the overall is 75%.
        $outcome = $this->engine->calculate(
            [$this->score('40', ResultState::Assessed, '30', [
                ['domain_id' => 1, 'allocation_percent' => '60'],
                ['domain_id' => 2, 'allocation_percent' => '40'],
            ])],
            [1 => '50', 2 => '50'],
            $this->rule(),
        );

        $this->assertSame('75.000000', $outcome->domains[0]->normalizedValue);
        $this->assertSame('75.000000', $outcome->domains[1]->normalizedValue);
        $this->assertSame('75.000000', $outcome->normalizedValue);
    }

    #[Test]
    public function not_applicable_leaves_the_denominator(): void
    {
        // Two 10-point items; one assessed 8, one N/A. The student is scored over
        // 10, not 20 — N/A leaves the fraction on both sides (§13.3).
        $outcome = $this->engine->calculate(
            [
                $this->score('10', ResultState::Assessed, '8', [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q1'),
                $this->score('10', ResultState::NotApplicable, null, [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q2'),
            ],
            [1 => '100'],
            $this->rule(),
        );

        $this->assertSame('80.000000', $outcome->normalizedValue);
        $this->assertSame('10.0000', $outcome->domains[0]->pointsPossible);
    }

    #[Test]
    public function a_pending_item_does_not_count_as_zero(): void
    {
        $outcome = $this->engine->calculate(
            [
                $this->score('10', ResultState::Assessed, '7', [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q1'),
                $this->score('10', ResultState::Pending, null, [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q2'),
            ],
            [1 => '100'],
            $this->rule(),
        );

        // Scored over the one marked item — pending is missing data, not a zero.
        $this->assertSame('70.000000', $outcome->normalizedValue);
    }

    #[Test]
    public function an_absence_is_excluded_and_warns_under_the_default_rule(): void
    {
        $outcome = $this->engine->calculate(
            [
                $this->score('10', ResultState::Assessed, '8', [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q1'),
                $this->score('10', ResultState::Absent, null, [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q2'),
            ],
            [1 => '100'],
            $this->rule('exclude_all_warn'),
        );

        $this->assertSame('80.000000', $outcome->normalizedValue);
        $this->assertTrue($outcome->coverageWarning);
    }

    #[Test]
    public function absence_mode_zero_all_counts_an_absence_as_zero(): void
    {
        $outcome = $this->engine->calculate(
            [
                $this->score('10', ResultState::Assessed, '8', [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q1'),
                $this->score('10', ResultState::Absent, null, [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q2'),
            ],
            [1 => '100'],
            $this->rule('zero_all'),
        );

        // 8 / 20 → 40%. The absence occupies the denominator with a zero numerator.
        $this->assertSame('40.000000', $outcome->normalizedValue);
    }

    #[Test]
    public function absence_mode_zero_unjustified_only_separates_the_two(): void
    {
        $unjustified = $this->engine->calculate(
            [$this->score('10', ResultState::Absent, null, [['domain_id' => 1, 'allocation_percent' => '100']])],
            [1 => '100'],
            $this->rule('zero_unjustified_only'),
        );
        // Unjustified → zero over 10 → 0%. (A genuine zero, because the rule says so.)
        $this->assertSame('0.000000', $unjustified->normalizedValue);

        $justified = $this->engine->calculate(
            [$this->score('10', ResultState::AbsentJustified, null, [['domain_id' => 1, 'allocation_percent' => '100']])],
            [1 => '100'],
            $this->rule('zero_unjustified_only'),
        );
        // Justified → excluded → no elements → no result, not zero.
        $this->assertNull($justified->normalizedValue);
    }

    #[Test]
    public function a_late_entry_element_is_excluded_without_penalty(): void
    {
        // The student joined after this instrument, so it is ineligible. It must
        // not become a zero (§11.4, A3).
        $outcome = $this->engine->calculate(
            [
                $this->score('10', ResultState::Assessed, '9', [['domain_id' => 1, 'allocation_percent' => '100']], eligible: true, item: 'Q1'),
                $this->score('10', ResultState::Pending, null, [['domain_id' => 1, 'allocation_percent' => '100']], eligible: false, item: 'Q2'),
            ],
            [1 => '100'],
            $this->rule(),
        );

        $this->assertSame('90.000000', $outcome->normalizedValue);
    }

    #[Test]
    public function a_student_with_no_applicable_elements_has_no_result_not_zero(): void
    {
        // A3 at its limit: joined in the last week, nothing applicable → "sem
        // elementos", never zero.
        $outcome = $this->engine->calculate(
            [$this->score('10', ResultState::Pending, null, [['domain_id' => 1, 'allocation_percent' => '100']], eligible: false)],
            [1 => '100'],
            $this->rule(),
        );

        $this->assertNull($outcome->normalizedValue);
        $this->assertNull($outcome->proposedValue);
        $this->assertSame(ResultState::Pending->value, $outcome->resultState);
        $this->assertTrue($outcome->coverageWarning);
    }

    #[Test]
    public function a_domain_with_no_elements_is_dropped_and_weights_renormalize(): void
    {
        // Leitura (25%) at 50%, Escrita (75%) with no elements. A missing domain
        // must not drag the result to 12.5% — it is dropped and Leitura's weight
        // renormalizes to the whole (§13.4).
        $outcome = $this->engine->calculate(
            [$this->score('10', ResultState::Assessed, '5', [['domain_id' => 1, 'allocation_percent' => '100']])],
            [1 => '25', 2 => '75'],
            $this->rule(),
        );

        $this->assertSame('50.000000', $outcome->normalizedValue);
        $this->assertTrue($outcome->coverageWarning);
        $this->assertContains(2, $outcome->explanation['dropped_domains']);
    }

    #[Test]
    public function a_bonus_item_lifts_the_numerator_without_the_denominator(): void
    {
        // 10/10 plus a 2-point bonus fully earned → 12/10 → 120%.
        $outcome = $this->engine->calculate(
            [
                $this->score('10', ResultState::Assessed, '10', [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q1'),
                $this->score('2', ResultState::Assessed, '2', [['domain_id' => 1, 'allocation_percent' => '100']], isBonus: true, item: 'B1'),
            ],
            [1 => '100'],
            $this->rule(),
        );

        $this->assertSame('120.000000', $outcome->normalizedValue);
    }

    #[Test]
    public function a_genuine_zero_is_a_result_not_missing_data(): void
    {
        $outcome = $this->engine->calculate(
            [$this->score('10', ResultState::Assessed, '0', [['domain_id' => 1, 'allocation_percent' => '100']])],
            [1 => '100'],
            $this->rule(),
        );

        // A zero the teacher gave is a real 0%, with a value — distinct from null.
        $this->assertSame('0.000000', $outcome->normalizedValue);
        $this->assertSame(ResultState::Assessed->value, $outcome->resultState);
    }

    #[Test]
    public function decimal_precision_is_preserved_without_float_artifacts(): void
    {
        // 1/3 → 33.333333, truncated to 6 places, never 33.33333333333.
        $outcome = $this->engine->calculate(
            [$this->score('3', ResultState::Assessed, '1', [['domain_id' => 1, 'allocation_percent' => '100']])],
            [1 => '100'],
            $this->rule(),
        );

        $this->assertSame('33.333333', $outcome->normalizedValue);
    }

    #[Test]
    public function rounding_happens_once_at_the_proposal(): void
    {
        // 80.5 → 81 half_up; the normalized value keeps full precision.
        $outcome = $this->engine->calculate(
            [$this->score('200', ResultState::Assessed, '161', [['domain_id' => 1, 'allocation_percent' => '100']])],
            [1 => '100'],
            $this->rule('exclude_all_warn', 'half_up', 0),
        );

        $this->assertSame('80.500000', $outcome->normalizedValue);
        $this->assertSame('81', $outcome->proposedValue);
    }

    #[Test]
    public function half_even_rounding_is_supported(): void
    {
        $this->assertSame('80', Bc::round('80.5', 0, 'half_even'));
        $this->assertSame('82', Bc::round('81.5', 0, 'half_even'));
        $this->assertSame('81', Bc::round('80.5', 0, 'half_up'));
        $this->assertSame('80', Bc::round('80.4999', 0, 'half_up'));
        $this->assertSame('12.35', Bc::round('12.345', 2, 'half_up'));
    }

    #[Test]
    public function the_explanation_lists_included_and_excluded_elements(): void
    {
        $outcome = $this->engine->calculate(
            [
                $this->score('10', ResultState::Assessed, '8', [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q1'),
                $this->score('10', ResultState::NotApplicable, null, [['domain_id' => 1, 'allocation_percent' => '100']], item: 'Q2'),
            ],
            [1 => '100'],
            $this->rule(),
        );

        $domain = $outcome->explanation['domains'][0];
        $this->assertSame('Q1', $domain['included'][0]['item']);
        $this->assertSame('Q2', $domain['excluded'][0]['item']);
        $this->assertSame('not_applicable', $domain['excluded'][0]['reason']);
        $this->assertSame('half_up', $outcome->explanation['rounding']['mode']);
        $this->assertNull($outcome->explanation['scale_level']);
    }
}
