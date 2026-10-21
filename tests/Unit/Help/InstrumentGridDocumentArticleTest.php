<?php

namespace Tests\Unit\Help;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The teacher's article on viewing, printing and saving the grid of an
 * instrument (0.174.0).
 *
 * What it must keep true is what the product does: the page it names exists, the
 * articles it points to exist, and the boundaries it draws — the raw score is not
 * the result, the charts are factual and only for a concluded correction,
 * interpretation and trends are Pro and not part of this document — are written
 * down in it.
 */
class InstrumentGridDocumentArticleTest extends TestCase
{
    private function article(): HelpArticle
    {
        $article = (new HelpCenter)->find('instruments.grid-document');

        $this->assertNotNull($article, 'o artigo instruments.grid-document não existe');

        return $article;
    }

    private function text(): string
    {
        return implode("\n", $this->article()->content);
    }

    #[Test]
    public function it_lives_with_the_assessment_articles_and_names_a_real_page(): void
    {
        $article = $this->article();

        $this->assertSame('Avaliação', $article->category);
        $this->assertSame(['instruments.grid-document'], $article->contexts);
        $this->assertTrue(Route::has('instruments.grid-document'), 'a rota que o artigo diz servir tem de existir');
        $this->assertTrue(Route::has('instruments.grid-document.pdf'));
    }

    #[Test]
    public function every_article_it_points_to_exists(): void
    {
        $center = new HelpCenter;

        foreach ($this->article()->related as $id) {
            $this->assertNotNull($center->find($id), "«{$id}» não existe");
        }
    }

    #[Test]
    public function it_tells_the_teacher_how_to_open_print_and_save_it(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('«Imprimir / PDF»', $text);
        $this->assertStringContainsString('«Descarregar grelha»', $text, 'não confundir com a folha de cálculo');
        $this->assertStringContainsString('«Imprimir»', $text);
        $this->assertStringContainsString('«PDF»', $text);
        $this->assertStringContainsString('A4', $text);
    }

    #[Test]
    public function it_keeps_the_raw_score_apart_from_the_result_and_says_empty_is_not_zero(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('PONTUAÇÃO BRUTA E O RESULTADO SÃO COISAS DIFERENTES', $text);
        $this->assertStringContainsString('não é a classificação do período', $text);
        $this->assertStringContainsString('não foi avaliado — não é zero', $text);
    }

    #[Test]
    public function it_says_what_provisional_and_concluded_mean_and_that_charts_need_a_concluded_correction(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('PROVISÓRIO', $text);
        $this->assertStringContainsString('Correção concluída', $text);
        $this->assertStringContainsString('só ficam disponíveis com a correção concluída', $text);
        $this->assertStringContainsString('não contam como zero', $text);
    }

    #[Test]
    public function it_separates_the_factual_charts_of_the_base_plan_from_what_is_reserved_to_pro(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('Os gráficos descrevem o que está registado; não o interpretam.', $text);
        $this->assertStringContainsString('A interpretação dos resultados, a comparação automática com outros instrumentos ou períodos e as tendências são capacidades do plano Pro', $text);
        $this->assertStringContainsString('não fazem parte deste documento', $text);
    }

    #[Test]
    public function it_states_the_limits_of_the_browser_print_and_prefers_the_pdf(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('Chromium', $text);
        $this->assertStringContainsString('noutros browsers esses dois elementos podem não aparecer', $text);
        $this->assertStringContainsString('a forma mais segura de obter sempre o mesmo resultado', $text);
    }

    #[Test]
    public function it_says_what_never_leaves_and_that_nothing_is_stored(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('Não inclui fotografias', $text);
        $this->assertStringContainsString('Só os professores da turma o podem abrir', $text);
        $this->assertStringContainsString('Não fica guardado', $text);
        $this->assertStringContainsString('Não é um relatório finalizado', $text);
    }

    #[Test]
    public function a_teacher_looking_for_it_finds_it(): void
    {
        $center = new HelpCenter;

        foreach (['imprimir grelha', 'grelha em pdf', 'pontuação bruta', 'média por questão'] as $query) {
            $ids = $center->search($query)->map(fn (HelpArticle $article): string => $article->id)->take(3)->all();

            $this->assertContains('instruments.grid-document', $ids, "«{$query}» não leva ao artigo");
        }
    }
}
