<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Structured\SectionedAnswer;
use App\Services\Assessment\Ai\ResultsAnalysisParser;
use App\Services\Progress\Ai\FollowupSynthesisParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The structured-output layer: the mechanics, then each feature's semantics.
 *
 * WHAT A PARSER IS FOR HERE. Not «decode the response» — an engine's answer is
 * untrusted text, and the parser is the boundary at which this application
 * decides whether it has something it can put in front of a teacher. An answer
 * it half-understood is refused, because half an analysis under a heading that
 * promises a whole one is worse than a retry button.
 */
class SectionedAnswerTest extends TestCase
{
    // --------------------------------------------------------- the mechanics

    #[Test]
    public function it_splits_on_labels_and_stops_at_the_next_one(): void
    {
        $answer = SectionedAnswer::parse(
            "SINTESE: Uma frase.\nPADROES: - Um padrão.\n- Outro padrão.\nSUGESTOES: - Uma sugestão.",
            ['SINTESE', 'PADROES', 'SUGESTOES'],
        );

        $this->assertSame('Uma frase.', $answer->paragraph('SINTESE'));
        $this->assertSame(['Um padrão.', 'Outro padrão.'], $answer->points('PADROES', 4));
        $this->assertSame(['Uma sugestão.'], $answer->points('SUGESTOES', 4));
    }

    /**
     * The prompt asks for `SINTESE`; a model that helpfully writes `Síntese`
     * has done nothing wrong enough to lose its answer over, and refusing would
     * turn a cosmetic difference into a failed request the teacher pays for.
     */
    #[Test]
    public function labels_are_accent_and_case_tolerant(): void
    {
        $answer = SectionedAnswer::parse(
            "Síntese: Uma frase.\nAtenção: - Um ponto.\nCautelas: - Uma cautela.",
            ['SINTESE', 'ATENCAO', 'CAUTELAS'],
        );

        $this->assertSame('Uma frase.', $answer->paragraph('SINTESE'));
        $this->assertSame(['Um ponto.'], $answer->points('ATENCAO', 4));
        $this->assertSame(['Uma cautela.'], $answer->points('CAUTELAS', 4));
    }

    /**
     * The prompt forbids markdown; models produce it anyway. The panels render
     * plain text into plain elements with no `v-html`, so leaving the asterisks
     * in would simply show them to the teacher.
     */
    #[Test]
    public function markdown_is_stripped_rather_than_shown(): void
    {
        $answer = SectionedAnswer::parse(
            "SINTESE: Uma **frase** com _ênfase_ e `código`.\nPADROES: * Um padrão.\n- **Outro**.",
            ['SINTESE', 'PADROES'],
        );

        $this->assertSame('Uma frase com _ênfase_ e código.', $answer->paragraph('SINTESE'));
        $this->assertSame(['Um padrão.', 'Outro.'], $answer->points('PADROES', 4));
    }

    #[Test]
    public function a_section_that_is_absent_is_empty_rather_than_an_error(): void
    {
        $answer = SectionedAnswer::parse('SINTESE: Uma frase.', ['SINTESE', 'CAUTELAS']);

        $this->assertSame('', $answer->paragraph('CAUTELAS'));
        $this->assertSame([], $answer->points('CAUTELAS', 4));
    }

    #[Test]
    public function the_point_ceiling_is_respected(): void
    {
        $answer = SectionedAnswer::parse(
            "PADROES: - Um.\n- Dois.\n- Três.\n- Quatro.\n- Cinco.\n- Seis.",
            ['PADROES'],
        );

        $this->assertCount(3, $answer->points('PADROES', 3));
    }

    #[Test]
    public function an_empty_answer_yields_nothing(): void
    {
        $answer = SectionedAnswer::parse('   ', ['SINTESE']);

        $this->assertSame('', $answer->paragraph('SINTESE'));
    }

    // ------------------------------------------------- avaliação (semantics)

    #[Test]
    public function the_assessment_parser_accepts_a_complete_answer(): void
    {
        $analysis = ResultsAnalysisParser::parse(implode("\n", [
            'SINTESE: Os resultados são globalmente positivos.',
            'PADROES: - Concentração nos níveis intermédios.',
            'FORTES: - A Leitura reúne evidência consistente.',
            'ATENCAO: - A Escrita apresenta maior dispersão.',
            'SUGESTOES: - Pode ser útil considerar tarefas de escrita.',
            'CAUTELAS: - Alguns resultados têm cobertura parcial.',
        ]));

        $this->assertNotNull($analysis);
        $this->assertSame('Os resultados são globalmente positivos.', $analysis->summary);
        $this->assertSame(['A Leitura reúne evidência consistente.'], $analysis->strengths);
        $this->assertSame(['Alguns resultados têm cobertura parcial.'], $analysis->cautions);
    }

    #[Test]
    public function the_assessment_parser_refuses_an_answer_with_no_summary(): void
    {
        $this->assertNull(ResultsAnalysisParser::parse("PADROES: - Um padrão.\nSUGESTOES: - Uma sugestão."));
    }

    /**
     * A reading with neither observations nor proposals is a paragraph, and the
     * panel's six blocks would have five empty ones.
     */
    #[Test]
    public function the_assessment_parser_refuses_a_summary_with_nothing_under_it(): void
    {
        $this->assertNull(ResultsAnalysisParser::parse('SINTESE: Uma frase e mais nada.'));
    }

    /**
     * Either half is enough. A reading that describes without proposing is
     * useful; so is one that proposes without restating the screen.
     */
    #[Test]
    public function the_assessment_parser_accepts_observations_without_proposals(): void
    {
        $analysis = ResultsAnalysisParser::parse("SINTESE: Uma frase.\nPADROES: - Um padrão.");

        $this->assertNotNull($analysis);
        $this->assertSame([], $analysis->suggestions);
    }

    // -------------------------------------------- acompanhamento (semantics)

    #[Test]
    public function the_followup_parser_accepts_a_complete_synthesis(): void
    {
        $synthesis = FollowupSynthesisParser::parse(implode("\n", [
            'SINTESE: O percurso é globalmente estável.',
            'POSITIVOS: - A Leitura mantém-se consolidada.',
            'ATENCAO: - Há três TPC por realizar.',
            'MUDOU: - O resultado desceu face ao período anterior.',
            'PROXIMO: - Pode ser útil conversar sobre a organização do trabalho.',
            'CAUTELAS: - A evidência do 2.º período ainda é escassa.',
        ]));

        $this->assertNotNull($synthesis);
        $this->assertSame(['A Leitura mantém-se consolidada.'], $synthesis->positiveSignals);
        $this->assertSame(['O resultado desceu face ao período anterior.'], $synthesis->whatChanged);
    }

    /**
     * THE ONE RULE THAT IS DIFFERENT FROM THE ASSESSMENT PARSER'S, and the
     * reason it exists: a synthesis of a child that lists only what is wrong is
     * exactly the failure the Evolução panel was designed to prevent. A model
     * that finds nothing positive is asked to say so in a sentence — and that
     * sentence satisfies this.
     */
    #[Test]
    public function the_followup_parser_refuses_a_synthesis_with_no_positive_signals(): void
    {
        $this->assertNull(FollowupSynthesisParser::parse(implode("\n", [
            'SINTESE: O percurso é preocupante.',
            'ATENCAO: - Há três TPC por realizar.',
            'ATENCAO: - Os resultados desceram.',
            'PROXIMO: - Pode ser útil marcar uma conversa.',
        ])));
    }

    #[Test]
    public function the_followup_parser_accepts_an_honest_absence_of_positives(): void
    {
        $synthesis = FollowupSynthesisParser::parse(implode("\n", [
            'SINTESE: A evidência ainda é escassa.',
            'POSITIVOS: - A evidência disponível ainda não permite destacar pontos consolidados.',
            'PROXIMO: - Pode ser útil registar mais evidência antes de concluir seja o que for.',
        ]));

        $this->assertNotNull($synthesis);
        $this->assertCount(1, $synthesis->positiveSignals);
    }
}
