<?php

namespace Tests\Unit\Help;

use App\Support\Help\HelpSearchVocabulary;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the vocabulary against the REAL `config/help-search.php` — same
 * "assert against the real thing" style `HelpCenterTest` uses for the real
 * article set. A fixture config would let these pass while the shipped one
 * said something else entirely.
 */
class HelpSearchVocabularyTest extends TestCase
{
    protected bool $seed = false;

    private function vocabulary(): HelpSearchVocabulary
    {
        return new HelpSearchVocabulary;
    }

    #[Test]
    public function fold_lowercases_strips_accents_and_drops_punctuation(): void
    {
        $this->assertSame('avaliacao dominios', HelpSearchVocabulary::fold('  Avaliação?  Domínios!! '));
        $this->assertSame('ano letivo 2026', HelpSearchVocabulary::fold('Ano letivo (2026)'));
        $this->assertSame('', HelpSearchVocabulary::fold('   '));
    }

    #[Test]
    public function terms_drops_the_scaffolding_of_a_question_and_keeps_its_subject(): void
    {
        $this->assertSame(
            ['primeiras', 'aplicacao'],
            $this->vocabulary()->terms('Quais são as primeiras coisas a fazer na aplicação?'),
        );
    }

    #[Test]
    public function terms_keeps_words_that_are_subjects_in_this_product(): void
    {
        // «ano» is question-shaped in ordinary Portuguese and a subject
        // here. Stopwording it would silently break «ano letivo».
        $this->assertSame(['ano', 'escolar'], $this->vocabulary()->terms('ano escolar'));
    }

    #[Test]
    public function terms_counts_two_words_from_one_synonym_group_only_once(): void
    {
        // «primeiros» and «passos» are the same group, so they are one piece
        // of evidence and not two — see the overlap gate in HelpCenter.
        $this->assertSame(['primeiros'], $this->vocabulary()->terms('primeiros passos'));
    }

    #[Test]
    public function terms_returns_nothing_for_a_question_that_is_only_scaffolding(): void
    {
        $this->assertSame([], $this->vocabulary()->terms('o que é que eu faço?'));
    }

    #[Test]
    public function expand_returns_the_whole_group_because_membership_is_symmetric(): void
    {
        $expanded = $this->vocabulary()->expand('escolar');

        $this->assertContains('escolar', $expanded);
        $this->assertContains('letivo', $expanded);

        // Symmetric: reading in from the other end gives the same group.
        $fromTheOtherEnd = $this->vocabulary()->expand('letivo');
        sort($expanded);
        sort($fromTheOtherEnd);

        $this->assertSame($expanded, $fromTheOtherEnd);
    }

    #[Test]
    public function expand_returns_an_unknown_term_unchanged(): void
    {
        $this->assertSame(['xyzzy'], $this->vocabulary()->expand('xyzzy'));
    }
}
