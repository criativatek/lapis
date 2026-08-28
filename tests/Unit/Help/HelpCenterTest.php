<?php

namespace Tests\Unit\Help;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises HelpCenter against the REAL `resources/help/articles/*.php` set
 * — the same "assert against the real thing" style RetentionPolicyTest
 * already uses for `config/retention.php`. A fixture directory would let
 * these tests pass even if a real article file went missing or malformed.
 */
class HelpCenterTest extends TestCase
{
    protected bool $seed = false;

    private function center(): HelpCenter
    {
        return new HelpCenter;
    }

    #[Test]
    public function all_returns_every_real_article_as_a_help_article(): void
    {
        $all = $this->center()->all();

        $this->assertGreaterThanOrEqual(9, $all->count());
        $this->assertTrue($all->every(fn (HelpArticle $article): bool => $article instanceof HelpArticle));
        $this->assertTrue($all->contains(fn (HelpArticle $article): bool => $article->id === 'classes.create'));
    }

    #[Test]
    public function find_returns_the_article_for_a_real_id(): void
    {
        $article = $this->center()->find('classes.create');

        $this->assertNotNull($article);
        $this->assertSame('classes.create', $article->id);
        $this->assertSame('Criar uma turma', $article->title);
        $this->assertSame('Turmas e alunos', $article->category);
        $this->assertNotEmpty($article->content);
    }

    #[Test]
    public function find_returns_null_for_a_non_existent_id_rather_than_throwing(): void
    {
        $this->assertNull($this->center()->find('does.not.exist'));
    }

    #[Test]
    public function search_matches_on_title(): void
    {
        $results = $this->center()->search('Criar uma turma');

        $this->assertTrue($results->contains(fn (HelpArticle $article): bool => $article->id === 'classes.create'));
    }

    #[Test]
    public function search_matches_on_summary(): void
    {
        // «exportável» only appears in reports.view's SUMMARY — never its
        // title, keywords, or content (which says "exportada"/"exportar"
        // instead) — proving the summary field itself is searched.
        $results = $this->center()->search('exportável');

        $this->assertTrue($results->contains(fn (HelpArticle $article): bool => $article->id === 'reports.view'));
    }

    #[Test]
    public function search_matches_on_keywords(): void
    {
        // «instrumento» is a keyword of instruments.create but the article's
        // title, summary and content deliberately say "elemento de
        // avaliação" throughout instead — proving keywords are searched.
        $results = $this->center()->search('instrumento');

        $this->assertTrue($results->contains(fn (HelpArticle $article): bool => $article->id === 'instruments.create'));
    }

    #[Test]
    public function search_matches_on_content(): void
    {
        // «reimportação» only appears inside students.import's body text.
        $results = $this->center()->search('reimportação');

        $this->assertTrue($results->contains(fn (HelpArticle $article): bool => $article->id === 'students.import'));
    }

    #[Test]
    public function search_is_tolerant_of_portuguese_diacritics(): void
    {
        // «avaliacao», typed with no accent, must still find the article
        // whose title/content spell «avaliação» correctly.
        $results = $this->center()->search('avaliacao');

        $this->assertTrue($results->contains(fn (HelpArticle $article): bool => $article->id === 'assessment.profiles'));
    }

    #[Test]
    public function search_ranks_a_title_match_above_a_mere_content_mention(): void
    {
        // «turma» is in classes.create's TITLE, and also merely mentioned in
        // several other articles' body text — the title match must rank first.
        $results = $this->center()->search('turma');

        $this->assertSame('classes.create', $results->first()->id);
    }

    #[Test]
    public function search_returns_nothing_for_an_empty_or_unmatched_query(): void
    {
        $this->assertCount(0, $this->center()->search(''));
        $this->assertCount(0, $this->center()->search('   '));
        $this->assertCount(0, $this->center()->search('xyzzy-nao-existe-nada-parecido'));
    }

    /**
     * Every one of these returned ZERO results before search() learned to
     * read a question, while «Começar a utilizar o Lapispro» sat there
     * answering all of them. They are the reason this behaviour exists.
     *
     * @return array<string, array{string}>
     */
    public static function writtenQuestionsAboutStartingOut(): array
    {
        return [
            'como começar' => ['como começar'],
            'por onde começo' => ['por onde começo'],
            'o que faço primeiro' => ['o que faço primeiro'],
            'primeiras coisas a fazer' => ['primeiras coisas a fazer'],
            'começar a usar a aplicação' => ['começar a usar a aplicação'],
            'pergunta inteira, com pontuação' => ['quais são as primeiras coisas a fazer na aplicação?'],
            'ajuda' => ['ajuda'],
            'como usar' => ['como usar'],
            'como funciona' => ['como funciona'],
        ];
    }

    #[Test]
    #[DataProvider('writtenQuestionsAboutStartingOut')]
    public function search_answers_a_written_question_with_the_article_that_answers_it(string $question): void
    {
        $results = $this->center()->search($question);

        $this->assertNotEmpty($results, "«{$question}» não devolveu nada.");
        $this->assertSame('getting-started', $results->first()->id, "«{$question}» não pôs «Começar a utilizar o Lapispro» em primeiro.");
    }

    #[Test]
    public function search_treats_ano_escolar_as_the_ano_letivo_it_means(): void
    {
        // «escolar» appears nowhere in getting-started, whose keyword is «ano
        // letivo». The synonym group is the only thing that connects them.
        $results = $this->center()->search('ano escolar');

        $this->assertTrue($results->contains(fn (HelpArticle $article): bool => $article->id === 'getting-started'));
    }

    #[Test]
    public function search_finds_the_enrolment_article_however_the_question_is_phrased(): void
    {
        foreach (['adicionar alunos', 'inscrever aluno', 'criar aluno'] as $question) {
            $this->assertSame('students.enroll', $this->center()->search($question)->first()->id, $question);
        }
    }

    #[Test]
    public function search_finds_the_class_article_however_the_question_is_phrased(): void
    {
        foreach (['criar uma turma', 'adicionar turma', 'nova turma'] as $question) {
            $this->assertSame('classes.create', $this->center()->search($question)->first()->id, $question);
        }
    }

    #[Test]
    public function search_finds_the_results_article_from_registar_notas(): void
    {
        // Not a literal string anywhere: the title says «Registar
        // resultados» and «notas» is only a keyword. Before, this was zero.
        $this->assertSame('results.record', $this->center()->search('registar notas')->first()->id);
    }

    #[Test]
    public function search_tolerates_punctuation_in_the_query(): void
    {
        $ids = fn (string $query): array => $this->center()->search($query)
            ->map(fn (HelpArticle $article): string => $article->id)
            ->all();

        $this->assertSame($ids('avaliacao'), $ids('Avaliação?'));
    }

    #[Test]
    public function search_still_answers_the_literal_queries_it_always_did(): void
    {
        // The regression net for the phrase pass: these worked before
        // search() learned to read questions, and must still rank the same
        // article first afterwards.
        $this->assertSame('classes.create', $this->center()->search('turma')->first()->id);
        $this->assertSame('results.record', $this->center()->search('resultados')->first()->id);
        $this->assertSame('assessment.profiles', $this->center()->search('perfil de avaliação')->first()->id);
        $this->assertSame('instruments.create', $this->center()->search('instrumento')->first()->id);
    }

    #[Test]
    public function search_says_nothing_about_a_question_it_has_no_answer_for(): void
    {
        $this->assertCount(0, $this->center()->search('como faço bolo de chocolate'));
    }

    #[Test]
    public function search_does_not_treat_one_incidental_body_word_as_an_answer(): void
    {
        // «existe» shows up in body text in passing («a turma existe mas…»)
        // and «parecido» appears nowhere. One weak, uncorroborated term is a
        // coincidence and not an answer — this is the overlap gate in
        // score() doing the only job it has.
        $this->assertCount(0, $this->center()->search('existe parecido'));
    }

    #[Test]
    public function for_context_returns_only_articles_naming_that_route(): void
    {
        $results = $this->center()->forContext('instruments.create');

        $this->assertTrue($results->contains(fn (HelpArticle $article): bool => $article->id === 'instruments.create'));
        $this->assertFalse($results->contains(fn (HelpArticle $article): bool => $article->id === 'data.export'));
    }

    #[Test]
    public function for_context_returns_nothing_for_a_route_no_article_names(): void
    {
        $this->assertCount(0, $this->center()->forContext('some.unrelated.route'));
    }

    #[Test]
    public function categories_groups_every_article_under_its_own_category(): void
    {
        $categories = $this->center()->categories();

        $this->assertTrue($categories->has('Turmas e alunos'));
        $this->assertTrue(
            $categories->get('Turmas e alunos')->contains(fn (HelpArticle $article): bool => $article->id === 'classes.create'),
        );
    }
}
