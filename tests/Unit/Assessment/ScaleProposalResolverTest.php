<?php

namespace Tests\Unit\Assessment;

use App\Domain\Assessment\ScaleProposal;
use App\Models\Scale;
use App\Services\Assessment\ScaleProposalResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Turning a normalized percentage into the proposal on the profile's scale.
 *
 * The rule these tests defend: the scale as configured is the only source of
 * truth. Not "basic education means 1 to 5", not "divide the percentage by
 * five" — and never a percentage standing in for a classification it was never
 * translated into (§10.4).
 */
class ScaleProposalResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function resolver(): ScaleProposalResolver
    {
        return new ScaleProposalResolver;
    }

    /**
     * A shared system scale. organization_id is not fillable by design, and the
     * model's creating hook only leaves it null when the key is already set —
     * hence forceFill rather than mass assignment.
     */
    protected function systemScale(string $name, string $kind, int $min, int $max): Scale
    {
        $scale = new Scale(['name' => $name, 'kind' => $kind, 'min_value' => $min, 'max_value' => $max]);
        $scale->forceFill(['organization_id' => null]);
        $scale->save();

        return $scale;
    }

    /**
     * The system 1-to-5 scale, with the product-approved bands.
     */
    protected function oneToFive(): Scale
    {
        $scale = $this->systemScale('Escala 1 a 5', 'level', 1, 5);

        foreach ([
            ['code' => '1', 'label' => 'Fraco', 'sequence' => 1, 'numeric_value' => 1, 'band_min_normalized' => '0.000000', 'band_max_normalized' => '19.499999'],
            ['code' => '2', 'label' => 'Insuficiente', 'sequence' => 2, 'numeric_value' => 2, 'band_min_normalized' => '19.500000', 'band_max_normalized' => '49.499999'],
            ['code' => '3', 'label' => 'Suficiente', 'sequence' => 3, 'numeric_value' => 3, 'band_min_normalized' => '49.500000', 'band_max_normalized' => '69.499999'],
            ['code' => '4', 'label' => 'Bom', 'sequence' => 4, 'numeric_value' => 4, 'band_min_normalized' => '69.500000', 'band_max_normalized' => '89.499999'],
            ['code' => '5', 'label' => 'Muito Bom', 'sequence' => 5, 'numeric_value' => 5, 'band_min_normalized' => '89.500000', 'band_max_normalized' => '100.000000'],
        ] as $level) {
            $scale->levels()->create($level + ['is_negative' => false, 'normalized_value' => null]);
        }

        return $scale->load('levels');
    }

    protected function levelIdFor(Scale $scale, string $code): int
    {
        return $scale->levels->firstWhere('code', $code)->id;
    }

    // -------------------------------------------------------- scale 1 to 5

    #[Test]
    public function a_result_is_read_as_the_level_its_band_contains(): void
    {
        $scale = $this->oneToFive();

        // 80,1% sits in the 69,5–89,49 band: the proposal is 4, not 80.
        $proposal = $this->resolver()->resolve($scale, $this->levelIdFor($scale, '4'), '80.100000', '80');

        $this->assertSame('4', $proposal->value);
        $this->assertTrue($proposal->isResolved());
        $this->assertFalse($proposal->isPercentage);
    }

    #[Test]
    public function results_in_different_bands_produce_different_levels(): void
    {
        $scale = $this->oneToFive();
        $resolver = $this->resolver();

        $this->assertSame('2', $resolver->resolve($scale, $this->levelIdFor($scale, '2'), '30.000000', '30')->value);
        $this->assertSame('4', $resolver->resolve($scale, $this->levelIdFor($scale, '4'), '80.100000', '80')->value);
        $this->assertSame('5', $resolver->resolve($scale, $this->levelIdFor($scale, '5'), '95.000000', '95')->value);
    }

    #[Test]
    public function the_percentage_never_leaks_into_a_non_percentage_scale(): void
    {
        $scale = $this->oneToFive();

        $proposal = $this->resolver()->resolve($scale, $this->levelIdFor($scale, '4'), '80.100000', '80');

        // The bug this whole change exists to fix.
        $this->assertNotSame('80', $proposal->value);
        $this->assertFalse($proposal->isPercentage, 'A 1-5 proposal must never be rendered with a % sign.');
    }

    // ------------------------------------------------------ a numeric scale

    #[Test]
    public function a_numeric_scale_places_the_result_on_its_own_interval(): void
    {
        // The system "Escala 0 a 20": numeric, deliberately without levels. The
        // interval itself is the definition — no bands needed.
        $scale = $this->systemScale('Escala 0 a 20', 'numeric', 0, 20)->load('levels');

        // 0 + (80.1/100) × 20 = 16.02, before the profile's own rounding.
        $exact = $this->resolver()->resolve($scale, null, '80.100000', '80', 'half_up', 2);
        $this->assertSame('16.02', $exact->value);
        $this->assertFalse($exact->isPercentage);

        // With the default whole-number rounding, the same result reads 16.
        $rounded = $this->resolver()->resolve($scale, null, '80.100000', '80', 'half_up', 0);
        $this->assertSame('16', $rounded->value);
    }

    #[Test]
    public function the_numeric_formula_is_generic_across_intervals(): void
    {
        $resolver = $this->resolver();

        // 0-10: 80% → 8. Same arithmetic, no special case.
        $zeroToTen = $this->systemScale('Escala 0 a 10', 'numeric', 0, 10)->load('levels');
        $this->assertSame('8', $resolver->resolve($zeroToTen, null, '80.000000', '80', 'half_up', 0)->value);

        // 1-20, where min is NOT zero: 1 + 0.80 × 19 = 16.2, not 16.
        $oneToTwenty = $this->systemScale('Escala 1 a 20', 'numeric', 1, 20)->load('levels');
        $this->assertSame('16.2', $resolver->resolve($oneToTwenty, null, '80.000000', '80', 'half_up', 1)->value);

        // And a deliberately odd interval, to prove nothing is hardcoded:
        // 5 + 0.80 × 10 = 13.
        $fiveToFifteen = $this->systemScale('Escala 5 a 15', 'numeric', 5, 15)->load('levels');
        $this->assertSame('13', $resolver->resolve($fiveToFifteen, null, '80.000000', '80', 'half_up', 0)->value);
    }

    #[Test]
    public function the_numeric_proposal_obeys_the_profiles_own_rounding_rule(): void
    {
        $scale = $this->systemScale('Escala 0 a 20', 'numeric', 0, 20)->load('levels');
        $resolver = $this->resolver();

        // 16.02 under three different configured rules.
        $this->assertSame('16', $resolver->resolve($scale, null, '80.100000', '80', 'half_up', 0)->value);
        $this->assertSame('17', $resolver->resolve($scale, null, '80.100000', '80', 'ceil', 0)->value);
        $this->assertSame('16', $resolver->resolve($scale, null, '80.100000', '80', 'floor', 0)->value);
    }

    #[Test]
    public function a_numeric_scale_with_a_degenerate_interval_cannot_place_anything(): void
    {
        $scale = $this->systemScale('Escala inválida', 'numeric', 10, 10)->load('levels');

        $proposal = $this->resolver()->resolve($scale, null, '80.100000', '80');

        $this->assertSame(ScaleProposal::UNCONFIGURED, $proposal->state);
    }

    #[Test]
    public function a_numeric_scale_that_does_have_bands_uses_them(): void
    {
        $scale = $this->systemScale('Escala 0 a 20 com bandas', 'numeric', 0, 20);
        $level = $scale->levels()->create([
            'code' => '16', 'label' => '16', 'sequence' => 16, 'numeric_value' => 16,
            'band_min_normalized' => '77.500000', 'band_max_normalized' => '82.499999',
            'is_negative' => false, 'normalized_value' => null,
        ]);

        $proposal = $this->resolver()->resolve($scale->load('levels'), $level->id, '80.100000', '80');

        $this->assertSame('16', $proposal->value);
        $this->assertFalse($proposal->isPercentage);
    }

    // --------------------------------------------------- a percentage scale

    #[Test]
    public function a_percentage_scale_classifies_in_percent(): void
    {
        $scale = $this->systemScale('Percentagem (0 a 100)', 'percentage', 0, 100)->load('levels');

        $proposal = $this->resolver()->resolve($scale, null, '80.100000', '80');

        // Here the scale IS the percentage, so the rounded value is the
        // translation — and the "%" is legitimate.
        $this->assertSame('80', $proposal->value);
        $this->assertTrue($proposal->isPercentage);
        $this->assertTrue($proposal->isResolved());
    }

    // ------------------------------------------------------- custom scales

    #[Test]
    public function a_purely_qualitative_level_is_named_by_its_label(): void
    {
        $scale = $this->systemScale('Escala descritiva', 'level', 1, 3);
        // No numeric_value: §10.4 forbids inventing a number for a descriptor.
        $level = $scale->levels()->create([
            'code' => 'C', 'label' => 'Consolidado', 'sequence' => 3, 'numeric_value' => null,
            'band_min_normalized' => '70.000000', 'band_max_normalized' => '100.000000',
            'is_negative' => false, 'normalized_value' => null,
        ]);

        $proposal = $this->resolver()->resolve($scale->load('levels'), $level->id, '80.100000', '80');

        $this->assertSame('Consolidado', $proposal->value);
        $this->assertFalse($proposal->isPercentage);
    }

    #[Test]
    public function a_decimal_level_value_keeps_its_decimals_but_loses_the_padding(): void
    {
        $scale = $this->systemScale('Escala com meios valores', 'level', 0, 20);
        $level = $scale->levels()->create([
            'code' => '16.5', 'label' => '16,5', 'sequence' => 1, 'numeric_value' => '16.500',
            'band_min_normalized' => '80.000000', 'band_max_normalized' => '84.999999',
            'is_negative' => false, 'normalized_value' => null,
        ]);

        $proposal = $this->resolver()->resolve($scale->load('levels'), $level->id, '80.100000', '80');

        // "16.500" is a storage detail; 16.5 is what a teacher reads.
        $this->assertSame('16.5', $proposal->value);
    }

    // --------------------------------------------------- absence of result

    #[Test]
    public function no_elements_is_never_the_lowest_level(): void
    {
        $scale = $this->oneToFive();

        $proposal = $this->resolver()->resolve($scale, null, null, null);

        $this->assertNull($proposal->value);
        $this->assertSame(ScaleProposal::NO_RESULT, $proposal->state);
        // Emphatically not "1", and not "0".
        $this->assertNotSame('1', $proposal->value);
    }

    #[Test]
    public function no_result_is_distinguishable_from_an_unconfigured_scale(): void
    {
        $scale = $this->oneToFive();

        // Both render as "—", but they mean different things and the UI has to
        // be able to tell the teacher which one it is.
        $noResult = $this->resolver()->resolve($scale, null, null, null);
        $unconfigured = $this->resolver()->resolve(null, null, '80.100000', '80');

        $this->assertNotSame($noResult->state, $unconfigured->state);
    }

    #[Test]
    public function a_result_outside_every_band_does_not_fall_back_to_the_percentage(): void
    {
        $scale = $this->oneToFive();

        // The engine matched no band — the scale cannot answer, and 80 is not
        // an acceptable stand-in for the answer it did not give.
        $proposal = $this->resolver()->resolve($scale, null, '80.100000', '80');

        $this->assertNull($proposal->value);
        $this->assertSame(ScaleProposal::UNCONFIGURED, $proposal->state);
    }

    // ------------------------------------------------- bands drive the value

    #[Test]
    public function changing_the_bands_changes_the_proposal_and_not_the_result(): void
    {
        $scale = $this->oneToFive();
        $normalized = '80.100000';

        $before = $this->resolver()->resolve($scale, $this->levelIdFor($scale, '4'), $normalized, '80');
        $this->assertSame('4', $before->value);

        // Widen level 5 downwards, as a school with different thresholds would.
        $scale->levels->firstWhere('code', '5')->update(['band_min_normalized' => '80.000000']);
        $scale->levels->firstWhere('code', '4')->update(['band_max_normalized' => '79.999999']);

        $after = $this->resolver()->resolve($scale->fresh('levels'), $this->levelIdFor($scale, '5'), $normalized, '80');

        $this->assertSame('5', $after->value);
        // The underlying result never moved — only its reading on the scale did.
        $this->assertSame('80.100000', $normalized);
    }
}
