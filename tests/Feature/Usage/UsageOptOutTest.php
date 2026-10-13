<?php

namespace Tests\Feature\Usage;

use App\Actions\Usage\SetUsageOptOut;
use App\Models\SchoolClass;
use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Models\User;
use App\Services\Usage\AggregateUsage;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFeature;
use App\Support\Usage\UsageRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Usage\Insights\InsightsTestCase;

/**
 * «Não registar a minha utilização» e o encerramento da conta
 * (docs/usage-insights.md, «Direito de oposição»): parar a recolha, eliminar
 * as métricas sem tocar em mais nada, nunca as reconstituir, e nunca fazer a
 * pessoa parecer inativa por se ter oposto.
 */
class UsageOptOutTest extends InsightsTestCase
{
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->person('Professora', '2026-06-01 09:00:00');

        Route::middleware(['web', 'auth', 'organization'])->post('/_usage/opt-out/record', function (UsageRecorder $recorder) {
            $recorder->action(UsageEventType::LessonSummarySaved, 'lesson-1');

            return response('ok');
        });

        // Um pedido que prepara um registo antes de a pessoa se opor, e só o
        // escreve depois do commit — quando ela já se opôs (e até já voltou a aceitar).
        Route::middleware(['web', 'auth', 'organization'])->post('/_usage/opt-out/in-flight', function (UsageRecorder $recorder) {
            DB::transaction(function () use ($recorder): void {
                $recorder->action(UsageEventType::LessonSummarySaved, 'lesson-2');

                $this->travelTo(Carbon::parse('2026-10-12 10:01:00', 'Europe/Lisbon'));
                app(SetUsageOptOut::class)->optOut(User::query()->where('name', 'Professora')->firstOrFail());

                if (request()->boolean('and_back')) {
                    $this->travelTo(Carbon::parse('2026-10-12 10:02:00', 'Europe/Lisbon'));
                    app(SetUsageOptOut::class)->optIn(User::query()->where('name', 'Professora')->firstOrFail());
                }
            });

            return response('ok');
        });
    }

    private function asTeacher(?User $user = null): self
    {
        $user ??= $this->teacher;

        return $this->actingAs($user)->withSession(['organization_id' => $user->personalOrganization()->id]);
    }

    private function seedMetrics(User $user): void
    {
        $this->seedUsageEvent($user->id, $user->personalOrganization()->id, '2026-10-10', UsageEventType::FeatureViewed, ['feature' => 'lessons']);
        $this->rollup($user, '2026-10-10');
        $this->state($user, ['first_useful_record_on' => '2026-09-05', 'first_class_created_on' => '2026-09-02']);
    }

    /** @return array<string, mixed>|null */
    private function settingsSection(?User $user = null): ?array
    {
        $captured = null;

        $this->asTeacher($user)->get('/settings/profile')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$captured): void {
                $captured = $page->toArray()['props']['usageMetrics'] ?? null;
            });

        return $captured;
    }

    // --- A secção nas definições --------------------------------------------

    #[Test]
    public function the_setting_appears_once_collection_has_been_activated(): void
    {
        DB::table('usage_collection_periods')->delete();
        app()->forgetScopedInstances();
        $this->assertNull($this->settingsSection(), 'A secção apareceu antes de a Política descrever as métricas.');

        $this->startUsageCollectionAt('2026-10-12 09:00:00');
        app()->forgetScopedInstances();

        $this->assertSame(['opted_out' => false, 'opted_out_at' => null, 'resumed_at' => null, 'collecting' => true], $this->settingsSection());
    }

    #[Test]
    public function it_stays_after_collection_is_switched_off(): void
    {
        DB::table('usage_collection_periods')->update(['ended_at' => '2026-10-11 18:00:00', 'is_open' => null]);

        $this->assertFalse($this->settingsSection()['collecting']);
    }

    // --- Opor-se ------------------------------------------------------------

    #[Test]
    public function opting_out_stops_collection_and_erases_the_metrics_and_nothing_else(): void
    {
        $colleague = $this->person('Colega');
        $this->seedMetrics($this->teacher);
        $this->seedMetrics($colleague);
        $class = SchoolClass::factory()->recycle($this->teacher->personalOrganization())->create();
        $classes = SchoolClass::withoutGlobalScopes()->count();

        $this->asTeacher()->put('/settings/usage-metrics', ['opted_out' => true])->assertRedirect('/settings/profile');

        $this->teacher->refresh();
        $this->assertSame('2026-10-12 10:00:00', $this->teacher->usage_opted_out_at?->format('Y-m-d H:i:s'));
        $this->assertSame(0, UsageEvent::query()->where('user_id', $this->teacher->id)->count());
        $this->assertSame(0, UsageDailyRollup::query()->where('user_id', $this->teacher->id)->count());
        $this->assertSame(0, UsageUserState::query()->where('user_id', $this->teacher->id)->count());

        // Os de outra pessoa ficam.
        $this->assertSame(1, UsageEvent::query()->where('user_id', $colleague->id)->count());
        $this->assertSame(1, UsageUserState::query()->where('user_id', $colleague->id)->count());

        // Dados pedagógicos e acesso, iguais.
        $this->assertSame($classes, SchoolClass::withoutGlobalScopes()->count());
        $this->assertTrue(SchoolClass::withoutGlobalScopes()->whereKey($class->id)->exists());
        $this->asTeacher()->get('/dashboard')->assertOk();

        // A recolha parou para esta pessoa.
        app()->forgetScopedInstances();
        $this->asTeacher()->post('/_usage/opt-out/record')->assertOk();
        $this->assertSame(0, UsageEvent::query()->where('user_id', $this->teacher->id)->count());

        $this->assertTrue($this->settingsSection()['opted_out']);
    }

    #[Test]
    public function repeating_the_opt_out_keeps_its_date(): void
    {
        $this->asTeacher()->put('/settings/usage-metrics', ['opted_out' => true]);
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Europe/Lisbon'));
        $this->asTeacher()->put('/settings/usage-metrics', ['opted_out' => true]);

        $this->assertSame('2026-10-12 10:00:00', $this->teacher->refresh()->usage_opted_out_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function only_the_person_can_decide_it(): void
    {
        $this->asTeacher()->put('/settings/usage-metrics', [])->assertSessionHasErrors('opted_out');

        $this->asTeacher()->withSession(['impersonator_id' => $this->operator->id])
            ->put('/settings/usage-metrics', ['opted_out' => true])
            ->assertForbidden();
        $this->assertNull($this->teacher->refresh()->usage_opted_out_at);
    }

    #[Test]
    public function a_request_in_flight_when_the_person_objects_writes_nothing(): void
    {
        $this->asTeacher()->post('/_usage/opt-out/in-flight')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_request_in_flight_does_not_bring_history_back_after_opting_back_in(): void
    {
        $this->asTeacher()->post('/_usage/opt-out/in-flight', ['and_back' => true])->assertOk();

        // O evento era de antes da oposição; voltar a aceitar não o reconstitui.
        $this->assertSame(0, UsageEvent::query()->count());
        $this->assertSame('2026-10-12 10:02:00', $this->teacher->refresh()->usage_resumed_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_later_aggregation_does_not_reconstitute_anything(): void
    {
        $this->seedMetrics($this->teacher);
        app(SetUsageOptOut::class)->optOut($this->teacher);
        // Restos que uma passagem a decorrer em paralelo ainda podia ter lido.
        $this->seedUsageEvent($this->teacher->id, $this->teacher->personalOrganization()->id, '2026-10-12', UsageEventType::FeatureViewed, ['feature' => 'lessons']);

        app(AggregateUsage::class)->run(CarbonImmutable::parse('2026-10-12 10:15:00', 'Europe/Lisbon'));

        $this->assertSame(0, UsageDailyRollup::query()->where('user_id', $this->teacher->id)->count());
        $this->assertSame(0, UsageUserState::query()->where('user_id', $this->teacher->id)->count());
    }

    // --- Nunca inativa por se ter oposto -------------------------------------

    #[Test]
    public function someone_who_objected_is_in_no_number_and_never_looks_inactive(): void
    {
        $active = $this->person('Ativa');
        $this->state($active);
        $this->rollup($active, '2026-10-08', UsageFeature::Lessons, actions: 1);
        $without = collect($this->props('admin.usage.overview')['signals'])->map(fn (array $signal): array => [$signal['count'], $signal['not_evaluated']])->all();
        $withoutRanking = $this->props('admin.usage.ranking');

        // Uma pessoa com uma turma desde setembro e sem atividade recente —
        // que, sem a oposição, daria sinais — opõe-se.
        $this->seedMetrics($this->teacher);
        UsageUserState::query()->where('user_id', $this->teacher->id)->update(['class_available_since' => '2026-09-02 10:00:00', 'first_useful_record_on' => null]);
        app(SetUsageOptOut::class)->optOut($this->teacher);

        $users = $this->props('admin.usage.users.index')['users']['data'];
        $this->assertSame(['Ativa'], array_column($users, 'name'));

        $ranking = $this->props('admin.usage.ranking');
        $this->assertSame(['Ativa'], array_column($ranking['ranking']['data'], 'name'));
        $this->assertSame($withoutRanking['unranked'], $ranking['unranked'], 'Quem se opôs foi contado como «sem atividade».');

        $overview = $this->props('admin.usage.overview');
        $this->assertSame($without, collect($overview['signals'])->map(fn (array $signal): array => [$signal['count'], $signal['not_evaluated']])->all());
        $this->assertContains('1 pessoa opôs-se à recolha: não tem métricas e fica fora de todos os números, sem contar como inativa.', $overview['coverage']['notes']);
    }

    // --- Voltar a aceitar -----------------------------------------------------

    #[Test]
    public function opting_back_in_starts_from_then_without_history_and_with_its_own_coverage(): void
    {
        $this->seedMetrics($this->teacher);
        $this->travelTo(Carbon::parse('2026-10-01 09:00:00', 'Europe/Lisbon'));
        $this->asTeacher()->put('/settings/usage-metrics', ['opted_out' => true]);
        $this->travelTo(Carbon::parse('2026-10-08 14:00:00', 'Europe/Lisbon'));
        $this->asTeacher()->put('/settings/usage-metrics', ['opted_out' => false])->assertRedirect('/settings/profile');

        $this->teacher->refresh();
        $this->assertNull($this->teacher->usage_opted_out_at);
        $this->assertSame('2026-10-08 14:00:00', $this->teacher->usage_resumed_at?->format('Y-m-d H:i:s'));
        $this->assertSame(0, UsageEvent::query()->count(), 'Voltou histórico.');

        // A recolha volta a valer a partir daqui.
        app()->forgetScopedInstances();
        $this->asTeacher()->post('/_usage/opt-out/record')->assertOk();
        $this->assertSame(1, UsageEvent::query()->count());

        $this->travelTo(Carbon::parse(self::TODAY.' 10:00:00', 'Europe/Lisbon'));
        // Uma turma disponível desde setembro e nenhum registo útil observado
        // DEPOIS da retoma: o que aconteceu antes não se sabe — por avaliar,
        // nunca «turma sem primeiro registo».
        $this->state($this->teacher, ['class_available_since' => '2026-09-02 10:00:00']);

        $row = collect($this->props('admin.usage.users.index')['users']['data'])->firstWhere('name', 'Professora');
        $this->assertSame([], $row['signals']);
        $this->assertSame('Recolha retomada durante o período', $row['status']);
        // Observada de 08/10 a 12/10: cinco dias, não trinta.
        $this->assertSame(5, $row['observation_days']);

        $detail = $this->props('admin.usage.users.show', [], ['usageUser' => $row['ulid']]);
        $this->assertSame(['no_first_record', 'went_quiet'], array_column($detail['not_evaluated'], 'key'));
        $this->assertStringStartsWith('Recolha retomada a 08/10', (string) $detail['first_steps'][0]['note']);
        $weeks = collect($detail['weeks'])->keyBy('start');
        $this->assertSame('no_coverage', $weeks['2026-09-28']['status'], 'Uma semana antes da retoma aparece como observada.');
        $this->assertNull($weeks['2026-09-28']['active_days']);
        $this->assertSame('partial_coverage', $weeks['2026-10-05']['status']);

        $ranking = collect($this->props('admin.usage.ranking')['ranking']['data'])->firstWhere('name', 'Professora');
        $this->assertTrue($ranking === null || $ranking['partial_observation'] === true);
    }

    // --- Encerramento -------------------------------------------------------

    #[Test]
    public function a_closure_request_erases_the_metrics_at_once_and_cancelling_restores_nothing(): void
    {
        $this->seedMetrics($this->teacher);

        $this->asTeacher()->post('/settings/account-closure')->assertRedirect();

        $this->assertNotNull($this->teacher->refresh()->closure_requested_at);
        $this->assertSame(0, UsageEvent::query()->count());
        $this->assertSame(0, UsageDailyRollup::query()->count());
        $this->assertSame(0, UsageUserState::query()->count());
        // E não é registado nada enquanto o pedido existir.
        app()->forgetScopedInstances();
        $this->asTeacher()->post('/_usage/opt-out/record');
        $this->assertSame(0, UsageEvent::query()->count());

        $this->travelTo(Carbon::parse('2026-10-14 08:00:00', 'Europe/Lisbon'));
        $this->asTeacher()->delete('/settings/account-closure')->assertRedirect();

        $this->teacher->refresh();
        $this->assertNull($this->teacher->closure_requested_at);
        $this->assertSame(0, UsageDailyRollup::query()->count(), 'O cancelamento recuperou métricas.');
        $this->assertSame('2026-10-14 08:00:00', $this->teacher->usage_resumed_at?->format('Y-m-d H:i:s'));

        // A recolha recomeça a partir do cancelamento.
        app()->forgetScopedInstances();
        $this->asTeacher()->post('/_usage/opt-out/record')->assertOk();
        $this->assertSame(1, UsageEvent::query()->count());
    }

    #[Test]
    public function cancelling_a_closure_keeps_an_earlier_objection(): void
    {
        app(SetUsageOptOut::class)->optOut($this->teacher);
        $this->asTeacher()->post('/settings/account-closure')->assertRedirect();
        $this->asTeacher()->delete('/settings/account-closure')->assertRedirect();

        $this->teacher->refresh();
        $this->assertNotNull($this->teacher->usage_opted_out_at);
        $this->assertNull($this->teacher->usage_resumed_at);
    }
}
