<?php

namespace Tests\Feature\Usage\Insights;

use App\Models\Organization;
use App\Models\UsageUserState;
use App\Models\User;
use App\Support\Usage\UsageFeature;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;

class UserDirectoryTest extends InsightsTestCase
{
    use SubscribesOrganizations;

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(array $query = []): array
    {
        return $this->props('admin.usage.users.index', $query)['users']['data'];
    }

    /**
     * @return list<string>
     */
    private function names(array $query = []): array
    {
        return array_column($this->rows($query), 'name');
    }

    private function stateOf(User $user): UsageUserState
    {
        return UsageUserState::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function listed(string $name, string $createdAt = '2026-06-01 10:00:00'): User
    {
        $user = $this->person($name, $createdAt);
        $this->state($user, ['class_available_since' => '2026-06-02 10:00:00', 'first_useful_record_on' => '2026-06-05']);

        return $user;
    }

    #[Test]
    public function only_people_in_the_scope_with_an_aggregated_state_are_listed(): void
    {
        $this->listed('Com Estado');
        $this->person('Sem Estado');
        $trial = $this->listed('Conta de Ensaio');
        $this->markTest($this->organizationOf($trial));

        $this->assertSame(['Com Estado'], $this->names());
        $this->assertSame(['Conta de Ensaio'], $this->names(['segment' => 'test']));
    }

    #[Test]
    public function a_row_carries_exactly_the_documented_fields_and_no_free_text(): void
    {
        $user = $this->listed('Linha Completa');
        $this->subscribeOrganizationTo($this->organizationOf($user), 'pro');
        $this->rollup($user, '2026-10-08', UsageFeature::Lessons, views: 1, actions: 2, items: 7);
        $this->rollup($user, '2026-09-20', UsageFeature::Reports, views: 1);

        $row = $this->rows()[0];

        $this->assertEqualsCanonicalizing([
            'ulid', 'name', 'email', 'joined_on', 'plans', 'organizations', 'flags', 'last_activity_on', 'active_days',
            'actions', 'items', 'features', 'signals', 'status', 'observation_days', 'period_days',
        ], array_keys($row));
        $this->assertSame('linha-completa@exemplo.pt', $row['email']);
        $this->assertSame('2026-06-01', $row['joined_on']);
        $this->assertSame(['Pro'], $row['plans']);
        $this->assertSame(1, $row['organizations']);
        $this->assertSame(['test' => false, 'internal' => false, 'unclassified' => false], $row['flags']);
        $this->assertSame('2026-10-08', $row['last_activity_on']);
        $this->assertSame([2, 2, 7], [$row['active_days'], $row['actions'], $row['items']]);
        $this->assertSame([['key' => 'lessons', 'label' => 'Aulas e sumários'], ['key' => 'reports', 'label' => 'Relatórios']], $row['features']);
        $this->assertSame(30, $row['observation_days']);
        $this->assertSame(30, $row['period_days']);
        $this->assertSame(26, strlen($row['ulid']));
    }

    #[Test]
    public function last_activity_looks_beyond_the_period_but_not_at_other_scopes(): void
    {
        $old = $this->listed('Atividade Antiga');
        $this->rollup($old, '2026-08-20');
        $trialOnly = $this->listed('Só no Ensaio');
        $trialSchool = Organization::factory()->institutional()->withMember($trialOnly)->create();
        $this->markTest($trialSchool);
        $this->rollup($trialOnly, '2026-10-09', organization: $trialSchool);

        $rows = collect($this->rows())->keyBy('name');

        $this->assertSame('2026-08-20', $rows['Atividade Antiga']['last_activity_on']);
        $this->assertSame(0, $rows['Atividade Antiga']['active_days']);
        // A atividade na organização de teste não entra no segmento principal.
        $this->assertNull($rows['Só no Ensaio']['last_activity_on']);
    }

    #[Test]
    public function search_matches_name_or_email_and_treats_wildcards_literally(): void
    {
        $this->listed('Maria Fernandes');
        $this->listed('João Silva');

        $this->assertSame(['Maria Fernandes'], $this->names(['q' => 'fernand']));
        $this->assertSame(['João Silva'], $this->names(['q' => 'joao-silva@']));
        $this->assertSame(['João Silva'], $this->names(['q' => 'JOÃO']));
        $this->assertSame([], $this->names(['q' => '%']));
        $this->assertSame([], $this->names(['q' => 'ninguém']));
    }

    #[Test]
    public function the_signal_filter_keeps_only_people_with_that_signal(): void
    {
        $stuck = $this->person('Sem Turma', '2026-09-20 10:00:00');
        $this->state($stuck);
        $this->listed('Tudo Bem');

        $this->assertSame(['Sem Turma'], $this->names(['signal' => 'no_class']));
        $this->assertSame([], $this->names(['signal' => 'technical_failures']));
        $this->assertSame(['Sem Turma', 'Tudo Bem'], $this->names(['sort' => 'name']));

        $this->actingAs($this->operator)->get(route('admin.usage.users.index', ['signal' => 'inventado']))->assertSessionHasErrors('signal');
    }

    #[Test]
    public function the_default_order_is_latest_activity_first_with_nobody_active_last(): void
    {
        $recent = $this->listed('Recente');
        $older = $this->listed('Antes');
        $this->listed('Nunca');
        $this->listed('Aaa Sem Atividade');
        $this->rollup($recent, '2026-10-10');
        $this->rollup($older, '2026-10-02');

        $this->assertSame(['Recente', 'Antes', 'Aaa Sem Atividade', 'Nunca'], $this->names());
    }

    #[Test]
    public function the_list_can_be_sorted_by_name_active_days_and_actions(): void
    {
        $few = $this->listed('Bruno');
        $many = $this->listed('Carla');
        $busy = $this->listed('Ana');
        $this->rollup($few, '2026-10-06', actions: 9);
        $this->rollup($many, '2026-10-06', actions: 1);
        $this->rollup($many, '2026-10-07', actions: 1);
        $this->rollup($busy, '2026-10-06', actions: 4);

        $this->assertSame(['Ana', 'Bruno', 'Carla'], $this->names(['sort' => 'name']));
        $this->assertSame(['Carla', 'Ana', 'Bruno'], $this->names(['sort' => 'active_days']));
        $this->assertSame(['Bruno', 'Ana', 'Carla'], $this->names(['sort' => 'actions']));

        $this->actingAs($this->operator)->get(route('admin.usage.users.index', ['sort' => 'ranking']))->assertSessionHasErrors('sort');
    }

    #[Test]
    public function the_status_is_descriptive_and_never_a_verdict(): void
    {
        $closing = $this->listed('Em Encerramento');
        DB::table('users')->where('id', $closing->id)->update(['closure_requested_at' => '2026-10-01 10:00:00']);
        $deactivated = $this->listed('Desativada');
        DB::table('users')->where('id', $deactivated->id)->update(['deactivated_at' => '2026-10-01 10:00:00']);
        $this->listed('Nova no Período', '2026-10-02 10:00:00');
        $this->listed('Parada');
        $active = $this->listed('Ativa');
        $this->rollup($active, '2026-10-08');

        $statuses = collect($this->rows())->pluck('status', 'name')->all();

        // Quem pediu o encerramento já não tem métricas (apagadas no pedido) e
        // sai da lista, em vez de aparecer sem atividade.
        $this->assertArrayNotHasKey('Em Encerramento', $statuses);
        $this->assertSame('Conta desativada', $statuses['Desativada']);
        $this->assertSame('Conta criada durante o período', $statuses['Nova no Período']);
        $this->assertSame('Sem atividade observada no período', $statuses['Parada']);
        $this->assertNull($statuses['Ativa']);

        foreach ($statuses as $status) {
            $this->assertDoesNotMatchRegularExpression('/inativ|abandon|desist|mau /iu', (string) $status);
        }
    }

    #[Test]
    public function a_recent_account_shows_how_many_days_were_observed(): void
    {
        $this->listed('Antiga');
        $this->listed('Recente', '2026-10-06 09:00:00');

        $rows = collect($this->rows())->keyBy('name');

        $this->assertSame(30, $rows['Antiga']['observation_days']);
        $this->assertSame(7, $rows['Recente']['observation_days']);
        $this->assertSame(30, $rows['Recente']['period_days']);
    }

    #[Test]
    public function internal_and_test_flags_show_in_their_segments(): void
    {
        $trial = $this->listed('Ensaio');
        $this->markTest($this->organizationOf($trial));
        $staff = $this->listed('Pessoal');
        $staff->forceFill(['is_support_technician' => true])->save();

        $rows = collect($this->rows(['segment' => 'test']))->keyBy('name')
            ->merge(collect($this->rows(['segment' => 'internal']))->keyBy('name'));

        $this->assertSame(['test' => true, 'internal' => false, 'unclassified' => false], $rows['Ensaio']['flags']);
        $this->assertSame(['test' => false, 'internal' => true, 'unclassified' => false], $rows['Pessoal']['flags']);
    }

    #[Test]
    public function the_list_is_paginated_in_pages_of_twenty_five_and_keeps_the_filters_in_the_links(): void
    {
        foreach (range(1, 27) as $number) {
            $this->listed(sprintf('Pessoa %02d', $number));
        }

        $first = $this->props('admin.usage.users.index', ['sort' => 'name', 'period' => '7'])['users'];
        $second = $this->props('admin.usage.users.index', ['sort' => 'name', 'period' => '7', 'page' => 2])['users'];

        $this->assertSame(27, $first['total']);
        $this->assertCount(25, $first['data']);
        $this->assertCount(2, $second['data']);
        $this->assertSame('Pessoa 26', $second['data'][0]['name']);
        $this->assertSame(2, $second['current_page']);
        $this->assertStringContainsString('period=7', (string) $first['next_page_url']);
        $this->assertStringContainsString('sort=name', (string) $first['next_page_url']);
    }

    // --- Detalhe ---------------------------------------------------------

    #[Test]
    public function the_detail_shows_the_header_first_steps_summary_weeks_and_features(): void
    {
        $user = $this->person('Pessoa Detalhada', '2026-06-01 10:00:00');
        $state = $this->state($user, [
            'class_available_since' => '2026-06-02 10:00:00',
            'first_class_created_on' => '2026-09-03',
            'first_useful_record_on' => '2026-09-10',
        ]);
        $this->subscribeOrganizationTo($this->organizationOf($user), 'pro');
        $this->rollup($user, '2026-10-05', UsageFeature::Lessons, views: 1, actions: 2, items: 6);
        $this->rollup($user, '2026-10-06', UsageFeature::Lessons, views: 1);
        $this->rollup($user, '2026-10-06', UsageFeature::Reports, views: 1, failures: 1);
        $this->rollup($user, '2026-10-12', UsageFeature::Lessons, views: 1);

        $props = $this->props('admin.usage.users.show', parameters: ['usageUser' => $state]);

        $this->assertSame($state->ulid, $props['user']['ulid']);
        $this->assertSame('Pessoa Detalhada', $props['user']['name']);
        $this->assertSame('2026-06-01', $props['user']['joined_on']);
        $this->assertSame([['name' => $this->organizationOf($user)->name, 'plan' => 'Pro', 'classification' => 'real']], $props['user']['organizations']);
        $this->assertSame(['test' => false, 'internal' => false, 'unclassified' => false], $props['user']['flags']);

        $steps = collect($props['first_steps'])->keyBy('key');
        $this->assertSame('2026-09-03', $steps['first_class_created']['observed_on']);
        $this->assertNull($steps['first_students_added']['observed_on']);
        $this->assertSame('2026-09-10', $steps['first_useful_record']['observed_on']);
        $this->assertSame('Conta anterior à recolha — pode ter acontecido antes.', $steps['first_useful_record']['note']);

        $this->assertSame('2026-06-02', $props['class_available_since']['date']);
        $this->assertSame('Turma disponível desde — configuração, não prova de utilização', $props['class_available_since']['label']);

        $this->assertSame([
            'active_days' => 3, 'active_weeks' => 2, 'actions' => 2, 'items' => 6, 'failures' => 1,
            'last_activity_on' => '2026-10-12', 'observation_days' => 30, 'period_days' => 30,
        ], $props['summary']);

        $weeks = collect($props['weeks'])->keyBy('start');
        $this->assertSame(2, $weeks['2026-10-05']['active_days']);
        $this->assertSame([2, 6, 1], [$weeks['2026-10-05']['actions'], $weeks['2026-10-05']['items'], $weeks['2026-10-05']['failures']]);
        $this->assertSame(['lessons', 'reports'], array_column($weeks['2026-10-05']['features'], 'key'));
        $this->assertSame('in_progress', $weeks['2026-10-12']['status']);
        $this->assertSame(0, $weeks['2026-09-28']['active_days']);

        $features = collect($props['features'])->keyBy('key');
        $this->assertSame(['lessons', 'reports'], $features->keys()->all());
        $this->assertSame(
            ['key' => 'lessons', 'label' => 'Aulas e sumários', 'active_days' => 3, 'view_days' => 3, 'actions' => 2, 'items' => 6, 'items_created' => 0, 'items_changed' => 0, 'items_unchanged' => 0, 'failures' => 0],
            $features['lessons'],
        );
        $this->assertSame(1, $features['reports']['failures']);
    }

    #[Test]
    public function weeks_before_the_collection_have_null_numbers_in_the_detail(): void
    {
        $this->startUsageCollectionAt('2026-10-06 14:00:00');
        $user = $this->listed('Pessoa Recente', '2026-10-01 10:00:00');
        $state = $this->stateOf($user);

        $weeks = collect($this->props('admin.usage.users.show', parameters: ['usageUser' => $state])['weeks'])->keyBy('start');

        $this->assertSame('no_coverage', $weeks['2026-09-14']['status']);
        $this->assertNull($weeks['2026-09-14']['active_days']);
        $this->assertNull($weeks['2026-09-14']['failures']);
        $this->assertSame([], $weeks['2026-09-14']['features']);
        $this->assertSame(0, $weeks['2026-10-05']['active_days']);
    }

    #[Test]
    public function the_detail_lists_the_signals_and_what_could_not_be_evaluated(): void
    {
        $this->startUsageCollectionAt('2026-10-01 14:00:00');
        $stuck = $this->person('Sem Turma', '2026-09-20 10:00:00');
        $stuckState = $this->state($stuck);
        $unknown = $this->person('Por Avaliar', '2026-09-10 10:00:00');
        $unknownState = $this->state($unknown, ['class_available_since' => '2026-09-28 10:00:00']);

        $stuckProps = $this->props('admin.usage.users.show', parameters: ['usageUser' => $stuckState]);
        $unknownProps = $this->props('admin.usage.users.show', parameters: ['usageUser' => $unknownState]);

        $this->assertSame(['no_class'], array_column($stuckProps['signals'], 'key'));
        $this->assertSame([], $unknownProps['signals']);
        // Sem cobertura desde que a turma ficou disponível, nem sobre as quatro semanas de referência.
        $this->assertSame(['no_first_record', 'went_quiet'], array_column($unknownProps['not_evaluated'], 'key'));
        $this->assertSame('Turma sem primeiro registo útil', $unknownProps['not_evaluated'][0]['label']);
    }

    #[Test]
    public function an_unknown_ulid_and_an_anonymised_person_are_a_404(): void
    {
        $this->visit('admin.usage.users.show', parameters: ['usageUser' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertNotFound();

        $user = $this->listed('A Anonimizar');
        $state = $this->stateOf($user);
        DB::table('users')->where('id', $user->id)->update(['anonymized_at' => '2026-10-10 10:00:00']);

        $this->visit('admin.usage.users.show', parameters: ['usageUser' => $state])->assertNotFound();
        $this->assertSame([], $this->names());
    }

    #[Test]
    public function the_detail_has_no_content_and_no_event_list(): void
    {
        $user = $this->listed('Sem Conteúdos');
        $state = $this->stateOf($user);
        $this->rollup($user, '2026-10-08', actions: 1);

        $props = $this->props('admin.usage.users.show', parameters: ['usageUser' => $state]);

        $this->assertEqualsCanonicalizing(['ulid', 'name', 'email', 'joined_on', 'status', 'organizations', 'flags'], array_keys($props['user']));
        $this->assertEqualsCanonicalizing(
            ['active_days', 'active_weeks', 'actions', 'items', 'failures', 'last_activity_on', 'observation_days', 'period_days'],
            array_keys($props['summary']),
        );

        $keys = [];
        array_walk_recursive($props, function (mixed $value, mixed $key) use (&$keys): void {
            $keys[(string) $key] = true;
        });
        foreach (['events', 'event', 'content', 'body', 'text_body', 'comment', 'query', 'url', 'ip', 'user_agent', 'dedupe_key'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $keys);
        }
    }

    #[Test]
    public function without_any_collection_in_the_period_the_status_says_so_instead_of_no_activity(): void
    {
        $user = $this->person('Sem Recolha', '2026-01-10 10:00:00');
        $this->state($user);

        $rows = $this->props('admin.usage.users.index', ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-20'])['users']['data'];
        $row = collect($rows)->firstWhere('name', 'Sem Recolha');

        $this->assertSame('Sem recolha no período', $row['status']);
    }
}
