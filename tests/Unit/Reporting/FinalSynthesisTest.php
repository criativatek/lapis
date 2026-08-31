<?php

namespace Tests\Unit\Reporting;

use App\Models\Report;
use App\Models\ReportScopeKind;
use App\Models\ReportType;
use App\Services\Reporting\ReportCapabilities;
use App\Services\Reporting\ReportContext;
use App\Services\Reporting\Sections\FinalSynthesisComposer;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * WHAT A SÍNTESE FINAL IS FOR (§9).
 *
 * It was restating the two figures the reader had just met — the class average
 * and the success rate — under a heading promising a synthesis. A recap that
 * recaps one section is not a synthesis; it is the last paragraph of that
 * section, printed twice.
 *
 * WHAT IT MAY ADD IS ONLY WHAT IS ALREADY IN HAND: the domain that came out
 * highest and the one that came out lowest, and how many of the comparable
 * students moved forward. Every one of those is a figure another section
 * already established, so nothing here is a new claim — what changes is that
 * they are finally in one place, which is what a reader turning to the end of a
 * report is looking for.
 *
 * WHAT IT MAY NEVER ADD: a cause, a diagnosis, a recommendation, or any reading
 * of what the numbers mean about the class or a child (§9, §16).
 *
 * EVERY CLAUSE IS OMISSIBLE. A class with one domain, or none, or no previous
 * period, or nobody comparable, gets a shorter sentence — never a broken one.
 * Built as a unit test against constructed facts, because that is the only way
 * to reach all nine shapes deterministically.
 */
class FinalSynthesisTest extends TestCase
{
    #[Test]
    public function it_names_the_highest_and_the_lowest_domain(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '58.4', 'success' => ['placed' => 6, 'rate' => '83.3']],
            'domains' => [
                ['label' => 'Leitura', 'period_average' => '65.2'],
                ['label' => 'Gramática', 'period_average' => '49.9'],
                ['label' => 'Escrita', 'period_average' => '57.0'],
            ],
        ]);

        $this->assertStringContainsString('taxa de sucesso de 83,3%', $text);
        $this->assertStringContainsString('Leitura', $text);
        $this->assertStringContainsString('65,2%', $text);
        $this->assertStringContainsString('Gramática', $text);
        $this->assertStringContainsString('49,9%', $text);
    }

    #[Test]
    public function a_single_domain_is_not_described_as_the_highest_and_the_lowest(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '58.4', 'success' => ['placed' => 6, 'rate' => '83.3']],
            'domains' => [
                ['label' => 'Leitura', 'period_average' => '65.2'],
            ],
        ]);

        // Naming the only domain as both the best and the worst is true and
        // absurd, and naming it as «the best» alone is a comparison with
        // nothing to compare against. With one domain there is no extreme to
        // report, so the sentence is simply not there.
        $this->assertStringNotContainsString('melhor resultado', $text);
        $this->assertStringNotContainsString('mais baixo', $text);
        $this->assertStringNotContainsString('Leitura', $text);
    }

    #[Test]
    public function two_domains_with_the_same_average_are_not_called_highest_and_lowest(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '60', 'success' => ['placed' => 6, 'rate' => '80']],
            'domains' => [
                ['label' => 'Leitura', 'period_average' => '60.0'],
                ['label' => 'Escrita', 'period_average' => '60.0'],
            ],
        ]);

        $this->assertStringNotContainsString('mais elevado', $text);
        $this->assertStringNotContainsString('mais baixo', $text);
    }

    #[Test]
    public function no_domains_still_produces_the_headline_figures(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '58.4', 'success' => ['placed' => 6, 'rate' => '83.3']],
            'domains' => [],
        ]);

        $this->assertStringContainsString('58,4%', $text);
        $this->assertStringContainsString('83,3%', $text);
        $this->assertStringNotContainsString('domínio', $text);
    }

    #[Test]
    public function domains_with_no_figure_are_not_extremes(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '58.4', 'success' => ['placed' => 6, 'rate' => '83.3']],
            'domains' => [
                ['label' => 'Oralidade', 'period_average' => null],
                ['label' => 'Leitura', 'period_average' => '65.2'],
            ],
        ]);

        $this->assertStringNotContainsString('Oralidade', $text);
    }

    #[Test]
    public function it_reports_progression_against_the_previous_moment(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '58.4', 'success' => ['placed' => 6, 'rate' => '83.3']],
            'domains' => [],
            'evolution' => ['comparable' => 6, 'progressed' => 4],
        ]);

        $this->assertStringContainsString('quatro dos seis alunos', $text);
        $this->assertStringContainsString('progrediram', $text);
    }

    #[Test]
    public function one_progression_is_said_in_the_singular(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '58.4', 'success' => ['placed' => 6, 'rate' => '83.3']],
            'domains' => [],
            'evolution' => ['comparable' => 6, 'progressed' => 1],
        ]);

        $this->assertStringContainsString('um dos seis alunos', $text);
        $this->assertStringContainsString('progrediu', $text);
        $this->assertStringNotContainsString('progrediram', $text);
    }

    #[Test]
    public function nobody_progressing_is_stated_and_not_hidden(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '58.4', 'success' => ['placed' => 6, 'rate' => '83.3']],
            'domains' => [],
            'evolution' => ['comparable' => 6, 'progressed' => 0],
        ]);

        $this->assertStringContainsString('nenhum', $text);
        // The zero case is singular, as everywhere else in this module.
        $this->assertStringNotContainsString('progrediram', $text);
    }

    #[Test]
    public function everybody_progressing_says_so_rather_than_counting(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '58.4', 'success' => ['placed' => 6, 'rate' => '83.3']],
            'domains' => [],
            'evolution' => ['comparable' => 6, 'progressed' => 6],
        ]);

        $this->assertStringContainsString('os seis alunos', $text);
        $this->assertStringNotContainsString('seis dos seis', $text);
    }

    #[Test]
    public function no_comparable_students_produce_no_progression_clause(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '58.4', 'success' => ['placed' => 6, 'rate' => '83.3']],
            'domains' => [],
            'evolution' => ['comparable' => 0, 'progressed' => 0],
        ]);

        $this->assertStringNotContainsString('progr', $text);
    }

    #[Test]
    public function no_previous_period_produces_no_progression_clause(): void
    {
        $text = $this->synthesis([
            'summary' => ['primary_average' => '58.4', 'success' => ['placed' => 6, 'rate' => '83.3']],
            'domains' => [],
        ]);

        $this->assertStringNotContainsString('progr', $text);
        $this->assertStringNotContainsString('momento anterior', $text);
    }

    #[Test]
    public function nothing_to_recap_produces_nothing(): void
    {
        $this->assertSame('', $this->synthesis([
            'summary' => ['primary_average' => null, 'success' => ['placed' => 0, 'rate' => null]],
            'domains' => [],
        ]));
    }

    #[Test]
    public function it_never_diagnoses_explains_or_recommends(): void
    {
        $text = mb_strtolower($this->synthesis([
            'summary' => ['primary_average' => '38.4', 'success' => ['placed' => 6, 'rate' => '33.3']],
            'domains' => [
                ['label' => 'Leitura', 'period_average' => '65.2'],
                ['label' => 'Gramática', 'period_average' => '19.9'],
            ],
            'evolution' => ['comparable' => 6, 'progressed' => 0],
        ]));

        foreach ([
            'deve-se', 'devido', 'porque', 'reflete', 'demonstra', 'sugere',
            'recomenda', 'aconselha', 'preocupante', 'insuficiente desempenho',
            'dificuldades de', 'falta de',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "A síntese interpretou: «{$forbidden}».");
        }
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function synthesis(array $facts): string
    {
        $report = new Report;
        $report->type = ReportType::SchoolClass;
        $report->scope_kind = ReportScopeKind::Period;
        $report->scope_label = '1.º Semestre';

        $context = new ReportContext(
            report: $report,
            facts: $facts,
            identity: [],
            // The synthesis asks the plan nothing: it recaps figures every plan
            // already produced. A stub says that plainly.
            capabilities: $this->createStub(ReportCapabilities::class),
            generatedOn: Carbon::parse('2027-06-30 12:00:00'),
        );

        return (string) (new FinalSynthesisComposer)->compose($context)->body;
    }
}
