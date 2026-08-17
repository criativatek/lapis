<?php

namespace Tests\Feature\Assessment;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The visual rules of Estatística, pinned.
 *
 * These assert on source rather than on a rendered chart because the project
 * has no JavaScript test runner — a deliberate limitation, recorded here so
 * nobody mistakes this for a rendering test, and the same compromise
 * CoverageTerminologyTest already makes for the words on screen.
 *
 * What they protect is the part that regresses silently and is expensive when
 * it does: someone reaching for a convenient red for a domain line, someone
 * letting a chart fall back to the library's own look, or someone keying a
 * series colour on render order so the same domain changes ink between two
 * charts on the same page.
 */
class StatisticsChartThemeTest extends TestCase
{
    protected function theme(): string
    {
        return (string) file_get_contents(resource_path('js/lib/chartTheme.ts'));
    }

    protected function page(): string
    {
        return (string) file_get_contents(resource_path('js/pages/results/Statistics.vue'));
    }

    protected function chart(): string
    {
        return (string) file_get_contents(resource_path('js/components/charts/StatChart.vue'));
    }

    // ------------------------------------ 1. as três linguagens visuais

    #[Test]
    public function performance_evolution_and_structure_are_three_separate_palettes(): void
    {
        $theme = $this->theme();

        // Mixing them is how a chart ends up telling a teacher that a student
        // who fell back is doing badly, which is a different claim about a
        // different thing.
        $this->assertStringContainsString('TONE_COLOURS', $theme);
        $this->assertStringContainsString('TREND_COLOURS', $theme);
        $this->assertStringContainsString('DOMAIN_PALETTE', $theme);
    }

    #[Test]
    public function the_domain_palette_contains_no_colour_that_could_read_as_a_trend(): void
    {
        preg_match('/DOMAIN_PALETTE: string\[\] = \[(.*?)\];/s', $this->theme(), $matches);

        $this->assertNotEmpty($matches, 'a paleta de domínios tem de existir');

        preg_match_all('/#([0-9a-fA-F]{6})/', $matches[1], $colours);

        $this->assertNotEmpty($colours[1]);

        foreach ($colours[1] as $hex) {
            $red = hexdec(substr($hex, 0, 2));
            $green = hexdec(substr($hex, 2, 2));
            $blue = hexdec(substr($hex, 4, 2));

            // A structural line drawn in red would read as «regressão» and one
            // in green as «progressão». A domain is neither — it is a subject.
            $this->assertFalse(
                $red > 150 && $green < 90 && $blue < 90,
                "#{$hex} é vermelho de mais para uma série estrutural",
            );
            $this->assertFalse(
                $green > 140 && $red < 90 && $blue < 120,
                "#{$hex} é verde de mais para uma série estrutural",
            );
        }
    }

    // ------------------------------------------ 2. cor determinística

    #[Test]
    public function a_domains_colour_comes_from_its_own_id_and_not_from_render_order(): void
    {
        $theme = $this->theme();

        // The same domain must keep the same ink in the bar chart, in the trend
        // lines, in the heatmap header and in a student's own panel — and keep
        // it tomorrow, after a domain is added or the page is sorted differently.
        $this->assertStringContainsString('export function domainColours(domainIds: number[])', $theme);
        $this->assertStringContainsString('id % DOMAIN_PALETTE.length', $theme);
        // Sorted before assigning, so the map cannot depend on the order the
        // caller happened to hand the ids over in.
        $this->assertStringContainsString('[...domainIds].sort((a, b) => a - b)', $theme);
    }

    #[Test]
    public function every_chart_that_names_a_domain_reads_the_same_colour_map(): void
    {
        $page = $this->page();

        // One map, built once from the domain ids, and read by all of them.
        $this->assertStringContainsString('const inks = computed(() => domainColours(', $page);

        // The series charts and the student's panel all colour from it.
        $this->assertGreaterThanOrEqual(
            4,
            substr_count($page, 'inks.value['),
            'os gráficos de domínio têm de partilhar o mesmo mapa de cores',
        );
    }

    // --------------------------------- 3. nada com aspeto de biblioteca

    #[Test]
    public function the_built_in_tooltip_is_replaced_by_the_pages_own(): void
    {
        $chart = $this->chart();

        // A canvas-drawn tooltip cannot align a column of values or set a label
        // and its figure in two different weights, and that alignment is most of
        // what separates reading a chart from decoding one.
        $this->assertStringContainsString('enabled: false', $chart);
        $this->assertStringContainsString('external:', $chart);
        $this->assertStringContainsString('externalTooltipHandler', $chart);
    }

    #[Test]
    public function the_axes_are_dressed_by_the_theme_and_not_left_to_the_library(): void
    {
        $theme = $this->theme();
        $page = $this->page();

        foreach (['percentAxis', 'categoryAxis', 'countAxis'] as $helper) {
            $this->assertStringContainsString("export function {$helper}(", $theme);
            $this->assertStringContainsString($helper, $page);
        }

        // No axis border, and a grid faint enough to read as a guide rather than
        // as furniture — heavy rules are most of what makes a chart look like a
        // spreadsheet.
        $this->assertStringContainsString('border: { display: false }', $theme);
    }

    #[Test]
    public function the_charts_type_stack_is_the_pages_own(): void
    {
        $this->assertStringContainsString('export const CHART_FONT', $this->theme());
        $this->assertStringContainsString('ui-sans-serif, system-ui', $this->theme());
    }

    // ------------------------------------------------- 4. movimento

    #[Test]
    public function nothing_animates_for_a_reader_who_asked_for_less_motion(): void
    {
        $theme = $this->theme();

        // Zero, not merely shorter. A shortened animation is still an animation.
        $this->assertStringContainsString('prefersReducedMotion() ? 0 : ', $theme);
        $this->assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)')", $theme);

        // The page's own transitions honour it too, not only the canvases.
        $this->assertStringContainsString('prefersReducedMotion()', $this->page());
        $this->assertStringContainsString('prefersReducedMotion()', $this->chart());
    }

    // ------------------------------------------------- 5. a seleção

    #[Test]
    public function a_selection_is_always_visible_and_always_clearable(): void
    {
        $page = $this->page();

        // A filter the reader cannot see is a filter they forget, and then they
        // wonder why half the class disappeared (§5).
        $this->assertStringContainsString('selectedDomainId', $page);
        $this->assertStringContainsString('selectedLevelId', $page);
        $this->assertStringContainsString('function clearSelection', $page);
        $this->assertStringContainsString('A destacar:', $page);
        $this->assertStringContainsString('Limpar', $page);
    }

    #[Test]
    public function selecting_a_band_dims_students_instead_of_removing_them(): void
    {
        $page = $this->page();

        // Highlighted, never hidden: a teacher must not have to remember that a
        // filter is why somebody is missing from a class list.
        $this->assertStringContainsString('function matchesLevel', $page);
        $this->assertStringContainsString("matchesLevel(student) ? '' : 'opacity-30'", $page);
        $this->assertStringNotContainsString('v-if="matchesLevel(student)"', $page);
    }

    // ------------------------------------------ 6. o que não se perde

    #[Test]
    public function every_chart_still_carries_its_data_in_words(): void
    {
        $chart = $this->chart();

        // The prettier the canvas gets, the more it matters that none of it is
        // the only copy of the numbers.
        $this->assertStringContainsString('role="img"', $chart);
        $this->assertStringContainsString(':aria-label="summary"', $chart);
        $this->assertStringContainsString('class="sr-only"', $chart);
        $this->assertStringNotContainsString('class="hidden"', $chart);
    }

    #[Test]
    public function the_heatmap_never_relies_on_colour_alone(): void
    {
        $page = $this->page();

        // The value is written in every cell; the tone only reinforces it.
        $this->assertMatchesRegularExpression(
            '/\{\{ pct\(heatCell\(student, domain\.id\)\?\.weighted_average \?\? null\) \}\}/',
            $page,
        );
        $this->assertStringContainsString(':title="heatCell(student, domain.id)?.mention?.label', $page);
    }
}
