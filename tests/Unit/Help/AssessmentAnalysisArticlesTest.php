<?php

namespace Tests\Unit\Help;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three Pro articles of the Fatia D (0.177.0): the analysis page, its document, and a student's
 * «Ao longo do ano».
 *
 * What they must keep true is what the product does: the pages they name exist, the articles they point
 * to exist, they are the help of the pages they describe, and the boundaries they draw — a trend needs
 * three moments and a persistent negative two, nothing is a diagnosis, a missing value is not a zero, names
 * only leave when asked — are written down in them.
 */
class AssessmentAnalysisArticlesTest extends TestCase
{
    protected bool $seed = false;

    private function article(string $id): HelpArticle
    {
        $article = (new HelpCenter)->find($id);

        $this->assertNotNull($article, "o artigo {$id} não existe");

        return $article;
    }

    private function text(string $id): string
    {
        return implode("\n", $this->article($id)->content);
    }

    /** @return iterable<string, array{string, string}> */
    public static function articles(): iterable
    {
        yield 'a análise' => ['assessment.analysis', 'assessment-analysis.show'];
        yield 'o documento' => ['assessment.analysis-document', 'assessment-analysis.document'];
        yield 'ao longo do ano' => ['assessment.year-reading', 'student-progress.student'];
    }

    #[Test]
    #[DataProvider('articles')]
    public function each_lives_with_the_assessment_articles_and_is_the_help_of_its_page(string $id, string $route): void
    {
        $article = $this->article($id);

        $this->assertSame('Avaliação', $article->category);
        $this->assertContains($route, $article->contexts);
        $this->assertTrue(Route::has($route), "a rota «{$route}», de que o artigo é a ajuda, tem de existir");

        $ids = (new HelpCenter)->forContext($route)->map(fn (HelpArticle $candidate): string => $candidate->id)->all();
        $this->assertContains($id, $ids, $route);
    }

    #[Test]
    #[DataProvider('articles')]
    public function every_article_each_points_to_exists(string $id, string $route): void
    {
        $this->assertNotSame('', $route);

        $center = new HelpCenter;

        foreach ($this->article($id)->related as $related) {
            $this->assertNotNull($center->find($related), "«{$related}» não existe");
        }
    }

    #[Test]
    public function the_pages_they_describe_exist(): void
    {
        foreach (['assessment-analysis.show', 'assessment-analysis.document', 'assessment-analysis.document.pdf', 'results.statistics'] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    #[Test]
    public function the_analysis_tells_how_to_open_it_and_says_it_is_pro(): void
    {
        $text = $this->text('assessment.analysis');

        $this->assertStringContainsString('plano Pro', $text);
        $this->assertStringContainsString('«Comparar momentos →»', $text);
        $this->assertStringContainsString('«Documento (imprimir ou PDF) →»', $text);
    }

    #[Test]
    public function the_analysis_draws_the_line_between_a_trend_and_a_persistent_negative(): void
    {
        $text = $this->text('assessment.analysis');

        $this->assertStringContainsString('pelo menos três momentos comparáveis', $text);
        $this->assertStringContainsString('precisa de dois', $text);
        $this->assertStringContainsString('progressão consistente', $text);
        $this->assertStringContainsString('regressão consistente', $text);
        $this->assertStringContainsString('evolução variável', $text);
        $this->assertStringContainsString('no momento mais recente e no anterior', $text);
        $this->assertStringContainsString('interrompe a sequência', $text);
    }

    #[Test]
    public function the_analysis_says_when_it_does_not_compare_and_that_nothing_is_a_diagnosis(): void
    {
        $text = $this->text('assessment.analysis');

        $this->assertStringContainsString('os números aparecem lado a lado e não há variação', $text);
        $this->assertStringContainsString('um resultado em falta não é zero', $text);
        $this->assertStringContainsString('não é um diagnóstico de dificuldades de aprendizagem', $text);
        $this->assertStringContainsString('não faz previsões', $text);
        $this->assertStringContainsString('se dois domínios empatam, nenhum é destacado', $text);
        $this->assertStringContainsString('pontos percentuais', $text);
        $this->assertStringContainsString('os mesmos alunos', $text);
    }

    #[Test]
    public function the_analysis_says_where_the_data_come_from_and_who_sees_the_names(): void
    {
        $text = $this->text('assessment.analysis');

        $this->assertStringContainsString('calculado com os dados de hoje', $text);
        $this->assertStringContainsString('guardado a', $text);
        $this->assertStringContainsString('só para os professores da turma', $text);
    }

    #[Test]
    public function the_document_says_it_is_what_the_screen_says_and_that_names_are_opt_in(): void
    {
        $text = $this->text('assessment.analysis-document');

        $this->assertStringContainsString('O DOCUMENTO DIZ O QUE O ECRÃ DIZ', $text);
        $this->assertStringContainsString('OS AVISOS ESTÃO NO TOPO', $text);
        $this->assertStringContainsString('OS NOMES SÓ SAEM QUANDO OS PEDE', $text);
        $this->assertStringContainsString('sem nomear nenhum aluno', $text);
        $this->assertStringContainsString('«Incluir a lista nominal de alunos»', $text);
        $this->assertStringContainsString('nunca com o nome de um aluno', $text);
        $this->assertStringContainsString('não fica guardado em lado nenhum', $text);
        $this->assertStringContainsString('Não é um relatório finalizado', $text);
    }

    #[Test]
    public function the_document_says_the_audit_trail_keeps_no_names_and_no_marks(): void
    {
        $this->assertStringContainsString('nunca com nomes nem notas', $this->text('assessment.analysis-document'));
    }

    #[Test]
    public function the_year_reading_says_which_result_it_uses_and_does_not_repeat_the_page(): void
    {
        $text = $this->text('assessment.year-reading');

        $this->assertStringContainsString('«Ao longo do ano»', $text);
        $this->assertStringContainsString('depois de «Em que domínios»', $text);
        $this->assertStringContainsString('resultado do próprio período', $text);
        $this->assertStringContainsString('leitura contínua', $text);
        $this->assertStringContainsString('não se repete', $text);
    }

    #[Test]
    public function the_year_reading_says_a_gap_is_not_a_zero_and_that_nothing_is_a_diagnosis(): void
    {
        $text = $this->text('assessment.year-reading');

        $this->assertStringContainsString('nunca um zero', $text);
        $this->assertStringContainsString('sem comparação', $text);
        $this->assertStringContainsString('ISTO NÃO É UM DIAGNÓSTICO', $text);
        $this->assertStringContainsString('não fazem previsões', $text);
        $this->assertStringContainsString('três momentos comparáveis', $text);
        $this->assertStringContainsString('só para os professores da turma', $text);
    }

    #[Test]
    public function they_are_found_by_what_a_teacher_would_search_for(): void
    {
        $center = new HelpCenter;

        foreach ([
            'comparar momentos' => 'assessment.analysis',
            'tendências' => 'assessment.analysis',
            'resultado negativo persistente' => 'assessment.analysis',
            'análise da avaliação em pdf' => 'assessment.analysis-document',
            'lista nominal' => 'assessment.analysis-document',
            'ao longo do ano' => 'assessment.year-reading',
        ] as $query => $id) {
            $this->assertContains($id, $center->search($query)->map(fn (HelpArticle $article): string => $article->id)->all(), "«{$query}»");
        }
    }

    #[Test]
    public function none_of_them_diagnoses_forecasts_or_recommends(): void
    {
        foreach (['assessment.analysis', 'assessment.analysis-document', 'assessment.year-reading'] as $id) {
            $text = mb_strtolower($this->text($id));
            $rest = str_replace(['não é um diagnóstico de dificuldades de aprendizagem', 'não é um diagnóstico', 'isto não é um diagnóstico', 'não são um diagnóstico'], '', $text);

            foreach (['dislexia', 'dificuldade de aprendizagem', 'recomendamos', 'deve fazer', 'risco de insucesso', 'vai piorar', 'vai melhorar'] as $word) {
                $this->assertStringNotContainsString($word, $rest, "{$id}: «{$word}»");
            }
        }
    }
}
