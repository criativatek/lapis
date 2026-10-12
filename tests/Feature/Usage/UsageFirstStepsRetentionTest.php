<?php

namespace Tests\Feature\Usage;

use App\Actions\Usage\SetUsageOptOut;
use App\Models\SchoolClass;
use App\Models\UsageUserState;
use App\Models\User;
use App\Services\Usage\AggregateUsage;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFeature;
use App\Support\Usage\UsageMilestone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Usage\Insights\InsightsTestCase;

/**
 * A data de cada primeiro passo guarda-se durante o prazo dos agregados (180
 * dias); depois fica só «Atingido», sem data (docs/usage-insights.md, «Datas
 * dos primeiros passos»). Nada volta a datar um passo atingido, «atingido»
 * nunca gera um sinal de falta de utilização, e nenhum cálculo que precise de
 * datas o situa num intervalo.
 *
 * Hoje é 2026-10-12; com 180 dias, a última data guardada é 2026-04-15.
 */
class UsageFirstStepsRetentionTest extends InsightsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['retention.usage_events_days' => 35, 'retention.usage_rollups_days' => 180]);
    }

    /**
     * @param  array<string, string|null>  $dates  milestone => data (ou null)
     * @param  list<string>  $reachedWithoutDate  milestones atingidos sem data
     */
    private function steps(User $user, array $dates = [], array $reachedWithoutDate = [], array $extra = []): UsageUserState
    {
        $state = $this->state($user, $extra);
        $values = [];

        foreach ($dates as $milestone => $date) {
            $values[UsageMilestone::from($milestone)->stateColumn()] = $date;
            $values[UsageMilestone::from($milestone)->reachedColumn()] = $date !== null;
        }
        foreach ($reachedWithoutDate as $milestone) {
            $values[UsageMilestone::from($milestone)->stateColumn()] = null;
            $values[UsageMilestone::from($milestone)->reachedColumn()] = true;
        }

        $state->forceFill($values)->save();

        return $state->refresh();
    }

    private function aggregate(string $at = '2026-10-12 10:15:00'): void
    {
        app(AggregateUsage::class)->run(CarbonImmutable::parse($at, 'Europe/Lisbon'));
    }

    // --- O prazo ------------------------------------------------------------

    #[Test]
    public function a_date_lives_as_long_as_an_aggregate_and_then_only_reached_remains(): void
    {
        $user = $this->person('Antiga', '2025-06-01 09:00:00');
        $this->steps($user, [
            'first_class_created' => '2026-04-14', // passou o prazo
            'first_students_added' => '2026-04-15', // último dia ainda guardado
        ]);

        $this->artisan('usage:prune')->assertSuccessful();

        $state = UsageUserState::query()->where('user_id', $user->id)->sole();
        $this->assertNull($state->first_class_created_on);
        $this->assertTrue($state->first_class_created_reached);
        $this->assertSame('2026-04-15', $state->first_students_added_on?->toDateString());
        $this->assertTrue($state->first_students_added_reached);
        // Um passo que nunca foi dado continua a não o ser.
        $this->assertNull($state->first_useful_record_on);
        $this->assertFalse($state->first_useful_record_reached);

        // No dia seguinte, a primeira passagem leva a de 15/04.
        $this->travelTo(Carbon::parse('2026-10-13 00:07:00', 'Europe/Lisbon'));
        $this->artisan('usage:prune')->assertSuccessful();
        $state->refresh();
        $this->assertNull($state->first_students_added_on);
        $this->assertTrue($state->first_students_added_reached);
    }

    #[Test]
    public function the_dates_also_expire_with_collection_switched_off(): void
    {
        DB::table('usage_collection_periods')->update(['ended_at' => '2026-05-01 10:00:00', 'is_open' => null]);
        $user = $this->person('Antiga', '2025-06-01 09:00:00');
        $this->steps($user, ['first_useful_record' => '2026-03-02']);

        $this->artisan('usage:prune')->assertSuccessful();

        $state = UsageUserState::query()->where('user_id', $user->id)->sole();
        $this->assertNull($state->first_useful_record_on);
        $this->assertTrue($state->first_useful_record_reached);
    }

    // --- Sem reconstrução ------------------------------------------------------

    #[Test]
    public function a_later_event_never_gives_an_old_step_a_date_again(): void
    {
        $user = $this->person('Antiga', '2025-06-01 09:00:00');
        $this->steps($user, [], ['first_class_created', 'first_useful_record']);
        $organization = $user->personalOrganization()->id;
        $this->seedUsageEvent($user->id, $organization, '2026-10-12', UsageEventType::ClassCreated);
        $this->seedUsageEvent($user->id, $organization, '2026-10-12', UsageEventType::LessonSummarySaved);
        $this->seedUsageEvent($user->id, $organization, '2026-10-12', UsageEventType::StudentsAdded);

        $this->aggregate();

        $state = UsageUserState::query()->where('user_id', $user->id)->sole();
        $this->assertNull($state->first_class_created_on, 'Uma turma nova datou um passo antigo.');
        $this->assertTrue($state->first_class_created_reached);
        $this->assertNull($state->first_useful_record_on, 'Um registo novo datou um passo antigo.');
        $this->assertTrue($state->first_useful_record_reached);
        // Um passo ainda não dado é datado normalmente.
        $this->assertSame('2026-10-12', $state->first_students_added_on?->toDateString());
        $this->assertTrue($state->first_students_added_reached);
    }

    #[Test]
    public function nothing_is_rebuilt_from_pedagogical_data(): void
    {
        $user = $this->person('Com Turmas', '2025-06-01 09:00:00');
        $class = SchoolClass::factory()->recycle($user->personalOrganization())->create();
        DB::table('class_teachers')->updateOrInsert(
            ['class_id' => $class->id, 'user_id' => $user->id],
            ['role' => 'owner', 'created_at' => '2025-09-01 10:00:00', 'updated_at' => '2025-09-01 10:00:00'],
        );

        $this->aggregate();

        $state = UsageUserState::query()->where('user_id', $user->id)->sole();
        // A turma existe (configuração), mas criá-la não foi observado: sem data e sem «atingido».
        $this->assertNotNull($state->class_available_since);
        $this->assertNull($state->first_class_created_on);
        $this->assertFalse($state->first_class_created_reached);
    }

    // --- Apresentação: três estados distintos ----------------------------------

    #[Test]
    public function reached_without_date_is_shown_as_such_and_kept_apart_from_not_observed(): void
    {
        // Conta anterior à recolha (01/09): a nota diz que pode ter acontecido antes.
        $user = $this->person('Antiga', '2025-06-01 09:00:00');
        $state = $this->steps($user, ['first_students_added' => '2026-09-20'], ['first_class_created']);

        $steps = collect($this->props('admin.usage.users.show', [], ['usageUser' => $state->ulid])['first_steps'])->keyBy('key');

        $this->assertSame(['observed_on' => null, 'reached' => true], [
            'observed_on' => $steps['first_class_created']['observed_on'],
            'reached' => $steps['first_class_created']['reached'],
        ]);
        $this->assertSame('2026-09-20', $steps['first_students_added']['observed_on']);
        $this->assertTrue($steps['first_students_added']['reached']);
        $this->assertNull($steps['first_useful_record']['observed_on']);
        $this->assertFalse($steps['first_useful_record']['reached']);
        $this->assertSame('Conta anterior à recolha — pode ter acontecido antes.', $steps['first_useful_record']['note']);
    }

    // --- Sinais e cálculos temporais -------------------------------------------

    #[Test]
    public function an_expired_date_never_produces_a_signal_of_missing_use(): void
    {
        $this->startUsageCollectionAt('2026-01-01 00:00:00');
        $reached = $this->person('Já Registou', '2025-06-01 09:00:00');
        $this->steps($reached, [], ['first_useful_record', 'first_class_created'], ['class_available_since' => '2025-09-01 10:00:00']);
        $notYet = $this->person('Ainda Não Registou', '2025-06-01 09:00:00');
        $this->steps($notYet, [], [], ['class_available_since' => '2025-09-01 10:00:00']);

        $rows = collect($this->props('admin.usage.users.index')['users']['data'])->keyBy('name');

        // Quem atingiu, mesmo sem data, não tem o sinal nem fica «por avaliar» por ele.
        $this->assertNotContains('no_first_record', array_column($rows['Já Registou']['signals'], 'key'));
        $detail = $this->props('admin.usage.users.show', [], ['usageUser' => $rows['Já Registou']['ulid']]);
        $this->assertNotContains('no_first_record', array_column($detail['not_evaluated'], 'key'));

        // Para contraste: quem nunca o deu é avaliado (aqui, por avaliar — a recolha começou depois da turma).
        $detail = $this->props('admin.usage.users.show', [], ['usageUser' => $rows['Ainda Não Registou']['ulid']]);
        $this->assertContains('no_first_record', array_column($detail['not_evaluated'], 'key'));
    }

    #[Test]
    public function calculations_that_need_dates_never_place_reached_in_an_interval(): void
    {
        $user = $this->person('Atingida Sem Data', '2025-06-01 09:00:00');
        $this->steps($user, [], ['first_class_created', 'first_students_added', 'first_useful_record']);
        $active = $this->person('Ativa');
        $this->state($active);
        $this->rollup($active, '2026-10-08', UsageFeature::Lessons, actions: 1);

        // Atividade no período: só agregados — «atingido» não é um dia ativo.
        $overview = $this->props('admin.usage.overview');
        $this->assertSame(1, $overview['headline']['active_period']);
        $this->assertSame(1, $overview['headline']['active_30']['value']);

        $row = collect($this->props('admin.usage.users.index')['users']['data'])->firstWhere('name', 'Atingida Sem Data');
        $this->assertSame(0, $row['active_days']);
        $this->assertSame(0, $row['actions']);
        $this->assertNull($row['last_activity_on']);
        $this->assertSame('Sem atividade observada no período', $row['status']);

        // Nem no ranking: sem atividade, fica contada à parte, não ordenada.
        $ranking = $this->props('admin.usage.ranking');
        $this->assertNotContains('Atingida Sem Data', array_column($ranking['ranking']['data'], 'name'));

        // Nem nas coortes (contas criadas no período): esta conta é anterior.
        $this->assertSame([], array_filter($overview['cohorts'], fn (array $cohort): bool => ($cohort['size'] ?? 0) > 1));
    }

    // --- Oposição, encerramento e retoma -----------------------------------------

    #[Test]
    public function opting_out_erases_the_reached_state_and_resuming_brings_nothing_back(): void
    {
        $user = $this->person('Antiga', '2025-06-01 09:00:00');
        $this->steps($user, ['first_students_added' => '2026-09-20'], ['first_class_created', 'first_useful_record']);

        app(SetUsageOptOut::class)->optOut($user);
        $this->assertSame(0, UsageUserState::query()->where('user_id', $user->id)->count());

        $this->travelTo(Carbon::parse('2026-10-12 11:00:00', 'Europe/Lisbon'));
        app(SetUsageOptOut::class)->optIn($user->refresh());
        $this->aggregate('2026-10-12 11:15:00');

        $state = UsageUserState::query()->where('user_id', $user->id)->sole();
        foreach (UsageMilestone::cases() as $milestone) {
            $this->assertFalse($state->hasReached($milestone), $milestone->value.' voltou depois da oposição.');
        }
    }

    #[Test]
    public function a_closure_request_erases_it_and_cancelling_brings_nothing_back(): void
    {
        $user = $this->person('Antiga', '2025-06-01 09:00:00');
        $this->steps($user, [], ['first_class_created', 'first_useful_record']);

        $this->actingAs($user)->post('/settings/account-closure')->assertRedirect();
        $this->assertNotNull($user->refresh()->closure_requested_at);
        $this->assertSame(0, UsageUserState::query()->where('user_id', $user->id)->count());

        $this->actingAs($user)->delete('/settings/account-closure')->assertRedirect();
        $this->assertNull($user->refresh()->closure_requested_at, 'O cancelamento não foi aceite.');
        $this->aggregate('2026-10-12 10:30:00');

        $state = UsageUserState::query()->where('user_id', $user->id)->sole();
        foreach (UsageMilestone::cases() as $milestone) {
            $this->assertFalse($state->hasReached($milestone), $milestone->value.' voltou depois do encerramento cancelado.');
        }
    }
}
