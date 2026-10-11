<?php

namespace Tests\Feature\Usage;

use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Models\User;
use App\Support\Retention\RetentionPolicy;
use App\Support\Usage\UsageEventType;
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

    #[Test]
    public function prune_deletes_what_is_older_than_the_window_and_keeps_the_rest(): void
    {
        config()->set('retention.usage_metrics_days', 365);
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
        config()->set('retention.usage_metrics_days', 30);
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
    public function closing_an_account_erases_its_usage_data_and_only_its_own(): void
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

        $this->artisan('lapis:usage-opt-out', ['email' => $objector->email, '--undo' => true])->assertSuccessful();

        $this->assertNull($objector->refresh()->usage_opted_out_at);
    }

    #[Test]
    public function opting_out_an_unknown_email_fails(): void
    {
        $this->artisan('lapis:usage-opt-out', ['email' => 'ninguem@exemplo.pt'])->assertFailed();
    }
}
