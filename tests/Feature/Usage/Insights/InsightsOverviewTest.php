<?php

namespace Tests\Feature\Usage\Insights;

use App\Models\Organization;
use App\Support\Usage\UsageFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;

class InsightsOverviewTest extends InsightsTestCase
{
    use SubscribesOrganizations;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowStartingAt(array $rows, string $start): array
    {
        foreach ($rows as $row) {
            if ($row['start'] === $start) {
                return $row;
            }
        }

        $this->fail("Não há linha a começar em {$start}.");
    }

    // --- Definições ------------------------------------------------------

    #[Test]
    public function active_users_are_counted_in_the_last_7_and_30_days(): void
    {
        $recent = $this->person('Recente');
        $older = $this->person('Antiga');
        $failureOnly = $this->person('Só Falhas');
        $tooOld = $this->person('Muito Antiga');

        $this->rollup($recent, '2026-10-10', views: 1);
        $this->rollup($older, '2026-09-20', views: 0, actions: 2);
        $this->rollup($failureOnly, '2026-10-11', views: 0, actions: 0, failures: 3);
        $this->rollup($tooOld, '2026-09-05', views: 1);

        $headline = $this->props('admin.usage.overview')['headline'];

        $this->assertSame(['value' => 1, 'observed_days' => 7, 'window_days' => 7], $headline['active_7']);
        $this->assertSame(['value' => 2, 'observed_days' => 30, 'window_days' => 30], $headline['active_30']);
        $this->assertSame(2, $headline['active_period']);
    }

    #[Test]
    public function a_user_in_two_organizations_counts_once(): void
    {
        $teacher = $this->person('Em Duas Escolas');
        $school = Organization::factory()->institutional()->withMember($teacher)->create();

        $this->rollup($teacher, '2026-10-10');
        $this->rollup($teacher, '2026-10-10', UsageFeature::Reports, organization: $school);
        $this->rollup($teacher, '2026-10-11', organization: $school);

        $props = $this->props('admin.usage.overview');

        $this->assertSame(1, $props['headline']['active_7']['value']);
        $this->assertSame(1, $props['headline']['active_period']);
    }

    #[Test]
    public function the_main_segment_leaves_out_test_organizations_and_internal_staff_and_the_test_segment_shows_them(): void
    {
        $real = $this->person('Professora Real');
        $trial = $this->person('Professora de Ensaio');
        $this->markTest($this->organizationOf($trial));
        $internal = $this->platformAdmin(name: 'Pessoal Interno');
        $support = $this->person('Técnica de Suporte');
        $support->forceFill(['is_support_technician' => true])->save();

        foreach ([$real, $trial, $internal, $support] as $person) {
            $this->rollup($person, '2026-10-10');
        }

        $main = $this->props('admin.usage.overview');
        $test = $this->props('admin.usage.overview', ['segment' => 'test']);

        $this->assertSame(1, $main['headline']['active_period']);
        $this->assertSame(3, $test['headline']['active_period']);
        $this->assertSame('main', $main['filters']['segment']);
        $this->assertSame('test', $test['filters']['segment']);
    }

    #[Test]
    public function the_segment_applies_per_row_so_a_person_active_in_both_kinds_of_organization_counts_in_each(): void
    {
        $teacher = $this->person('Dois Mundos');
        $trialSchool = Organization::factory()->institutional()->withMember($teacher)->create();
        $this->markTest($trialSchool);

        $this->rollup($teacher, '2026-10-10');
        $this->rollup($teacher, '2026-10-10', UsageFeature::Reports, organization: $trialSchool);

        $this->assertSame(1, $this->props('admin.usage.overview')['headline']['active_period']);
        $this->assertSame(1, $this->props('admin.usage.overview', ['segment' => 'test'])['headline']['active_period']);
    }

    #[Test]
    public function the_plan_and_organization_filters_narrow_the_scope(): void
    {
        $onBase = $this->person('Na Base');
        $onPro = $this->person('No Pro');
        $this->subscribeOrganizationTo($this->organizationOf($onPro), 'pro');

        $this->rollup($onBase, '2026-10-10');
        $this->rollup($onPro, '2026-10-10');

        $proOrganization = $this->organizationOf($onPro);

        $this->assertSame(2, $this->props('admin.usage.overview')['headline']['active_period']);
        $this->assertSame(1, $this->props('admin.usage.overview', ['plan' => 'pro'])['headline']['active_period']);
        $this->assertSame(1, $this->props('admin.usage.overview', ['plan' => 'base'])['headline']['active_period']);
        $this->assertSame(1, $this->props('admin.usage.overview', ['organization' => $proOrganization->ulid])['headline']['active_period']);
        $this->assertSame(0, $this->props('admin.usage.overview', ['plan' => 'base', 'organization' => $proOrganization->ulid])['headline']['active_period']);
    }

    #[Test]
    public function the_feature_table_separates_users_from_users_with_actions(): void
    {
        $writer = $this->person('Escreve');
        $reader = $this->person('Lê');

        $this->rollup($writer, '2026-10-10', UsageFeature::Lessons, views: 1, actions: 2, items: 5);
        $this->rollup($reader, '2026-10-10', UsageFeature::Lessons, views: 2, failures: 1);
        $this->rollup($reader, '2026-10-11', UsageFeature::Reports, views: 1);

        $features = collect($this->props('admin.usage.overview')['features'])->keyBy('key');

        $this->assertSame(
            ['key' => 'lessons', 'label' => 'Aulas e sumários', 'users' => 2, 'users_with_actions' => 1, 'actions' => 2, 'items' => 5, 'failures' => 1],
            $features['lessons'],
        );
        $this->assertSame(1, $features['reports']['users']);
        $this->assertSame(0, $features['assessment']['users']);
    }

    #[Test]
    public function the_server_returns_counts_and_denominators_never_loose_percentages(): void
    {
        $this->rollup($this->person('Uma'), '2026-10-10');

        $props = $this->props('admin.usage.overview');
        $encoded = json_encode($props);

        $this->assertStringNotContainsString('percent', (string) $encoded);
        foreach (['done', 'of', 'before_collection'] as $key) {
            $this->assertIsInt($props['headline']['first_useful_record'][$key]);
        }
        $this->assertIsInt($props['headline']['new_accounts']);
        $this->assertIsInt($props['headline']['active_7']['observed_days']);
    }

    // --- Semanas, meses, mudança de hora ---------------------------------

    #[Test]
    public function weeks_run_monday_to_sunday_and_the_autumn_clock_change_does_not_move_a_day(): void
    {
        // 2026-10-25 foi o dia em que os relógios recuaram uma hora: domingo.
        $this->travelTo(Carbon::parse('2026-11-02 10:00:00', 'Europe/Lisbon'));
        $teacher = $this->person('Domingo e Segunda');
        $this->rollup($teacher, '2026-10-25', actions: 1, items: 3);
        $this->rollup($teacher, '2026-10-26', actions: 2, items: 4);

        $props = $this->props('admin.usage.overview', ['period' => 'custom', 'from' => '2026-10-19', 'to' => '2026-11-01']);

        $this->assertSame(14, $props['filters']['days']);
        $this->assertSame(['2026-10-19', '2026-10-26'], array_column($props['weeks'], 'start'));
        $this->assertSame(['2026-10-25', '2026-11-01'], array_column($props['weeks'], 'end'));

        $first = $this->rowStartingAt($props['weeks'], '2026-10-19');
        $second = $this->rowStartingAt($props['weeks'], '2026-10-26');
        $this->assertSame([1, 1, 1, 3], [$first['active_users'], $first['active_user_days'], $first['actions'], $first['items']]);
        $this->assertSame([1, 1, 2, 4], [$second['active_users'], $second['active_user_days'], $second['actions'], $second['items']]);
    }

    #[Test]
    public function a_period_crossing_the_spring_clock_change_has_the_right_days(): void
    {
        // 2027-03-28 tem 23 horas em Lisboa; continua a ser UM dia.
        $this->travelTo(Carbon::parse('2027-04-02 10:00:00', 'Europe/Lisbon'));
        $teacher = $this->person('Mudança de Hora');
        $this->rollup($teacher, '2027-03-28', actions: 1);
        $this->rollup($teacher, '2027-03-29', actions: 1);

        $props = $this->props('admin.usage.overview', ['period' => 'custom', 'from' => '2027-03-24', 'to' => '2027-03-31']);

        $this->assertSame(8, $props['filters']['days']);
        $this->assertSame(['2027-03-22', '2027-03-29'], array_column($props['weeks'], 'start'));
        $this->assertSame(1, $this->rowStartingAt($props['weeks'], '2027-03-22')['active_user_days']);
        $this->assertSame(1, $this->rowStartingAt($props['weeks'], '2027-03-29')['active_user_days']);
        $this->assertSame('partial_period', $this->rowStartingAt($props['weeks'], '2027-03-22')['status']);
        $this->assertSame(['2027-03'], array_column($props['months'], 'month'));
    }

    #[Test]
    public function months_are_calendar_months_with_a_portuguese_label(): void
    {
        $teacher = $this->person('Dois Meses');
        $this->rollup($teacher, '2026-09-20');
        $this->rollup($teacher, '2026-10-05');

        $months = collect($this->props('admin.usage.overview')['months'])->keyBy('month');

        $this->assertSame(['2026-09', '2026-10'], $months->keys()->all());
        $this->assertSame('outubro de 2026', $months['2026-10']['label']);
        $this->assertSame(1, $months['2026-09']['active_users']);
        $this->assertSame('in_progress', $months['2026-10']['status']);
        $this->assertSame('partial_period', $months['2026-09']['status']);
    }

    // --- Cobertura -------------------------------------------------------

    #[Test]
    public function weeks_before_the_collection_began_have_no_numbers_and_the_first_day_is_partial(): void
    {
        $this->startUsageCollectionAt('2026-10-06 14:00:00');
        $teacher = $this->person('Depois do Início');
        $this->rollup($teacher, '2026-10-07', actions: 1);

        $props = $this->props('admin.usage.overview');

        $before = $this->rowStartingAt($props['weeks'], '2026-09-07');
        $this->assertSame('no_coverage', $before['status']);
        $this->assertNull($before['active_users']);
        $this->assertNull($before['active_user_days']);
        $this->assertNull($before['actions']);
        $this->assertNull($before['items']);

        $startWeek = $this->rowStartingAt($props['weeks'], '2026-10-05');
        $this->assertSame('partial_coverage', $startWeek['status']);
        $this->assertSame(1, $startWeek['active_users']);

        $current = $this->rowStartingAt($props['weeks'], '2026-10-12');
        $this->assertSame('in_progress', $current['status']);

        $this->assertSame('2026-10-06', $props['coverage']['start_day']);
        $this->assertSame('2026-10-06 14:00', $props['coverage']['collection_started_at']);
        $this->assertSame('partial', $props['coverage']['period_status']);
        $this->assertSame('2026-10-06', $props['coverage']['observed_from']);
        $this->assertSame('2026-10-12', $props['coverage']['observed_to']);
        $this->assertSame(7, $props['headline']['active_30']['observed_days']);
        $this->assertNotEmpty(array_filter($props['coverage']['notes'], fn (string $note): bool => str_contains($note, 'começou a 06/10/2026 às 14:00')));
        $this->assertNotEmpty(array_filter($props['coverage']['notes'], fn (string $note): bool => str_contains($note, 'começa antes da recolha')));
    }

    #[Test]
    public function a_period_wholly_before_the_collection_has_no_numbers_at_all(): void
    {
        $this->startUsageCollectionAt('2026-10-06 14:00:00');

        $props = $this->props('admin.usage.overview', ['period' => 'custom', 'from' => '2026-09-14', 'to' => '2026-09-27']);

        $this->assertNull($props['headline']['active_period']);
        $this->assertSame('none', $props['coverage']['period_status']);
        $this->assertNull($props['coverage']['observed_from']);
        $this->assertSame([], $props['features']);
        foreach ($props['weeks'] as $week) {
            $this->assertSame('no_coverage', $week['status']);
            $this->assertNull($week['active_users']);
        }
        foreach ($props['months'] as $month) {
            $this->assertNull($month['active_users']);
        }
    }

    #[Test]
    public function a_headline_window_with_no_recording_is_null_not_zero(): void
    {
        // A recolha começou há dois dias; a janela de 7 dias só tem 3 dias observados.
        $this->startUsageCollectionAt('2026-10-10 08:00:00');
        $this->rollup($this->person('Recém Chegada'), '2026-10-11');

        $headline = $this->props('admin.usage.overview')['headline'];
        $this->assertSame(['value' => 1, 'observed_days' => 3, 'window_days' => 7], $headline['active_7']);

        // E se a recolha só começar amanhã, não há dias observados: null.
        $this->startUsageCollectionAt('2026-10-13 08:00:00');
        $headline = $this->props('admin.usage.overview')['headline'];
        $this->assertSame(['value' => null, 'observed_days' => 0, 'window_days' => 7], $headline['active_7']);
        $this->assertNull($headline['active_period']);
    }

    #[Test]
    public function the_aggregation_time_is_shown_and_its_absence_is_noted(): void
    {
        $props = $this->props('admin.usage.overview');
        $this->assertSame('2026-10-12 09:45', $props['coverage']['aggregated_at']);

        DB::table('usage_aggregation_state')->delete();
        $props = $this->props('admin.usage.overview');
        $this->assertNull($props['coverage']['aggregated_at']);
        $this->assertNotEmpty(array_filter($props['coverage']['notes'], fn (string $note): bool => str_contains($note, 'agregação diária ainda não correu')));
    }

    // --- Primeiros registos e coortes ------------------------------------

    #[Test]
    public function the_first_useful_record_denominator_only_counts_accounts_created_after_the_collection_began(): void
    {
        $this->startUsageCollectionAt('2026-09-01 00:00:00');

        $this->person('Antes A', '2026-08-20 10:00:00');
        $this->person('Antes B', '2026-08-30 10:00:00');
        $done = $this->person('Depois Feito', '2026-09-10 10:00:00');
        $this->person('Depois Por Fazer', '2026-09-15 10:00:00');
        $this->state($done, ['first_useful_record_on' => '2026-09-12']);

        $props = $this->props('admin.usage.overview', ['period' => 'custom', 'from' => '2026-08-15', 'to' => '2026-10-12']);

        $this->assertSame(4, $props['headline']['new_accounts']);
        $this->assertSame(['done' => 1, 'of' => 2, 'before_collection' => 2], $props['headline']['first_useful_record']);
    }

    #[Test]
    public function new_accounts_only_count_the_period_and_the_scope(): void
    {
        $this->person('Dentro', '2026-10-01 10:00:00');
        $this->person('Fora do Período', '2026-08-01 10:00:00');
        $trial = $this->person('De Ensaio', '2026-10-02 10:00:00');
        $this->markTest($this->organizationOf($trial));

        $this->assertSame(1, $this->props('admin.usage.overview')['headline']['new_accounts']);
        // «Teste e internas»: a conta de ensaio e a do próprio operador (pessoal interno).
        $this->assertSame(2, $this->props('admin.usage.overview', ['segment' => 'test'])['headline']['new_accounts']);
    }

    #[Test]
    public function cohorts_group_by_signup_week_and_mark_weeks_that_have_not_started_or_are_in_progress(): void
    {
        $x = $this->person('Coorte A1', '2026-09-21 10:00:00');
        $y = $this->person('Coorte A2', '2026-09-23 10:00:00');
        $z = $this->person('Coorte B1', '2026-10-05 10:00:00');
        $this->person('Coorte C1', '2026-10-12 08:00:00');

        $this->rollup($x, '2026-09-22');
        $this->rollup($x, '2026-09-29');
        $this->rollup($y, '2026-09-22');
        $this->rollup($y, '2026-10-06');
        $this->rollup($z, '2026-10-06');

        $cohorts = collect($this->props('admin.usage.overview')['cohorts'])->keyBy('week_start');

        $this->assertSame(['2026-09-21', '2026-10-05', '2026-10-12'], $cohorts->keys()->all());
        $this->assertSame(2, $cohorts['2026-09-21']['size']);
        $this->assertSame('2026-09-27', $cohorts['2026-09-21']['week_end']);

        $this->assertSame([
            ['offset' => 0, 'status' => 'complete', 'returned' => 2],
            ['offset' => 1, 'status' => 'complete', 'returned' => 1],
            ['offset' => 2, 'status' => 'complete', 'returned' => 1],
            ['offset' => 3, 'status' => 'in_progress', 'returned' => 0],
            ['offset' => 4, 'status' => 'not_started', 'returned' => null],
        ], $cohorts['2026-09-21']['weeks']);

        $this->assertSame([
            ['offset' => 0, 'status' => 'complete', 'returned' => 1],
            ['offset' => 1, 'status' => 'in_progress', 'returned' => 0],
            ['offset' => 2, 'status' => 'not_started', 'returned' => null],
            ['offset' => 3, 'status' => 'not_started', 'returned' => null],
            ['offset' => 4, 'status' => 'not_started', 'returned' => null],
        ], $cohorts['2026-10-05']['weeks']);

        $this->assertSame('in_progress', $cohorts['2026-10-12']['weeks'][0]['status']);
        $this->assertSame('not_started', $cohorts['2026-10-12']['weeks'][1]['status']);
    }

    #[Test]
    public function accounts_created_before_the_collection_are_not_in_any_cohort(): void
    {
        $this->person('Já Cá Estava', '2026-08-20 10:00:00');

        $this->assertSame([], $this->props('admin.usage.overview', ['period' => 'custom', 'from' => '2026-08-15', 'to' => '2026-10-12'])['cohorts']);
    }

    // --- Filtros ---------------------------------------------------------

    #[Test]
    public function a_period_before_the_retention_limit_is_clamped_with_a_note(): void
    {
        config(['retention.usage_metrics_days' => 20]);

        $props = $this->props('admin.usage.overview', ['period' => '90']);

        $this->assertSame('2026-09-22', $props['filters']['from']);
        $this->assertSame('2026-10-12', $props['filters']['to']);
        $this->assertSame(21, $props['filters']['days']);
        $this->assertTrue($props['filters']['adjusted_to_retention']);
        $this->assertSame('2026-09-22', $props['coverage']['retention_cutoff']);
        $this->assertNotEmpty(array_filter($props['coverage']['notes'], fn (string $note): bool => str_contains($note, 'prazo de conservação')));
    }

    #[Test]
    public function presets_end_today_and_start_n_minus_one_days_before(): void
    {
        $this->assertSame('2026-10-06', $this->props('admin.usage.overview', ['period' => '7'])['filters']['from']);
        $this->assertSame('2026-09-13', $this->props('admin.usage.overview')['filters']['from']);
        $this->assertSame('2026-07-15', $this->props('admin.usage.overview', ['period' => '90'])['filters']['from']);
        $this->assertSame('30', $this->props('admin.usage.overview')['filters']['period']);
    }

    #[Test]
    public function invalid_custom_periods_are_refused(): void
    {
        $overview = route('admin.usage.overview');

        $this->actingAs($this->operator)->get($overview.'?period=custom&from=2026-10-10&to=2026-10-01')->assertSessionHasErrors('from');
        $this->actingAs($this->operator)->get($overview.'?period=custom&from=2026-10-01&to=2026-10-20')->assertSessionHasErrors('to');
        $this->actingAs($this->operator)->get($overview.'?period=custom&from=2025-09-01&to=2026-10-12')->assertSessionHasErrors('to');
        $this->actingAs($this->operator)->get($overview.'?period=custom')->assertSessionHasErrors(['from', 'to']);
        $this->actingAs($this->operator)->get($overview.'?period=45')->assertSessionHasErrors('period');
        $this->actingAs($this->operator)->get($overview.'?segment=tudo')->assertSessionHasErrors('segment');
        $this->actingAs($this->operator)->get($overview.'?plan=inexistente')->assertSessionHasErrors('plan');

        $this->actingAs($this->operator)->get($overview.'?period=custom&from=2025-10-12&to=2026-10-12')->assertOk();
    }

    #[Test]
    public function the_options_list_plans_organizations_and_signals(): void
    {
        $this->markTest($this->organizationOf($this->person('Ensaio')));

        $options = $this->props('admin.usage.overview')['options'];

        $this->assertContains('pro', array_column($options['plans'], 'key'));
        $this->assertNotEmpty(array_filter($options['organizations'], fn (array $organization): bool => $organization['is_test_account']));
        $this->assertSame(
            ['no_class', 'no_first_record', 'went_quiet', 'technical_failures'],
            array_column($options['signal_options'], 'key'),
        );

        $names = array_column($options['organizations'], 'name');
        $sorted = $names;
        usort($sorted, 'strcasecmp');
        $this->assertSame($sorted, $names);
    }

    #[Test]
    public function the_definitions_say_that_last_activity_is_not_the_last_login(): void
    {
        $definitions = collect($this->props('admin.usage.overview')['definitions'])->keyBy('term');

        $this->assertStringContainsString('Não é o último login', $definitions['Última atividade observada']['text']);
        foreach (['Utilizador ativo', 'Dia ativo', 'Ação concluída', 'Elementos afetados', 'Falha técnica', 'Segmentos', 'Sinais'] as $term) {
            $this->assertArrayHasKey($term, $definitions->all());
        }
    }

    #[Test]
    public function a_cohort_week_without_collection_is_no_coverage_and_never_zero(): void
    {
        // Recolha de 01/09 a 20/09 e de novo desde 05/10: 21/09–04/10 não tem recolha.
        $this->startUsageCollectionAt('2026-09-01 00:00:00', '2026-09-21 00:00:00');
        DB::table('usage_collection_periods')->insert(['started_at' => '2026-10-05 00:00:00', 'ended_at' => null]);

        $person = $this->person('Coorte Com Buraco', '2026-09-08 10:00:00');
        $this->rollup($person, '2026-09-09');

        $weeks = collect($this->props('admin.usage.overview', ['period' => '90'])['cohorts'])
            ->keyBy('week_start')['2026-09-07']['weeks'];

        $this->assertSame([
            ['offset' => 0, 'status' => 'complete', 'returned' => 1],
            ['offset' => 1, 'status' => 'complete', 'returned' => 0],
            ['offset' => 2, 'status' => 'no_coverage', 'returned' => null],
            ['offset' => 3, 'status' => 'no_coverage', 'returned' => null],
            ['offset' => 4, 'status' => 'complete', 'returned' => 0],
        ], $weeks);
    }
}
