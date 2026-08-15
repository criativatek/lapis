<?php

namespace Tests\Feature\Assessment;

use PHPUnit\Framework\Attributes\Test;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The words the teacher reads.
 *
 * These assert on the component source rather than on a rendered DOM because the
 * project has no JavaScript test runner — a deliberate limitation, recorded here
 * so nobody mistakes this for a rendering test. What they do protect is the part
 * that would silently regress: someone reintroducing "cobertura insuficiente",
 * or adding a state to the map without the words that describe it.
 *
 * The distinction being defended is not cosmetic. "Insuficiente" claims the
 * evidence was not enough — and if that were true, LÁPIS should not have
 * produced a value at all. What actually happened is that a result exists and
 * rests on part of the applicable elements. That is "parcial".
 */
class CoverageTerminologyTest extends TestCase
{
    protected function componentSource(): string
    {
        return (string) file_get_contents(resource_path('js/components/CoverageWarning.vue'));
    }

    #[Test]
    public function the_phrase_cobertura_insuficiente_appears_nowhere_in_the_interface(): void
    {
        $offenders = [];

        $files = Finder::create()
            ->files()
            ->in(resource_path('js'))
            ->name(['*.vue', '*.ts']);

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (stripos($contents, 'cobertura insuficiente') !== false) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'A designação "cobertura insuficiente" foi substituída por "cobertura parcial".');
    }

    #[Test]
    public function a_calculated_result_is_headed_cobertura_parcial(): void
    {
        $this->assertStringContainsString('Cobertura parcial', $this->componentSource());
    }

    #[Test]
    public function the_absence_of_elements_is_not_called_partial_coverage(): void
    {
        $component = $this->componentSource();

        // Two headings, chosen by whether a value exists — not by whether an
        // absence was recorded. Nothing can be PARTIALLY covered if there is no
        // result for the coverage to be partial of.
        $this->assertStringContainsString('Sem elementos avaliados', $component);
        $this->assertStringContainsString('Ainda não existem elementos avaliados neste domínio.', $component);
        $this->assertMatchesRegularExpression(
            "/hasValue\s*\?\s*'Cobertura parcial'\s*:\s*'Sem elementos avaliados'/u",
            $component,
        );
    }

    #[Test]
    public function each_recorded_state_is_described_by_its_own_words(): void
    {
        $component = $this->componentSource();

        $expected = [
            'absent' => 'Ausência',
            'absent_justified' => 'Ausência justificada',
            'exempt' => 'Dispensa',
            'not_applicable' => 'Não aplicável',
            'annulled' => 'Anulado',
        ];

        foreach ($expected as $state => $label) {
            $this->assertMatchesRegularExpression(
                "/\b{$state}:\s*\{\s*label:\s*'".preg_quote($label, '/')."'/u",
                $component,
                "O estado {$state} tem de ser descrito como «{$label}».",
            );
        }
    }

    #[Test]
    public function an_absence_and_a_structural_exclusion_do_not_share_wording(): void
    {
        $component = $this->componentSource();

        // An absence leaves a question WITHOUT a classification that was expected.
        // A dispensation or a non-applicable question was never going to carry one.
        // Collapsing the two would describe a student as having missed something
        // they were never due to sit.
        $this->assertMatchesRegularExpression(
            "/absent:\s*\{\s*label:\s*'Ausência',\s*effect:\s*'sem classificação'/u",
            $component,
        );
        $this->assertMatchesRegularExpression(
            "/not_applicable:\s*\{\s*label:\s*'Não aplicável',\s*effect:\s*'não consideradas'/u",
            $component,
        );
    }

    #[Test]
    public function an_unmapped_state_gets_neutral_wording_instead_of_an_invented_reason(): void
    {
        $component = $this->componentSource();

        // If the engine ever flags a state this map does not know, the tooltip
        // says how many questions are out and stops. Guessing the reason would
        // put an occurrence on the record that the data does not support.
        $this->assertStringContainsString('${questions} fora do cálculo.', $component);
    }
}
