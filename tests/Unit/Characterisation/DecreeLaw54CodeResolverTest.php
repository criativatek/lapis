<?php

namespace Tests\Unit\Characterisation;

use App\Models\CatalogueFamily;
use App\Models\Organization;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Support\Characterisation\AcronymDictionary;
use App\Support\Characterisation\CodeConfidence;
use App\Support\Characterisation\DecreeLaw54CodeResolver;
use App\Support\Interventions\InterventionLegalFramework;
use App\Support\Interventions\LegalFrameworkRegistry;
use App\Support\Interventions\LegalFrameworkResolver;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Tests\Support\Interventions\FictitiousLegalFramework;
use Tests\TestCase;

/**
 * Reading a school's shorthand against the legal framework that applies.
 *
 * Most of what is asserted here is restraint: a letter that stays a letter
 * without a level, an acronym that stays unexpanded, a contradiction that stays
 * a contradiction. The rest asserts the opposite and just as deliberately —
 * that where the diploma itself supplies the answer, the resolver reads it
 * instead of holding a copy.
 *
 * No database: the resolver reads an organization's jurisdiction and asks a
 * framework, and neither needs a row to exist.
 */
class DecreeLaw54CodeResolverTest extends TestCase
{
    /**
     * @param  list<InterventionLegalFramework>|null  $frameworks
     */
    private function resolver(string $jurisdiction = 'PT', ?array $frameworks = null): DecreeLaw54CodeResolver
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
            new LegalFrameworkResolver(new LegalFrameworkRegistry($frameworks)),
            $tenant,
        );
    }

    // ---------------------------------------------- medidas nomeadas

    public function test_a_named_measure_resolves_on_its_own(): void
    {
        $resolutions = $this->resolver()->resolveCell('ACNS');

        $this->assertCount(1, $resolutions);
        $this->assertSame(CodeConfidence::Recognised, $resolutions[0]->confidence);
        $this->assertSame(SupportMeasureCode::NonSignificantCurricularAdaptation, $resolutions[0]->code);
        $this->assertSame(SupportMeasureLevel::Selective, $resolutions[0]->level);
        $this->assertTrue($resolutions[0]->isStorable());
    }

    /**
     * The brief's worked example — and under the real diploma the two halves
     * agree: article 9.º, n.º 2, alínea b) IS «as adaptações curriculares não
     * significativas». The letter corroborates the measure instead of riding
     * along unresolved.
     */
    public function test_a_level_a_letter_and_a_measure_that_agree_are_recognised(): void
    {
        $resolutions = $this->resolver()->resolveCell('MS b) + ACNS');

        $this->assertCount(1, $resolutions);
        $this->assertSame(CodeConfidence::Recognised, $resolutions[0]->confidence);
        $this->assertSame(SupportMeasureCode::NonSignificantCurricularAdaptation, $resolutions[0]->code);
        $this->assertSame(SupportMeasureLevel::Selective, $resolutions[0]->level);
        $this->assertSame([], $resolutions[0]->unresolvedAnnotations);
    }

    /** A letter that contradicts the measure beside it is two sources disagreeing. */
    public function test_a_letter_that_contradicts_its_measure_is_ambiguous(): void
    {
        $resolutions = $this->resolver()->resolveCell('MS c) + ACNS');

        $this->assertSame(CodeConfidence::Ambiguous, $resolutions[0]->confidence);
        $this->assertNull($resolutions[0]->code);
        $this->assertFalse($resolutions[0]->isStorable());
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
        $this->assertStringContainsString('não corresponde', (string) $resolutions[0]->note);
    }

    // ---------------------------------------------- alíneas

    /**
     * THE CANONICAL AMBIGUITY, and now provable rather than assumed: the regime
     * names an alínea b) under each of the three levels — articles 8.º, 9.º and
     * 10.º — so a bare letter identifies three different measures at once.
     */
    public function test_a_bare_letter_without_a_level_is_ambiguous(): void
    {
        $resolutions = $this->resolver()->resolveCell('b)');

        $this->assertCount(1, $resolutions);
        $this->assertSame(CodeConfidence::Ambiguous, $resolutions[0]->confidence);
        $this->assertNull($resolutions[0]->code);
        $this->assertSame(['b)'], $resolutions[0]->unresolvedAnnotations);
        $this->assertFalse($resolutions[0]->isStorable());
    }

    /**
     * With a level the same letter identifies exactly one measure, and the
     * framework is what says which — article 8.º, n.º 2, alínea b).
     */
    public function test_a_level_and_a_letter_resolve_through_the_framework(): void
    {
        $resolutions = $this->resolver()->resolveCell('MU b)');

        $this->assertSame(CodeConfidence::Recognised, $resolutions[0]->confidence);
        $this->assertSame(SupportMeasureCode::CurricularAccommodation, $resolutions[0]->code);
        $this->assertSame(SupportMeasureLevel::Universal, $resolutions[0]->level);
    }

    /** A column header naming the level is the same evidence as a token. */
    public function test_a_column_level_is_enough_context_for_a_letter(): void
    {
        $resolutions = $this->resolver()->resolveCell('b)', SupportMeasureLevel::Universal);

        $this->assertSame(CodeConfidence::Recognised, $resolutions[0]->confidence);
        $this->assertSame(SupportMeasureCode::CurricularAccommodation, $resolutions[0]->code);
    }

    public function test_several_letters_at_one_level_resolve_to_several_measures(): void
    {
        $resolutions = $this->resolver()->resolveCell('MU a) b) e)');

        $this->assertCount(3, $resolutions);
        $this->assertSame(
            [
                SupportMeasureCode::PedagogicalDifferentiation,
                SupportMeasureCode::CurricularAccommodation,
                SupportMeasureCode::AcademicFocusSmallGroup,
            ],
            array_map(fn ($r) => $r->code, $resolutions),
        );
    }

    public function test_a_level_with_no_letter_and_no_measure_is_ambiguous(): void
    {
        $resolutions = $this->resolver()->resolveCell('MS');

        $this->assertSame(CodeConfidence::Ambiguous, $resolutions[0]->confidence);
        $this->assertSame(SupportMeasureLevel::Selective, $resolutions[0]->level);
        $this->assertNull($resolutions[0]->code);
    }

    // ---------------------------------------------- siglas que não são medidas

    /**
     * THE TRAP THE CANONICAL CATALOGUE OPENED. The regime names «o plano
     * individual de transição» at 10.º/4 c) and the catalogue has a case for
     * it — and a column reading «PIT» is still far more often the document a
     * school keeps than a statement that the measure applies to that child.
     * A sigla does not become a measure because a related concept exists.
     */
    public function test_pit_does_not_become_a_measure_just_because_the_catalogue_knows_one(): void
    {
        $resolutions = $this->resolver()->resolveCell('PIT');

        $this->assertCount(1, $resolutions);
        $this->assertSame(CodeConfidence::Unrecognised, $resolutions[0]->confidence);
        $this->assertNull($resolutions[0]->code);
        $this->assertFalse($resolutions[0]->isStorable());
    }

    /**
     * CRI is understood — it is a Centro de Recursos para a Inclusão, a
     * resource — and it is still not storable, because the catalogue has no
     * `resource_support` item and therefore no honest column. Knowing what
     * kind of thing something is and having somewhere to put it are different
     * questions, and this is the case where they come apart.
     */
    public function test_cri_is_recognised_as_a_resource_and_is_not_storable(): void
    {
        $resolutions = $this->resolver()->resolveCell('CRI');

        $this->assertCount(1, $resolutions);
        $this->assertSame(CatalogueFamily::SupportResource, $resolutions[0]->family);
        $this->assertTrue($resolutions[0]->isResource());
        $this->assertNull($resolutions[0]->code);
        $this->assertNull($resolutions[0]->level);
        $this->assertFalse($resolutions[0]->isStorable());
        $this->assertStringContainsString('Centro de Recursos para a Inclusão', (string) $resolutions[0]->note);
    }

    /** A resource is never a legal measure, however the cell is written. */
    public function test_a_resource_never_becomes_a_legal_measure(): void
    {
        foreach (['CRI', 'MU + CRI', 'CRI b)'] as $cell) {
            foreach ($this->resolver()->resolveCell($cell) as $resolution) {
                if ($resolution->isResource()) {
                    $this->assertNull($resolution->code, $cell);
                    $this->assertFalse($resolution->isStorable(), $cell);
                }
            }
        }
    }

    /** A resource beside a measure does not contaminate either of them. */
    public function test_a_resource_and_a_measure_in_one_cell_stay_apart(): void
    {
        $resolutions = $this->resolver()->resolveCell('CRI; ACNS');

        $this->assertCount(2, $resolutions);

        $resources = array_values(array_filter($resolutions, fn ($r) => $r->isResource()));
        $measures = array_values(array_filter($resolutions, fn ($r) => $r->isStorable()));

        $this->assertCount(1, $resources);
        $this->assertCount(1, $measures);
        $this->assertSame(SupportMeasureCode::NonSignificantCurricularAdaptation, $measures[0]->code);
    }

    /**
     * The other siglas that MIGHT be resources or structures stay unconfirmed.
     * Classifying them would be inventing the classification, which is the same
     * failure as inventing an expansion.
     */
    public function test_unconfirmed_siglas_are_not_classified_as_resources(): void
    {
        foreach (['SPO', 'DEE', 'CAA', 'GAAF', 'ATE'] as $token) {
            $resolutions = $this->resolver()->resolveCell($token);

            $this->assertSame(CodeConfidence::Unrecognised, $resolutions[0]->confidence, $token);
            $this->assertNull($resolutions[0]->family, $token);
            $this->assertFalse($resolutions[0]->isResource(), $token);
        }
    }

    /** Instruments, not measures — whatever the catalogue holds. */
    public function test_instruments_never_become_measures_by_acronym(): void
    {
        foreach (['RTP', 'PEI', 'PEL', 'SPO', 'DEE', 'ATE', 'CAA', 'GAAF'] as $token) {
            $resolutions = $this->resolver()->resolveCell($token);

            $this->assertSame(CodeConfidence::Unrecognised, $resolutions[0]->confidence, $token);
            $this->assertNull($resolutions[0]->code, $token);
            $this->assertNull($resolutions[0]->level, $token);
            $this->assertFalse($resolutions[0]->isStorable(), $token);
        }
    }

    /** PLNM is a curricular pathway. Confirmed as a sigla, never a measure. */
    public function test_plnm_is_confirmed_but_is_not_a_measure(): void
    {
        $resolutions = $this->resolver()->resolveCell('PLNM');

        $this->assertSame(CodeConfidence::Unrecognised, $resolutions[0]->confidence);
        $this->assertNull($resolutions[0]->code);
        $this->assertStringContainsString('Português Língua Não Materna', (string) $resolutions[0]->note);
    }

    public function test_an_unknown_acronym_is_unrecognised(): void
    {
        $resolutions = $this->resolver()->resolveCell('XPTO');

        $this->assertSame(CodeConfidence::Unrecognised, $resolutions[0]->confidence);
        $this->assertFalse($resolutions[0]->isStorable());
    }

    // ---------------------------------------------- designações do diploma

    public function test_the_diplomas_own_designations_resolve(): void
    {
        $resolutions = $this->resolver()->resolveCell('As adaptações curriculares significativas');

        $this->assertSame(SupportMeasureCode::SignificantCurricularAdaptation, $resolutions[0]->code);
        $this->assertSame(SupportMeasureLevel::Additional, $resolutions[0]->level);
    }

    /** A sheet written in the singular names the same measure as the diploma's plural. */
    public function test_singular_and_plural_spellings_reach_the_same_measure(): void
    {
        $singular = $this->resolver()->resolveCell('Adaptação curricular significativa');
        $plural = $this->resolver()->resolveCell('Adaptações curriculares significativas');

        $this->assertSame(SupportMeasureCode::SignificantCurricularAdaptation, $singular[0]->code);
        $this->assertSame(SupportMeasureCode::SignificantCurricularAdaptation, $plural[0]->code);
    }

    /** «significativa» must not be found inside «não significativa». */
    public function test_the_longer_designation_wins(): void
    {
        $resolutions = $this->resolver()->resolveCell('Adaptações curriculares não significativas');

        $this->assertCount(1, $resolutions);
        $this->assertSame(SupportMeasureCode::NonSignificantCurricularAdaptation, $resolutions[0]->code);
    }

    /**
     * A measure this branch never heard of — it arrived with the canonical
     * catalogue — resolves without the parser being touched. That is the whole
     * point of reading the framework instead of holding a list.
     */
    public function test_a_measure_the_parser_never_knew_resolves_through_the_catalogue(): void
    {
        $resolutions = $this->resolver()->resolveCell('Os percursos curriculares diferenciados');

        $this->assertSame(CodeConfidence::Recognised, $resolutions[0]->confidence);
        $this->assertSame(SupportMeasureCode::DifferentiatedCurricularPaths, $resolutions[0]->code);
        $this->assertSame(SupportMeasureLevel::Selective, $resolutions[0]->level);
    }

    // ---------------------------------------------- enquadramento e tempo

    /**
     * A jurisdiction Lapispro has no framework for gets no framework — not
     * Portugal's. «MU» is two letters in a school that never heard of
     * Decreto-Lei 54/2018.
     */
    public function test_a_jurisdiction_without_a_legal_taxonomy_resolves_nothing(): void
    {
        $resolutions = $this->resolver('FR')->resolveCell('MS b) + ACNS');

        $this->assertCount(1, $resolutions);
        $this->assertSame(CodeConfidence::Unrecognised, $resolutions[0]->confidence);
        $this->assertNull($resolutions[0]->code);
        $this->assertNull($resolutions[0]->level);
        $this->assertSame('MS b) + ACNS', $resolutions[0]->rawToken);
    }

    /**
     * The level is the applicable framework's answer, not the enum's. A
     * fictitious regime that puts the same measure somewhere else is read the
     * way IT reads it — which is what stops a record from 2026 being
     * reclassified by a law passed afterwards.
     */
    public function test_the_level_comes_from_the_framework_not_from_the_enum(): void
    {
        $framework = new FictitiousLegalFramework;

        $resolver = $this->resolver($framework->jurisdiction() ?? 'ZZ', [$framework]);

        $level = $resolver->levelFor(SupportMeasureCode::TutorialSupport, Carbon::parse('2026-10-01'));

        $this->assertSame($framework->levelFor(SupportMeasureCode::TutorialSupport), $level);
    }

    /** No framework covers the date → no level, and therefore nothing storable. */
    public function test_a_date_no_framework_covers_yields_no_level(): void
    {
        $level = $this->resolver()->levelFor(
            SupportMeasureCode::TutorialSupport,
            Carbon::parse('1990-01-01'),
        );

        $this->assertNull($level);
    }

    public function test_the_current_regime_answers_for_today(): void
    {
        $this->assertSame(
            SupportMeasureLevel::Selective,
            $this->resolver()->levelFor(SupportMeasureCode::TutorialSupport),
        );
    }

    // ---------------------------------------------- forma

    public function test_statements_separated_by_semicolons_resolve_independently(): void
    {
        $resolutions = $this->resolver()->resolveCell('MU a); MS b) + ACNS');

        $this->assertCount(2, $resolutions);
        $this->assertSame(SupportMeasureCode::PedagogicalDifferentiation, $resolutions[0]->code);
        $this->assertSame(SupportMeasureCode::NonSignificantCurricularAdaptation, $resolutions[1]->code);
    }

    public function test_an_empty_cell_resolves_to_nothing(): void
    {
        $this->assertSame([], $this->resolver()->resolveCell('   '));
    }

    /** Whatever the interpretation turns out to be worth, the source text survives it. */
    public function test_the_raw_token_is_always_kept(): void
    {
        $resolutions = $this->resolver()->resolveCell('MS b) + ACNS');

        $this->assertSame('MS b) + ACNS', $resolutions[0]->rawToken);
    }
}
