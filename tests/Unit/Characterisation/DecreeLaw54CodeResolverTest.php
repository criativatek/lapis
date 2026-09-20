<?php

namespace Tests\Unit\Characterisation;

use App\Models\Organization;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Support\Characterisation\AcronymDictionary;
use App\Support\Characterisation\CodeConfidence;
use App\Support\Characterisation\DecreeLaw54CodeResolver;
use App\Support\Interventions\LegalFrameworkRegistry;
use App\Support\Interventions\LegalFrameworkResolver;
use App\Support\Tenancy\CurrentOrganization;
use Tests\TestCase;

/**
 * The resolver's whole job is knowing when NOT to answer, so most of what is
 * asserted here is restraint: a letter that stays a letter, an acronym that
 * stays unexpanded, a contradiction that stays a contradiction.
 *
 * No database: the resolver reads an organization's jurisdiction and a
 * dictionary, and neither needs a row to exist.
 */
class DecreeLaw54CodeResolverTest extends TestCase
{
    private function resolver(string $jurisdiction = 'PT'): DecreeLaw54CodeResolver
    {
        $organization = new Organization;
        $organization->jurisdiction = $jurisdiction;

        $tenant = new class($organization) extends CurrentOrganization
        {
            public function __construct(private readonly Organization $resolved) {}

            public function isResolved(): bool
            {
                return true;
            }

            public function get(): Organization
            {
                return $this->resolved;
            }
        };

        return new DecreeLaw54CodeResolver(
            new AcronymDictionary,
            new LegalFrameworkResolver(new LegalFrameworkRegistry),
            $tenant,
        );
    }

    public function test_a_named_measure_resolves_on_its_own(): void
    {
        $resolutions = $this->resolver()->resolveCell('ACNS');

        $this->assertCount(1, $resolutions);
        $this->assertSame(CodeConfidence::Recognised, $resolutions[0]->confidence);
        $this->assertSame(SupportMeasureCode::NonSignificantCurricularAdaptation, $resolutions[0]->code);
        $this->assertSame(SupportMeasureLevel::Selective, $resolutions[0]->level);
        $this->assertTrue($resolutions[0]->isStorable());
    }

    /** The brief's worked example: strong contextual evidence, recognised. */
    public function test_a_level_a_letter_and_a_measure_together_are_recognised(): void
    {
        $resolutions = $this->resolver()->resolveCell('MS b) + ACNS');

        $this->assertCount(1, $resolutions);
        $this->assertSame(CodeConfidence::Recognised, $resolutions[0]->confidence);
        $this->assertSame(SupportMeasureCode::NonSignificantCurricularAdaptation, $resolutions[0]->code);
        // The letter was read and kept, but it did not become the measure.
        $this->assertSame(['b)'], $resolutions[0]->unresolvedAnnotations);
    }

    /**
     * The case the brief is most explicit about. A letter alone says nothing
     * without knowing which level's list it indexes into.
     */
    public function test_a_bare_letter_without_a_level_is_ambiguous(): void
    {
        $resolutions = $this->resolver()->resolveCell('b)');

        $this->assertCount(1, $resolutions);
        $this->assertSame(CodeConfidence::Ambiguous, $resolutions[0]->confidence);
        $this->assertNull($resolutions[0]->level);
        $this->assertNull($resolutions[0]->code);
        $this->assertFalse($resolutions[0]->isStorable());
    }

    public function test_a_column_level_gives_a_bare_letter_its_level_but_never_a_measure(): void
    {
        $resolutions = $this->resolver()->resolveCell('b)', SupportMeasureLevel::Universal);

        $this->assertSame(CodeConfidence::Ambiguous, $resolutions[0]->confidence);
        $this->assertSame(SupportMeasureLevel::Universal, $resolutions[0]->level);
        $this->assertNull($resolutions[0]->code);
        $this->assertSame(['b)'], $resolutions[0]->unresolvedAnnotations);
    }

    public function test_letters_never_resolve_to_a_measure_even_with_a_level(): void
    {
        $resolutions = $this->resolver()->resolveCell('MU a) b) e)');

        $this->assertSame(CodeConfidence::Ambiguous, $resolutions[0]->confidence);
        $this->assertSame(SupportMeasureLevel::Universal, $resolutions[0]->level);
        $this->assertNull($resolutions[0]->code);
        $this->assertSame(['a)', 'b)', 'e)'], $resolutions[0]->unresolvedAnnotations);
    }

    /**
     * Two sources disagreeing is not evidence for either of them. Picking the
     * measure and discarding the stated level would be the application quietly
     * overruling the school's own document.
     */
    public function test_a_level_contradicting_its_measure_is_ambiguous(): void
    {
        $resolutions = $this->resolver()->resolveCell('MS + ACS');

        $this->assertSame(CodeConfidence::Ambiguous, $resolutions[0]->confidence);
        $this->assertNull($resolutions[0]->code);
        $this->assertStringContainsString('não corresponde', $resolutions[0]->note);
    }

    public function test_a_known_acronym_with_no_confirmed_meaning_is_not_invented(): void
    {
        foreach (['RTP', 'PEI', 'CRI', 'PIT', 'GAAF'] as $token) {
            $resolutions = $this->resolver()->resolveCell($token);

            $this->assertSame(CodeConfidence::Unrecognised, $resolutions[0]->confidence, $token);
            $this->assertNull($resolutions[0]->code, $token);
            $this->assertNull($resolutions[0]->level, $token);
            $this->assertFalse($resolutions[0]->isStorable(), $token);
        }
    }

    public function test_an_unknown_acronym_is_unrecognised(): void
    {
        $resolutions = $this->resolver()->resolveCell('XPTO');

        $this->assertSame(CodeConfidence::Unrecognised, $resolutions[0]->confidence);
        $this->assertFalse($resolutions[0]->isStorable());
    }

    /** PLNM is confirmed, but it is not a support measure and must not become one. */
    public function test_a_confirmed_acronym_that_is_not_a_measure_stays_out_of_the_measure_destination(): void
    {
        $resolutions = $this->resolver()->resolveCell('PLNM');

        $this->assertSame(CodeConfidence::Unrecognised, $resolutions[0]->confidence);
        $this->assertNull($resolutions[0]->code);
        $this->assertStringContainsString('Português Língua Não Materna', $resolutions[0]->note);
    }

    public function test_full_labels_resolve_and_the_longer_one_wins(): void
    {
        $significant = $this->resolver()->resolveCell('Adaptação curricular significativa');

        $this->assertSame(SupportMeasureCode::SignificantCurricularAdaptation, $significant[0]->code);

        $nonSignificant = $this->resolver()->resolveCell('Adaptação curricular não significativa');

        $this->assertSame(SupportMeasureCode::NonSignificantCurricularAdaptation, $nonSignificant[0]->code);
    }

    public function test_statements_separated_by_semicolons_resolve_independently(): void
    {
        $resolutions = $this->resolver()->resolveCell('MU a); MS b) + ACNS');

        $this->assertCount(2, $resolutions);
        $this->assertSame(CodeConfidence::Ambiguous, $resolutions[0]->confidence);
        $this->assertSame(CodeConfidence::Recognised, $resolutions[1]->confidence);
    }

    public function test_an_empty_cell_resolves_to_nothing(): void
    {
        $this->assertSame([], $this->resolver()->resolveCell('   '));
    }

    /**
     * A jurisdiction Lapispro has no framework for gets no framework — not
     * Portugal's. «MU» is two letters in a school that never heard of
     * Decreto-Lei 54/2018, and reading it as a measure level would apply one
     * country's law to another country's school.
     */
    public function test_a_jurisdiction_without_a_legal_taxonomy_resolves_nothing(): void
    {
        $resolutions = $this->resolver('FR')->resolveCell('MS b) + ACNS');

        $this->assertCount(1, $resolutions);
        $this->assertSame(CodeConfidence::Unrecognised, $resolutions[0]->confidence);
        $this->assertNull($resolutions[0]->code);
        $this->assertNull($resolutions[0]->level);
        $this->assertFalse($resolutions[0]->isStorable());
        $this->assertSame('MS b) + ACNS', $resolutions[0]->rawToken);
    }

    /** Whatever the interpretation turns out to be worth, the source text survives it. */
    public function test_the_raw_token_is_always_kept(): void
    {
        $resolutions = $this->resolver()->resolveCell('MS b) + ACNS');

        $this->assertSame('MS b) + ACNS', $resolutions[0]->rawToken);
    }
}
