<?php

namespace Tests\Unit\Help;

use App\Services\Help\Ai\HelpAnswerParser;
use App\Support\Help\HelpArticle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parser is where a citation stops being a claim and becomes a link, so
 * this file is mostly about the ids it refuses.
 */
class HelpAnswerParserTest extends TestCase
{
    /**
     * @return array<string, HelpArticle>
     */
    private function grounding(): array
    {
        return [
            'classes.create' => new HelpArticle(
                id: 'classes.create', title: 'Criar uma turma', summary: 'Sumário.',
                category: 'Turmas e alunos', content: ['Um parágrafo.'],
            ),
            'getting-started' => new HelpArticle(
                id: 'getting-started', title: 'Começar a utilizar o Lapispro', summary: 'Sumário.',
                category: 'Começar', content: ['Um parágrafo.'],
            ),
        ];
    }

    #[Test]
    public function a_well_formed_answer_keeps_only_the_articles_it_actually_cited(): void
    {
        $answer = HelpAnswerParser::parse(
            "RESPOSTA: Vá a Turmas e carregue em «Nova turma».\nARTIGOS: classes.create",
            $this->grounding(),
        );

        $this->assertNotNull($answer);
        $this->assertTrue($answer->sufficient);
        $this->assertCount(1, $answer->references);
        $this->assertSame('classes.create', $answer->references[0]->id);
        $this->assertSame('Criar uma turma', $answer->references[0]->title);
    }

    #[Test]
    public function an_article_that_was_never_supplied_cannot_become_a_link(): void
    {
        $answer = HelpAnswerParser::parse(
            "RESPOSTA: Uma resposta.\nARTIGOS: tutorial.inventado, classes.create, admin.secreto",
            $this->grounding(),
        );

        $this->assertNotNull($answer);
        $this->assertSame(['classes.create'], array_map(fn ($reference) => $reference->id, $answer->references));
    }

    #[Test]
    public function citing_nothing_real_falls_back_to_the_articles_the_answer_was_grounded_in(): void
    {
        $answer = HelpAnswerParser::parse(
            "RESPOSTA: Uma resposta.\nARTIGOS: nada.disto.existe",
            $this->grounding(),
        );

        $this->assertNotNull($answer);
        // The grounding set WAS the basis of the answer whether or not the
        // engine remembered to list it — so the honest fallback is all of it,
        // never an empty citation list.
        $this->assertCount(2, $answer->references);
    }

    #[Test]
    public function references_come_back_in_relevance_order_not_in_the_order_the_engine_listed_them(): void
    {
        $answer = HelpAnswerParser::parse(
            "RESPOSTA: Uma resposta.\nARTIGOS: getting-started, classes.create",
            $this->grounding(),
        );

        $this->assertNotNull($answer);
        // The grounding's own order — which is the search's ranking.
        $this->assertSame(
            ['classes.create', 'getting-started'],
            array_map(fn ($reference) => $reference->id, $answer->references),
        );
    }

    #[Test]
    public function the_insufficiency_sentinel_is_an_answer_not_a_failure(): void
    {
        $answer = HelpAnswerParser::parse('SEM_INFORMACAO', $this->grounding());

        $this->assertNotNull($answer);
        $this->assertFalse($answer->sufficient);
        $this->assertSame([], $answer->references);
        $this->assertStringContainsString('não cobre esta pergunta', $answer->text);
    }

    #[Test]
    public function the_sentinel_quoted_inside_a_real_answer_does_not_discard_it(): void
    {
        $answer = HelpAnswerParser::parse(
            "RESPOSTA: Quando não houver artigos, o assistente responde SEM_INFORMACAO e não inventa nada.\nARTIGOS: classes.create",
            $this->grounding(),
        );

        $this->assertNotNull($answer);
        $this->assertTrue($answer->sufficient);
    }

    #[Test]
    public function an_answer_that_forgot_its_label_is_still_an_answer(): void
    {
        $answer = HelpAnswerParser::parse(
            "Vá a Turmas e carregue em «Nova turma».\nARTIGOS: classes.create",
            $this->grounding(),
        );

        $this->assertNotNull($answer);
        $this->assertTrue($answer->sufficient);
        $this->assertSame('Vá a Turmas e carregue em «Nova turma».', $answer->text);
        $this->assertStringNotContainsString('ARTIGOS', $answer->text);
    }

    #[Test]
    public function an_empty_or_unreadable_reply_is_null_which_the_caller_reports_as_a_failure(): void
    {
        $this->assertNull(HelpAnswerParser::parse('', $this->grounding()));
        $this->assertNull(HelpAnswerParser::parse('   ', $this->grounding()));
        $this->assertNull(HelpAnswerParser::parse('RESPOSTA:', $this->grounding()));
    }

    // A reference's `url` is deliberately NOT asserted here. `toArray()`
    // resolves it through the `help.show` named route, which needs the
    // container this plain TestCase does not have — and asserting it against a
    // hand-built string would be asserting the test's own guess rather than
    // the route. It is checked end to end instead, against `route()` itself,
    // in Tests\Feature\Help\HelpAssistantTest.
}
