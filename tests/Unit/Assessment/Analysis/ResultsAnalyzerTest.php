<?php

namespace Tests\Unit\Assessment\Analysis;

use App\Domain\Assessment\Analysis\AnalysisBand;
use App\Domain\Assessment\Analysis\Observation;
use App\Domain\Assessment\Analysis\ObservationStatus;
use App\Domain\Assessment\Analysis\ResultsAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResultsAnalyzerTest extends TestCase
{
    private function observation(string $key, ?string $exact, bool $partial = false): Observation
    {
        return new Observation($key, ObservationStatus::Classified, $exact, $partial);
    }

    private function missing(string $key, ObservationStatus $status): Observation
    {
        return new Observation($key, $status, null, false);
    }

    #[Test]
    public function empty_input_produces_a_clean_zero_analysis(): void
    {
        $analysis = (new ResultsAnalyzer)->analyse([], []);

        $this->assertSame(0, $analysis['universe']);
        $this->assertSame(0, $analysis['classified']);
        $this->assertNull($analysis['mean']);
        $this->assertNull($analysis['median']);
        $this->assertNull($analysis['min']);
        $this->assertNull($analysis['max']);
        $this->assertNull($analysis['threshold']['below']['percent']);
        $this->assertFalse($analysis['qualitative']['available']);
    }

    #[Test]
    public function mean_and_median_with_an_odd_count(): void
    {
        $observations = [
            $this->observation('1', '10'),
            $this->observation('2', '20'),
            $this->observation('3', '90'),
        ];

        $analysis = (new ResultsAnalyzer)->analyse($observations, []);

        $this->assertSame('40.0', $analysis['mean']);
        $this->assertSame('20.0', $analysis['median']);
    }

    #[Test]
    public function mean_and_median_with_an_even_count(): void
    {
        $observations = [
            $this->observation('1', '10'),
            $this->observation('2', '20'),
            $this->observation('3', '30'),
            $this->observation('4', '40'),
        ];

        $analysis = (new ResultsAnalyzer)->analyse($observations, []);

        $this->assertSame('25.0', $analysis['mean']);
        // (20 + 30) / 2 = 25
        $this->assertSame('25.0', $analysis['median']);
    }

    #[Test]
    public function denominators_exclude_every_non_classified_status(): void
    {
        $observations = [
            $this->observation('1', '80'),
            $this->missing('2', ObservationStatus::Pending),
            $this->missing('3', ObservationStatus::UnderReview),
            $this->missing('4', ObservationStatus::Absent),
            $this->missing('5', ObservationStatus::AbsentJustified),
            $this->missing('6', ObservationStatus::Exempt),
            $this->missing('7', ObservationStatus::NotApplicable),
            $this->missing('8', ObservationStatus::Annulled),
            $this->missing('9', ObservationStatus::OutOfScope),
        ];

        $analysis = (new ResultsAnalyzer)->analyse($observations, []);

        $this->assertSame(8, $analysis['universe'], 'Out of scope must not enter the universe.');
        $this->assertSame(1, $analysis['classified']);
        $this->assertSame(1, $analysis['out_of_scope']);
        $this->assertSame(7, $analysis['missing']['total']);
        $this->assertSame(1, $analysis['missing']['pending']);
        $this->assertSame(1, $analysis['missing']['under_review']);
        $this->assertSame(1, $analysis['missing']['absent']);
        $this->assertSame(1, $analysis['missing']['absent_justified']);
        $this->assertSame(1, $analysis['missing']['exempt']);
        $this->assertSame(1, $analysis['missing']['not_applicable']);
        $this->assertSame(1, $analysis['missing']['annulled']);
        $this->assertSame(1, $analysis['threshold']['at_or_above']['count']);
    }

    #[Test]
    public function the_threshold_is_evaluated_on_the_exact_value(): void
    {
        $observations = [
            $this->observation('below1', '49.499999'),
            $this->observation('below2', '49.46'),
            $this->observation('at', '49.5'),
        ];

        $analysis = (new ResultsAnalyzer)->analyse($observations, []);

        $this->assertSame(2, $analysis['threshold']['below']['count'], '49,46 displays as 49,5 but is still below the threshold.');
        $this->assertSame(1, $analysis['threshold']['at_or_above']['count']);
    }

    #[Test]
    public function quantitative_class_boundaries(): void
    {
        $observations = [
            $this->observation('a', '10.0'),
            $this->observation('b', '100'),
            $this->observation('c', '105'),
        ];

        $analysis = (new ResultsAnalyzer)->analyse($observations, []);
        $classes = $analysis['quantitative']['classes'];

        $this->assertSame(1, $classes[1]['count'], '10.0 goes to [10, 20[.');
        $this->assertSame(2, $classes[9]['count'], '100 and >100 both land in the last, closed class.');
        $this->assertSame('[0, 10[', $classes[0]['label']);
        $this->assertSame('[90, 100]', $classes[9]['label']);

        $this->assertTrue($classes[3]['below_threshold']);
        $this->assertFalse($classes[4]['below_threshold'], '[40, 50[ straddles the 49,5 threshold and is not flagged.');
    }

    #[Test]
    public function qualitative_distribution_with_the_system_1_to_5_bands(): void
    {
        $bands = [
            new AnalysisBand(1, 'insuficiente', 'Insuficiente', 1, true, '0', '19.499999'),
            new AnalysisBand(2, 'suficiente', 'Suficiente', 2, false, '19.5', '49.499999'),
            new AnalysisBand(3, 'bom', 'Bom', 3, false, '49.5', '69.499999'),
            new AnalysisBand(4, 'muito_bom', 'Muito Bom', 4, false, '69.5', '89.499999'),
            new AnalysisBand(5, 'excelente', 'Excelente', 5, false, '89.5', '100'),
        ];

        $observations = [
            $this->observation('a', '19.499999'),
            $this->observation('b', '19.5'),
            $this->observation('c', '49.499999'),
            $this->observation('d', '49.5'),
            $this->observation('e', '69.5'),
            $this->observation('f', '89.5'),
        ];

        $analysis = (new ResultsAnalyzer)->analyse($observations, $bands);

        $this->assertTrue($analysis['qualitative']['available']);
        $categoriesByKey = collect($analysis['qualitative']['categories'])->keyBy('key');

        $this->assertSame(1, $categoriesByKey['1']['count'], 'Insuficiente ends at 19,499999.');
        $this->assertSame(2, $categoriesByKey['2']['count'], '19,5 starts Suficiente; 49,499999 still belongs to it.');
        $this->assertSame(1, $categoriesByKey['3']['count'], '49,5 starts Bom.');
        $this->assertSame(1, $categoriesByKey['4']['count'], '69,5 starts Muito Bom.');
        $this->assertSame(1, $categoriesByKey['5']['count'], '89,5 starts Excelente.');
        $this->assertSame(0, $analysis['qualitative']['unplaced']);
    }

    #[Test]
    public function qualitative_distribution_with_a_custom_three_band_scale(): void
    {
        $bands = [
            new AnalysisBand('a', 'baixo', 'Baixo', 1, true, '0', '33'),
            new AnalysisBand('b', 'medio', 'Médio', 2, false, '33.000001', '66'),
            new AnalysisBand('c', 'alto', 'Alto', 3, false, '66.000001', '100'),
        ];

        $observations = [
            $this->observation('x', '10'),
            $this->observation('y', '50'),
            $this->observation('z', '90'),
        ];

        $analysis = (new ResultsAnalyzer)->analyse($observations, $bands);
        $categoriesByKey = collect($analysis['qualitative']['categories'])->keyBy('key');

        $this->assertSame(1, $categoriesByKey['a']['count']);
        $this->assertSame(1, $categoriesByKey['b']['count']);
        $this->assertSame(1, $categoriesByKey['c']['count']);
        $this->assertSame(0, $analysis['qualitative']['unplaced'], 'No fixed intervals — a custom scale places every value in its own bands.');
    }

    #[Test]
    public function a_scale_without_bands_makes_the_qualitative_distribution_unavailable(): void
    {
        $observations = [$this->observation('a', '50')];

        $analysis = (new ResultsAnalyzer)->analyse($observations, []);

        $this->assertFalse($analysis['qualitative']['available']);
        $this->assertSame([], $analysis['qualitative']['categories']);
    }

    #[Test]
    public function percentages_are_null_with_a_zero_denominator(): void
    {
        $observations = [$this->missing('1', ObservationStatus::Pending)];

        $analysis = (new ResultsAnalyzer)->analyse($observations, []);

        $this->assertSame(0, $analysis['classified']);
        $this->assertNull($analysis['threshold']['below']['percent']);
        $this->assertNull($analysis['threshold']['at_or_above']['percent']);
    }
}
