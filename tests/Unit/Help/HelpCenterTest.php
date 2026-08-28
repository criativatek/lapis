<?php

namespace Tests\Unit\Help;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
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
