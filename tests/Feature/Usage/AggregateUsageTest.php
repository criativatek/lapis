<?php

namespace Tests\Feature\Usage;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\UsageDailyRollup;
use App\Models\UsageUserState;
use App\Models\User;
use App\Services\Usage\AggregateUsage;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Usage\UsageCoverage;
use App\Support\Usage\UsageEventType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

class AggregateUsageTest extends TestCase
{
    use RefreshDatabase, SetsUsageCoverage;

    private CarbonImmutable $now;

    private User $teacher;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->now = CarbonImmutable::parse('2026-10-12 10:00:00', 'Europe/Lisbon');
        $this->startUsageCollectionAt('2026-10-01 00:00:00');

        $this->teacher = User::factory()->create();
        $this->colleague = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function organizationOf(User $user): int
    {
        return $user->personalOrganization()->id;
    }

    private function aggregate(): array
    {
        return app(AggregateUsage::class)->run($this->now);
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function rollups(): array
    {
        return UsageDailyRollup::query()->orderBy('day')->orderBy('user_id')->orderBy('feature')->get()
            ->mapWithKeys(fn (UsageDailyRollup $rollup): array => [
                $rollup->day->toDateString().'|'.$rollup->user_id.'|'.$rollup->organization_id.'|'.$rollup->feature->value => [
                    'views' => $rollup->views, 'actions' => $rollup->actions, 'useful' => $rollup->useful_actions,
                    'items' => $rollup->items, 'failures' => $rollup->failures,
                ],
            ])->all();
    }

    private function seedYesterday(): void
    {
        $organization = $this->organizationOf($this->teacher);
        $id = $this->teacher->id;

        $this->seedUsageEvent($id, $organization, '2026-10-11', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($id, $organization, '2026-10-11', UsageEventType::LessonSummarySaved, ['item_count' => 1]);
        $this->seedUsageEvent($id, $organization, '2026-10-11', UsageEventType::LessonTaught, ['item_count' => 3]);
        $this->seedUsageEvent($id, $organization, '2026-10-11', UsageEventType::OperationFailed, ['feature' => 'lessons']);
        $this->seedUsageEvent($id, $organization, '2026-10-11', UsageEventType::OperationFailed, ['feature' => 'lessons']);
        $this->seedUsageEvent($id, $organization, '2026-10-11', UsageEventType::ScoresRecorded, ['item_count' => 5]);
        $this->seedUsageEvent($this->colleague->id, $this->organizationOf($this->colleague), '2026-10-11', UsageEventType::FeatureViewed, ['feature' => 'records']);
    }

    #[Test]
    public function rollups_count_views_actions_useful_actions_items_and_failures_per_user_organization_feature_and_day(): void
    {
        $this->seedYesterday();

        $this->aggregate();

        $organization = $this->organizationOf($this->teacher);
        $rollups = $this->rollups();

        $this->assertSame(
            ['views' => 1, 'actions' => 2, 'useful' => 1, 'items' => 4, 'failures' => 2],
            $rollups["2026-10-11|{$this->teacher->id}|{$organization}|lessons"],
        );
        $this->assertSame(
            ['views' => 0, 'actions' => 1, 'useful' => 1, 'items' => 5, 'failures' => 0],
            $rollups["2026-10-11|{$this->teacher->id}|{$organization}|assessment"],
        );
        $this->assertSame(
            ['views' => 1, 'actions' => 0, 'useful' => 0, 'items' => 0, 'failures' => 0],
            $rollups["2026-10-11|{$this->colleague->id}|{$this->organizationOf($this->colleague)}|records"],
        );
        $this->assertCount(3, $rollups);
    }

    #[Test]
    public function running_twice_gives_the_same_result(): void
    {
        $this->seedYesterday();

        $this->aggregate();
        $first = $this->rollups();
        $this->aggregate();

        $this->assertSame($first, $this->rollups());
    }

    #[Test]
    public function yesterday_is_recomputed_when_a_late_event_arrives(): void
    {
        $this->seedYesterday();
        $this->aggregate();

        $this->seedUsageEvent($this->colleague->id, $this->organizationOf($this->colleague), '2026-10-11', UsageEventType::NotebookEntryCreated, ['item_count' => 1]);
        $this->aggregate();

        $key = "2026-10-11|{$this->colleague->id}|{$this->organizationOf($this->colleague)}|class_notebook";
        $this->assertSame(1, $this->rollups()[$key]['actions']);
    }

    #[Test]
    public function the_run_records_when_it_aggregated(): void
    {
        $this->aggregate();

        $state = DB::table('usage_aggregation_state')->where('id', 1)->first();
        $this->assertSame('2026-10-12', substr($state->aggregated_through, 0, 10));
        $this->assertSame('2026-10-12 10:00:00', $state->aggregated_at);
    }

    #[Test]
    public function the_command_runs(): void
    {
        $this->seedYesterday();

        $this->artisan('usage:aggregate')->assertSuccessful();

        $this->assertGreaterThan(0, UsageDailyRollup::query()->count());
    }

    #[Test]
    public function class_available_since_is_the_earliest_class_teacher_row_and_is_configuration_not_use(): void
    {
        $this->makeClass($this->teacher, '2026-10-05 09:00:00', 'owner');
        $this->makeClass($this->teacher, '2026-10-03 08:30:00', 'co_teacher');

        $this->aggregate();

        $state = UsageUserState::query()->where('user_id', $this->teacher->id)->sole();
        $this->assertSame('2026-10-03 08:30:00', $state->class_available_since->format('Y-m-d H:i:s'));
        $this->assertNull($state->first_class_created_on);
        $this->assertNotNull($state->ulid);
        $this->assertNull(UsageUserState::query()->where('user_id', $this->colleague->id)->sole()->class_available_since);
    }

    #[Test]
    public function milestones_are_the_first_observation_and_stay_even_if_the_events_are_deleted(): void
    {
        $organization = $this->organizationOf($this->teacher);
        $this->seedUsageEvent($this->teacher->id, $organization, '2026-10-08', UsageEventType::ClassCreated);
        $this->seedUsageEvent($this->teacher->id, $organization, '2026-10-06', UsageEventType::ClassCreated);
        $this->seedUsageEvent($this->teacher->id, $organization, '2026-10-09', UsageEventType::StudentsImported);
        $this->seedUsageEvent($this->teacher->id, $organization, '2026-10-10', UsageEventType::LessonSummarySaved);
        $this->seedUsageEvent($this->teacher->id, $organization, '2026-10-07', UsageEventType::LessonTaught);

        $this->aggregate();

        $state = UsageUserState::query()->where('user_id', $this->teacher->id)->sole();
        $ulid = $state->ulid;
        $this->assertSame('2026-10-06', $state->first_class_created_on->toDateString());
        $this->assertSame('2026-10-09', $state->first_students_added_on->toDateString());
        // «Aula lecionada» não é registo útil.
        $this->assertSame('2026-10-10', $state->first_useful_record_on->toDateString());

        DB::table('usage_events')->delete();
        $this->aggregate();

        $state = UsageUserState::query()->where('user_id', $this->teacher->id)->sole();
        $this->assertSame($ulid, $state->ulid);
        $this->assertSame('2026-10-06', $state->first_class_created_on->toDateString());
        $this->assertSame('2026-10-10', $state->first_useful_record_on->toDateString());
    }

    #[Test]
    public function anonymised_and_opted_out_users_get_no_state_and_lose_an_existing_one(): void
    {
        $this->aggregate();
        $this->assertSame(2, UsageUserState::query()->count());

        $this->colleague->forceFill(['usage_opted_out_at' => now()])->save();
        $this->aggregate();

        $this->assertSame([$this->teacher->id], UsageUserState::query()->pluck('user_id')->all());
    }

    #[Test]
    public function coverage_answers_per_day_and_is_never_a_zero(): void
    {
        $this->startUsageCollectionAt('2026-10-05 15:00:00', '2026-10-08 12:00:00');
        $coverage = new UsageCoverage;

        $this->assertSame('2026-10-05', $coverage->startDay()->toDateString());
        $this->assertFalse($coverage->isDayCovered(CarbonImmutable::parse('2026-10-04', 'Europe/Lisbon')));
        $this->assertTrue($coverage->isDayCovered(CarbonImmutable::parse('2026-10-05', 'Europe/Lisbon')));
        $this->assertFalse($coverage->isDayFullyCovered(CarbonImmutable::parse('2026-10-05', 'Europe/Lisbon')));
        $this->assertTrue($coverage->isDayFullyCovered(CarbonImmutable::parse('2026-10-06', 'Europe/Lisbon')));
        $this->assertTrue($coverage->isDayCovered(CarbonImmutable::parse('2026-10-08', 'Europe/Lisbon')));
        $this->assertFalse($coverage->isDayFullyCovered(CarbonImmutable::parse('2026-10-08', 'Europe/Lisbon')));
        $this->assertFalse($coverage->isDayCovered(CarbonImmutable::parse('2026-10-09', 'Europe/Lisbon')));

        $days = $coverage->coveredDaysIn(CarbonImmutable::parse('2026-10-01', 'Europe/Lisbon'), CarbonImmutable::parse('2026-10-12', 'Europe/Lisbon'));
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'], array_map(fn ($day) => $day->toDateString(), $days));

        $this->assertTrue($coverage->fullyCoveredSince(CarbonImmutable::parse('2026-10-05 16:00', 'Europe/Lisbon'), CarbonImmutable::parse('2026-10-08 11:00', 'Europe/Lisbon')));
        $this->assertFalse($coverage->fullyCoveredSince(CarbonImmutable::parse('2026-10-05 16:00', 'Europe/Lisbon'), CarbonImmutable::parse('2026-10-09 11:00', 'Europe/Lisbon')));
    }

    #[Test]
    public function a_gap_in_collection_is_not_fully_covered(): void
    {
        DB::table('usage_collection_periods')->delete();
        DB::table('usage_collection_periods')->insert([
            ['started_at' => '2026-10-01 00:00:00', 'ended_at' => '2026-10-03 00:00:00'],
            ['started_at' => '2026-10-06 00:00:00', 'ended_at' => null],
        ]);
        $coverage = new UsageCoverage;

        $this->assertFalse($coverage->fullyCoveredSince(CarbonImmutable::parse('2026-10-02', 'Europe/Lisbon'), CarbonImmutable::parse('2026-10-07', 'Europe/Lisbon')));
        $this->assertTrue($coverage->fullyCoveredSince(CarbonImmutable::parse('2026-10-06', 'Europe/Lisbon'), CarbonImmutable::parse('2026-10-12', 'Europe/Lisbon')));
        $this->assertFalse($coverage->isDayCovered(CarbonImmutable::parse('2026-10-04', 'Europe/Lisbon')));
    }

    private function makeClass(User $teacher, string $createdAt, string $role): void
    {
        $organization = $teacher->personalOrganization();

        $schoolClass = app(CurrentOrganization::class)->runFor($organization, function () use ($organization): SchoolClass {
            return SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($organization)->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])->id,
            ]);
        });

        $schoolClass->teachers()->attach($teacher, ['role' => $role]);
        DB::table('class_teachers')->where('class_id', $schoolClass->id)->update(['created_at' => $createdAt]);
    }

    #[Test]
    public function a_run_with_nothing_new_rewrites_no_state(): void
    {
        $first = app(AggregateUsage::class)->run($this->now);
        $this->assertGreaterThanOrEqual(2, $first['states']);
        $computedAt = DB::table('usage_user_states')->where('user_id', $this->teacher->id)->value('computed_at');

        $this->travelTo(Carbon::parse('2026-10-12 10:20:00', 'Europe/Lisbon'));
        $second = app(AggregateUsage::class)->run(CarbonImmutable::parse('2026-10-12 10:20:00', 'Europe/Lisbon'));

        // Nada mudou: nenhum UPDATE, e `computed_at` continua a ser o da última alteração.
        $this->assertSame(0, $second['states']);
        $this->assertSame($computedAt, DB::table('usage_user_states')->where('user_id', $this->teacher->id)->value('computed_at'));
    }
}
