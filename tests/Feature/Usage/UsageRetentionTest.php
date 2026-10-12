<?php

namespace Tests\Feature\Usage;

use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Models\User;
use App\Services\Usage\AggregateUsage;
use App\Support\Retention\RetentionPolicy;
use App\Support\Usage\UsageCoverage;
use App\Support\Usage\UsageEventType;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

class UsageRetentionTest extends TestCase
{
    use RefreshDatabase, SetsUsageCoverage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->startUsageCollectionAt('2025-01-01 00:00:00');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function seedRollup(User $user, string $day): void
    {
        DB::table('usage_daily_rollups')->insert([
            'day' => $day, 'user_id' => $user->id, 'organization_id' => $user->personalOrganization()->id,
            'feature' => 'lessons', 'views' => 1, 'actions' => 0, 'useful_actions' => 0, 'items' => 0, 'failures' => 0,
        ]);
    }

    private function seedState(User $user): void
    {
        UsageUserState::query()->create(['user_id' => $user->id, 'computed_at' => now()]);
    }

    private function aggregatedThrough(?string $day): void
    {
        DB::table('usage_aggregation_state')->updateOrInsert(['id' => 1], [
            'aggregated_through' => $day,
            'aggregated_at' => $day === null ? null : $day.' 09:45:00',
        ]);
    }

    #[Test]
    public function prune_deletes_what_is_older_than_the_window_and_keeps_the_rest(): void
    {
        config()->set('retention.usage_events_days', 365);
        config()->set('retention.usage_rollups_days', 365);
        $this->aggregatedThrough('2026-10-12');
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization()->id;

        // Hoje - 365 dias = 2025-10-12: esse dia fica, o anterior sai.
        $this->seedUsageEvent($teacher->id, $organization, '2025-10-11', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($teacher->id, $organization, '2025-10-12', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($teacher->id, $organization, '2026-10-01', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedRollup($teacher, '2025-10-11');
        $this->seedRollup($teacher, '2026-10-01');
        $this->seedState($teacher);

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['2025-10-12', '2026-10-01'], UsageEvent::query()->get()->map(fn ($event) => $event->occurred_on->toDateString())->all());
        $this->assertSame(['2026-10-01'], UsageDailyRollup::query()->get()->map(fn ($rollup) => $rollup->day->toDateString())->all());
        // O estado dos primeiros passos fica enquanto a conta existir.
        $this->assertSame(1, UsageUserState::query()->count());
    }

    #[Test]
    public function the_window_follows_the_configuration(): void
    {
        config()->set('retention.usage_events_days', 30);
        config()->set('retention.usage_rollups_days', 365);
        $this->aggregatedThrough('2026-10-12');
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization()->id;

        $this->seedUsageEvent($teacher->id, $organization, '2026-09-01', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($teacher->id, $organization, '2026-10-01', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(1, UsageEvent::query()->count());
    }

    #[Test]
    public function prune_also_sweeps_everything_of_opted_out_users(): void
    {
        $stayer = User::factory()->create();
        $objector = User::factory()->create(['usage_opted_out_at' => now()]);

        $this->seedUsageEvent($stayer->id, $stayer->personalOrganization()->id, '2026-10-10', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($objector->id, $objector->personalOrganization()->id, '2026-10-10', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedRollup($objector, '2026-10-10');

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame([$stayer->id], UsageEvent::query()->pluck('user_id')->all());
        $this->assertSame(0, UsageDailyRollup::query()->count());
    }

    #[Test]
    public function anonymising_a_closed_account_finds_nothing_left_and_leaves_others_alone(): void
    {
        $closing = User::factory()->create();
        $other = User::factory()->create();

        foreach ([$closing, $other] as $user) {
            $this->seedUsageEvent($user->id, $user->personalOrganization()->id, '2026-10-10', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
            $this->seedRollup($user, '2026-10-10');
            $this->seedState($user);
        }

        $days = app(RetentionPolicy::class)->personalAccountClosureDays();
        $closing->forceFill([
            'closure_requested_at' => now()->subDays($days + 1),
            'scheduled_deletion_at' => now()->subDay(),
        ])->save();

        $this->artisan('retention:execute')->assertSuccessful();

        $this->assertNotNull($closing->refresh()->anonymized_at);
        $this->assertSame(0, UsageEvent::query()->where('user_id', $closing->id)->count());
        $this->assertSame(0, UsageDailyRollup::query()->where('user_id', $closing->id)->count());
        $this->assertSame(0, UsageUserState::query()->where('user_id', $closing->id)->count());

        $this->assertSame(1, UsageEvent::query()->where('user_id', $other->id)->count());
        $this->assertSame(1, UsageDailyRollup::query()->where('user_id', $other->id)->count());
        $this->assertSame(1, UsageUserState::query()->where('user_id', $other->id)->count());
    }

    #[Test]
    public function opting_out_records_the_date_and_erases_and_undo_turns_collection_back_on(): void
    {
        $objector = User::factory()->create();
        $this->seedUsageEvent($objector->id, $objector->personalOrganization()->id, '2026-10-10', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedRollup($objector, '2026-10-10');
        $this->seedState($objector);

        $this->artisan('lapis:usage-opt-out', ['email' => $objector->email])->assertSuccessful();

        $objector->refresh();
        $this->assertSame('2026-10-12 10:00:00', $objector->usage_opted_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, UsageEvent::query()->count());
        $this->assertSame(0, UsageDailyRollup::query()->count());
        $this->assertSame(0, UsageUserState::query()->count());

        $this->travelTo(Carbon::parse('2026-10-13 08:00:00', 'Europe/Lisbon'));
        $this->artisan('lapis:usage-opt-out', ['email' => $objector->email, '--undo' => true])->assertSuccessful();

        $objector->refresh();
        $this->assertNull($objector->usage_opted_out_at);
        // A recolha volta a valer a partir daqui, sem histórico.
        $this->assertSame('2026-10-13 08:00:00', $objector->usage_resumed_at?->format('Y-m-d H:i:s'));
        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function opting_out_an_unknown_email_fails(): void
    {
        $this->artisan('lapis:usage-opt-out', ['email' => 'ninguem@exemplo.pt'])->assertFailed();
    }

    // --- Prazos separados (docs/usage-insights-decisions.md §2) -------------

    #[Test]
    public function events_go_sooner_than_the_per_person_aggregates(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        $this->aggregatedThrough('2026-10-12');
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization()->id;

        // Hoje − 35 = 2026-09-07; hoje − 180 = 2026-04-15.
        $this->seedUsageEvent($teacher->id, $organization, '2026-09-06', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($teacher->id, $organization, '2026-09-07', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedRollup($teacher, '2026-04-14');
        $this->seedRollup($teacher, '2026-04-15');
        $this->seedRollup($teacher, '2026-09-06');

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(['2026-09-07'], UsageEvent::query()->get()->map(fn ($event) => $event->occurred_on->toDateString())->all());
        $this->assertEqualsCanonicalizing(['2026-04-15', '2026-09-06'], UsageDailyRollup::query()->get()->map(fn ($rollup) => $rollup->day->toDateString())->all());
    }

    #[Test]
    public function the_events_window_is_never_longer_than_the_aggregates_window(): void
    {
        // Configurado ao contrário: os eventos sobreviveriam aos agregados e uma
        // agregação posterior podia reconstituir agregados já apagados.
        config(['retention.usage_events_days' => 400, 'retention.usage_rollups_days' => 120]);

        $this->assertSame(120, app(RetentionPolicy::class)->usageEventsDays());
    }

    // --- O prazo dos eventos é um limite efetivo ------------------------------

    #[Test]
    public function the_limit_is_exact_to_the_second_at_each_pass(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        $this->aggregatedThrough('2026-10-12');
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization()->id;

        // Agora − 35 dias = 2026-09-07 10:00:00.
        $this->seedUsageEvent($teacher->id, $organization, '2026-09-07', UsageEventType::FeatureViewed, ['feature' => 'lessons', 'occurred_at' => '2026-09-07 09:59:59']);
        $this->seedUsageEvent($teacher->id, $organization, '2026-09-07', UsageEventType::FeatureViewed, ['feature' => 'records', 'occurred_at' => '2026-09-07 10:00:00']);
        $this->seedUsageEvent($teacher->id, $organization, '2026-09-07', UsageEventType::FeatureViewed, ['feature' => 'reports', 'occurred_at' => '2026-09-07 10:30:00']);

        $this->artisan('usage:prune')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['records', 'reports'], UsageEvent::query()->toBase()->pluck('feature')->all());

        // A passagem da hora seguinte leva os que entretanto passaram o prazo:
        // nenhum evento vive mais do que o prazo e uma hora.
        $this->travelTo(Carbon::parse('2026-10-12 11:00:00', 'Europe/Lisbon'));
        $this->artisan('usage:prune')->assertSuccessful();
        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function the_prune_runs_every_hour(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'usage:prune'));

        $this->assertNotNull($event);
        $this->assertSame('7 * * * *', $event->expression);
    }

    #[Test]
    public function a_prolonged_aggregation_failure_does_not_extend_the_events_life_and_leaves_a_gap(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        // A agregação parou a 2026-08-22 às 09:45: esse dia ficou a meio, os
        // seguintes nunca foram agregados. Agora − 35 dias = 2026-09-07 10:00.
        $this->aggregatedThrough('2026-08-22');
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization()->id;

        $this->seedUsageEvent($teacher->id, $organization, '2026-08-20', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($teacher->id, $organization, '2026-08-22', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($teacher->id, $organization, '2026-09-01', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($teacher->id, $organization, '2026-09-10', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedRollup($teacher, '2026-08-20'); // agregado a tempo
        $this->seedRollup($teacher, '2026-08-22'); // a meio, quando a agregação parou

        $this->artisan('usage:prune')->assertSuccessful();

        // Os eventos saem ao prazo, agregados ou não.
        $this->assertSame(['2026-09-10'], UsageEvent::query()->get()->map(fn ($event) => $event->occurred_on->toDateString())->all());
        // Os dias que expiraram por agregar ficam como lacuna — só o dia e o motivo.
        $this->assertSame(['2026-08-22', '2026-09-01'], DB::table('usage_coverage_gaps')->orderBy('day')->pluck('day')->map(fn ($day) => substr((string) $day, 0, 10))->all());
        $this->assertSame(['id', 'day', 'reason', 'recorded_at'], DB::getSchemaBuilder()->getColumnListing('usage_coverage_gaps'));
        $this->assertSame(['expired_unaggregated'], DB::table('usage_coverage_gaps')->distinct()->pluck('reason')->all());
        // O agregado a meio sai; o que foi agregado a tempo fica.
        $this->assertSame(['2026-08-20'], UsageDailyRollup::query()->get()->map(fn ($rollup) => $rollup->day->toDateString())->all());
    }

    #[Test]
    public function after_recovery_a_gap_is_never_recomputed_nor_shown_as_zero(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        $this->aggregatedThrough('2026-08-22');
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization()->id;
        $this->seedUsageEvent($teacher->id, $organization, '2026-09-01', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($teacher->id, $organization, '2026-09-10', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        $this->artisan('usage:prune')->assertSuccessful();
        // A agregação volta.
        app(AggregateUsage::class)->run(CarbonImmutable::now('Europe/Lisbon'));

        $days = UsageDailyRollup::query()->get()->map(fn ($rollup) => $rollup->day->toDateString())->all();
        $this->assertSame(['2026-09-10'], $days, 'Um dia de lacuna voltou a ter agregados.');

        $coverage = app(UsageCoverage::class);
        $this->assertFalse($coverage->isDayCovered(CarbonImmutable::parse('2026-09-01', 'Europe/Lisbon')), 'A lacuna aparece como dia observado (zero).');
        $this->assertTrue($coverage->isDayCovered(CarbonImmutable::parse('2026-09-02', 'Europe/Lisbon')));
        $this->assertFalse($coverage->fullyCoveredSince(CarbonImmutable::parse('2026-08-25', 'Europe/Lisbon'), now()), 'Uma janela com lacuna conta como observada por inteiro.');
        $this->assertTrue($coverage->fullyCoveredSince(CarbonImmutable::parse('2026-09-02', 'Europe/Lisbon'), now()));
    }

    #[Test]
    public function without_any_aggregation_expired_events_still_go_and_their_days_become_gaps(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        $teacher = User::factory()->create();

        $this->seedUsageEvent($teacher->id, $teacher->personalOrganization()->id, '2026-08-10', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(0, UsageEvent::query()->count());
        $this->assertSame(['2026-08-10'], DB::table('usage_coverage_gaps')->pluck('day')->map(fn ($day) => substr((string) $day, 0, 10))->all());
    }

    #[Test]
    public function a_day_aggregated_in_time_never_becomes_a_gap(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        $this->aggregatedThrough('2026-10-12');
        $teacher = User::factory()->create();
        $this->seedUsageEvent($teacher->id, $teacher->personalOrganization()->id, '2026-08-10', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(0, UsageEvent::query()->count());
        $this->assertSame(0, DB::table('usage_coverage_gaps')->count());
    }

    #[Test]
    public function with_collection_off_the_limit_still_applies(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        $this->startUsageCollectionAt('2026-08-01 00:00:00', '2026-08-20 18:00:00');
        $this->aggregatedThrough('2026-10-12');
        $teacher = User::factory()->create();
        $this->seedUsageEvent($teacher->id, $teacher->personalOrganization()->id, '2026-08-19', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function gaps_leave_together_with_the_aggregates_window(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        // Hoje − 180 = 2026-04-15.
        DB::table('usage_coverage_gaps')->insert([
            ['day' => '2026-04-14', 'reason' => 'expired_unaggregated', 'recorded_at' => '2026-05-20 10:00:00'],
            ['day' => '2026-04-15', 'reason' => 'expired_unaggregated', 'recorded_at' => '2026-05-21 10:00:00'],
        ]);

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(['2026-04-15'], DB::table('usage_coverage_gaps')->pluck('day')->map(fn ($day) => substr((string) $day, 0, 10))->all());
    }

    #[Test]
    public function prune_also_removes_the_state_of_whoever_objected(): void
    {
        $objector = User::factory()->create(['usage_opted_out_at' => now()]);
        $this->seedState($objector);

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(0, UsageUserState::query()->count());
    }
}
