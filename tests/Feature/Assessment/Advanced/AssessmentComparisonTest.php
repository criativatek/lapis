<?php

namespace Tests\Feature\Assessment\Advanced;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\Module;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\Advanced\AdvancedAnalyticsUnavailable;
use App\Services\Assessment\Advanced\BuildAssessmentComparison;
use App\Services\Assessment\Advanced\BuildAssessmentTimeline;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Entitlements\Entitlements;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * D1 — Comparar dois momentos de uma turma, sobre a turma REAL da demo (duas épocas, ambas com
 * resultados): o serviço e a página.
 *
 * O QUE ESTES TESTES DEFENDEM: que a comparação é a linha temporal e os números de sempre, lidos
 * lado a lado (paridade com a progressão e com a Estatística); que nunca se subtrai o que não se
 * demonstra comparável; que um resultado em falta não é zero; que é Pro até ao serviço e a página
 * recusa o que o Base não tem; e que nada privado viaja nem o custo cresce com a turma.
 */
class AssessmentComparisonTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));
        $this->subscribe('pro');

        $this->asTenant(function (): void {
            $class = $this->class();
            foreach ($class->academicYear->periods()->orderBy('sequence')->get() as $period) {
                app(ProposeClassifications::class)->forPeriod($class, $period);
            }
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function subscribe(string $plan, SubscriptionStatus $status = SubscriptionStatus::Active): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->teacher->personalOrganization()->id],
            ['plan_id' => Plan::where('key', $plan)->firstOrFail()->id, 'status' => $status, 'starts_at' => Carbon::parse('2026-01-01 00:00:00'), 'ends_at' => null],
        );
        app(Entitlements::class)->flush();
    }

    private function class(): SchoolClass
    {
        return SchoolClass::where('label', '7.º A')->with('profileVersion')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function timeline(): array
    {
        return $this->asTenant(fn (): array => app(BuildAssessmentTimeline::class)->for($this->class()));
    }

    /**
     * @param  array<string, mixed>|null  $timeline
     * @return array<string, mixed>
     */
    private function compare(?string $from = null, ?string $to = null, ?array $timeline = null): array
    {
        return $this->asTenant(fn (): array => app(BuildAssessmentComparison::class)->between($timeline ?? $this->timeline(), $from, $to));
    }

    /** @return array<string, mixed> */
    private function progression(): array
    {
        return $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->class()));
    }

    /** @return array{string, string} the keys of the two periods */
    private function periodKeys(): array
    {
        return $this->asTenant(function (): array {
            $periods = $this->class()->academicYear->periods()->orderBy('sequence')->get();

            return ['period:'.$periods[0]->ulid, 'period:'.$periods[1]->ulid];
        });
    }

    private function decide(string $name, string $code, int $sequence): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid, $levelId] = $this->asTenant(function () use ($name, $code, $sequence): array {
            $class = $this->class();

            return [
                $class->ulid,
                $class->academicYear->periods()->where('sequence', $sequence)->firstOrFail()->ulid,
                $class->enrollments()->with('student.identity')->get()
                    ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === $name)->ulid,
                $class->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id,
            ];
        });

        $this->actingAs($this->teacher)->post("/classes/{$classUlid}/classifications/{$periodUlid}/{$enrollmentUlid}/decide", ['final_scale_level_id' => $levelId])
            ->assertSessionHasNoErrors()->assertRedirect();
    }

    private function keep(string $label): string
    {
        [$classUlid, $periodUlid] = $this->asTenant(fn (): array => [$this->class()->ulid, $this->class()->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid]);

        $this->actingAs($this->teacher)->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
            'moment_label' => $label, 'effective_at' => '2026-12-15', 'scope' => ClassificationScope::Period->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return $this->asTenant(fn (): string => EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail()->ulid);
    }

    private function rewrite(string $exportUlid, callable $change): void
    {
        $this->asTenant(function () use ($exportUlid, $change): void {
            $export = EvaluationSheetExport::where('ulid', $exportUlid)->firstOrFail();
            $payload = $change($export->payload);

            DB::table('evaluation_sheet_exports')->where('id', $export->id)->update([
                'payload' => json_encode($payload),
                'payload_hash' => CanonicalPayload::hash($payload),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $comparison
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $comparison): array
    {
        $byName = [];
        foreach ($comparison['students'] as $student) {
            $byName[$student['name']] = $student;
        }

        return $byName;
    }

    // ================================================================= seleção

    #[Test]
    public function by_default_it_compares_the_latest_moment_with_the_nearest_earlier_one_it_may_be_compared_with(): void
    {
        [$first, $second] = $this->periodKeys();
        $comparison = $this->compare();

        $this->assertSame('ready', $comparison['state']);
        $this->assertSame($first, $comparison['from']['key']);
        $this->assertSame($second, $comparison['to']['key']);
        $this->assertSame(['from' => $first, 'to' => $second, 'defaulted' => true, 'swapped' => false], $comparison['selection']);
        $this->assertSame([$first, $second], array_column($comparison['options'], 'key'));
    }

    #[Test]
    public function the_default_skips_back_over_a_moment_under_other_criteria_and_says_why_when_none_is_comparable(): void
    {
        [$first, $second] = $this->periodKeys();

        // A photograph under other weights sits between the two periods: the default goes past it.
        $moment = $this->keep('Outros pesos');
        $this->rewrite($moment, function (array $payload): array {
            $payload['domains'][0]['weight_percent'] = '99.0000';

            return $payload;
        });

        $comparison = $this->compare();
        $this->assertSame($first, $comparison['from']['key']);
        $this->assertSame($second, $comparison['to']['key']);

        // Explicitly asked for, it is refused with the reason — never silently swapped for another.
        $refused = $this->compare('moment:'.$moment, $second);
        $this->assertFalse($refused['selection']['defaulted']);
        $this->assertFalse($refused['comparability']['percentages']);
        $this->assertSame(['criteria_differ'], array_column($refused['comparability']['reasons'], 'code'));
    }

    #[Test]
    public function an_explicit_choice_is_honoured_ordered_in_time_and_an_invalid_one_falls_back(): void
    {
        [$first, $second] = $this->periodKeys();

        $reversed = $this->compare($second, $first);
        $this->assertSame([$first, $second], [$reversed['from']['key'], $reversed['to']['key']]);
        $this->assertTrue($reversed['selection']['swapped']);
        $this->assertFalse($reversed['selection']['defaulted']);

        foreach ([[$first, $first], ['period:'.str_repeat('0', 26), $second], [null, $second], ['lixo', 'lixo']] as [$from, $to]) {
            $fallback = $this->compare($from, $to);

            $this->assertTrue($fallback['selection']['defaulted'], json_encode([$from, $to]));
            $this->assertSame([$first, $second], [$fallback['from']['key'], $fallback['to']['key']]);
        }
    }

    #[Test]
    public function with_fewer_than_two_moments_with_results_it_says_so_instead_of_comparing(): void
    {
        $timeline = $this->timeline();
        $timeline['points'][0]['has_results'] = false;
        $timeline['availability']['reason'] = 'Só existe 1 momento com resultados: a comparação precisa de pelo menos 2.';

        $comparison = $this->compare(timeline: $timeline);

        $this->assertSame('unavailable', $comparison['state']);
        $this->assertSame($timeline['availability']['reason'], $comparison['reason']);
        $this->assertNull($comparison['class']);
        $this->assertSame([], $comparison['students']);
        $this->assertCount(1, $comparison['options']);
    }

    // ================================================================= os números são os de sempre

    #[Test]
    public function each_moments_class_mean_is_the_one_estatistica_shows(): void
    {
        $comparison = $this->compare();

        foreach ([1 => 'from', 2 => 'to'] as $sequence => $end) {
            $mean = $this->asTenant(function () use ($sequence): ?string {
                $class = $this->class();
                $period = $class->academicYear->periods()->where('sequence', $sequence)->firstOrFail();

                return app(BuildClassStatistics::class)->for($class, $period)['summary']['class_average'];
            });

            $this->assertNotNull($mean);
            $this->assertSame($mean, $comparison['class']['moments'][$end]['mean'], "period {$sequence}");
        }
    }

    #[Test]
    public function the_counts_and_the_movement_of_every_student_are_the_progressions_evolution(): void
    {
        $comparison = $this->compare();
        $progression = $this->progression();
        $expected = ['up' => 0, 'down' => 0, 'flat' => 0];
        $withoutEvolution = 0;
        $byName = $this->byName($comparison);

        foreach ($progression['students'] as $student) {
            $evolution = $student['periods'][1]['evolution'];
            $own = $byName[$student['name']]['movement'];

            if ($evolution === null) {
                $withoutEvolution++;
                $this->assertSame('unavailable', $own['state'], $student['name']);

                continue;
            }

            $expected[$evolution['direction']]++;
            $this->assertSame($evolution['direction'], $own['state'], $student['name']);
            $this->assertSame($evolution['points'], $own['points'], $student['name']);
        }

        $counts = $comparison['class']['counts'];
        $this->assertSame(['up' => $expected['up'], 'flat' => $expected['flat'], 'down' => $expected['down'], 'compared' => array_sum($expected)], $counts);
        $this->assertGreaterThan(0, $counts['compared'], 'the check is not vacuous');
        $this->assertSame($withoutEvolution, array_sum($comparison['class']['not_compared']));
        $this->assertSame(count($progression['students']), $counts['compared'] + $withoutEvolution);
    }

    #[Test]
    public function the_domain_counts_are_the_progressions_domain_evolutions(): void
    {
        $comparison = $this->compare();
        $progression = $this->progression();
        $domainUlid = collect($progression['domains'])->pluck('ulid', 'id')->all();

        $expected = [];
        foreach ($progression['students'] as $student) {
            foreach ($student['periods'][1]['domains'] as $row) {
                $direction = $row['evolution']['direction'] ?? null;
                if ($direction !== null) {
                    $expected[$domainUlid[$row['domain_id']]][$direction] = ($expected[$domainUlid[$row['domain_id']]][$direction] ?? 0) + 1;
                }
            }
        }

        $this->assertNotEmpty($expected);
        foreach ($comparison['domains'] as $domain) {
            $counts = $domain['counts'];
            $own = $expected[$domain['ulid']] ?? [];

            $this->assertSame($own['up'] ?? 0, $counts['up'], $domain['name']);
            $this->assertSame($own['flat'] ?? 0, $counts['flat'], $domain['name']);
            $this->assertSame($own['down'] ?? 0, $counts['down'], $domain['name']);
        }
    }

    #[Test]
    public function the_class_evolution_is_the_mean_of_the_same_students_at_both_ends(): void
    {
        $comparison = $this->compare();
        $paired = array_values(array_filter($comparison['students'], fn (array $student): bool => $student['from']['value'] !== null && $student['to']['value'] !== null));

        // The demo's first period has results for four students; the second for six: four are paired.
        $this->assertCount(4, $paired);
        $this->assertSame(4, $comparison['class']['paired']['students']);
        $this->assertSame(4, $comparison['class']['moments']['from']['students']);
        $this->assertSame(6, $comparison['class']['moments']['to']['students']);

        $mean = fn (array $values): string => number_format(array_sum($values) / count($values), 1, '.', '');

        $this->assertEqualsWithDelta((float) $mean(array_map(fn (array $student): float => (float) $student['from']['value'], $paired)), (float) $comparison['class']['paired']['from_mean'], 0.051);
        $this->assertEqualsWithDelta((float) $mean(array_map(fn (array $student): float => (float) $student['to']['value'], $paired)), (float) $comparison['class']['paired']['to_mean'], 0.051);

        // The change of the class IS the rule of movement applied to those two means.
        $change = $comparison['class']['paired']['change'];
        $this->assertSame(
            BuildResultsProgression::movement($comparison['class']['paired']['from_mean'], $comparison['class']['paired']['to_mean']),
            $change,
        );
        // And the unpaired mean of the second moment is NOT the paired one (two more students are in it).
        $this->assertNotSame($comparison['class']['moments']['to']['mean'], $comparison['class']['paired']['to_mean']);
    }

    // ================================================================= sem comparação artificial

    #[Test]
    public function a_result_at_one_end_only_is_listed_and_never_counted_as_zero(): void
    {
        $comparison = $this->compare();
        $missing = array_values(array_filter($comparison['students'], fn (array $student): bool => $student['from']['value'] === null));

        $this->assertCount(2, $missing);
        foreach ($missing as $student) {
            $this->assertNotNull($student['to']['value']);
            $this->assertSame(['state' => 'unavailable', 'points' => null, 'reason' => 'missing_from'], $student['movement']);
        }

        $this->assertSame(2, $comparison['class']['not_compared']['missing_from']);
        $this->assertSame(0, $comparison['class']['not_compared']['missing_to']);
        $this->assertSame(4, $comparison['class']['counts']['compared']);
    }

    #[Test]
    public function under_other_criteria_the_numbers_stand_side_by_side_and_nothing_is_subtracted(): void
    {
        [, $second] = $this->periodKeys();
        $moment = $this->keep('Outros pesos');
        $this->rewrite($moment, function (array $payload): array {
            $payload['domains'][0]['weight_percent'] = '99.0000';

            return $payload;
        });

        $comparison = $this->compare('moment:'.$moment, $second);

        $this->assertFalse($comparison['comparability']['percentages']);
        $this->assertNull($comparison['class']['counts']);
        $this->assertNull($comparison['class']['paired']['change']);
        foreach ($comparison['domains'] as $domain) {
            $this->assertNull($domain['counts']);
            $this->assertNull($domain['paired']['change']);
        }
        foreach ($comparison['students'] as $student) {
            $this->assertNull($student['movement']['points']);
            $this->assertContains($student['movement']['reason'], ['not_comparable', 'missing_from', 'missing_to', 'missing_both']);
        }

        // Each side is still shown, with its own mean and coverage.
        $this->assertNotNull($comparison['class']['moments']['from']['mean']);
        $this->assertNotNull($comparison['class']['moments']['to']['mean']);
        // The levels are a separate question (the scale is the same): still compared.
        $this->assertTrue($comparison['levels']['comparable']);
    }

    #[Test]
    public function a_scale_that_is_not_demonstrably_the_same_refuses_the_levels_and_keeps_the_percentages(): void
    {
        [, $second] = $this->periodKeys();
        $moment = $this->keep('Antes da escala viajar');
        $this->rewrite($moment, function (array $payload): array {
            unset($payload['scale']);
            $payload['version'] = 2;

            return $payload;
        });

        $comparison = $this->compare('moment:'.$moment, $second);

        $this->assertTrue($comparison['comparability']['percentages']);
        $this->assertFalse($comparison['levels']['comparable']);
        $this->assertSame(['scale_unknown'], array_column($comparison['levels']['reasons'], 'code'));
        $this->assertNull($comparison['levels']['result']);
        $this->assertNull($comparison['levels']['assigned']);
        $this->assertNotNull($comparison['class']['counts'], 'the percentages are still compared');
        foreach ($comparison['students'] as $student) {
            $this->assertSame('unavailable', $student['result_level']['state']);
        }
    }

    // ================================================================= níveis

    #[Test]
    public function the_assigned_levels_are_compared_on_the_scale_and_the_crossing_of_the_line_is_counted(): void
    {
        $this->decide('Carolina Nunes', '2', 1);
        $this->decide('Carolina Nunes', '4', 2);
        $this->decide('Ana Marques', '4', 1);
        $this->decide('Ana Marques', '3', 2);

        $comparison = $this->compare();
        $byName = $this->byName($comparison);

        $up = $byName['Carolina Nunes']['assigned']['change'];
        $this->assertSame('up', $up['state']);
        $this->assertSame('to_positive', $up['crossed_line']);
        $this->assertSame(['2', '4'], [$up['from']['code'], $up['to']['code']]);

        $down = $byName['Ana Marques']['assigned']['change'];
        $this->assertSame('down', $down['state']);
        $this->assertNull($down['crossed_line'], '4 → 3 stays on the positive side');

        $assigned = $comparison['levels']['assigned'];
        $this->assertSame(1, $assigned['counts']['up']);
        $this->assertSame(1, $assigned['counts']['down']);
        $this->assertSame(1, $assigned['crossed']['to_positive']);
        $this->assertSame(0, $assigned['crossed']['to_negative']);

        $row = collect($assigned['distribution'])->keyBy('code');
        $this->assertSame(1, $row['2']['from']);
        $this->assertSame(1, $row['4']['to']);
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($assigned['distribution'], 'code'), 'in the scale\'s own order');
    }

    #[Test]
    public function the_result_level_change_is_read_from_the_bands_of_the_two_results(): void
    {
        $comparison = $this->compare();
        $summary = $comparison['levels']['result'];
        $counted = 0;

        foreach ($comparison['students'] as $student) {
            $from = $student['from']['band'];
            $to = $student['to']['band'];

            if ($from === null || $to === null) {
                $this->assertSame('unavailable', $student['result_level']['state']);

                continue;
            }

            $counted++;
            $this->assertSame($to['sequence'] <=> $from['sequence'], ['down' => -1, 'same' => 0, 'up' => 1][$student['result_level']['state']]);
        }

        $this->assertSame($counted, $summary['counts']['compared']);
        $this->assertSame($counted, $summary['counts']['up'] + $summary['counts']['same'] + $summary['counts']['down']);
    }

    // ================================================================= a origem dos dados e as cautelas

    #[Test]
    public function the_origin_of_each_moment_and_the_limits_of_the_comparison_are_said(): void
    {
        $comparison = $this->compare();

        $this->assertSame('Calculado com os dados de hoje', $comparison['from']['basis']['label']);
        $this->assertStringContainsString('calculados com os dados de hoje', $comparison['basis_note']);
        $this->assertSame(4, $comparison['from']['students_with_result']);
        $this->assertSame(6, $comparison['from']['students_total']);
        $this->assertSame(['few_students', 'few_students_point'], array_column($comparison['cautions'], 'code'));

        $moment = $this->keep('Momento de dezembro');
        [, $second] = $this->periodKeys();
        $mixed = $this->compare('moment:'.$moment, $second);

        $this->assertSame('Guardado a 15/12/2026', $mixed['from']['basis']['label']);
        $this->assertStringContainsString('inclui as correções feitas entretanto', $mixed['basis_note']);

        $other = $this->keep('Outro momento');
        $frozen = $this->compare('moment:'.$moment, 'moment:'.$other);
        $this->assertStringContainsString('fotografias guardadas', $frozen['basis_note']);
    }

    #[Test]
    public function the_service_explains_the_calculations_in_plain_words(): void
    {
        $keys = array_column($this->compare()['explanations'], 'key');

        $this->assertSame(['movement', 'points', 'class', 'domains', 'levels', 'comparable', 'basis'], $keys);
        foreach ($this->compare()['explanations'] as $explanation) {
            $this->assertNotSame('', $explanation['text']);
        }
    }

    // ================================================================= Pro, privacidade, custo

    #[Test]
    public function the_service_refuses_what_the_plan_does_not_include(): void
    {
        $timeline = $this->timeline();

        $this->subscribe('base');

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        app(BuildAssessmentComparison::class)->between($timeline);
    }

    #[Test]
    public function a_suspended_subscription_does_not_get_a_new_comparison_either(): void
    {
        $timeline = $this->timeline();
        $this->subscribe('pro', SubscriptionStatus::Suspended);

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        app(BuildAssessmentComparison::class)->between($timeline);
    }

    #[Test]
    public function comparing_costs_only_the_entitlement_check_whatever_the_size_of_the_class(): void
    {
        $queries = function (): int {
            $timeline = $this->timeline();
            $this->compare(timeline: $timeline);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->compare(timeline: $timeline);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $small = $queries();
        $this->enrolUpTo(30);
        $large = $queries();

        // The one query is the plan's answer (`advanced_analytics`), asked again: the aggregation itself
        // is arithmetic over the timeline already in memory.
        $this->assertLessThanOrEqual(1, $small);
        $this->assertSame($small, $large);
    }

    #[Test]
    public function nothing_private_travels_in_the_comparison(): void
    {
        $this->decide('Carolina Nunes', '4', 2);
        $this->asTenant(function (): void {
            Classification::query()->update(['override_reason' => 'MOTIVO-LIVRE-RESERVADO']);
        });

        $json = (string) json_encode($this->compare(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('MOTIVO-LIVRE-RESERVADO', $json);
        foreach (['enrollment_id', 'domain_id', 'period_id', 'scale_level_id', 'scale_id', 'student_id', 'override_reason', 'self_assessment'] as $forbidden) {
            $this->assertStringNotContainsString("\"{$forbidden}\"", $json, "«{$forbidden}» must not travel");
        }
    }

    // ================================================================= a página

    private function page(string $query = ''): TestResponse
    {
        $ulid = $this->asTenant(fn (): string => $this->class()->ulid);

        return $this->actingAs($this->teacher)->get("/classes/{$ulid}/analise-avaliacao{$query}");
    }

    #[Test]
    public function a_pro_teacher_opens_the_page_with_the_comparison(): void
    {
        [$first, $second] = $this->periodKeys();

        $this->page()->assertOk()->assertInertia(fn ($page) => $page
            ->component('assessment-analysis/Show')
            ->where('schoolClass.label', '7.º A')
            ->where('comparison.state', 'ready')
            ->where('comparison.from.key', $first)
            ->where('comparison.to.key', $second)
            ->has('comparison.students', 6)
            ->has('comparison.domains')
            ->has('availability'));

        $this->page('?de='.$second.'&para='.$first)->assertOk()->assertInertia(fn ($page) => $page
            ->where('comparison.selection.swapped', true)
            ->where('comparison.from.key', $first));
    }

    #[Test]
    public function a_malformed_moment_in_the_url_is_a_validation_error_and_not_a_guess(): void
    {
        $this->page('?de=nao-e-um-momento')->assertSessionHasErrors('de');
        $this->page('?para='.str_repeat('a', 300))->assertSessionHasErrors('para');
    }

    #[Test]
    public function a_base_organization_is_refused_by_the_server_and_receives_no_analysis(): void
    {
        $this->subscribe('base');

        $response = $this->page()->assertForbidden();
        $response->assertInertia(fn ($page) => $page->component('ModuleUnavailable'));

        $this->assertStringNotContainsString('comparison', (string) $response->getContent());
        $this->assertStringNotContainsString('Carolina', (string) $response->getContent());
    }

    #[Test]
    public function a_suspended_or_overridden_organization_is_refused_too(): void
    {
        $this->subscribe('pro', SubscriptionStatus::Suspended);
        $this->page()->assertForbidden()->assertInertia(fn ($page) => $page->component('ModuleUnavailable'));

        $this->subscribe('pro');
        $this->page()->assertOk();

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'module_id' => Module::where('key', 'advanced_analytics')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();

        $this->page()->assertForbidden();
    }

    #[Test]
    public function another_organizations_teacher_cannot_open_the_class(): void
    {
        $ulid = $this->asTenant(fn (): string => $this->class()->ulid);
        $stranger = User::factory()->create();
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $stranger->personalOrganization()->id],
            ['plan_id' => Plan::where('key', 'pro')->firstOrFail()->id, 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::parse('2026-01-01 00:00:00'), 'ends_at' => null],
        );
        app(Entitlements::class)->flush();

        $this->actingAs($stranger)->get("/classes/{$ulid}/analise-avaliacao")->assertNotFound();
    }

    #[Test]
    public function a_guest_is_sent_to_login(): void
    {
        $ulid = $this->asTenant(fn (): string => $this->class()->ulid);

        $this->get("/classes/{$ulid}/analise-avaliacao")->assertRedirect('/login');
    }

    private function enrolUpTo(int $total): void
    {
        $this->asTenant(function () use ($total): void {
            $class = $this->class();
            $organization = $this->teacher->personalOrganization();
            $existing = Enrollment::query()->where('class_id', $class->getKey())->count();

            for ($number = $existing + 1; $number <= $total; $number++) {
                $student = Student::factory()->recycle($organization)->create();
                StudentIdentity::create(['student_id' => $student->getKey(), 'organization_id' => $organization->getKey(), 'display_name' => "Aluno de Teste {$number}"]);
                Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->getKey(), 'student_id' => $student->getKey(), 'class_number' => $number, 'enrolled_on' => '2026-09-14',
                ]);
            }
        });
    }

    private function pageQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page()->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function the_pages_cost_does_not_grow_with_the_class(): void
    {
        $this->pageQueries(); // warm every one-off

        $small = $this->pageQueries();
        $this->enrolUpTo(30);
        $large = $this->pageQueries();

        $this->assertSame($small, $large, "Six students cost {$small} queries and thirty cost {$large}: the cost grows with the class.");
    }
}
