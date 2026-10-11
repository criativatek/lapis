<?php

namespace Tests\Feature\Usage\Insights;

use App\Models\Organization;
use App\Models\UsageUserState;
use App\Models\User;
use App\Support\Usage\UsageFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * A base dos testes do painel de utilização.
 *
 * O relógio fica fixo ANTES de criar dados (segunda-feira 2026-10-12, 10h de
 * Lisboa) e a recolha começa a 2026-09-01. Os agregados inserem-se
 * diretamente: o que se testa aqui é a LEITURA, e a agregação tem os seus
 * próprios testes. Uma pessoa só tem estado agregado (`usage_user_states`) —
 * e por isso entra na lista — se o teste o pedir com `state()`.
 */
abstract class InsightsTestCase extends TestCase
{
    use RefreshDatabase, SetsUsageCoverage;

    protected const TODAY = '2026-10-12';

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 10:00:00', 'Europe/Lisbon'));
        $this->startUsageCollectionAt('2026-09-01 00:00:00');

        DB::table('usage_aggregation_state')->updateOrInsert(['id' => 1], [
            'aggregated_through' => self::TODAY,
            'aggregated_at' => self::TODAY.' 09:45:00',
        ]);

        $this->operator = $this->platformAdmin(usageInsights: true);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    protected function platformAdmin(bool $usageInsights = false, string $name = 'Operadora'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['is_platform_admin' => true, 'can_view_usage_insights' => $usageInsights])->save();

        return $user;
    }

    /**
     * Um professor com a sua organização pessoal, criado em `$createdAt`.
     */
    protected function person(string $name, string $createdAt = '2026-08-01 09:00:00'): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => str($name)->slug().'@exemplo.pt']);
        DB::table('users')->where('id', $user->id)->update(['created_at' => $createdAt]);

        return $user->refresh();
    }

    protected function organizationOf(User $user): Organization
    {
        return $user->personalOrganization()->refresh();
    }

    protected function markTest(Organization $organization, bool $isTest = true): void
    {
        $organization->forceFill(['is_test_account' => $isTest])->save();
    }

    /**
     * Uma linha do agregado diário.
     */
    protected function rollup(
        User $user,
        string $day,
        UsageFeature|string $feature = UsageFeature::Lessons,
        int $views = 1,
        int $actions = 0,
        int $items = 0,
        int $failures = 0,
        ?Organization $organization = null,
    ): void {
        DB::table('usage_daily_rollups')->insert([
            'day' => $day,
            'user_id' => $user->id,
            'organization_id' => ($organization ?? $this->organizationOf($user))->id,
            'feature' => $feature instanceof UsageFeature ? $feature->value : $feature,
            'views' => $views,
            'actions' => $actions,
            'useful_actions' => 0,
            'items' => $items,
            'failures' => $failures,
        ]);
    }

    /**
     * O estado agregado de uma pessoa (é ele que a põe na lista).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function state(User $user, array $attributes = []): UsageUserState
    {
        return UsageUserState::query()->create(array_merge([
            'user_id' => $user->id,
            'computed_at' => self::TODAY.' 09:45:00',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function visit(string $routeName, array $query = [], ?User $as = null, array $parameters = []): TestResponse
    {
        return $this->actingAs($as ?? $this->operator)->get(route($routeName, $parameters + $query));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function props(string $routeName, array $query = [], array $parameters = []): array
    {
        $captured = [];

        $this->visit($routeName, $query, null, $parameters)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$captured): void {
                $captured = $page->toArray()['props'];
            });

        return $captured;
    }

    /**
     * Quantas consultas tocam em cada tabela, durante `$callback`.
     *
     * @return list<string> as consultas SQL, em minúsculas
     */
    protected function queriesDuring(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = array_map(fn (array $entry): string => strtolower($entry['query']), DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }
}
