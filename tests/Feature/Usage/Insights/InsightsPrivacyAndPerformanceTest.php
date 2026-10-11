<?php

namespace Tests\Feature\Usage\Insights;

use App\Models\UsageUserState;
use App\Services\Usage\AggregateUsage;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFeature;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class InsightsPrivacyAndPerformanceTest extends InsightsTestCase
{
    private const FORBIDDEN_TABLES = [
        'usage_events', 'lessons', 'lesson_summaries', 'class_notebook_entries', 'interventions',
        'evidence_records', 'student_item_scores', 'class_teachers',
    ];

    #[Test]
    public function opening_the_four_pages_never_reads_raw_events_or_pedagogical_tables(): void
    {
        $user = $this->person('Pessoa Ativa', '2026-08-01 10:00:00');
        $this->state($user, ['class_available_since' => '2026-08-02 10:00:00']);
        $this->rollup($user, '2026-10-08', UsageFeature::Lessons, actions: 1, items: 2, failures: 1);
        $state = UsageUserState::query()->where('user_id', $user->id)->firstOrFail();

        $queries = $this->queriesDuring(function () use ($state): void {
            $this->actingAs($this->operator);
            $this->get(route('admin.usage.overview'))->assertOk();
            $this->get(route('admin.usage.users.index'))->assertOk();
            $this->get(route('admin.usage.users.show', $state))->assertOk();
            $this->get(route('admin.usage.ranking'))->assertOk();
        });

        $this->assertNotEmpty($queries);

        foreach ($queries as $query) {
            foreach (self::FORBIDDEN_TABLES as $table) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(?<![a-z_])'.$table.'(?![a-z_])/',
                    $query,
                    "O painel não pode ler {$table}: {$query}",
                );
            }
        }
    }

    #[Test]
    public function rollup_queries_filter_dates_by_range_and_never_with_wheredate(): void
    {
        $this->rollup($this->person('Pessoa'), '2026-10-08');

        $queries = $this->queriesDuring(function (): void {
            $this->actingAs($this->operator);
            $this->get(route('admin.usage.overview'))->assertOk();
            $this->get(route('admin.usage.ranking'))->assertOk();
        });

        $rollupQueries = array_filter($queries, fn (string $query): bool => str_contains($query, 'usage_daily_rollups'));

        $this->assertNotEmpty($rollupQueries);
        foreach ($rollupQueries as $query) {
            $this->assertStringNotContainsString('strftime', $query, 'whereDate() em SQLite');
            $this->assertStringNotContainsString('date(', $query, 'whereDate() mata o índice em MySQL');
        }
    }

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_number_of_people(): void
    {
        $count = fn (): int => count($this->queriesDuring(function (): void {
            $this->actingAs($this->operator);
            $this->get(route('admin.usage.users.index'))->assertOk();
            $this->get(route('admin.usage.ranking'))->assertOk();
        }));

        foreach (range(1, 3) as $number) {
            $person = $this->person("Pessoa {$number}", '2026-08-01 10:00:00');
            $this->state($person);
            $this->rollup($person, '2026-10-08');
        }
        // A primeira passagem aquece caches (planos, módulos); só a seguinte conta.
        $count();
        $few = $count();

        foreach (range(4, 20) as $number) {
            $person = $this->person("Pessoa {$number}", '2026-08-01 10:00:00');
            $this->state($person);
            $this->rollup($person, '2026-10-08');
        }
        $many = $count();

        $this->assertSame($few, $many, 'Há consultas por pessoa (N+1).');
    }

    #[Test]
    public function the_panel_works_end_to_end_from_events_through_the_aggregation(): void
    {
        $teacher = $this->person('Professora Real', '2026-09-20 10:00:00');
        $organization = $this->organizationOf($teacher);

        $this->seedUsageEvent($teacher->id, $organization->id, '2026-10-08', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($teacher->id, $organization->id, '2026-10-08', UsageEventType::LessonSummarySaved, ['item_count' => 1]);
        $this->seedUsageEvent($teacher->id, $organization->id, '2026-10-09', UsageEventType::OperationFailed, ['feature' => 'reports']);

        // A primeira agregação de sempre: sem estado, recomeça do primeiro evento.
        DB::table('usage_aggregation_state')->delete();
        app(AggregateUsage::class)->run(now()->toImmutable());

        $overview = $this->props('admin.usage.overview');
        $this->assertSame(1, $overview['headline']['active_period']);
        $week = collect($overview['weeks'])->firstWhere('start', '2026-10-05');
        $this->assertSame([1, 1, 1, 1], [$week['active_users'], $week['active_user_days'], $week['actions'], $week['items']]);

        $users = $this->props('admin.usage.users.index')['users']['data'];
        $this->assertSame(['Professora Real'], array_column($users, 'name'));
        $this->assertSame('2026-10-08', $users[0]['last_activity_on']);
        $this->assertSame(1, $users[0]['active_days']);

        $detail = $this->props('admin.usage.users.show', parameters: ['usageUser' => UsageUserState::query()->where('user_id', $teacher->id)->firstOrFail()]);
        $this->assertSame(1, $detail['summary']['failures']);
        $this->assertSame(1, $detail['summary']['actions']);
    }
}
