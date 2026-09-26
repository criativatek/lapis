<?php

namespace Tests\Unit\Assessment\Analysis;

use App\Domain\Assessment\Analysis\DescriptiveReport;
use App\Domain\Assessment\Analysis\Observation;
use App\Domain\Assessment\Analysis\ObservationStatus;
use App\Domain\Assessment\Analysis\ResultsAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DescriptiveReportTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function context(bool $isDiagnostic = false, bool $counts = true): array
    {
        return [
            'is_diagnostic' => $isDiagnostic,
            'counts_toward_classification' => $counts,
            'instrument' => ['title' => 'Ficha de Português', 'applied_on' => '2026-10-01', 'purpose_label' => 'Sumativa'],
            'class' => ['label' => '7.º A'],
            'period' => ['label' => '1.º Período'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dimensions(array $globalObservations, array $domainObservations = [], array $bands = []): array
    {
        $analyzer = new ResultsAnalyzer;

        $dimensions = [
            ['key' => 'global', 'label' => 'Global', 'analysis' => $analyzer->analyse($globalObservations, $bands, '49.5')],
        ];

        foreach ($domainObservations as $key => $observations) {
            $dimensions[] = ['key' => $key, 'label' => $key, 'analysis' => $analyzer->analyse($observations, $bands, '49.5')];
        }

        return $dimensions;
    }

    private function classified(string $key, string $exact): Observation
    {
        return new Observation($key, ObservationStatus::Classified, $exact, false);
    }

    #[Test]
    public function sections_appear_in_the_specified_order(): void
    {
        $report = DescriptiveReport::compose($this->context(), $this->dimensions([$this->classified('1', '80')]));

        $this->assertSame(
            ['identification', 'global_summary', 'quantitative', 'qualitative', 'domains', 'differences'],
            array_column($report['sections'], 'key'),
        );
    }

    #[Test]
    public function no_student_names_appear_anywhere(): void
    {
        $observations = [
            $this->classified('1', '73.5'),
            $this->classified('2', '41.2'),
        ];

        $report = DescriptiveReport::compose($this->context(), $this->dimensions($observations));

        $text = json_encode($report);
        $this->assertIsString($text);
        $this->assertStringNotContainsString('enrollment_id', $text);
        // Only aggregate statistics (mean/median/min/max/counts) ever surface —
        // never a per-student label or identifier.
        $this->assertStringNotContainsString('aluno(a)', $text);
    }

    #[Test]
    public function diagnostic_title_and_sentence(): void
    {
        $report = DescriptiveReport::compose($this->context(isDiagnostic: true, counts: false), $this->dimensions([]));

        $this->assertSame('Relatório da avaliação diagnóstica', $report['title']);

        $identification = $report['sections'][0];
        $this->assertStringContainsString(
            'Os instrumentos de avaliação diagnóstica não contribuem para as médias classificativas.',
            implode(' ', $identification['paragraphs']),
        );
    }

    #[Test]
    public function a_diagnostic_never_gets_a_counting_warning_whatever_its_stored_flag(): void
    {
        // The engine excludes every diagnostic (InstrumentEligibility), so the
        // report states the rule — never a configuration-dependent caveat.
        $report = DescriptiveReport::compose($this->context(isDiagnostic: true, counts: true), $this->dimensions([]));

        $text = implode(' ', $report['sections'][0]['paragraphs']);

        $this->assertStringContainsString('Os instrumentos de avaliação diagnóstica não contribuem para as médias classificativas.', $text);
        $this->assertStringNotContainsString('ENTRAM atualmente', $text);
        $this->assertStringNotContainsString('ainda não está implementada', $text);
    }

    #[Test]
    public function non_diagnostic_uses_the_statistical_title(): void
    {
        $report = DescriptiveReport::compose($this->context(isDiagnostic: false), $this->dimensions([]));

        $this->assertSame('Relatório estatístico do instrumento', $report['title']);
    }

    #[Test]
    public function differences_section_is_neutral_when_no_conditions_are_met(): void
    {
        // A single dimension, no domains, well above the threshold, N >= 5, no
        // missing/partial — nothing to say.
        $observations = array_map(fn (int $i) => $this->classified((string) $i, '80'), range(1, 6));

        $report = DescriptiveReport::compose($this->context(), $this->dimensions($observations));
        $differences = $report['sections'][5];

        $this->assertSame(['Sem diferenças estatísticas relevantes a assinalar.'], $differences['paragraphs']);
    }

    #[Test]
    public function differences_section_flags_a_small_sample(): void
    {
        $observations = [$this->classified('1', '80'), $this->classified('2', '90')];

        $report = DescriptiveReport::compose($this->context(), $this->dimensions($observations));
        $differences = $report['sections'][5];

        $this->assertStringContainsString('Cautela', implode(' ', $differences['paragraphs']));
    }

    #[Test]
    public function the_report_never_states_a_cause(): void
    {
        $observations = [
            $this->classified('1', '10'),
            $this->classified('2', '20'),
            $this->classified('3', '95'),
        ];
        $domainObservations = [
            'd1' => [$this->classified('1', '10'), $this->classified('2', '20'), $this->classified('3', '95')],
            'd2' => [$this->classified('1', '90'), $this->classified('2', '85'), $this->classified('3', '92')],
        ];

        $report = DescriptiveReport::compose($this->context(), $this->dimensions($observations, $domainObservations));

        $forbidden = ['porque', 'devido', 'causa', 'falta de estudo'];
        foreach ($report['sections'] as $section) {
            $text = mb_strtolower(implode(' ', $section['paragraphs']));
            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString($word, $text, "The report must never say «{$word}».");
            }
        }
    }
}
