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
 * evidence was not enough — and if that were true, Lapispro should not have
 * produced a value at all. What actually happened is that a result exists and
 * rests on part of the applicable elements. That is "parcial".
 */
class CoverageTerminologyTest extends TestCase
{
    protected function componentSource(): string
    {
        return (string) file_get_contents(resource_path('js/components/CoverageWarning.vue'));
    }

    /**
     * The state→words map, which two screens now read: the ⚠ on Resultados and
     * the partial-coverage note on the INOVAR export. It lives on its own so
     * that «Ausência justificada» cannot become «Falta justificada» on one of
     * them — which is exactly what a second copy would eventually do.
     */
    protected function labelSource(): string
    {
        return (string) file_get_contents(resource_path('js/lib/coverage.ts'));
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
        $labels = $this->labelSource();

        $expected = [
            'absent' => 'Ausência',
            'absent_justified' => 'Ausência justificada',
            'exempt' => 'Dispensa',
            'not_applicable' => 'Não aplicável',
            // Beside an instrument and a date, «Anulado» reads as though the
            // student was annulled. It has to name what was.
            'annulled' => 'Elemento anulado',
        ];

        foreach ($expected as $state => $label) {
            $this->assertMatchesRegularExpression(
                "/\b{$state}:\s*'".preg_quote($label, '/')."'/u",
                $labels,
                "O estado {$state} tem de ser descrito como «{$label}».",
            );
        }
    }

    #[Test]
    public function the_words_are_not_copied_into_the_screens_that_use_them(): void
    {
        // One map, read by both. A component carrying its own copy is how the
        // two screens end up describing the same recorded state differently.
        $this->assertStringContainsString("from '@/lib/coverage'", $this->componentSource());
        $this->assertStringNotContainsString("label: 'Ausência'", $this->componentSource());
    }

    #[Test]
    public function an_absence_and_a_structural_exclusion_do_not_share_wording(): void
    {
        $component = $this->componentSource();
        $labels = $this->labelSource();

        // An absence leaves a question WITHOUT a classification that was expected.
        // A dispensation or a non-applicable question was never going to carry one.
        // Collapsing the two would describe a student as having missed something
        // they were never due to sit. The name of the state and the effect on the
        // calculation are asserted where each of them lives.
        $this->assertMatchesRegularExpression("/absent:\s*'Ausência'/u", $labels);
        $this->assertMatchesRegularExpression(
            "/absent:\s*\{\s*effect:\s*'sem classificação'/u",
            $component,
        );

        $this->assertMatchesRegularExpression("/not_applicable:\s*'Não aplicável'/u", $labels);
        $this->assertMatchesRegularExpression(
            "/not_applicable:\s*\{\s*effect:\s*'não consideradas'/u",
            $component,
        );
    }

    #[Test]
    public function a_state_the_map_does_not_know_is_never_called_an_absence(): void
    {
        $labels = $this->labelSource();

        // `pending` is «not graded yet», which says nothing about whether anybody
        // was there. In Lapispro a blank is not a zero and no score is not an
        // absence — so an unknown state gets a phrase that claims neither.
        $this->assertStringNotContainsString('pending:', $labels);
        $this->assertStringContainsString("?? 'Sem registo de avaliação'", $labels);
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
