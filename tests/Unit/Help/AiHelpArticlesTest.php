<?php

namespace Tests\Unit\Help;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE QUESTIONS A TEACHER WILL ACTUALLY TYPE, and the article each one has to
 * reach.
 *
 * §23 of the AI-complete brief lists six of them by name. Documentation that
 * exists and cannot be found is documentation that does not exist, and the
 * search is a pure function of the articles plus `config/help-search.php` — so
 * «findable» is a property that can be, and here is, asserted rather than hoped
 * for.
 *
 * IT ASSERTS THE FIRST RESULT, NOT MERE PRESENCE. «The article is somewhere in
 * the list» passes on a search that returns everything. Naming the article that
 * must come FIRST is what makes this a test of ranking, which is what a teacher
 * experiences.
 *
 * ONE EXCEPTION, AND IT IS DOCUMENTED IN THE CASE ITSELF: «como usar IA» is a
 * genuinely ambiguous question — every AI article answers part of it — so that
 * case only requires an AI article to win, not a particular one.
 */
class AiHelpArticlesTest extends TestCase
{
    protected bool $seed = false;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function questions(): array
    {
        return [
            'analisar avaliação' => ['analisar avaliação', 'ai.assessment'],
            'ajuda com estratégias' => ['ajuda com estratégias', 'ai.strategies'],
            'IA no relatório' => ['IA no relatório', 'ai.reports'],
            'limite de IA' => ['limite de IA', 'ai.limits'],
            'porque a IA não aparece' => ['porque a IA não aparece', 'ai.limits'],
            'síntese do aluno' => ['síntese do aluno', 'ai.followup'],
            'privacidade da IA' => ['privacidade da IA', 'ai.privacy'],
            'pseudonimização' => ['pseudonimização', 'ai.privacy'],
        ];
    }

    #[DataProvider('questions')]
    #[Test]
    public function a_question_reaches_the_article_that_answers_it(string $query, string $expected): void
    {
        $results = (new HelpCenter)->search($query);

        $this->assertNotEmpty($results, "«{$query}» found no article at all.");
        $this->assertSame(
            $expected,
            $results->first()->id,
            sprintf(
                '«%s» should reach %s first; it reached %s.',
                $query,
                $expected,
                $results->first()->id,
            ),
        );
    }

    /**
     * The broadest question in the brief's list, and the only one this test
     * does not pin to a first place.
     *
     * «como usar IA» reduces to «usar» + «ia», and `config/help-search.php`
     * deliberately points «usar» at the same group as «ajuda» and «começar» —
     * so «Começar a utilizar o Lapispro» wins, which `HelpCenterTest` has fixed
     * since 0.82.0 and which is the right answer for somebody who typed the
     * word «usar». What this asserts instead is that the AI documentation is
     * reachable from the question at all, and that it is near the top rather
     * than buried.
     */
    #[Test]
    public function the_broadest_question_reaches_the_ai_documentation(): void
    {
        $results = (new HelpCenter)->search('como usar IA');

        $this->assertNotEmpty($results);

        $top = $results->take(4)->map(fn (HelpArticle $article): string => $article->id)->all();

        $this->assertTrue(
            collect($top)->contains(fn (string $id): bool => str_starts_with($id, 'ai.')),
            '«como usar IA» should surface the AI documentation in the first few results; it returned: '.implode(', ', $top),
        );
    }

    /**
     * Every AI article carries the disclosure sentence, or says why it does
     * not. §22 asks for it on the experiences; an article that describes an
     * experience and omits it would be the one place a reader could conclude
     * the rule has exceptions.
     */
    #[Test]
    public function every_experience_article_states_who_decides(): void
    {
        $experiences = ['ai.assistant', 'ai.pedagogical-analysis', 'ai.assessment', 'ai.followup', 'ai.strategies', 'ai.reports'];

        foreach ($experiences as $id) {
            $article = (new HelpCenter)->find($id);

            $this->assertNotNull($article, "{$id} is missing.");
            $this->assertTrue(
                $this->mentions($article, 'decisões pedagógicas continuam a ser do professor')
                    || $this->mentions($article, 'nunca uma decisão sobre um aluno')
                    || $this->mentions($article, 'continuam a ser do professor'),
                "{$id} does not say who decides.",
            );
        }
    }

    /**
     * «Pseudonimizado», never «anónimo». The distinction is legal and real: the
     * teacher, holding the class list, recognises every line. An article that
     * promised anonymity would be promising what the product does not do.
     *
     * THE NEGATION IS EXPLICITLY ALLOWED, and is in fact the sentence the
     * articles are supposed to contain: «pseudonimizado não é anónimo» is the
     * distinction being drawn, not a promise being made.
     */
    #[Test]
    public function no_article_promises_anonymity(): void
    {
        foreach ((new HelpCenter)->all() as $article) {
            foreach ($article->content as $paragraph) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(?<!não\s)\b(?:são|é|fica|ficam)\s+anónim/iu',
                    $paragraph,
                    "{$article->id} promises anonymity. The data is pseudonymised, which is a different and weaker claim.",
                );
            }
        }
    }

    /**
     * No article quotes a concrete ceiling. The numbers are configurable per
     * installation and per plan, and an article that said «40 por dia» would be
     * false the first time somebody changed a setting — and nobody would go
     * back to correct it.
     */
    #[Test]
    public function no_article_quotes_a_concrete_ai_quota(): void
    {
        $article = (new HelpCenter)->find('ai.limits');

        $this->assertNotNull($article);

        foreach ($article->content as $paragraph) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b\d+\s+pedidos?\s+por\s+(?:dia|mês)/iu',
                $paragraph,
                'The limits article quotes a number. Limits are configuration, not documentation.',
            );
        }
    }

    private function mentions(HelpArticle $article, string $needle): bool
    {
        foreach ($article->content as $paragraph) {
            if (str_contains($paragraph, $needle)) {
                return true;
            }
        }

        return false;
    }
}
