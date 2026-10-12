<?php

namespace Tests\Feature\Usage;

use App\Actions\Usage\SetUsageCollection;
use App\Models\AuditEvent;
use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Models\User;
use App\Services\Usage\AggregateUsage;
use App\Support\Legal\LegalDocuments;
use App\Support\Legal\PrivacyVersion;
use App\Support\Usage\UsageCoverage;
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
 * Ativação e desativação EXPLÍCITAS (docs/usage-insights.md, «Ativação e
 * desativação»): a recolha nasce desligada, só `lapis:usage-collection
 * start|stop` a liga e desliga, com auditoria, e o período abre e fecha no
 * instante da transição — não no primeiro evento, nem na agregação seguinte. A
 * única fonte de verdade é a base de dados. E nenhuma passagem da agregação
 * reconstitui o que a conservação ou a eliminação apagaram.
 */
class UsageActivationTest extends TestCase
{
    use RefreshDatabase, SetsUsageCoverage;

    private User $teacher;

    private User $operator;

    private static int $snapshotCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->teacher = User::factory()->create();
        $this->operator = User::factory()->create(['email' => 'operador@example.test']);
        $this->operator->forceFill(['is_platform_admin' => true, 'can_view_usage_insights' => true])->save();

        Route::middleware(['web', 'auth', 'organization'])->post('/_usage/activation', function (UsageRecorder $recorder) {
            $recorder->action(UsageEventType::LessonSummarySaved, 'lesson-1');

            return response('ok');
        });

        // Um pedido que prepara o registo com a recolha ligada e só escreve
        // depois do commit — e, entretanto, a recolha é desligada.
        Route::middleware(['web', 'auth', 'organization'])->post('/_usage/in-flight-stop', function (UsageRecorder $recorder) {
            DB::transaction(function () use ($recorder): void {
                $recorder->action(UsageEventType::LessonSummarySaved, 'lesson-2');
                app(SetUsageCollection::class)->stop(User::query()->where('email', 'operador@example.test')->firstOrFail());
            });

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

    private function start(): void
    {
        $this->artisan('lapis:usage-collection', ['action' => 'start', '--operator' => 'operador@example.test'])->assertSuccessful();
        // Um pedido novo — a memória do estado é por pedido.
        app()->forgetScopedInstances();
    }

    private function stop(): void
    {
        $this->artisan('lapis:usage-collection', ['action' => 'stop', '--operator' => 'operador@example.test'])->assertSuccessful();
        app()->forgetScopedInstances();
    }

    // --- Desligada por omissão ----------------------------------------------

    #[Test]
    public function switched_off_by_default_nothing_is_recorded_and_no_event_opens_a_period(): void
    {
        $this->assertSame(0, DB::table('usage_collection_periods')->count(), 'A migração abriu um período.');

        $this->actingAs($this->teacher)->post('/_usage/activation')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
        $this->assertSame(0, DB::table('usage_collection_periods')->count(), 'Um evento abriu a recolha.');
        $this->assertFalse(PrivacyVersion::describesUsageMetrics());
    }

    #[Test]
    public function there_is_no_configuration_switch_to_contradict_the_database(): void
    {
        $this->assertNull(config('usage.enabled'), 'Voltou a haver uma chave de configuração para a recolha.');

        // Mesmo que alguém a ponha, não liga nada.
        config(['usage.enabled' => true]);
        $this->actingAs($this->teacher)->post('/_usage/activation')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    // --- Ativação -----------------------------------------------------------

    #[Test]
    public function start_opens_the_period_at_that_instant_without_any_activity_and_is_audited(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 10:07:00', 'Europe/Lisbon'));

        $this->start();

        $period = DB::table('usage_collection_periods')->sole();
        $this->assertSame('2026-10-12 10:07:00', substr((string) $period->started_at, 0, 19));
        $this->assertNull($period->ended_at);
        $this->assertSame(0, UsageEvent::query()->count(), 'A ativação não precisa de atividade.');
        $this->assertTrue(app(UsageCoverage::class)->isActive());

        $audit = AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_collection_started')->sole();
        $this->assertSame($this->operator->id, $audit->causer_id);
        $this->assertNull($audit->organization_id);
    }

    #[Test]
    public function the_privacy_policy_describes_the_metrics_from_the_activation_instant(): void
    {
        $this->assertFalse(PrivacyVersion::describesUsageMetrics());

        $this->travelTo(Carbon::parse('2026-10-12 10:07:00', 'Europe/Lisbon'));
        $this->start();

        $this->assertTrue(PrivacyVersion::describesUsageMetrics());
        $this->assertSame('2026-10-12 10:07:00', PrivacyVersion::effectiveAt()?->format('Y-m-d H:i:s'));
        $this->assertTrue(PrivacyVersion::latestChangeIsUsageMetrics());
    }

    #[Test]
    public function repeating_start_opens_no_second_period_and_audits_nothing_new(): void
    {
        $this->start();
        $this->travelTo(Carbon::parse('2026-10-12 11:00:00', 'Europe/Lisbon'));
        $this->start();

        $this->assertSame(1, DB::table('usage_collection_periods')->count());
        $this->assertSame('2026-10-12 10:00:00', substr((string) DB::table('usage_collection_periods')->value('started_at'), 0, 19));
        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_collection_started')->count());
    }

    #[Test]
    public function only_a_named_operator_with_access_to_the_panel_can_switch_it(): void
    {
        $this->artisan('lapis:usage-collection', ['action' => 'start'])->assertFailed();
        $this->artisan('lapis:usage-collection', ['action' => 'start', '--operator' => $this->teacher->email])->assertFailed();

        $this->assertSame(0, DB::table('usage_collection_periods')->count());
    }

    #[Test]
    public function status_only_reads(): void
    {
        $this->artisan('lapis:usage-collection', ['action' => 'status'])->assertSuccessful();
        $this->assertSame(0, DB::table('usage_collection_periods')->count());

        $this->start();
        $this->artisan('lapis:usage-collection', ['action' => 'status'])
            ->expectsOutputToContain('LIGADA')
            ->assertSuccessful();
    }

    #[Test]
    public function once_started_a_request_records(): void
    {
        $this->start();

        $this->actingAs($this->teacher)->post('/_usage/activation')->assertOk();

        $this->assertSame(1, UsageEvent::query()->count());
    }

    // --- Desativação --------------------------------------------------------

    #[Test]
    public function stop_closes_the_period_at_that_instant_and_blocks_new_events_immediately(): void
    {
        $this->start();
        $this->travelTo(Carbon::parse('2026-10-12 12:34:56', 'Europe/Lisbon'));
        $this->stop();

        $period = DB::table('usage_collection_periods')->sole();
        $this->assertSame('2026-10-12 12:34:56', substr((string) $period->ended_at, 0, 19));
        $this->assertNull($period->is_open);
        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_collection_stopped')->count());

        // Nenhuma passagem da agregação é precisa para isto valer.
        $this->actingAs($this->teacher)->post('/_usage/activation')->assertOk();
        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_request_in_flight_when_collection_stops_writes_nothing_after_its_commit(): void
    {
        $this->start();

        $this->actingAs($this->teacher)->post('/_usage/in-flight-stop')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count(), 'O callback depois do commit gravou com a recolha já desligada.');
    }

    #[Test]
    public function repeating_stop_changes_nothing(): void
    {
        $this->start();
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Europe/Lisbon'));
        $this->stop();
        $this->travelTo(Carbon::parse('2026-10-12 13:00:00', 'Europe/Lisbon'));
        $this->stop();

        $this->assertSame('2026-10-12 12:00:00', substr((string) DB::table('usage_collection_periods')->value('ended_at'), 0, 19));
        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_collection_stopped')->count());
    }

    #[Test]
    public function switching_off_keeps_the_privacy_information_about_what_is_still_retained(): void
    {
        $this->start();
        $this->travelTo(Carbon::parse('2026-10-13 09:00:00', 'Europe/Lisbon'));
        $this->stop();

        $this->assertTrue(PrivacyVersion::describesUsageMetrics());
        $this->get('/privacidade')->assertOk();
        $headings = array_column(LegalDocuments::privacy()['sections'], 'heading');
        $this->assertContains('Métricas de utilização do serviço', $headings);
    }

    // --- Reativação ---------------------------------------------------------

    #[Test]
    public function reactivation_opens_a_new_period_and_keeps_the_gap(): void
    {
        $this->start();
        $this->travelTo(Carbon::parse('2026-10-14 18:00:00', 'Europe/Lisbon'));
        $this->stop();
        $this->travelTo(Carbon::parse('2026-10-20 08:30:00', 'Europe/Lisbon'));
        $this->start();

        $periods = DB::table('usage_collection_periods')->orderBy('started_at')->get();
        $this->assertCount(2, $periods);
        $this->assertSame('2026-10-14 18:00:00', substr((string) $periods[0]->ended_at, 0, 19));
        $this->assertSame('2026-10-20 08:30:00', substr((string) $periods[1]->started_at, 0, 19));

        $coverage = app(UsageCoverage::class);
        $this->assertTrue($coverage->isDayCovered(CarbonImmutable::parse('2026-10-14', 'Europe/Lisbon')));
        $this->assertFalse($coverage->isDayCovered(CarbonImmutable::parse('2026-10-17', 'Europe/Lisbon')), 'O intervalo desligado foi preenchido.');
        $this->assertTrue($coverage->isDayCovered(CarbonImmutable::parse('2026-10-20', 'Europe/Lisbon')));
        $this->assertFalse($coverage->isDayFullyCovered(CarbonImmutable::parse('2026-10-20', 'Europe/Lisbon')));
        // A Política continua a datar da primeira ativação.
        $this->assertSame('2026-10-12 10:00:00', PrivacyVersion::effectiveAt()?->format('Y-m-d H:i:s'));
    }

    // --- A agregação só lê o estado -----------------------------------------

    #[Test]
    public function the_aggregation_neither_opens_nor_closes_periods(): void
    {
        $this->aggregate();
        $this->assertSame(0, DB::table('usage_collection_periods')->count());

        $this->start();
        $this->aggregate('2026-10-12 11:00:00');
        $this->assertNull(DB::table('usage_collection_periods')->value('ended_at'));
    }

    #[Test]
    public function with_collection_off_the_aggregation_reads_nobody(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00', '2026-10-12 09:00:00');
        $this->seedUsageEvent($this->teacher->id, $this->teacher->personalOrganization()->id, '2026-10-11', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        $result = $this->aggregate();

        // O que já tinha sido observado continua agregado…
        $this->assertSame(1, UsageDailyRollup::query()->count());
        // …mas sem ler dados de pessoas (turmas, primeiros passos) nem se declarar em dia.
        $this->assertSame(0, $result['states']);
        $this->assertSame(0, UsageUserState::query()->count());
        $this->assertNull(DB::table('usage_aggregation_state')->where('id', 1)->value('aggregated_at'));
    }

    // --- Processos persistentes ---------------------------------------------

    #[Test]
    public function the_state_is_remembered_per_request_or_job_never_per_process(): void
    {
        $first = app(UsageRecorder::class);
        $this->assertSame($first, app(UsageRecorder::class), 'Dentro do mesmo pedido o recorder é o mesmo.');
        $this->assertFalse(PrivacyVersion::describesUsageMetrics());

        app(SetUsageCollection::class)->start($this->operator);
        // Um worker esquece as instâncias `scoped` antes de cada job.
        app()->forgetScopedInstances();

        $this->assertNotSame($first, app(UsageRecorder::class));
        $this->assertTrue(PrivacyVersion::describesUsageMetrics());
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

    // --- Nada é lido quando nada vai ser registado ----------------------------

    #[Test]
    public function with_collection_off_an_edit_hook_reads_nothing(): void
    {
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

    // --- O prazo dos eventos vale com a recolha desligada ---------------------

    #[Test]
    public function switched_off_for_longer_than_the_events_window_old_events_still_go(): void
    {
        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
        // Recolha de 01/08 a 20/08, desligada desde então.
        $this->startUsageCollectionAt('2026-08-01 00:00:00', '2026-08-20 18:00:00');
        // A última passagem antes de desligar foi a 19/08 (o próprio dia do evento ainda por fechar).
        DB::table('usage_aggregation_state')->updateOrInsert(['id' => 1], ['aggregated_through' => '2026-08-19', 'aggregated_at' => '2026-08-20 17:45:00']);
        $this->seedUsageEvent($this->teacher->id, $this->teacher->personalOrganization()->id, '2026-08-19', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        // A recolha continua desligada; a agregação corre na mesma.
        $this->aggregate();
        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(0, UsageEvent::query()->count(), 'Um evento sobreviveu ao prazo de 35 dias com a recolha desligada.');
        // O agregado desse dia (180 dias) continua, e não é uma lacuna: foi agregado a tempo.
        $this->assertSame(1, UsageDailyRollup::query()->count());
        $this->assertSame(0, DB::table('usage_coverage_gaps')->count());
        // E a hora da agregação não mudou: sem recolha, os sinais não estão em dia.
        $this->assertSame('2026-08-20 17:45:00', substr((string) DB::table('usage_aggregation_state')->where('id', 1)->value('aggregated_at'), 0, 19));
    }

    // --- Um só período aberto -------------------------------------------------

    #[Test]
    public function the_database_refuses_a_second_open_period(): void
    {
        DB::table('usage_collection_periods')->insert(['started_at' => '2026-10-12 09:00:00', 'ended_at' => null, 'is_open' => true]);

        $this->expectException(QueryException::class);
        DB::table('usage_collection_periods')->insert(['started_at' => '2026-10-12 09:00:01', 'ended_at' => null, 'is_open' => true]);
    }
}
