<?php

namespace Tests\Feature\Assessment;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the results screen says, and what it refuses to say.
 *
 * «Resultado» named nothing in particular: this screen shows the weighted
 * average of the period being looked at, computed from that period's own
 * evidence, and now says so. The accumulated figure is a different number with
 * a different name, and neither borrows the other's label for convenience.
 *
 * The other claim under test is a separation: the background of a cell is
 * TREND and the colour of a level is PERFORMANCE. A student who went 25% to 40%
 * improved and is still failing; one who went 92% to 85% fell back and is still
 * excellent. Drawing both with the same ink would make each unreadable.
 */
class ResultsScreenTest extends TestCase
{
    protected function screen(): string
    {
        return (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/results/Show.vue')),
        );
    }

    protected function controller(): string
    {
        return (string) file_get_contents(app_path('Http/Controllers/ResultsController.php'));
    }

    // -------------------------------------------------------- terminology (§4)

    #[Test]
    public function the_column_is_the_weighted_average_of_the_period(): void
    {
        $screen = $this->screen();

        $this->assertStringContainsString('{{ weightedAverageLabel }}', $screen);
        $this->assertStringContainsString("'weightedAverageLabel' => 'Média Ponderada'", $this->controller());

        // «Resultado» no longer heads a column, and the note explains which
        // average this is rather than leaving the teacher to assume.
        $this->assertStringNotContainsString('font-medium">Resultado</th>', $screen);
        $this->assertStringContainsString('calculado apenas com as evidências dele', $screen);
    }

    #[Test]
    public function the_screen_shows_the_period_and_never_labels_it_accumulated(): void
    {
        // forPeriod, not forAccumulated: the label and the number agree.
        $this->assertStringContainsString('forPeriod($class, $selected)', $this->controller());
        $this->assertStringNotContainsString('Média Ponderada Acumulada</th>', $this->screen());
    }

    // ------------------------------------------------- the three columns (§5, §6)

    #[Test]
    public function the_three_judgements_each_get_their_own_column(): void
    {
        $screen = $this->screen();

        foreach (['Proposta', 'Autoavaliação', 'Nível atribuído'] as $heading) {
            $this->assertStringContainsString("font-medium\">{$heading}</th>", $screen);
        }
    }

    #[Test]
    public function the_decided_level_is_the_strongest_of_the_three(): void
    {
        $screen = $this->screen();

        // Bold, and only this one: it is the judgement that is true rather than
        // proposed (§7).
        $this->assertStringContainsString('py-0.5 font-bold', $screen);
        $this->assertSame(1, substr_count($screen, 'font-bold'));

        // Never filled in from the proposal — the screen reads `final` and the
        // read model was already proven not to write it.
        $this->assertStringContainsString('row.classification?.final', $screen);
        $this->assertStringContainsString('Ainda por atribuir', $screen);
    }

    #[Test]
    public function a_student_who_did_not_answer_shows_a_dash_and_never_a_zero(): void
    {
        $screen = $this->screen();

        $this->assertStringContainsString(
            'O aluno não respondeu à autoavaliação global deste período.',
            $screen,
        );
        // The global answer, never an average of the per-domain ones (§5).
        $this->assertStringContainsString('row.self_assessment', $screen);
        $this->assertStringNotContainsString('domains.reduce', $screen);
    }

    #[Test]
    public function a_level_that_differs_from_the_proposal_is_noted_and_never_warned_about(): void
    {
        $screen = $this->screen();

        $this->assertStringContainsString('Nível atribuído diferente da proposta do LÁPIS.', $screen);
        // Neutral: no amber, no alert, no demand for a reason (§7).
        $this->assertStringNotContainsString('differs_from_proposal" class="text-amber', $screen);
    }

    // ------------------------------------------------- trend vs performance (§9)

    #[Test]
    public function the_background_is_trend_and_the_badge_is_performance(): void
    {
        $screen = $this->screen();

        // Trend paints the cell…
        $this->assertStringContainsString('bg-emerald-50 dark:bg-emerald-950/40', $screen);
        $this->assertStringContainsString('bg-rose-50 dark:bg-rose-950/40', $screen);
        $this->assertStringContainsString('TENDÊNCIA, and never performance', $screen);

        // …and performance comes from the canonical resolver, never from a
        // colour chosen here.
        $this->assertStringContainsString('qualitativeToneClasses[qualitativeToneFor(level, props.scaleBands)]', $screen);
        $this->assertStringNotContainsString('text-red-600', $screen);
    }

    #[Test]
    public function standing_still_and_having_nothing_to_compare_both_stay_neutral(): void
    {
        $screen = $this->screen();

        // No background and no arrow for either — an absence is not a fall, and
        // a flat period is not an event (§10).
        $this->assertStringContainsString(
            "if (evolution === null || evolution.direction === 'flat') { return ''; }",
            $screen,
        );
    }

    #[Test]
    public function the_tooltip_states_points_and_not_per_cent(): void
    {
        $screen = $this->screen();

        // The difference between two percentages is not itself a percentage.
        $this->assertStringContainsString('p.p.', $screen);
        $this->assertStringContainsString('Período anterior:', $screen);
        $this->assertStringContainsString('Média Ponderada Acumulada:', $screen);
    }
}
