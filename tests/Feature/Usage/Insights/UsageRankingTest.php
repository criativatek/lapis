<?php

namespace Tests\Feature\Usage\Insights;

use App\Support\Usage\UsageFeature;
use PHPUnit\Framework\Attributes\Test;

class UsageRankingTest extends InsightsTestCase
{
    /**
     * Ana: 3 dias ativos em 2 semanas, 5 ações, 2 funcionalidades.
     * Bruno: 3 dias ativos numa semana, 9 ações.
     * Carla: 2 dias ativos em 2 semanas, 1 ação.
     * Duarte (conta de 02/10): 1 dia ativo, 0 ações.
     * Eva: sem atividade.
     */
    private function seedPeople(): void
    {
        $ana = $this->person('Ana', '2026-06-01 10:00:00');
        $bruno = $this->person('Bruno', '2026-06-01 10:00:00');
        $carla = $this->person('Carla', '2026-06-01 10:00:00');
        $duarte = $this->person('Duarte', '2026-10-02 10:00:00');
        $eva = $this->person('Eva', '2026-06-01 10:00:00');
        foreach ([$ana, $bruno, $carla, $duarte, $eva] as $person) {
            $this->state($person);
        }

        $this->rollup($ana, '2026-10-01', UsageFeature::Lessons, actions: 3, items: 12);
        $this->rollup($ana, '2026-10-02', UsageFeature::Reports, actions: 2, items: 8);
        $this->rollup($ana, '2026-10-05', UsageFeature::Lessons);

        foreach (['2026-10-06', '2026-10-07', '2026-10-08'] as $day) {
            $this->rollup($bruno, $day, UsageFeature::Lessons, actions: 3, items: 1);
        }

        $this->rollup($carla, '2026-09-15', UsageFeature::Lessons, actions: 1);
        $this->rollup($carla, '2026-10-09', UsageFeature::Lessons);

        $this->rollup($duarte, '2026-10-03', UsageFeature::Lessons);
    }

    /**
     * @return array{names: list<string>, positions: list<int>, tied: list<bool>}
     */
    private function ordered(array $query = []): array
    {
        $rows = $this->props('admin.usage.ranking', $query)['ranking']['data'];

        return [
            'names' => array_column($rows, 'name'),
            'positions' => array_column($rows, 'position'),
            'tied' => array_column($rows, 'tied'),
        ];
    }

    #[Test]
    public function it_ranks_by_active_days_by_default_and_ties_share_the_position(): void
    {
        $this->seedPeople();

        $this->assertSame(
            ['names' => ['Ana', 'Bruno', 'Carla', 'Duarte'], 'positions' => [1, 1, 2, 3], 'tied' => [true, true, false, false]],
            $this->ordered(),
        );
    }

    #[Test]
    public function it_ranks_by_completed_actions(): void
    {
        $this->seedPeople();

        $this->assertSame(
            ['names' => ['Bruno', 'Ana', 'Carla', 'Duarte'], 'positions' => [1, 2, 3, 4], 'tied' => [false, false, false, false]],
            $this->ordered(['sort' => 'actions']),
        );
    }

    #[Test]
    public function it_ranks_by_weeks_with_activity(): void
    {
        $this->seedPeople();

        // Ana e Carla têm 2 semanas; Bruno e Duarte 1. A ordem secundária é o nome.
        $this->assertSame(
            ['names' => ['Ana', 'Carla', 'Bruno', 'Duarte'], 'positions' => [1, 1, 2, 2], 'tied' => [true, true, true, true]],
            $this->ordered(['sort' => 'active_weeks']),
        );
    }

    #[Test]
    public function it_ranks_by_features_used_as_a_complementary_indicator(): void
    {
        $this->seedPeople();

        $this->assertSame(
            ['names' => ['Ana', 'Bruno', 'Carla', 'Duarte'], 'positions' => [1, 2, 2, 2], 'tied' => [false, true, true, true]],
            $this->ordered(['sort' => 'features']),
        );
    }

    #[Test]
    public function a_row_keeps_each_metric_separate_with_no_composite_score(): void
    {
        $this->seedPeople();

        $props = $this->props('admin.usage.ranking');
        $ana = $props['ranking']['data'][0];

        $this->assertEqualsCanonicalizing([
            'position', 'tied', 'ulid', 'name', 'email', 'plans', 'joined_on', 'account_age_days', 'observation_days',
            'period_days', 'partial_observation', 'active_days', 'actions', 'items', 'active_weeks', 'observed_weeks', 'features',
        ], array_keys($ana));
        $this->assertSame(
            ['active_days' => 3, 'actions' => 5, 'items' => 20, 'active_weeks' => 2, 'features' => 2],
            array_intersect_key($ana, array_flip(['active_days', 'actions', 'items', 'active_weeks', 'features'])),
        );
        $this->assertSame('Base', $ana['plans'][0]);
        $this->assertSame(133, $ana['account_age_days']);
        $this->assertStringNotContainsString('score', (string) json_encode($props));
    }

    #[Test]
    public function people_without_activity_in_the_period_are_not_ranked_and_are_counted_apart(): void
    {
        $this->seedPeople();

        $props = $this->props('admin.usage.ranking');

        $this->assertSame(4, $props['total_ranked']);
        $this->assertSame(1, $props['unranked']);
        $this->assertNotContains('Eva', array_column($props['ranking']['data'], 'name'));
    }

    #[Test]
    public function a_recent_account_is_flagged_as_partially_observed(): void
    {
        $this->seedPeople();

        $rows = collect($this->props('admin.usage.ranking')['ranking']['data'])->keyBy('name');

        // Duarte criou a conta a 02/10: 11 dos 30 dias, em 3 semanas.
        $this->assertTrue($rows['Duarte']['partial_observation']);
        $this->assertSame(11, $rows['Duarte']['observation_days']);
        $this->assertSame(30, $rows['Duarte']['period_days']);
        $this->assertSame(3, $rows['Duarte']['observed_weeks']);
        $this->assertSame(10, $rows['Duarte']['account_age_days']);

        $this->assertFalse($rows['Ana']['partial_observation']);
        $this->assertSame(30, $rows['Ana']['observation_days']);
        $this->assertSame(6, $rows['Ana']['observed_weeks']);
    }

    #[Test]
    public function the_age_filter_narrows_by_account_age_or_full_observation(): void
    {
        $this->seedPeople();
        $mid = $this->person('Marta', '2026-08-01 10:00:00');
        $this->state($mid);
        $this->rollup($mid, '2026-10-06');

        $this->assertSame(['Ana', 'Bruno', 'Carla', 'Duarte', 'Marta'], $this->ordered()['names']);
        $this->assertSame(['Ana', 'Bruno', 'Carla', 'Marta'], $this->ordered(['age' => 'full'])['names']);
        $this->assertSame(['Duarte'], $this->ordered(['age' => 'lt30'])['names']);
        $this->assertSame(['Marta'], $this->ordered(['age' => '30to90'])['names']);
        $this->assertSame(['Ana', 'Bruno', 'Carla'], $this->ordered(['age' => 'gt90'])['names']);

        // O filtro também move quem fica por ordenar: a Eva só entra com a antiguidade dela.
        $this->assertSame(1, $this->props('admin.usage.ranking', ['age' => 'gt90'])['unranked']);
        $this->assertSame(0, $this->props('admin.usage.ranking', ['age' => 'lt30'])['unranked']);

        $this->actingAs($this->operator)->get(route('admin.usage.ranking', ['age' => 'velhos']))->assertSessionHasErrors('age');
    }

    #[Test]
    public function the_support_text_and_the_criteria_are_what_the_spec_says(): void
    {
        $props = $this->props('admin.usage.ranking');

        $this->assertSame('Compara a regularidade e as ações concluídas na aplicação. Não avalia a qualidade do trabalho pedagógico.', $props['support_text']);
        $this->assertSame(['active_days', 'actions', 'active_weeks', 'features'], array_column($props['sort_options'], 'key'));
        $this->assertSame('active_days', $props['filters']['sort']);
    }

    #[Test]
    public function it_pages_by_fifty(): void
    {
        foreach (range(1, 52) as $number) {
            $person = $this->person(sprintf('Pessoa %02d', $number), '2026-06-01 10:00:00');
            $this->state($person);
            $this->rollup($person, '2026-10-06');
        }

        $first = $this->props('admin.usage.ranking')['ranking'];
        $second = $this->props('admin.usage.ranking', ['page' => 2])['ranking'];

        $this->assertSame(52, $first['total']);
        $this->assertCount(50, $first['data']);
        $this->assertCount(2, $second['data']);
        $this->assertSame(1, $second['data'][0]['position']);
        $this->assertTrue($second['data'][0]['tied']);
    }

    #[Test]
    public function the_ranking_respects_the_segment(): void
    {
        $this->seedPeople();
        $trial = $this->person('Ensaio', '2026-06-01 10:00:00');
        $this->state($trial);
        $this->markTest($this->organizationOf($trial));
        $this->rollup($trial, '2026-10-06');

        $this->assertNotContains('Ensaio', $this->ordered()['names']);
        $this->assertSame(['Ensaio'], $this->ordered(['segment' => 'test'])['names']);
    }

    #[Test]
    public function a_period_without_collection_ranks_nobody_and_counts_nobody(): void
    {
        $this->seedPeople();

        $props = $this->props('admin.usage.ranking', ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-20']);

        $this->assertSame([], $props['ranking']['data']);
        $this->assertNull($props['unranked']);
    }

    #[Test]
    public function the_day_collection_started_is_partial_so_the_week_is_not_a_full_observation(): void
    {
        $this->startUsageCollectionAt('2026-10-06 10:00:00');
        $old = $this->person('Antiga', '2026-01-10 10:00:00');
        $this->state($old);
        $this->rollup($old, '2026-10-07');

        $row = collect($this->props('admin.usage.ranking', ['period' => '7'])['ranking']['data'])->firstWhere('name', 'Antiga');

        $this->assertSame(7, $row['observation_days']);
        $this->assertTrue($row['partial_observation']);
        $this->assertSame([], $this->ordered(['period' => '7', 'age' => 'full'])['names']);
    }
}
