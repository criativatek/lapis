<?php

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Ai\ClassAnalysisParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The boundary between what a model said and what a teacher is shown.
 *
 * Everything here is about NOT trusting the reply: the four blocks are only
 * built when they are really there, and the small amount of markdown a model
 * reaches for despite being told not to is removed once, here, rather than in
 * every component that renders a line.
 */
class ClassAnalysisParserTest extends TestCase
{
    private function wellFormed(): string
    {
        return <<<'TEXT'
        SINTESE: Os resultados situam-se maioritariamente no nível Suficiente, com uma minoria abaixo.
        PADROES: - A distribuição concentra-se num único nível.
        - O domínio da compreensão leitora acompanha a média geral.
        ATENCAO: - Um resultado assenta em cobertura parcial.
        SUGESTOES: - Pode ser útil considerar uma recolha adicional de evidência.
        TEXT;
    }

    #[Test]
    public function a_well_formed_answer_becomes_four_typed_blocks(): void
    {
        $analysis = ClassAnalysisParser::parse($this->wellFormed());

        $this->assertNotNull($analysis);
        $this->assertStringContainsString('nível Suficiente', $analysis->summary);
        $this->assertCount(2, $analysis->patterns);
        $this->assertCount(1, $analysis->cautions);
        $this->assertCount(1, $analysis->suggestions);
        $this->assertSame('A distribuição concentra-se num único nível.', $analysis->patterns[0]);
    }

    #[Test]
    public function accented_labels_are_accepted_because_losing_an_answer_to_an_accent_would_be_pedantry(): void
    {
        $analysis = ClassAnalysisParser::parse(
            "SÍNTESE: Uma leitura.\nPADRÕES: - Um padrão.\nATENÇÃO: - Um ponto.\nSUGESTÕES: - Uma sugestão.",
        );

        $this->assertNotNull($analysis);
        $this->assertSame('Uma leitura.', $analysis->summary);
        $this->assertSame(['Um padrão.'], $analysis->patterns);
        $this->assertSame(['Um ponto.'], $analysis->cautions);
    }

    #[Test]
    public function an_empty_cautions_block_is_allowed_because_nothing_to_report_is_a_real_finding(): void
    {
        $analysis = ClassAnalysisParser::parse(
            "SINTESE: Uma leitura.\nPADROES: - Um padrão.\nSUGESTOES: - Uma sugestão.",
        );

        $this->assertNotNull($analysis);
        $this->assertSame([], $analysis->cautions);
    }

    #[Test]
    public function an_answer_without_a_summary_is_refused_rather_than_half_shown(): void
    {
        $this->assertNull(ClassAnalysisParser::parse("PADROES: - Um padrão.\nSUGESTOES: - Uma sugestão."));
    }

    #[Test]
    public function a_bare_paragraph_with_no_blocks_at_all_is_refused(): void
    {
        $this->assertNull(ClassAnalysisParser::parse('A turma está bem, de um modo geral.'));
        $this->assertNull(ClassAnalysisParser::parse('SINTESE: Uma leitura sozinha, sem mais nada.'));
        $this->assertNull(ClassAnalysisParser::parse(''));
        $this->assertNull(ClassAnalysisParser::parse('   '));
    }

    #[Test]
    public function markdown_the_prompt_forbade_is_stripped_instead_of_shown_to_the_teacher(): void
    {
        $analysis = ClassAnalysisParser::parse(
            "SINTESE: Uma leitura **com negrito** e `código`.\nPADROES: * Um padrão em *itálico*.\nSUGESTOES: - Uma sugestão.",
        );

        $this->assertNotNull($analysis);
        $this->assertSame('Uma leitura com negrito e código.', $analysis->summary);
        $this->assertSame('Um padrão em itálico.', $analysis->patterns[0]);
        $this->assertStringNotContainsString('*', $analysis->patterns[0]);
    }

    #[Test]
    public function a_runaway_list_is_capped_rather_than_rendered_whole(): void
    {
        $points = implode("\n", array_map(fn (int $i): string => "- Observação número {$i}.", range(1, 12)));

        $analysis = ClassAnalysisParser::parse("SINTESE: Uma leitura.\nPADROES: {$points}\nSUGESTOES: - Uma sugestão.");

        $this->assertNotNull($analysis);
        $this->assertCount(ClassAnalysisParser::MAX_POINTS, $analysis->patterns);
    }

    #[Test]
    public function the_four_blocks_are_the_only_thing_that_reaches_the_screen(): void
    {
        $analysis = ClassAnalysisParser::parse($this->wellFormed());

        $this->assertNotNull($analysis);
        $this->assertSame(
            ['summary', 'patterns', 'cautions', 'suggestions'],
            array_keys($analysis->toArray()),
        );
    }
}
