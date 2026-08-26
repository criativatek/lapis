<?php

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Progress\BuildStudentInsights;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Margem de progressão» (§3) and «Potencialidades» → «Próximo passo» (§4)
 * of the panel review, round 2 — tested directly against hand-built
 * `domains.highlights` fixtures (via Reflection, as
 * `ResultsProgressionTest` already does for a sibling builder) because the
 * real demo scenario does not happen to produce every overlap case: none of
 * its six students have a `largest_rise` that coincides with `lowest` or
 * `highest`, and BuildStudentInsights takes no DB dependency at all — every
 * one of its methods is a pure function of the payload BuildStudentProgress
 * already built.
 *
 * NEVER TWO INDEPENDENT NOTIONS OF "THE WEAKEST DOMAIN". Both sections read
 * `domains.highlights.lowest` through the exact same
 * `consolidationPriority()` call, so the class-level assertions below prove
 * they cannot drift apart — not just that each one, read in isolation,
 * happens to look right.
 */
class BuildStudentInsightsConsolidationTest extends TestCase
{
    private function consolidationDetail(array $progress): string
    {
        $service = new BuildStudentInsights;
        $method = new \ReflectionMethod($service, 'consolidationDetail');

        return $method->invoke($service, $progress);
    }

    private function nextStep(array $progress, array $margin): ?string
    {
        $service = new BuildStudentInsights;
        $method = new \ReflectionMethod($service, 'potentialities');

        /** @var array{next_step: string|null} $potentialities */
        $potentialities = $method->invoke($service, $progress, $margin);

        return $potentialities['next_step'];
    }

    private function progressWith(?array $lowest, ?array $rise, ?array $highest): array
    {
        return [
            'domains' => [
                'highlights' => [
                    'highest' => $highest,
                    'lowest' => $lowest,
                    'largest_rise' => $rise,
                    'largest_fall' => null,
                ],
            ],
        ];
    }

    // ------------------------------------------------- §3: margem de progressão

    #[Test]
    public function names_the_lowest_domain_directly_with_its_real_current_value(): void
    {
        $progress = $this->progressWith(
            lowest: ['domain_id' => 4, 'name' => 'Gramática', 'value' => '37.5'],
            rise: null,
            highest: null,
        );

        $this->assertSame(
            'A prioridade de consolidação é Gramática, atualmente o domínio com resultado mais baixo (37,5%).',
            $this->consolidationDetail($progress),
        );
    }

    #[Test]
    public function reconciles_with_a_lowest_domain_that_is_also_rising(): void
    {
        $progress = $this->progressWith(
            lowest: ['domain_id' => 4, 'name' => 'Gramática', 'value' => '37.5'],
            rise: ['domain_id' => 4, 'name' => 'Gramática', 'value' => '12.0'],
            highest: null,
        );

        $this->assertSame(
            'A prioridade de consolidação é Gramática (37,5%), também o domínio com maior subida recente — '
            .'o foco está em consolidar essa subida antes de avançar para novos patamares.',
            $this->consolidationDetail($progress),
        );
    }

    #[Test]
    public function falls_back_to_the_generic_sentence_with_no_nameable_lowest_domain(): void
    {
        $progress = $this->progressWith(lowest: null, rise: null, highest: null);

        $this->assertSame(
            'Face à trajetória atual, o foco está em consolidar a aprendizagem de base antes de avançar para novos patamares.',
            $this->consolidationDetail($progress),
        );
    }

    // --------------------------------------------- §4: potencialidades → próximo passo

    #[Test]
    public function synthesises_all_three_distinct_roles(): void
    {
        $progress = $this->progressWith(
            lowest: ['domain_id' => 4, 'name' => 'Gramática', 'value' => '55.0'],
            rise: ['domain_id' => 3, 'name' => 'Escrita', 'value' => '5.0'],
            highest: ['domain_id' => 2, 'name' => 'Leitura', 'value' => '66.25'],
        );

        $this->assertSame(
            'Priorizar a consolidação de Gramática; estabilizar a progressão em Escrita; continuar a aprofundar Leitura.',
            $this->nextStep($progress, ['state' => 'foco_na_consolidacao', 'detail' => 'x']),
        );
    }

    #[Test]
    public function collapses_lowest_and_rise_into_one_clause_when_they_are_the_same_domain(): void
    {
        $progress = $this->progressWith(
            lowest: ['domain_id' => 4, 'name' => 'Gramática', 'value' => '37.5'],
            rise: ['domain_id' => 4, 'name' => 'Gramática', 'value' => '12.0'],
            highest: ['domain_id' => 2, 'name' => 'Leitura', 'value' => '66.25'],
        );

        $sentence = $this->nextStep($progress, ['state' => 'foco_na_consolidacao', 'detail' => 'x']);

        $this->assertSame(
            'Priorizar a consolidação da subida recente em Gramática; continuar a aprofundar Leitura.',
            $sentence,
        );
        // Gramática is never named twice.
        $this->assertSame(1, substr_count($sentence, 'Gramática'));
    }

    #[Test]
    public function collapses_highest_and_rise_into_one_clause_when_they_are_the_same_domain(): void
    {
        $progress = $this->progressWith(
            lowest: ['domain_id' => 4, 'name' => 'Gramática', 'value' => '55.0'],
            rise: ['domain_id' => 2, 'name' => 'Leitura', 'value' => '5.0'],
            highest: ['domain_id' => 2, 'name' => 'Leitura', 'value' => '66.25'],
        );

        $sentence = $this->nextStep($progress, ['state' => 'foco_na_consolidacao', 'detail' => 'x']);

        $this->assertSame(
            'Priorizar a consolidação de Gramática; consolidar e aprofundar Leitura.',
            $sentence,
        );
        $this->assertSame(1, substr_count($sentence, 'Leitura'));
    }

    #[Test]
    public function degrades_gracefully_when_fewer_than_three_roles_have_data(): void
    {
        $onlyHighest = $this->progressWith(lowest: null, rise: null, highest: ['domain_id' => 2, 'name' => 'Leitura', 'value' => '66.25']);
        $this->assertSame('Continuar a aprofundar Leitura.', $this->nextStep($onlyHighest, ['state' => 'consolidado_com_margem', 'detail' => 'x']));

        $lowestAndHighest = $this->progressWith(
            lowest: ['domain_id' => 4, 'name' => 'Gramática', 'value' => '55.0'],
            rise: null,
            highest: ['domain_id' => 2, 'name' => 'Leitura', 'value' => '66.25'],
        );
        $this->assertSame(
            'Priorizar a consolidação de Gramática; continuar a aprofundar Leitura.',
            $this->nextStep($lowestAndHighest, ['state' => 'foco_na_consolidacao', 'detail' => 'x']),
        );

        $none = $this->progressWith(lowest: null, rise: null, highest: null);
        $this->assertNull($this->nextStep($none, ['state' => 'foco_na_consolidacao', 'detail' => 'x']));
    }

    /**
     * The reconciliation itself: whichever domain `consolidationDetail()`
     * names as the consolidation priority is the SAME domain `next_step`
     * opens with — read from the identical `domains.highlights.lowest`,
     * through the identical `consolidationPriority()` call, never two
     * independently-computed notions of "the weakest domain".
     */
    #[Test]
    public function progression_margin_and_next_step_can_never_name_two_different_weakest_domains(): void
    {
        $progress = $this->progressWith(
            lowest: ['domain_id' => 4, 'name' => 'Gramática', 'value' => '55.0'],
            rise: ['domain_id' => 3, 'name' => 'Escrita', 'value' => '5.0'],
            highest: ['domain_id' => 2, 'name' => 'Leitura', 'value' => '66.25'],
        );

        $margin = $this->consolidationDetail($progress);
        $nextStep = $this->nextStep($progress, ['state' => 'foco_na_consolidacao', 'detail' => $margin]);

        $this->assertStringContainsString('Gramática', $margin);
        $this->assertStringContainsString('Gramática', $nextStep);
    }
}
