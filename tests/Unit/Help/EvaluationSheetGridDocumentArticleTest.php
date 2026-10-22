<?php

namespace Tests\Unit\Help;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The teacher's article on viewing, printing and saving the grid of a class — the
 * Pauta as a document (0.175.0).
 *
 * What it must keep true is what the product does: the pages it names exist, the
 * articles it points to exist, and the boundaries it draws — the result, the
 * proposal and the decision are three things, a kept moment is the day it was kept,
 * the charts are factual and interpretation and trends are Pro — are written down
 * in it.
 */
class EvaluationSheetGridDocumentArticleTest extends TestCase
{
    private function article(): HelpArticle
    {
        $article = (new HelpCenter)->find('evaluation-sheets.grid-document');

        $this->assertNotNull($article, 'o artigo evaluation-sheets.grid-document não existe');

        return $article;
    }

    private function text(): string
    {
        return implode("\n", $this->article()->content);
    }

    #[Test]
    public function it_lives_with_the_assessment_articles_and_names_real_pages(): void
    {
        $article = $this->article();

        $this->assertSame('Avaliação', $article->category);
        $this->assertSame(['evaluation-sheets.grid-document', 'evaluation-sheets.snapshot.grid-document'], $article->contexts);

        foreach (['evaluation-sheets.grid-document', 'evaluation-sheets.grid-document.pdf', 'evaluation-sheets.snapshot.grid-document', 'evaluation-sheets.snapshot.grid-document.pdf'] as $name) {
            $this->assertTrue(Route::has($name), "a rota «{$name}», que o artigo diz servir, tem de existir");
        }
    }

    #[Test]
    public function it_is_the_help_of_both_pages(): void
    {
        $center = new HelpCenter;

        foreach (['evaluation-sheets.grid-document', 'evaluation-sheets.snapshot.grid-document'] as $route) {
            $ids = $center->forContext($route)->map(fn (HelpArticle $article): string => $article->id)->all();

            $this->assertContains('evaluation-sheets.grid-document', $ids, $route);
        }
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

        $this->assertStringContainsString('«Grelha para imprimir»', $text);
        $this->assertStringContainsString('Histórico', $text);
        $this->assertStringContainsString('«Imprimir»', $text);
        $this->assertStringContainsString('«Exportar CSV»', $text);
        $this->assertStringContainsString('«PDF»', $text);
        $this->assertStringContainsString('A4', $text);
    }

    #[Test]
    public function it_keeps_the_result_the_proposal_and_the_decision_apart_and_says_empty_is_not_zero(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('O RESULTADO, A PROPOSTA E A DECISÃO SÃO TRÊS COISAS DIFERENTES', $text);
        $this->assertStringContainsString('a proposta nunca passa por decisão', $text);
        $this->assertStringContainsString('Proposta, Confirmada ou Publicada', $text);
        $this->assertStringContainsString('«prof.»', $text);
        $this->assertStringContainsString('Não é zero', $text);
    }

    #[Test]
    public function it_says_what_provisional_means(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('PROVISÓRIO', $text);
        $this->assertStringContainsString('Classificações tomadas', $text);
        $this->assertStringContainsString('em todas as páginas do PDF', $text);
    }

    #[Test]
    public function it_says_that_a_kept_moment_is_the_day_it_was_kept_and_never_changes(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('Estado à data de emissão', $text);
        $this->assertStringContainsString('é uma fotografia', $text);
        $this->assertStringContainsString('nunca é alterada por correções, decisões ou propostas posteriores', $text);
        $this->assertStringContainsString('um intercalar de hoje mostra os mesmos números que o final', $text);
    }

    #[Test]
    public function it_describes_the_options_and_keeps_the_self_assessment_off_by_default(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('A orientação é horizontal por omissão', $text);
        $this->assertStringContainsString('«Parte 1 de 3»', $text);
        $this->assertStringContainsString('A autoavaliação vem desligada', $text);
        $this->assertStringContainsString('desligados por omissão', $text);
    }

    #[Test]
    public function it_separates_the_factual_charts_of_the_base_plan_from_what_is_reserved_to_pro(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('Os gráficos descrevem o que está registado; não o interpretam.', $text);
        $this->assertStringContainsString('a comparação automática com outros períodos ou momentos e as tendências são capacidades do plano Pro', $text);
        $this->assertStringContainsString('não fazem parte deste documento', $text);
        $this->assertStringContainsString('abaixo de cinco alunos', $text);
        $this->assertStringContainsString('nunca entra como zero', $text);
        $this->assertStringContainsString('antes da 0.175.0', $text);
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
        $this->assertStringContainsString('o motivo escrito de uma decisão', $text);
        $this->assertStringContainsString('Só os professores da turma o podem abrir', $text);
        $this->assertStringContainsString('o PDF não fica guardado', $text);
        $this->assertStringContainsString('nunca com nomes', $text);
        $this->assertStringContainsString('Não é um relatório finalizado', $text);
    }

    #[Test]
    public function a_teacher_looking_for_it_finds_it(): void
    {
        $center = new HelpCenter;

        foreach (['grelha da turma', 'pauta em pdf', 'imprimir pauta', 'nível atribuído', 'média da turma'] as $query) {
            $ids = $center->search($query)->map(fn (HelpArticle $article): string => $article->id)->take(3)->all();

            $this->assertContains('evaluation-sheets.grid-document', $ids, "«{$query}» não leva ao artigo");
        }
    }
}
