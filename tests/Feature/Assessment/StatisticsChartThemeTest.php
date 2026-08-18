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

    protected function domainBars(): string
    {
        return (string) file_get_contents(resource_path('js/components/infographic/DomainBars.vue'));
    }

    protected function plates(): string
    {
        return (string) file_get_contents(resource_path('js/components/infographic/DistributionBands.vue'));
    }

    protected function slopegraph(): string
    {
        return (string) file_get_contents(resource_path('js/components/infographic/Slopegraph.vue'));
    }

    protected function donut(): string
    {
        return (string) file_get_contents(resource_path('js/components/infographic/OutcomeDonut.vue'));
    }

    protected function kpi(): string
    {
        return (string) file_get_contents(resource_path('js/components/infographic/KpiCard.vue'));
    }

    protected function spectrum(): string
    {
        return (string) file_get_contents(resource_path('js/components/infographic/StudentSpectrum.vue'));
    }

    // ------------------------------------ 0. o valor é o valor, não o desenho

    #[Test]
    public function a_kpi_states_the_base_of_its_own_number(): void
    {
        $kpi = $this->kpi();
        $page = $this->page();

        // «68,4%» alone is unreadable — of what, out of how many? Every card
        // carries a context line, and the pedagogical note lives behind the
        // info button rather than as a paragraph inside the card (§39, §40).
        $this->assertStringContainsString('{{ context }}', $kpi);
        $this->assertStringContainsString(':title="help"', $kpi);
        $this->assertStringContainsString(':aria-label="`O que é: ${label}. ${help}`"', $kpi);

        $this->assertStringContainsString('classificações positivas', $page);
        $this->assertStringContainsString('com dois momentos comparáveis', $page);
    }

    #[Test]
    public function the_headline_figure_is_the_result_of_the_moment(): void
    {
        $page = $this->page();

        // Not «the period's own average» by default. Which figure answers was
        // decided server-side from the profile's own continuity (§1, §9).
        $this->assertStringContainsString(':label="readingLabel"', $page);
        $this->assertStringContainsString(':value="pct(readingAverage)"', $page);
        $this->assertStringContainsString("stats.value.primary.kind === 'accumulated' && continuousView.value", $page);

        // And the other reading is present, smaller, and named (§5, §23).
        $this->assertStringContainsString('stats.primary.has_supplementary', $page);
        $this->assertStringContainsString('supplementaryLabel', $page);
    }

    #[Test]
    public function the_words_beside_the_headline_follow_the_reading_it_shows(): void
    {
        $page = $this->page();

        // The figure follows the switch, so the label has to: «66,8%» under
        // «Média acumulada da turma» is a contradiction the reader would have
        // to resolve on the application's behalf (§1, §2, §17).
        $this->assertStringContainsString(':label="readingLabel"', $page);
        $this->assertStringContainsString(':context="readingCaption"', $page);
        $this->assertStringContainsString(':help="readingHelp"', $page);

        $this->assertStringContainsString("usingAccumulated.value ? 'Média acumulada da turma' : `Média ponderada do \${periodName.value}`", $page);
        $this->assertStringContainsString('Considera a avaliação realizada ao longo do ano letivo até este momento.', $page);
        $this->assertStringContainsString('Considera apenas os elementos realizados no ${periodName.value}.', $page);
    }

    #[Test]
    public function the_second_reading_is_named_in_full_and_never_as_a_bare_acumulado(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('Média acumulada ao longo do ano letivo', $page);
        $this->assertStringContainsString('Só no ${periodName.value}', $page);
        $this->assertStringNotContainsString("'Acumulado do ano'", $page);

        // And the gap between the two is stated, from the two figures that are
        // already on the page — no third number is computed. It names what it
        // is measured against, so «+6,5 p.p.» is never left hanging (§4, §10).
        $this->assertStringContainsString('p.p. face ao acumulado', $page);
        $this->assertStringContainsString('p.p. face ao desempenho só deste período', $page);
        $this->assertStringContainsString('Number(primary).toFixed(1)', $page);
    }

    #[Test]
    public function the_period_is_named_by_the_academic_period_and_never_hardcoded(): void
    {
        $page = $this->page();

        $this->assertStringContainsString("stats.value.selected_period?.label ?? 'período'", $page);

        // «2.º Semestre» must never be written into the interface: a school on
        // trimesters would read a lie. Checked against the TEMPLATE alone — the
        // docblock above the rule names examples on purpose (§3).
        preg_match('/<template>(.*)<\/template>/s', $page, $rendered);
        $this->assertNotEmpty($rendered);

        $this->assertDoesNotMatchRegularExpression('/\d\.º\s*(Semestre|Trimestre|Período)/ui', $rendered[1]);
    }

    #[Test]
    public function the_sparkline_says_which_line_it_is_drawing(): void
    {
        $page = $this->page();

        // A label reading «ao longo do ano» over a line of period figures
        // describes the wrong thing (§5).
        $this->assertStringContainsString("usingAccumulated.value ? 'Evolução da média acumulada' : 'Média de cada período'", $page);
        $this->assertStringContainsString('usingAccumulated.value ? row.primary_average : row.class_average', $page);
        $this->assertStringContainsString(':aria-label="trendSummary"', $page);
    }

    #[Test]
    public function the_reading_switch_moves_averages_and_never_the_grades(): void
    {
        $page = $this->page();

        // Spectrum, domains and the map follow the switch; the success rate,
        // the assigned distribution and the crossings do not — those are
        // statements about decisions the teacher took (§34).
        $this->assertStringContainsString('readingValueOf(student)', $page);
        $this->assertStringContainsString('usingAccumulated.value ? row.accumulated_average : row.period_average', $page);
        $this->assertStringContainsString('usingAccumulated.value ? cell.accumulated_average : cell.weighted_average', $page);

        $this->assertStringContainsString(':succeeded="stats.summary.success.succeeded"', $page);
        $this->assertStringContainsString(':bands="assignedBands"', $page);
        $this->assertStringContainsString('não muda classificações nem taxa de sucesso', $page);
    }

    #[Test]
    public function the_two_evolutions_reach_the_page_under_different_names(): void
    {
        $page = $this->page();

        // A single «evolução» that silently changed meaning with the toggle
        // would be the bug this feature exists to avoid (§13, §15).
        $this->assertStringContainsString('continuous_evolution', $page);
        $this->assertStringContainsString('stats.value.evolution', $page);
        $this->assertStringContainsString("'Evolução na avaliação contínua'", $page);
        $this->assertStringContainsString("'Evolução do desempenho'", $page);
    }

    #[Test]
    public function the_outcome_ring_counts_grades_and_never_movement(): void
    {
        $donut = $this->donut();

        // Positive is not «progrediu». A student can fall six points and stay
        // comfortably positive; drawing the two as one ring would say
        // otherwise (§18, §19).
        $this->assertStringContainsString('Classificação positiva', $donut);
        $this->assertStringContainsString('Classificação negativa', $donut);
        $this->assertStringNotContainsString('Progrediram', $donut);
        $this->assertStringNotContainsString('Regrediram', $donut);

        // The counts are written out, so nothing depends on judging an angle.
        $this->assertStringContainsString('{{ succeeded }}', $donut);
        $this->assertStringContainsString('{{ failed }}', $donut);
        $this->assertStringContainsString('Sem classificação', $donut);
    }

    #[Test]
    public function the_movement_bars_share_one_scale_and_never_the_largest_count(): void
    {
        $board = (string) file_get_contents(resource_path('js/components/infographic/MovementBoard.vue'));

        // Scaling to the tallest bar would draw «4 de 6» and «4 de 40» the
        // same, and the shape of a class would redraw itself per class.
        $this->assertStringContainsString('props.comparable + props.noComparison', $board);
        $this->assertStringContainsString('barWidth(movement.count)', $board);
        $this->assertStringContainsString('{{ movement.count }}', $board);
    }

    #[Test]
    public function the_spectrum_places_students_without_ordering_or_ranking_them(): void
    {
        $spectrum = $this->spectrum();
        $page = $this->page();

        // A dot sits at its own value. The sort is only so that dots landing on
        // the same percent can stack instead of overlapping — it decides
        // nothing about anybody.
        $this->assertStringContainsString('left: `${point.percent}%`', $spectrum);
        $this->assertStringContainsString('lanes', $spectrum);

        // Neutral ends, and no verdicts in anything a teacher READS. Checked
        // against the template alone: the docblock above it says «this is not a
        // ranking», and a test that failed on the explanation rather than on
        // the words on screen would be measuring the wrong thing.
        preg_match('/<template>(.*)<\/template>/s', $spectrum, $rendered);
        $this->assertNotEmpty($rendered);

        foreach (['fraco', 'fracos', 'forte', 'fortes', 'ranking', 'melhores', 'piores'] as $forbidden) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b'.preg_quote($forbidden, '/').'\b/iu',
                $rendered[1],
                "«{$forbidden}» é um juízo sobre um aluno, não uma posição num eixo",
            );
        }

        // Only students who HAVE a result are placed: an axis of results has no
        // position for an absence, and zero is not one.
        $this->assertStringContainsString('filter((student) => readingValueOf(student) !== null)', $page);
    }

    #[Test]
    public function the_spectrum_says_in_words_what_the_dots_say_in_position(): void
    {
        $spectrum = $this->spectrum();

        // A position is unreadable without sight.
        $this->assertStringContainsString('class="sr-only"', $spectrum);
        $this->assertStringContainsString(':aria-label="`${point.name}: ${point.display}', $spectrum);
    }

    #[Test]
    public function a_band_with_nobody_keeps_its_row_and_its_zero(): void
    {
        $bands = (string) file_get_contents(resource_path('js/components/infographic/DistributionBands.vue'));

        $this->assertStringContainsString('band.count === 0', $bands);
        $this->assertStringContainsString('Um nível vazio continua visível', $bands);
        // Never dropped from the list.
        $this->assertStringNotContainsString('v-if="band.count > 0"', $bands);
    }

    #[Test]
    public function a_domain_bar_keeps_the_three_languages_apart(): void
    {
        $bars = (string) file_get_contents(resource_path('js/components/infographic/DomainBars.vue'));

        // The ground and the bar are the DOMAIN's ink, the badge is the SCALE's
        // tone, the change is the TREND's. Merging any two would claim
        // something none of them says — a domain drawn in red because it fell
        // reads as one that is failing.
        $this->assertStringContainsString('color-mix(in srgb, ${bar.colour}', $bars);
        $this->assertStringContainsString('shade(bar.colour, 0.3)}, ${bar.colour}', $bars);
        $this->assertStringContainsString(':class="bar.mentionClass"', $bars);
        $this->assertStringContainsString("bar.direction === 'up' ? 'text-emerald-700", $bars);

        $page = $this->page();
        $this->assertStringContainsString('colour: inks.value[row.domain_id]', $page);
        $this->assertStringContainsString('mentionClass: toneClass(row.qualitative_band)', $page);
    }

    #[Test]
    public function the_cards_sit_on_tinted_grounds_rather_than_white_on_white(): void
    {
        $surfaces = (string) file_get_contents(resource_path('js/lib/surfaces.ts'));
        $page = $this->page();

        // The page used to be white cards on a white page, which is why every
        // rearrangement still looked the same: nothing about the FIELD changed.
        $this->assertStringContainsString('export const PAGE', $surfaces);
        $this->assertStringContainsString(':class="PAGE"', $page);

        foreach (['amber', 'violet', 'mint', 'sky', 'rose'] as $tone) {
            $this->assertStringContainsString("{$tone}:", $surfaces);
        }

        // Each of the top cards gets its own ground, so they read as a set
        // rather than as four copies. The KPIs name the tone; the sections
        // still reach for the surface helper directly.
        foreach (['tone="violet"', 'tone="mint"', 'tone="sky"', 'tone="amber"'] as $usage) {
            $this->assertStringContainsString($usage, $page);
        }

        $this->assertStringContainsString("card('sky')", $page);
        $this->assertStringContainsString("card('mint')", $page);
    }

    #[Test]
    public function a_tinted_ground_never_competes_with_a_result_or_a_trend(): void
    {
        $surfaces = (string) file_get_contents(resource_path('js/lib/surfaces.ts'));

        // Saturated colour on this page already means something: the scale's
        // tones mean performance, the trend inks mean movement. A surface must
        // never be mistaken for either, so every ground is a 50-step at partial
        // opacity — weaker than both by a wide margin.
        // The `dark:` prefix has to be part of the match, not merely near it —
        // on a dark ground a pale wash turns to mud, so those variants are
        // deliberately deep and are judged by a different rule.
        preg_match_all('/(dark:)?bg-(\w+)-(\d+)\/(\d+)/', $surfaces, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches);

        $light = 0;

        foreach ($matches as [$whole, $isDark, , $step, $opacity]) {
            if ($isDark !== '') {
                // Dark twins carry their identity in the border, so the fill
                // stays faint.
                $this->assertLessThanOrEqual(30, (int) $opacity, "«{$whole}» é opaco de mais para um fundo escuro");

                continue;
            }

            $light++;
            $this->assertLessThanOrEqual(50, (int) $step, "«{$whole}» é escuro de mais para um fundo");
            $this->assertLessThanOrEqual(70, (int) $opacity, "«{$whole}» é opaco de mais para um fundo");
        }

        $this->assertGreaterThan(0, $light, 'nenhum fundo claro foi encontrado');
    }

    #[Test]
    public function the_new_pieces_stay_still_for_a_reader_who_asked_them_to(): void
    {
        foreach ([
            $this->donut(),
            $this->spectrum(),
            (string) file_get_contents(resource_path('js/components/infographic/DistributionBands.vue')),
            (string) file_get_contents(resource_path('js/components/infographic/DomainBars.vue')),
        ] as $contents) {
            $this->assertStringContainsString('prefersReducedMotion()', $contents);
        }
    }

    #[Test]
    public function no_charting_library_was_installed_for_any_of_this(): void
    {
        $package = (string) file_get_contents(base_path('package.json'));

        // A gauge is one SVG path and a spectrum is a row of divs. Neither is
        // worth a dependency (§31).
        foreach (['d3', 'apexcharts', 'highcharts', 'echarts', 'gauge'] as $library) {
            $this->assertStringNotContainsString("\"{$library}", $package);
        }

        // The one that IS here stayed.
        $this->assertStringContainsString('"chart.js"', $package);
    }

    // ------------------------------------- 0b. a forma segue os dados

    #[Test]
    public function the_trend_visualisation_is_chosen_from_how_many_periods_have_results(): void
    {
        $page = $this->page();

        // TWO POINTS DO NOT JUSTIFY A LINE CHART. It spends a tall box and a
        // 0–100 axis to draw one segment, and leaves the reader to estimate the
        // change off a grid — which is the one thing they came for.
        $this->assertStringContainsString("const trendShape = computed<'single' | 'slope' | 'line'>", $page);
        $this->assertStringContainsString("return 'single';", $page);
        $this->assertStringContainsString("periodsWithResults.value.length === 2 ? 'slope' : 'line'", $page);

        // And the template actually branches on it.
        $this->assertStringContainsString("v-if=\"trendShape !== 'single'\"", $page);
        $this->assertStringContainsString("v-if=\"trendShape === 'slope'\"", $page);
    }

    #[Test]
    public function a_slopegraph_places_both_ends_on_the_same_scale(): void
    {
        $slopegraph = $this->slopegraph();

        // The steepness of a line IS the size of its change, so two lines can be
        // compared against each other. Nothing is stretched to look dramatic.
        $this->assertStringContainsString('return TOP + (1 - value / 100) * (HEIGHT - TOP - BOTTOM);', $slopegraph);

        // Both values and the difference are written out — an SVG cannot spell
        // them, and the difference is the whole point of the shape.
        $this->assertStringContainsString('formatPoints(change(slope))', $slopegraph);
        $this->assertStringContainsString('sem comparação', $slopegraph);
    }

    #[Test]
    public function the_distribution_is_drawn_without_a_chart_library(): void
    {
        $page = $this->page();
        $plates = $this->plates();

        // A bar chart of five bands is mostly empty plot: the count is the only
        // number and it is never large. A row carries the band, its words, its
        // count and its share on one line, so five bands fill a card instead of
        // floating in one.
        $this->assertStringContainsString('<DistributionBands', $page);
        $this->assertStringNotContainsString('distributionChart', $page);

        // The bar is the SHARE and the number beside it is the count — the only
        // length in the row, and never scaled to the largest band.
        $this->assertStringContainsString('width: band.percent === null', $plates);
    }

    // The empty-band invariant moved with the component that draws it — see
    // a_band_with_nobody_keeps_its_row_and_its_zero above.

    #[Test]
    public function class_movement_is_a_board_of_figures_and_not_a_bar_or_a_doughnut(): void
    {
        $page = $this->page();
        $board = $this->board();

        $this->assertStringContainsString('<MovementBoard', $page);
        $this->assertStringNotContainsString("type: 'doughnut'", $page);

        // THE OLD READING IS GONE, not restyled: no segmented bar of the class
        // and no component drawing one.
        $this->assertStringNotContainsString('FlowRibbons', $page);
        $this->assertFileDoesNotExist(resource_path('js/components/infographic/FlowRibbons.vue'));

        // The movement bars ARE lengths, deliberately — three counts on one
        // baseline answer «quantos, comparados uns com os outros» in a way
        // three separate numerals cannot. What is forbidden is a length with
        // no numeral beside it, so every bar carries its own count and share.
        $this->assertStringContainsString('{{ movement.count }}', $board);
        $this->assertStringContainsString('{{ movement.share }}', $board);
        $this->assertStringContainsString('{{ crossing.count }}', $board);
    }

    #[Test]
    public function the_board_keeps_moving_and_crossing_as_two_separate_readings(): void
    {
        $board = $this->board();

        // Two registers, each with its own heading and its own denominator —
        // rising six points and crossing the line are different facts.
        $this->assertStringContainsString('Mudanças de patamar', $board);
        $this->assertStringContainsString('com dois períodos comparáveis', $board);
        $this->assertStringContainsString('com classificação atribuída nos dois momentos', $board);

        // AND THE SOURCE OF EACH IS SAID ON THE CARD. A teacher must never have
        // to guess whether a figure came from the averages or from the grades.
        $this->assertStringContainsString('lê a classificação que atribuiu, não a média', $board);
    }

    #[Test]
    public function the_official_rate_says_it_counts_assigned_classifications(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('classificações positivas', $page);
        $this->assertStringContainsString('Conta as classificações que atribuiu, não as médias', $page);
        $this->assertStringContainsString('Por classificar', $page);

        // And the statistical block beside it says which figure IT bands, so
        // the two readings are never mistaken for each other (§13).
        $this->assertStringContainsString('Leitura secundária', $page);
        $this->assertStringContainsString('o que o cálculo', $page);
    }

    #[Test]
    public function the_distribution_the_page_opens_on_counts_the_assigned_levels(): void
    {
        $page = $this->page();

        // The primary grid is the grades. The averages keep their own grid,
        // folded, under a heading that names what is inside it (§2, §7).
        $this->assertStringContainsString('Como se distribuem as classificações', $page);
        $this->assertStringContainsString(':bands="assignedBands"', $page);
        $this->assertStringContainsString('Sem classificação atribuída', $page);

        $this->assertStringContainsString(':bands="distributionBands"', $page);
        $this->assertStringContainsString('showCalculatedDistribution', $page);
    }

    #[Test]
    public function a_band_chosen_on_the_assigned_grid_highlights_who_was_graded_there(): void
    {
        $page = $this->page();

        // Two selections, kept apart: choosing «Insuficiente» on the grades
        // must not light up the students whose AVERAGE happens to land there,
        // and vice versa (§14).
        $this->assertStringContainsString('student.assigned?.scale_level_id === selectedLevelId.value', $page);
        $this->assertStringContainsString('student.band?.scale_level_id === selectedCalculatedLevelId.value', $page);

        // And the chip says which of the two a highlight came from.
        $this->assertStringContainsString('atribuído · ', $page);
        $this->assertStringContainsString('calculado · ', $page);
    }

    #[Test]
    public function the_gradients_are_fixed_grounds_and_never_carry_a_value(): void
    {
        $board = $this->board();

        // A ground is chosen by CATEGORY and written as a constant. Nothing
        // interpolates a colour, a stop or an opacity from a figure (§8).
        $this->assertStringContainsString('bg-gradient-to-br from-emerald-50', $board);
        $this->assertStringNotContainsString('gradient-to-br from-${', $board);
        $this->assertDoesNotMatchRegularExpression(
            '/(from|via|to)-\[.*(count|share|percent|average)/',
            $board,
            'um degradé não pode ser calculado a partir de um valor',
        );

        // And each has a dark twin rather than the same pastel over a dark page.
        $this->assertStringContainsString('dark:from-emerald-950', $board);
        $this->assertStringContainsString('dark:from-rose-950', $board);
    }

    #[Test]
    public function every_figure_on_the_board_carries_a_word_beside_its_icon(): void
    {
        $board = $this->board();

        // Every icon is decoration and says so, so nothing on this board is
        // announced to a screen reader as a picture of a trend.
        preg_match_all('/<component\s+:is="(?:HeroIcon|\w+_ICONS)[^>]*>/s', $board, $icons);

        $this->assertNotEmpty($icons[0]);

        foreach ($icons[0] as $icon) {
            $this->assertStringContainsString('aria-hidden="true"', $icon, "ícone sem aria-hidden: {$icon}");
        }

        $this->assertStringContainsString('{{ movement.label }}', $board);
        $this->assertStringContainsString('{{ crossing.label }}', $board);
        $this->assertStringContainsString('{{ row.label }}', $board);
    }

    #[Test]
    public function the_board_honours_a_reader_who_asked_for_less_motion(): void
    {
        $board = $this->board();

        $this->assertStringContainsString('prefersReducedMotion()', $board);
        $this->assertStringContainsString("'duration-0'", $board);
    }

    #[Test]
    public function a_card_nobody_is_in_is_not_a_pressable_control(): void
    {
        $board = $this->board();

        // Zero is still shown — «ninguém passou» is an answer — but it stops
        // being a button, because there is nobody to highlight.
        $this->assertStringContainsString("isSelectable(movement.count) ? 'button' : 'div'", $board);
        $this->assertStringContainsString("isSelectable(crossing.count) ? 'button' : 'div'", $board);
        $this->assertStringContainsString('return props.interactive && count > 0;', $board);
        // Pressed state is announced, not merely drawn.
        $this->assertStringContainsString(':aria-pressed=', $board);
    }

    #[Test]
    public function each_of_the_six_readings_has_a_word_of_its_own(): void
    {
        $page = $this->page();

        // Colour distinguishes them at a glance; the words are what actually
        // carry the meaning, so a reader who cannot tell mint from coral loses
        // nothing (§27).
        foreach ([
            'Progrediram',
            'Mantiveram-se',
            'Regrediram',
            'Passaram a resultado positivo',
            'Passaram a resultado negativo',
            'Mantiveram resultado positivo',
            'Mantiveram resultado negativo',
        ] as $word) {
            $this->assertStringContainsString($word, $page, "falta a leitura «{$word}»");
        }

        // And «sem classificação comparável» keeps its own sentence rather than
        // being folded into «mantiveram-se».
        $this->assertStringContainsString('Sem classificação comparável', $this->board());
        $this->assertStringContainsString('não são «mantiveram-se»', $this->board());
    }

    private function board(): string
    {
        return (string) file_get_contents(resource_path('js/components/infographic/MovementBoard.vue'));
    }

    // ------------------------- 0b. profundidade decorativa, nunca quantitativa

    #[Test]
    public function the_depth_on_a_column_is_a_constant_offset_and_never_a_perspective(): void
    {
        $theme = $this->theme();

        // THE POINT OF THE WHOLE THING. A 3-D pie or a tilted axis distorts the
        // very comparison the reader is making. This cannot: the offset is a
        // fixed number of pixels, identical on a bar of 4 students and one of
        // 40, so it cannot change how two bars compare.
        $this->assertStringContainsString('const depth = chart.width < 480 ? 0 : 7;', $theme);

        preg_match('/afterDatasetsDraw\(chart: ChartType\): void \{(.*?)\n    \},/s', $theme, $matches);
        $this->assertNotEmpty($matches, 'o plugin de profundidade tem de existir');

        $body = $matches[1];

        // The front face is Chart.js's own bar, untouched. Nothing here may
        // recompute a height, a scale or a value.
        foreach (['getPixelForValue', 'scale.getPixel', 'bar.height *', 'bar.y *'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "o plugin não pode recalcular geometria: «{$forbidden}»");
        }

        // The faces are drawn from the bar's own y and base, offset by `depth`.
        $this->assertStringContainsString('context.moveTo(right, bar.y)', $body);
        $this->assertStringContainsString('bar.y - depth', $body);
    }

    #[Test]
    public function depth_is_dropped_entirely_on_a_narrow_viewport(): void
    {
        // On a phone the extra geometry is noise rather than depth (§21).
        $this->assertStringContainsString('chart.width < 480 ? 0 : 7', $this->theme());
        $this->assertStringContainsString('if (depth === 0) {', $this->theme());
    }

    #[Test]
    public function a_domain_bars_length_is_the_percentage_itself(): void
    {
        $bars = $this->domainBars();

        // The width IS the percentage — never scaled to the largest value,
        // which would make a class of 40s look like a class of 90s.
        $this->assertStringContainsString('width: `${Math.max(bar.percent, 1)}%`', $bars);
        $this->assertStringNotContainsString('Math.max(...', $bars);

        // No value is no bar — never a stub standing in for a zero (§37).
        $this->assertStringContainsString('v-if="bar.percent !== null"', $bars);
        $this->assertStringContainsString('Sem resultado neste período', $bars);
    }

    #[Test]
    public function the_domain_bars_carry_their_values_as_text_rather_than_as_length_alone(): void
    {
        $bars = $this->domainBars();

        // Real DOM, so no parallel table is needed — but only because the value
        // is written out beside every bar.
        $this->assertStringContainsString('{{ bar.display }}', $bars);
        $this->assertStringContainsString('{{ bar.label }}', $bars);
        $this->assertStringContainsString(':aria-pressed="selectedId === bar.id"', $bars);
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

        // Only the axes that still have a canvas to dress: counting students
        // moved to DistributionBands, which needs no axis at all.
        foreach (['percentAxis', 'categoryAxis'] as $helper) {
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
    public function the_infographic_numerals_are_decoration_and_not_content(): void
    {
        $heading = (string) file_get_contents(resource_path('js/components/infographic/SectionHeading.vue'));

        // «01» says the page has a reading order. It is not a name, an id or a
        // value, so it is hidden from assistive technology and the real heading
        // beside it does the work.
        $this->assertStringContainsString('aria-hidden="true"', $heading);
        $this->assertStringContainsString('<h2 :id="id"', $heading);
    }

    #[Test]
    public function the_heatmap_cells_lift_without_hiding_anything(): void
    {
        $page = $this->page();

        // Depth is decoration: a lift and a shadow, both skipped for a reader
        // who asked for less motion, and neither carrying meaning (§22).
        $this->assertStringContainsString('hover:-translate-y-0.5', $page);
        $this->assertStringContainsString("prefersReducedMotion() ? '' : 'transition-all duration-150 hover:-translate-y-0.5", $page);

        // The crosshair is a reading aid over a wide table, and nothing more.
        $this->assertStringContainsString('hoveredStudentId', $page);
        $this->assertStringContainsString('hoveredDomainId', $page);
    }

    #[Test]
    public function the_heatmap_never_relies_on_colour_alone(): void
    {
        $page = $this->page();

        // The value is written in every cell; the tone only reinforces it.
        $this->assertStringContainsString('{{ pct(heatValue(student, domain.id)) }}', $page);
        $this->assertStringContainsString(':title="heatCell(student, domain.id)?.mention?.label', $page);
    }
}
