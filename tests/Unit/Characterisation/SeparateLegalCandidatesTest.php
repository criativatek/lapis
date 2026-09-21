<?php

namespace Tests\Unit\Characterisation;

use App\Models\Organization;
use App\Services\Characterisation\Import\SeparateLegalCandidates;
use App\Support\Characterisation\AcronymDictionary;
use App\Support\Characterisation\DecreeLaw54CodeResolver;
use App\Support\Interventions\LegalFrameworkRegistry;
use App\Support\Interventions\LegalFrameworkResolver;
use App\Support\Tenancy\CurrentOrganization;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The 9 regression cases the hotfix was written against, each pinned
 * directly against this class rather than through the full preview/resolver
 * stack — so a failure here says exactly which fragment was misjudged,
 * without a framework/organization/dictionary lookup in the way.
 *
 * BuildCharacterisationPreviewTest and CharacterisationRealisticTableImportTest
 * cover the same cases end to end, through the real preview endpoint and a
 * real LegalCodeResolver — see those for the "does it actually stay out of
 * `unresolved`" proof; this file is the "does the separation itself decide
 * correctly" proof.
 */
class SeparateLegalCandidatesTest extends TestCase
{
    /**
     * The same PT jurisdiction, fully-resolved-organization setup
     * DecreeLaw54CodeResolverTest uses for its own resolver() helper — this
     * class's designation-oracle probe (see its own docblock) needs a real,
     * working resolver, not a stub, to prove "Os percursos curriculares
     * diferenciados" is genuinely told apart from ordinary prose by MEANING,
     * not merely by a hand-picked test double that always says yes.
     */
    private function separator(): SeparateLegalCandidates
    {
        $organization = new Organization;
        $organization->jurisdiction = 'PT';

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

        $resolver = new DecreeLaw54CodeResolver(
            new AcronymDictionary,
            new LegalFrameworkResolver(new LegalFrameworkRegistry(null)),
            $tenant,
        );

        return new SeparateLegalCandidates($resolver);
    }

    // ---------------------------------------------- prosa pura: nada extraído

    #[Test]
    public function a_school_year_shorthand_is_not_a_legal_candidate(): void
    {
        $this->assertSame([], $this->separator()->candidatesFor('1R 25/26'));
    }

    #[Test]
    public function an_ordinary_sentence_about_class_size_is_not_a_legal_candidate(): void
    {
        $this->assertSame([], $this->separator()->candidatesFor('Redução de turma'));
    }

    #[Test]
    public function a_sentence_about_speech_therapy_is_not_a_legal_candidate(): void
    {
        $this->assertSame([], $this->separator()->candidatesFor('Teve alta da Terapia da Fala'));
    }

    // ---------------------------------------------- código puro: preservado verbatim

    #[Test]
    public function three_subparagraphs_under_mu_are_preserved_whole(): void
    {
        $this->assertSame(['MU a) b) e)'], $this->separator()->candidatesFor('MU a) b) e)'));
    }

    #[Test]
    public function a_named_measure_beside_two_more_subparagraphs_is_preserved_whole(): void
    {
        $this->assertSame(['MS b) ACNS c) d)'], $this->separator()->candidatesFor('MS b) ACNS c) d)'));
    }

    /** A bare alínea is a plausible candidate on its own — genuinely ambiguous, not prose. */
    #[Test]
    public function a_bare_subparagraph_letter_is_still_a_candidate(): void
    {
        $this->assertSame(['b)'], $this->separator()->candidatesFor('b)'));
    }

    // ---------------------------------------------- o caso difícil: dentro do statement

    /**
     * THE DESIGN-DEFINING CASE. "MS b)" is extracted; "Preencher ACNS" is
     * NOT re-examined for a second run once prose has interrupted the first
     * one — see the class docblock on why resuming would be guessing at
     * context nobody supplied. Both halves of the original text remain
     * available elsewhere: this fragment is what reaches the resolver, and
     * the WHOLE cell (unmodified) is what BuildCharacterisationPreview still
     * hands to the Characterisation section for an alsoFreeText column.
     */
    #[Test]
    public function only_the_leading_run_is_extracted_when_prose_interrupts_it(): void
    {
        $this->assertSame(['MS b)'], $this->separator()->candidatesFor('MS b) Preencher ACNS'));
    }

    // ---------------------------------------------- não regride resolução existente

    #[Test]
    public function cri_alone_is_still_a_candidate(): void
    {
        $this->assertSame(['CRI'], $this->separator()->candidatesFor('CRI'));
    }

    #[Test]
    public function plnm_alone_is_still_a_candidate(): void
    {
        $this->assertSame(['PLNM'], $this->separator()->candidatesFor('PLNM'));
    }

    // ---------------------------------------------- robustez adicional (revisão adversarial)

    /**
     * A code-shaped run does not need to be the FIRST thing in the
     * statement — only the first one found is taken, wherever it starts.
     * Losing a real code because unrelated prose happened to precede it
     * would be exactly the false negative the adversarial review looked for.
     */
    #[Test]
    public function a_run_preceded_by_prose_is_still_found(): void
    {
        $this->assertSame(['MU a)'], $this->separator()->candidatesFor('Nota: MU a)'));
    }

    /** "+" is a connector inside a statement, never prose that ends a run — mirrors DecreeLaw54CodeResolver::splitStatements()'s own note on "+". */
    #[Test]
    public function a_plus_connector_does_not_break_a_run(): void
    {
        $this->assertSame(['MS b) + ACNS'], $this->separator()->candidatesFor('MS b) + ACNS'));
    }

    /** An OCR misread that mixes a digit into an acronym is still worth asking about — deciding whether it MEANS anything is the resolver's job, not this one's. */
    #[Test]
    public function an_ocr_misread_with_a_digit_is_still_a_candidate(): void
    {
        $this->assertSame(['ACN5'], $this->separator()->candidatesFor('ACN5'));
    }

    /** Statements are split the same way the resolver splits them — ';' and line breaks — so multi-statement cells separate independently. */
    #[Test]
    public function each_statement_is_judged_on_its_own(): void
    {
        $this->assertSame(
            ['ACNS', 'RTP'],
            $this->separator()->candidatesFor('ACNS; RTP'),
        );

        $this->assertSame(
            ['MU a)'],
            $this->separator()->candidatesFor("Redução de turma\nMU a)"),
        );
    }

    /**
     * THE FALSE-NEGATIVE THE ADVERSARIAL REVIEW FOUND: the diploma's own
     * designations are ordinary lower-case Portuguese, shape-indistinguishable
     * from real narrative prose. A shape-only rule swallows this exact
     * sentence as prose and silently loses a real, storable measure — which
     * is worse than the noise this class exists to remove. This is why
     * wholeStatementIsRecognised() exists: it is the one case where this
     * class must ask the resolver even though nothing here LOOKS like a
     * code.
     */
    #[Test]
    public function a_spelled_out_designation_with_no_acronym_shape_is_still_recognised(): void
    {
        $this->assertSame(
            ['Os percursos curriculares diferenciados'],
            $this->separator()->candidatesFor('Os percursos curriculares diferenciados'),
        );
    }

    /** A cell with no code-shaped fragment ANYWHERE returns no candidates at all — not even an empty string. */
    #[Test]
    public function a_wholly_unshaped_cell_returns_no_candidates(): void
    {
        $this->assertSame(
            [],
            $this->separator()->candidatesFor("Observação simples sobre o aluno.\nSem nada de código aqui."),
        );
    }
}
