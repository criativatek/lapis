<?php

namespace Tests\Unit\Help;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The teacher's article on an assessment meeting without Internet (0.197.0).
 *
 * The article promises nothing the product does not do: Lapispro does not work
 * offline, and it says so. What it must keep true is that every document it
 * tells the teacher to save still exists under the button it names, that it is
 * found by the words a teacher in that situation would type, and that the
 * advice to carry a copy never travels without the advice to look after it.
 */
class MeetingWithoutInternetArticleTest extends TestCase
{
    private function article(): HelpArticle
    {
        $article = (new HelpCenter)->find('reports.meeting-without-internet');

        $this->assertNotNull($article, 'o artigo reports.meeting-without-internet não existe');

        return $article;
    }

    private function text(): string
    {
        return implode("\n", $this->article()->content);
    }

    #[Test]
    public function it_lives_with_the_reports_articles_and_is_helped_from_the_pauta_where_the_button_is(): void
    {
        $article = $this->article();

        $this->assertSame('Relatórios e pautas', $article->category);
        $this->assertSame(['evaluation-sheets.show'], $article->contexts, 'o botão «Preparar reunião» está na Pauta');
    }

    #[Test]
    public function the_documents_it_is_related_to_point_back_to_it(): void
    {
        $center = new HelpCenter;

        $this->assertContains('reports.meeting-without-internet', $center->find('evaluation-sheets.grid-document')->related);
        $this->assertNotContains('reports.meeting-without-internet', $center->find('reports.measures-map')->related, 'o Mapa de Medidas já não faz parte do pacote');
    }

    #[Test]
    public function every_article_it_points_to_exists(): void
    {
        $center = new HelpCenter;

        $this->assertNotEmpty($this->article()->related);

        foreach ($this->article()->related as $id) {
            $this->assertNotNull($center->find($id), "«{$id}» não existe");
        }
    }

    #[Test]
    public function every_document_it_tells_the_teacher_to_save_still_exists(): void
    {
        foreach ([
            'evaluation-sheets.grid-document',
            'evaluation-sheets.grid-document.pdf',
            'evaluation-sheets.meeting-package',
            'evaluation-sheets.xlsx',
            'results.summary',
            'results.summary.xlsx',
            'instruments.grid-document',
            'instruments.grid.results',
            'reports.measures-map.pdf',
            'assessment-analysis.document.pdf',
        ] as $route) {
            $this->assertTrue(Route::has($route), "a rota «{$route}», de que o artigo depende, tem de existir");
        }
    }

    #[Test]
    public function it_names_the_buttons_as_the_pages_name_them(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('«Preparar reunião»', $text);
        $this->assertStringContainsString('«Grelha para imprimir»', $text);
        $this->assertStringContainsString('«Imprimir / PDF»', $text);
        $this->assertStringContainsString('«Documentos da turma»', $text);
        $this->assertStringContainsString('Mapa de Medidas Educativas', $text);
    }

    #[Test]
    public function it_does_not_promise_that_the_application_works_offline(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('precisa de ligação à Internet', $text);
        $this->assertStringContainsString('SEM LIGAÇÃO, CONSULTA-SE; NÃO SE LANÇA', $text);
        $this->assertStringContainsString('não altera nada na aplicação', $text);
        $this->assertStringContainsString('não se atualiza sozinha', $text);
    }

    #[Test]
    public function the_advice_to_carry_a_copy_comes_with_the_advice_to_look_after_it(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('DADOS DE ALUNOS IDENTIFICÁVEIS', $text);
        $this->assertStringContainsString('apague-as quando a reunião terminar', $text);
    }

    #[Test]
    public function it_says_the_analysis_document_is_pro(): void
    {
        $this->assertStringContainsString('no plano Pro, a Análise da Avaliação', $this->text());
    }

    #[Test]
    public function it_starts_from_the_package_and_says_what_it_does_not_hold(): void
    {
        $text = $this->text();

        $this->assertLessThan(strpos($text, 'SE PREFERIR PAPEL'), strpos($text, '«Preparar reunião»'), 'o pacote vem antes dos PDF soltos');
        $this->assertStringContainsString('folhas de Excel', $text);
        $this->assertStringContainsString('o Quadro Síntese da turma', $text);
        $this->assertStringContainsString('cada elemento de avaliação desses períodos', $text);
        $this->assertStringContainsString('A avaliação é contínua', $text);
        $this->assertStringContainsString('as dos períodos anteriores do ano letivo', $text);
        $this->assertStringContainsString('não entram no acumulado', $text);
        $this->assertStringContainsString('assinalado como provisório', $text);
        $this->assertStringContainsString('O Mapa de Medidas Educativas e, no plano Pro, a Análise da Avaliação não vêm no pacote', $text);
        $this->assertStringContainsString('LEIA-ME.txt', $text);
        $this->assertStringContainsString('É uma turma de cada vez', $text);
    }

    #[Test]
    public function a_teacher_without_a_connection_finds_it(): void
    {
        $center = new HelpCenter;

        foreach (['sem internet', 'reunião de avaliação', 'offline', 'a rede da escola falhou', 'preparar reunião'] as $query) {
            $ids = $center->search($query)->map(fn (HelpArticle $article): string => $article->id)->all();

            $this->assertContains('reports.meeting-without-internet', $ids, "«{$query}» não encontra o artigo");
        }
    }
}
