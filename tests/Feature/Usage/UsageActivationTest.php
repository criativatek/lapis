<?php

namespace Tests\Feature\Usage;

use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Models\User;
use App\Services\Usage\AggregateUsage;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * A recolha nasce DESLIGADA até à decisão de ativação, e a cobertura começa na
 * ativação efetiva (docs/usage-insights-decisions.md §5). E nenhuma passagem
 * da agregação reconstitui o que a conservação ou a eliminação apagaram (§2).
 */
class UsageActivationTest extends TestCase
{
    use RefreshDatabase, SetsUsageCoverage;

    private User $teacher;

    private static int $snapshotCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->teacher = User::factory()->create();

        Route::middleware(['web', 'auth', 'organization'])->post('/_usage/activation', function (UsageRecorder $recorder) {
            $recorder->action(UsageEventType::LessonSummarySaved, 'lesson-1');

            return response('ok');
        });

        // Um gancho de edição: a impressão digital só pode ser calculada quando
        // alguma coisa vai ser registada.
        self::$snapshotCalls = 0;
        Route::middleware(['web', 'auth', 'organization'])->post('/_usage/snapshot', function (UsageRecorder $recorder) {
            $recorder->snapshot(function (): array {
                self::$snapshotCalls++;

                return ['conteúdo' => 'lido'];
            });

            return response('ok');
        });
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function aggregate(string $at = '2026-10-12 10:00:00'): array
    {
        return app(AggregateUsage::class)->run(CarbonImmutable::parse($at, 'Europe/Lisbon'));
    }

    // --- Ativação -----------------------------------------------------------

    #[Test]
    public function switched_off_by_default_nothing_is_recorded_and_no_period_opens(): void
    {
        $this->assertFalse((bool) config('usage.enabled'));

        $this->actingAs($this->teacher)->post('/_usage/activation')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
        $this->assertSame(0, DB::table('usage_collection_periods')->count());
    }

    #[Test]
    public function the_first_possible_record_opens_the_period_at_that_instant(): void
    {
        config(['usage.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-12 10:07:00', 'Europe/Lisbon'));

        $this->actingAs($this->teacher)->post('/_usage/activation')->assertOk();

        $this->assertSame(1, UsageEvent::query()->count());
        $period = DB::table('usage_collection_periods')->sole();
        $this->assertSame('2026-10-12 10:07:00', substr((string) $period->started_at, 0, 19));
        $this->assertNull($period->ended_at);

        // Um segundo registo não abre outro período.
        $this->travelTo(Carbon::parse('2026-10-12 11:00:00', 'Europe/Lisbon'));
        $this->actingAs($this->teacher)->post('/_usage/activation')->assertOk();
        $this->assertSame(1, DB::table('usage_collection_periods')->count());
    }

    #[Test]
    public function switching_off_closes_the_period_and_the_aggregation_reads_nobody(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $this->seedUsageEvent($this->teacher->id, $this->teacher->personalOrganization()->id, '2026-10-11', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        config(['usage.enabled' => false]);
        $result = $this->aggregate();

        // O período fecha nesse instante…
        $period = DB::table('usage_collection_periods')->sole();
        $this->assertSame('2026-10-12 10:00:00', substr((string) $period->ended_at, 0, 19));
        // …o que já tinha sido observado continua agregado…
        $this->assertSame(1, UsageDailyRollup::query()->count());
        // …mas sem ler dados de pessoas (turmas, primeiros passos) nem se declarar em dia.
        $this->assertSame(0, $result['states']);
        $this->assertSame(0, UsageUserState::query()->count());
        $this->assertNull(DB::table('usage_aggregation_state')->where('id', 1)->value('aggregated_at'));
    }

    #[Test]
    public function after_a_closure_request_nothing_more_is_recorded(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $this->teacher->forceFill(['closure_requested_at' => now()])->save();

        $this->actingAs($this->teacher)->post('/_usage/activation');

        $this->assertSame(0, UsageEvent::query()->count());
    }

    // --- Nenhuma reconstituição ---------------------------------------------

    #[Test]
    public function an_aggregation_never_rewrites_metrics_of_whoever_objected_or_was_anonymised(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $objector = User::factory()->create();
        $organization = $objector->personalOrganization()->id;

        // Eventos que ainda lá estavam quando a agregação começou (a corrida com ForgetUsageData).
        $this->seedUsageEvent($objector->id, $organization, '2026-10-12', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $objector->forceFill(['usage_opted_out_at' => now()])->save();
        // E restos de uma passagem anterior.
        DB::table('usage_daily_rollups')->insert([
            'day' => '2026-10-05', 'user_id' => $objector->id, 'organization_id' => $organization, 'feature' => 'lessons',
            'views' => 1, 'actions' => 0, 'useful_actions' => 0, 'items' => 0, 'failures' => 0,
        ]);
        UsageUserState::query()->create(['user_id' => $objector->id, 'computed_at' => now()]);

        $this->aggregate();

        $this->assertSame(0, UsageDailyRollup::query()->where('user_id', $objector->id)->count());
        $this->assertSame(0, UsageUserState::query()->where('user_id', $objector->id)->count());
    }

    #[Test]
    public function an_aggregation_never_rebuilds_aggregates_past_their_retention(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        $this->startUsageCollectionAt('2026-01-01 00:00:00');
        $organization = $this->teacher->personalOrganization()->id;

        // Sem agregação nenhuma até agora: a primeira passagem parte do evento
        // mais antigo — mas nunca para trás do limite dos agregados (2026-04-15).
        $this->seedUsageEvent($this->teacher->id, $organization, '2026-03-01', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->seedUsageEvent($this->teacher->id, $organization, '2026-08-20', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        $this->aggregate();

        $days = UsageDailyRollup::query()->get()->map(fn ($rollup) => $rollup->day->toDateString())->all();
        $this->assertSame(['2026-08-20'], $days, 'Um dia anterior ao limite dos agregados foi reconstituído.');
    }

    #[Test]
    public function a_stalled_aggregation_still_recovers_days_older_than_the_events_window(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        $this->startUsageCollectionAt('2026-01-01 00:00:00');
        // A agregação parou a 2026-08-22; os eventos desses dias foram guardados
        // por `usage:prune` à espera dela (são mais antigos do que 35 dias).
        DB::table('usage_aggregation_state')->updateOrInsert(['id' => 1], ['aggregated_through' => '2026-08-22', 'aggregated_at' => '2026-08-22 09:45:00']);
        $this->seedUsageEvent($this->teacher->id, $this->teacher->personalOrganization()->id, '2026-08-25', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        $this->artisan('usage:prune')->assertSuccessful();
        $this->aggregate();

        $this->assertSame(1, UsageDailyRollup::query()->where('day', '>=', '2026-08-25')->where('day', '<', '2026-08-26')->count());
    }

    // --- Nada é lido quando nada vai ser registado (revisão, I1) ----------------

    #[Test]
    public function with_collection_off_an_edit_hook_reads_nothing(): void
    {
        $this->assertFalse((bool) config('usage.enabled'));

        $this->actingAs($this->teacher)->post('/_usage/snapshot')->assertOk();

        $this->assertSame(0, self::$snapshotCalls, 'Com a recolha desligada, um gancho leu dados para uma impressão digital.');
    }

    #[Test]
    public function during_technical_access_or_after_an_objection_an_edit_hook_reads_nothing(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');

        $this->actingAs($this->teacher)->withSession(['impersonator_id' => 999])->post('/_usage/snapshot')->assertOk();
        $this->assertSame(0, self::$snapshotCalls);

        $this->teacher->forceFill(['usage_opted_out_at' => now()])->save();
        $this->actingAs($this->teacher->fresh())->post('/_usage/snapshot')->assertOk();
        $this->assertSame(0, self::$snapshotCalls);
    }

    #[Test]
    public function with_collection_on_the_edit_hook_computes_its_fingerprint(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');

        $this->actingAs($this->teacher)->post('/_usage/snapshot')->assertOk();

        $this->assertSame(1, self::$snapshotCalls);
    }

    // --- O prazo dos eventos vale com a recolha desligada (revisão, I2) -------

    #[Test]
    public function switched_off_for_longer_than_the_events_window_old_events_still_go(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        // Recolha de 01/08 a 20/08, desligada desde então; a agregação parou com ela.
        $this->startUsageCollectionAt('2026-08-01 00:00:00', '2026-08-20 18:00:00');
        config(['usage.enabled' => false]);
        // A última passagem antes de desligar foi a 19/08 (o próprio dia do evento ainda por fechar).
        DB::table('usage_aggregation_state')->updateOrInsert(['id' => 1], ['aggregated_through' => '2026-08-19', 'aggregated_at' => '2026-08-20 17:45:00']);
        $this->seedUsageEvent($this->teacher->id, $this->teacher->personalOrganization()->id, '2026-08-19', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        // A recolha continua desligada; a agregação corre na mesma.
        $this->aggregate();
        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(0, UsageEvent::query()->count(), 'Um evento sobreviveu ao prazo de 35 dias com a recolha desligada.');
        // O agregado desse dia (180 dias) continua.
        $this->assertSame(1, UsageDailyRollup::query()->count());
        // E a hora da agregação não mudou: sem recolha, os sinais não estão em dia.
        $this->assertSame('2026-08-20 17:45:00', substr((string) DB::table('usage_aggregation_state')->where('id', 1)->value('aggregated_at'), 0, 19));
    }

    // --- Um só período aberto (revisão, M1) -----------------------------------

    #[Test]
    public function the_database_refuses_a_second_open_period(): void
    {
        DB::table('usage_collection_periods')->insert(['started_at' => '2026-10-12 09:00:00', 'ended_at' => null, 'is_open' => true]);

        $this->expectException(QueryException::class);
        DB::table('usage_collection_periods')->insert(['started_at' => '2026-10-12 09:00:01', 'ended_at' => null, 'is_open' => true]);
    }

    #[Test]
    public function closing_a_period_frees_the_open_slot_for_the_next_activation(): void
    {
        config(['usage.enabled' => true]);
        $this->aggregate('2026-10-12 09:00:00');
        config(['usage.enabled' => false]);
        $this->aggregate('2026-10-12 10:00:00');
        config(['usage.enabled' => true]);
        $this->aggregate('2026-10-12 11:00:00');

        $periods = DB::table('usage_collection_periods')->orderBy('started_at')->get();
        $this->assertCount(2, $periods);
        $this->assertNull($periods[0]->is_open);
        $this->assertNotNull($periods[0]->ended_at);
        $this->assertSame(1, (int) $periods[1]->is_open);
        $this->assertNull($periods[1]->ended_at);
    }
}
