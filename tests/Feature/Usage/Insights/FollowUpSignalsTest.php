<?php

namespace Tests\Feature\Usage\Insights;

use App\Models\Organization;
use App\Models\User;
use App\Support\Usage\UsageFeature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * Hoje é segunda-feira 2026-10-12. As regras estão em docs/usage-insights.md,
 * «Sinais de acompanhamento».
 */
class FollowUpSignalsTest extends InsightsTestCase
{
    /**
     * Regista dias não letivos no calendário de uma organização.
     */
    private function calendarBreak(Organization $organization, string $from, string $to, string $type = 'school_break'): void
    {
        $yearId = DB::table('academic_years')->where('organization_id', $organization->id)->value('id')
            ?? DB::table('academic_years')->insertGetId([
                'ulid' => (string) Str::ulid(),
                'organization_id' => $organization->id,
                'label' => '2026/2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-08-31',
                'status' => 'active',
                'country_code' => 'PT',
                'created_at' => '2026-09-01 00:00:00',
                'updated_at' => '2026-09-01 00:00:00',
            ]);

        DB::table('academic_calendar_exceptions')->insert([
            'ulid' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'academic_year_id' => $yearId,
            'type' => $type,
            'title' => 'Interrupção',
            'starts_on' => $from,
            'ends_on' => $to,
            'source' => 'manual',
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    private function signalsOf(User $user): array
    {
        $rows = $this->props('admin.usage.users.index', ['q' => $user->name])['users']['data'];
        $this->assertCount(1, $rows, "{$user->name} devia estar na lista");

        return $rows[0]['signals'];
    }

    private function keysOf(User $user): array
    {
        return array_column($this->signalsOf($user), 'key');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function summary(): array
    {
        return collect($this->props('admin.usage.overview')['signals'])->keyBy('key')->all();
    }

    // --- Conta sem turma -------------------------------------------------

    #[Test]
    public function an_account_without_a_class_for_seven_days_gets_the_signal_with_its_reason(): void
    {
        $user = $this->person('Sem Turma', '2026-10-03 10:00:00');
        $this->state($user);

        $signal = $this->signalsOf($user)[0];

        $this->assertSame('no_class', $signal['key']);
        $this->assertSame('Conta sem turma', $signal['label']);
        $this->assertSame('Conta criada a 03/10 e sem nenhuma turma disponível há 9 dias (dias de calendário).', $signal['reason']);
        $this->assertSame('2026-10-03', $signal['window_from']);
        $this->assertSame('2026-10-12', $signal['window_to']);
    }

    #[Test]
    public function the_no_class_threshold_is_seven_days_and_a_class_available_removes_it(): void
    {
        $exactlySeven = $this->person('Sete Dias', '2026-10-05 10:00:00');
        $sixDays = $this->person('Seis Dias', '2026-10-06 10:00:00');
        $withClass = $this->person('Com Turma', '2026-09-10 10:00:00');
        $this->state($exactlySeven);
        $this->state($sixDays);
        $this->state($withClass, ['class_available_since' => '2026-09-11 10:00:00', 'first_useful_record_on' => '2026-09-12']);

        $this->assertSame(['no_class'], $this->keysOf($exactlySeven));
        $this->assertSame([], $this->keysOf($sixDays));
        $this->assertSame([], $this->keysOf($withClass));
    }

    #[Test]
    public function no_class_does_not_need_collection_coverage(): void
    {
        // A recolha só começou ontem: o sinal lê o estado das turmas, não os eventos.
        $this->startUsageCollectionAt('2026-10-11 08:00:00');
        $user = $this->person('Sem Turma Nem Recolha', '2026-09-20 10:00:00');
        $this->state($user);

        $this->assertSame(['no_class'], $this->keysOf($user));
    }

    #[Test]
    public function a_recorded_school_break_extends_the_deadline(): void
    {
        // Criada há 8 dias, com 3 de interrupção: 5 dias efetivos, ainda cedo.
        $user = $this->person('Interrupção Longa', '2026-10-04 10:00:00');
        $this->state($user);
        $this->calendarBreak($this->organizationOf($user), '2026-10-05', '2026-10-07');

        $this->assertSame([], $this->keysOf($user));
    }

    #[Test]
    public function the_reason_names_the_non_teaching_days_that_were_not_counted(): void
    {
        $user = $this->person('Dois Feriados', '2026-10-02 10:00:00');
        $this->state($user);
        $this->calendarBreak($this->organizationOf($user), '2026-10-05', '2026-10-05', 'holiday');
        $this->calendarBreak($this->organizationOf($user), '2026-10-06', '2026-10-06', 'non_teaching_day');

        $signal = $this->signalsOf($user)[0];

        $this->assertSame('Conta criada a 02/10 e sem nenhuma turma disponível há 8 dias (sem contar 2 dias não letivos).', $signal['reason']);
    }

    #[Test]
    public function a_day_off_in_any_of_the_persons_organizations_does_not_count(): void
    {
        $user = $this->person('Duas Escolas', '2026-10-04 10:00:00');
        $this->state($user);
        $school = Organization::factory()->institutional()->withMember($user)->create();
        $this->calendarBreak($school, '2026-10-05', '2026-10-07');

        $this->assertSame([], $this->keysOf($user));
    }

    #[Test]
    public function other_kinds_of_exception_that_are_not_non_teaching_do_not_extend_the_deadline(): void
    {
        $user = $this->person('Exceção Alheia', '2026-10-04 10:00:00');
        $this->state($user);
        $this->calendarBreak($this->organizationOf($user), '2026-10-05', '2026-10-07', 'meeting');

        $this->assertSame(['no_class'], $this->keysOf($user));
    }

    // --- Turma sem primeiro registo útil ---------------------------------

    #[Test]
    public function a_class_available_for_seven_days_without_a_useful_record_gets_the_signal(): void
    {
        $user = $this->person('Sem Registo', '2026-09-10 10:00:00');
        $this->state($user, ['class_available_since' => '2026-09-28 10:00:00']);

        $signal = $this->signalsOf($user)[0];

        $this->assertSame('no_first_record', $signal['key']);
        $this->assertSame('Turma disponível desde 28/09 e nenhum registo útil observado desde então (14 dias de calendário).', $signal['reason']);
        $this->assertSame('2026-09-28', $signal['window_from']);
    }

    #[Test]
    public function the_no_first_record_reason_mentions_non_teaching_days(): void
    {
        $user = $this->person('Sem Registo Com Pausa', '2026-09-10 10:00:00');
        $this->state($user, ['class_available_since' => '2026-09-28 10:00:00']);
        $this->calendarBreak($this->organizationOf($user), '2026-10-05', '2026-10-06');

        $this->assertSame(
            'Turma disponível desde 28/09 e nenhum registo útil observado desde então (12 dias, sem contar 2 dias não letivos).',
            $this->signalsOf($user)[0]['reason'],
        );
    }

    #[Test]
    public function no_first_record_is_not_raised_when_there_is_a_record_or_it_is_too_soon(): void
    {
        $withRecord = $this->person('Com Registo', '2026-09-10 10:00:00');
        $tooSoon = $this->person('Cedo Demais', '2026-09-10 10:00:00');
        $this->state($withRecord, ['class_available_since' => '2026-09-20 10:00:00', 'first_useful_record_on' => '2026-09-25']);
        $this->state($tooSoon, ['class_available_since' => '2026-10-08 10:00:00']);

        $this->assertSame([], $this->keysOf($withRecord));
        $this->assertSame([], $this->keysOf($tooSoon));
    }

    #[Test]
    public function without_coverage_since_the_class_appeared_it_is_not_evaluated_instead_of_a_signal(): void
    {
        // A turma ficou disponível ANTES de a recolha começar: não se sabe o que aconteceu entre as duas coisas.
        $this->startUsageCollectionAt('2026-10-01 14:00:00');
        $user = $this->person('Antes da Recolha', '2026-09-10 10:00:00');
        $this->state($user, ['class_available_since' => '2026-09-28 10:00:00']);

        $this->assertSame([], $this->keysOf($user));
        $this->assertSame(0, $this->summary()['no_first_record']['count']);
        $this->assertSame(1, $this->summary()['no_first_record']['not_evaluated']);
    }

    #[Test]
    public function a_suspension_in_the_middle_also_makes_it_not_evaluated(): void
    {
        $this->startUsageCollectionAt('2026-09-01 00:00:00', '2026-10-02 00:00:00');
        DB::table('usage_collection_periods')->insert(['started_at' => '2026-10-05 00:00:00', 'ended_at' => null]);

        $user = $this->person('Com Buraco', '2026-09-10 10:00:00');
        $this->state($user, ['class_available_since' => '2026-09-20 10:00:00']);

        $this->assertSame([], $this->keysOf($user));
        $this->assertSame(1, $this->summary()['no_first_record']['not_evaluated']);
    }

    // --- Sem atividade recente -------------------------------------------

    private function quietUser(string $name = 'Calou-se'): User
    {
        $user = $this->person($name, '2026-07-01 10:00:00');
        $this->state($user, ['class_available_since' => '2026-07-02 10:00:00', 'first_useful_record_on' => '2026-07-05']);
        $this->rollup($user, '2026-09-01');
        $this->rollup($user, '2026-09-08');
        $this->rollup($user, '2026-09-16');

        return $user;
    }

    #[Test]
    public function activity_in_three_of_four_weeks_and_none_in_the_last_fourteen_days_gets_the_signal(): void
    {
        $this->startUsageCollectionAt('2026-08-01 00:00:00');
        $user = $this->quietUser();

        $signal = $this->signalsOf($user)[0];

        $this->assertSame('went_quiet', $signal['key']);
        $this->assertSame('Sem atividade recente', $signal['label']);
        $this->assertSame('Atividade em 3 das 4 semanas de 31/08 a 27/09 e nenhuma nos últimos 14 dias (desde 29/09).', $signal['reason']);
        $this->assertSame('2026-08-31', $signal['window_from']);
        $this->assertSame('2026-10-12', $signal['window_to']);
    }

    #[Test]
    public function recent_activity_or_too_few_active_weeks_means_no_signal(): void
    {
        $this->startUsageCollectionAt('2026-08-01 00:00:00');

        $recent = $this->quietUser('Voltou');
        $this->rollup($recent, '2026-10-05');

        $fewWeeks = $this->person('Pouca Atividade', '2026-07-01 10:00:00');
        $this->state($fewWeeks, ['class_available_since' => '2026-07-02 10:00:00', 'first_useful_record_on' => '2026-07-05']);
        $this->rollup($fewWeeks, '2026-09-02');

        $this->assertSame([], $this->keysOf($recent));
        $this->assertSame([], $this->keysOf($fewWeeks));
    }

    #[Test]
    public function a_failure_alone_does_not_count_as_activity_for_the_quiet_window(): void
    {
        $this->startUsageCollectionAt('2026-08-01 00:00:00');
        $user = $this->quietUser('Só Falhou');
        $this->rollup($user, '2026-10-08', views: 0, failures: 1);

        $this->assertSame(['went_quiet'], $this->keysOf($user));
    }

    #[Test]
    public function without_coverage_over_the_four_weeks_it_is_not_evaluated(): void
    {
        $this->startUsageCollectionAt('2026-09-10 00:00:00');
        $user = $this->quietUser();

        $this->assertSame([], $this->keysOf($user));
        $this->assertSame(0, $this->summary()['went_quiet']['count']);
        $this->assertSame(1, $this->summary()['went_quiet']['not_evaluated']);
    }

    #[Test]
    public function non_teaching_days_push_the_quiet_window_back(): void
    {
        $this->startUsageCollectionAt('2026-08-01 00:00:00');
        $user = $this->person('Pausa Letiva', '2026-07-01 10:00:00');
        $this->state($user, ['class_available_since' => '2026-07-02 10:00:00', 'first_useful_record_on' => '2026-07-05']);
        $this->rollup($user, '2026-08-26');
        $this->rollup($user, '2026-09-02');
        $this->rollup($user, '2026-09-10');
        $this->calendarBreak($this->organizationOf($user), '2026-10-01', '2026-10-02');

        $this->assertSame(
            'Atividade em 3 das 4 semanas de 24/08 a 20/09 e nenhuma nos últimos 14 dias (desde 27/09, sem contar 2 dias não letivos).',
            $this->signalsOf($user)[0]['reason'],
        );
    }

    #[Test]
    public function an_account_too_new_to_have_had_a_reference_week_is_neither_a_signal_nor_pending(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $user = $this->person('Muito Nova', '2026-10-02 10:00:00');
        $this->state($user, ['class_available_since' => '2026-10-02 12:00:00', 'first_useful_record_on' => '2026-10-03']);

        $this->assertSame([], $this->keysOf($user));
        $this->assertSame(0, $this->summary()['went_quiet']['not_evaluated']);
    }

    // --- Falhas técnicas repetidas ---------------------------------------

    #[Test]
    public function three_technical_failures_in_seven_days_get_the_signal_with_a_breakdown(): void
    {
        $user = $this->person('Com Falhas', '2026-09-01 10:00:00');
        $this->state($user, ['class_available_since' => '2026-09-02 10:00:00', 'first_useful_record_on' => '2026-09-05']);
        $this->rollup($user, '2026-10-12', UsageFeature::Lessons);
        $this->rollup($user, '2026-10-08', UsageFeature::Lessons, views: 0, failures: 2);
        $this->rollup($user, '2026-10-10', UsageFeature::Reports, views: 0, failures: 1);

        $signal = $this->signalsOf($user)[0];

        $this->assertSame('technical_failures', $signal['key']);
        $this->assertSame('3 falhas técnicas entre 06/10 e 12/10 (Aulas e sumários: 2, Relatórios: 1).', $signal['reason']);
        $this->assertSame('2026-10-06', $signal['window_from']);
        $this->assertSame('2026-10-12', $signal['window_to']);
    }

    #[Test]
    public function failures_below_the_threshold_or_outside_the_window_do_not_count(): void
    {
        $few = $this->person('Duas Falhas', '2026-09-01 10:00:00');
        $old = $this->person('Falhas Antigas', '2026-09-01 10:00:00');
        foreach ([$few, $old] as $user) {
            $this->state($user, ['class_available_since' => '2026-09-02 10:00:00', 'first_useful_record_on' => '2026-09-05']);
            $this->rollup($user, '2026-10-12');
        }
        $this->rollup($few, '2026-10-10', views: 0, failures: 2);
        $this->rollup($old, '2026-10-05', views: 0, failures: 5);

        $this->assertSame([], $this->keysOf($few));
        $this->assertSame([], $this->keysOf($old));
    }

    #[Test]
    public function failures_do_not_need_full_coverage_but_the_reason_says_when_the_collection_began(): void
    {
        $this->startUsageCollectionAt('2026-10-09 08:00:00');
        $user = $this->person('Falhas Na Recolha', '2026-09-01 10:00:00');
        $this->state($user, ['class_available_since' => '2026-09-02 10:00:00', 'first_useful_record_on' => '2026-09-05']);
        $this->rollup($user, '2026-10-10', UsageFeature::Assessment, views: 0, failures: 3);
        $this->rollup($user, '2026-10-12');

        $signal = $this->signalsOf($user)[0];

        $this->assertSame('3 falhas técnicas desde 09/10 (início da recolha) até 12/10 (Avaliação: 3).', $signal['reason']);
        $this->assertSame('2026-10-09', $signal['window_from']);
    }

    // --- Quem não gera sinais, e o resumo --------------------------------

    #[Test]
    public function accounts_being_closed_deactivated_or_anonymised_neither_signal_nor_pend(): void
    {
        $this->startUsageCollectionAt('2026-10-01 14:00:00');

        $closing = $this->person('Em Encerramento', '2026-08-01 10:00:00');
        $deactivated = $this->person('Desativada', '2026-08-01 10:00:00');
        $anonymised = $this->person('Anonimizada', '2026-08-01 10:00:00');
        foreach ([$closing, $deactivated, $anonymised] as $user) {
            $this->state($user, ['class_available_since' => '2026-08-02 10:00:00']);
        }
        DB::table('users')->where('id', $closing->id)->update(['closure_requested_at' => '2026-10-01 10:00:00']);
        DB::table('users')->where('id', $deactivated->id)->update(['deactivated_at' => '2026-10-01 10:00:00']);
        DB::table('users')->where('id', $anonymised->id)->update(['anonymized_at' => '2026-10-01 10:00:00']);

        $normal = $this->person('Normal', '2026-08-01 10:00:00');
        $this->state($normal, ['class_available_since' => '2026-08-02 10:00:00']);

        $this->assertSame([], $this->keysOf($closing));
        $this->assertSame([], $this->keysOf($deactivated));
        // Só a conta normal, que seria avaliada, fica «por avaliar».
        $this->assertSame(1, $this->summary()['no_first_record']['not_evaluated']);
    }

    #[Test]
    public function the_overview_counts_signals_and_describes_the_rules(): void
    {
        $one = $this->person('Sem Turma Um', '2026-10-01 10:00:00');
        $two = $this->person('Sem Turma Dois', '2026-09-20 10:00:00');
        $this->state($one);
        $this->state($two);

        $summary = $this->summary();

        $this->assertSame(2, $summary['no_class']['count']);
        $this->assertSame('Conta sem turma', $summary['no_class']['label']);
        $this->assertSame('Conta criada há pelo menos 7 dias e sem nenhuma turma disponível.', $summary['no_class']['description']);
        $this->assertSame('Atividade em pelo menos 2 das 4 semanas completas anteriores e nenhuma nos últimos 14 dias.', $summary['went_quiet']['description']);
        $this->assertSame('Pelo menos 3 falhas técnicas nos últimos 7 dias.', $summary['technical_failures']['description']);
        $this->assertSame(0, $summary['technical_failures']['count']);
    }

    #[Test]
    public function a_rule_that_is_switched_off_in_the_config_produces_nothing(): void
    {
        config(['usage.signals.no_class.enabled' => false]);
        $user = $this->person('Sem Turma', '2026-09-20 10:00:00');
        $this->state($user);

        $this->assertSame([], $this->keysOf($user));
        $this->assertArrayNotHasKey('no_class', $this->summary());
    }

    #[Test]
    public function the_thresholds_come_from_the_config(): void
    {
        $user = $this->person('Prazo Maior', '2026-09-20 10:00:00');
        $this->state($user);

        config(['usage.signals.no_class.days' => 30]);
        $this->assertSame([], $this->keysOf($user));

        config(['usage.signals.no_class.days' => 23]);
        $this->assertSame([], $this->keysOf($user));

        config(['usage.signals.no_class.days' => 22]);
        $this->assertSame(['no_class'], $this->keysOf($user));
    }
}
