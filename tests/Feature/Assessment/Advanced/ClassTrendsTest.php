<?php

namespace Tests\Feature\Assessment\Advanced;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\Advanced\AdvancedAnalyticsUnavailable;
use App\Services\Assessment\Advanced\BuildAssessmentTimeline;
use App\Services\Assessment\Advanced\BuildClassTrends;
use App\Services\Assessment\Advanced\TimelineCriteria;
use App\Services\Assessment\Advanced\TimelineSeries;
use App\Services\Assessment\Advanced\TrajectoryReading;
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
 * D4 — Tendências e resultados negativos persistentes da turma.
 *
 * Duas famílias. Sobre a turma REAL da demo, com momentos guardados a fazer de série de quatro pontos:
 * a integração (a linha, a ordem, a página, o Pro, o isolamento, o custo). Sobre linhas temporais
 * SINTÉTICAS, escritas à mão: cada regra — três pontos para uma tendência, dois para um resultado
 * negativo persistente, o buraco que interrompe, a mudança de regra que fica de fora.
 */
class ClassTrendsTest extends TestCase
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
    private function trends(?array $timeline = null): array
    {
        return $this->asTenant(fn (): array => app(BuildClassTrends::class)->for($timeline ?? $this->timeline()));
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

    // ================================================================= a turma real, com uma série de quatro pontos

    #[Test]
    public function with_two_moments_a_persistent_negative_can_be_read_but_not_a_trend(): void
    {
        $trends = $this->trends();

        $this->assertSame('ready', $trends['state']);
        $this->assertSame(2, $trends['availability']['comparable_points']);
        $this->assertFalse($trends['availability']['trends']);
        $this->assertTrue($trends['availability']['persistence']);
        $this->assertStringContainsString('as tendências precisam de pelo menos 3', $trends['availability']['reason']);

        foreach ($trends['students'] as $row) {
            $this->assertSame('insufficient', $row['global']['trend']['state'], $row['name']);
        }
        $this->assertSame('insufficient', $trends['class']['global']['trend']['state']);
    }

    #[Test]
    public function kept_moments_under_the_same_criteria_make_a_line_of_four_in_time_order(): void
    {
        $this->keep('Antes do fim do período');
        $this->keep('Mais perto do fim');

        $trends = $this->trends();

        $this->assertSame(4, $trends['availability']['comparable_points']);
        $this->assertTrue($trends['availability']['trends']);
        $this->assertNull($trends['availability']['reason']);
        $this->assertSame(['Antes do fim do período', 'Mais perto do fim', '1.º Semestre', '2.º Semestre'], array_column($trends['points'], 'label'));
        $this->assertSame(['frozen', 'frozen', 'live', 'live'], array_column($trends['points'], 'basis_kind'));
        $this->assertSame('2.º Semestre', $trends['reference']['label'], 'the line is anchored on the latest moment');
        $this->assertSame([], $trends['left_out']);
    }

    #[Test]
    public function every_student_row_is_the_trajectory_reading_of_that_students_own_figures(): void
    {
        $this->keep('Momento A');
        $this->keep('Momento B');
        $timeline = $this->timeline();
        $trends = $this->trends($timeline);
        $series = TimelineSeries::of($timeline);

        $this->assertNotNull($series);
        foreach ($trends['students'] as $row) {
            $student = collect($timeline['students'])->firstWhere('enrollment_ulid', $row['enrollment_ulid']);

            $expected = TrajectoryReading::read(TimelineSeries::entries($series['points'], fn (string $key): ?array => $student['cells'][$key]['result'] ?? null));
            $this->assertSame($expected, $row['global'], $row['name']);

            // A value that is not there is null, never '0'.
            foreach ($row['global']['values'] as $value) {
                $this->assertNotSame('0', $value);
                $this->assertNotSame('0.0', $value);
            }
        }
    }

    #[Test]
    public function the_counts_agree_with_the_rows_and_carry_their_totals(): void
    {
        $this->keep('Momento A');
        $this->keep('Momento B');
        $trends = $this->trends();
        $counts = $trends['counts'];

        $this->assertSame(6, $counts['students']);
        $this->assertSame(6, $counts['global']['of']);
        $this->assertSame(6, array_sum($counts['global']['trend']));
        $this->assertSame(6, array_sum($counts['global']['persistent']));

        foreach ($trends['students'] as $row) {
            $state = $row['global']['trend']['state'];
            $this->assertGreaterThan(0, $counts['global']['trend'][$state]);
        }

        $this->assertCount(5, $counts['domains']);
        foreach ($counts['domains'] as $domain) {
            $this->assertSame(6, $domain['of']);
            $this->assertSame(6, array_sum($domain['trend']));
        }
    }

    #[Test]
    public function a_kept_moment_under_other_weights_is_left_out_with_the_reason_and_breaks_the_line(): void
    {
        $this->keep('Antes');
        $other = $this->keep('Outros pesos');
        $this->rewrite($other, function (array $payload): array {
            $payload['domains'][0]['weight_percent'] = '99.0000';

            return $payload;
        });

        $trends = $this->trends();

        $this->assertSame(3, $trends['availability']['comparable_points']);
        $this->assertSame(['Outros pesos'], array_column($trends['left_out'], 'label'));
        $this->assertSame(['criteria_differ'], array_column($trends['left_out'][0]['reasons'], 'code'));
        $this->assertContains(false, array_column($trends['points'], 'comparable'));
    }

    #[Test]
    public function a_photograph_from_before_the_scale_travelled_is_not_in_the_line(): void
    {
        $this->keep('Sem escala');
        $old = $this->keep('Antiga');
        $this->rewrite($old, function (array $payload): array {
            unset($payload['scale']);
            $payload['version'] = 2;

            return $payload;
        });

        $trends = $this->trends();

        $this->assertSame(['Antiga'], array_column($trends['left_out'], 'label'));
        $this->assertSame(['scale_unknown'], array_column($trends['left_out'][0]['reasons'], 'code'));
    }

    // ================================================================= Pro, privacidade, custo, página

    #[Test]
    public function the_service_refuses_what_the_plan_does_not_include(): void
    {
        $timeline = $this->timeline();
        $this->subscribe('base');

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        app(BuildClassTrends::class)->for($timeline);
    }

    #[Test]
    public function a_suspended_subscription_does_not_get_a_new_reading_either(): void
    {
        $timeline = $this->timeline();
        $this->subscribe('pro', SubscriptionStatus::Suspended);

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        app(BuildClassTrends::class)->for($timeline);
    }

    #[Test]
    public function nothing_private_travels(): void
    {
        $this->asTenant(fn () => Classification::query()->update(['override_reason' => 'MOTIVO-LIVRE-RESERVADO']));
        $this->keep('Momento');

        $json = (string) json_encode($this->trends(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('MOTIVO-LIVRE-RESERVADO', $json);
        foreach (['enrollment_id', 'domain_id', 'period_id', 'scale_level_id', 'scale_id', 'student_id', 'override_reason', 'self_assessment'] as $forbidden) {
            $this->assertStringNotContainsString("\"{$forbidden}\"", $json, "«{$forbidden}» must not travel");
        }
    }

    private function page(): TestResponse
    {
        $ulid = $this->asTenant(fn (): string => $this->class()->ulid);

        return $this->actingAs($this->teacher)->get("/classes/{$ulid}/analise-avaliacao");
    }

    #[Test]
    public function the_page_carries_the_trends_beside_the_other_sections(): void
    {
        $this->page()->assertOk()->assertInertia(fn ($page) => $page
            ->component('assessment-analysis/Show')
            ->where('trends.state', 'ready')
            ->has('trends.students', 6)
            ->has('trends.counts.domains', 5)
            ->has('comparison')
            ->has('strengths'));
    }

    #[Test]
    public function a_base_organization_receives_no_trajectories_at_all(): void
    {
        $this->subscribe('base');

        $response = $this->page()->assertForbidden();

        $this->assertStringNotContainsString('trends', (string) $response->getContent());
        $this->assertStringNotContainsString('persistent', (string) $response->getContent());
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
        $this->keep('Momento');
        $this->pageQueries();

        $small = $this->pageQueries();
        $this->enrolUpTo(30);
        $large = $this->pageQueries();

        $this->assertSame($small, $large, "Six students cost {$small} queries and thirty cost {$large}: the cost grows with the class.");
    }

    // ================================================================= a regra de cada leitura (linhas temporais sintéticas)

    /**
     * A timeline written by hand: p1…pN, every point under the same criteria and scale unless said.
     *
     * @param  list<array<string, mixed>>  $students  `['name' => string, 'cells' => list<array{r: ?string, d?: list<?string>}>]`, one cell per point
     * @param  array<string, array<string, mixed>>  $pointOver  overrides per point key (p1, p2, …)
     * @return array<string, mixed>
     */
    private function synthetic(array $students, int $points, array $pointOver = [], int $domains = 1, bool $withBands = true): array
    {
        $scale = ['name' => 'Escala de teste', 'kind' => 'levels', 'levels' => [
            ['code' => '1', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true],
            ['code' => '2', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false],
        ]];
        $band = fn (?string $value): ?array => $value === null || ! $withBands ? null : ((float) $value < 50
            ? ['code' => '1', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true]
            : ['code' => '2', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false]);
        $figure = fn (?string $value): array => ['value' => $value, 'band' => $band($value), 'coverage_warning' => false];

        $domainList = [];
        for ($index = 0; $index < $domains; $index++) {
            $domainList[] = ['ulid' => 'dom-'.$index, 'name' => 'Domínio '.($index + 1), 'weight_percent' => '50.0000'];
        }

        $pointList = [];
        for ($number = 1; $number <= $points; $number++) {
            $pointList[] = [
                'key' => "period:p{$number}", 'kind' => 'period', 'label' => "Momento {$number}", 'period_ulid' => "p{$number}", 'closes_the_year' => false,
                'basis' => ['kind' => 'live', 'label' => 'Calculado com os dados de hoje', 'as_of' => null],
                'level_signature' => TimelineCriteria::levels($scale), 'percentage_signature' => 'P', 'notices' => [], 'ordinal' => $number,
                'students_total' => count($students), 'students_with_result' => count($students), 'partial_coverage' => 0, 'has_results' => true, 'few_students' => count($students) < 5,
                ...($pointOver["p{$number}"] ?? []),
            ];
        }

        $rows = [];
        foreach ($students as $index => $student) {
            $cells = [];
            foreach ($student['cells'] as $position => $cell) {
                $entry = ['result' => $figure($cell['r']), 'domains' => [], 'classification' => null, 'participation' => null];
                foreach ($cell['d'] ?? [] as $domainIndex => $value) {
                    $entry['domains']['dom-'.$domainIndex] = $figure($value);
                }
                $cells['period:p'.($position + 1)] = $entry;
            }
            $rows[] = ['enrollment_ulid' => 'enr-'.$index, 'name' => $student['name'], 'class_number' => $index + 1, 'cells' => $cells];
        }

        return [
            'class_ulid' => 'cls', 'scale' => $scale, 'domains' => $domainList, 'points' => $pointList, 'students' => $rows,
            'availability' => ['points_with_results' => $points, 'comparable_points' => $points, 'comparison' => true, 'trends' => $points >= 3, 'reason' => null],
            'notices' => [], 'context' => [],
        ];
    }

    /**
     * @param  list<string|null>  $values  one overall value per point
     * @return array{name: string, cells: list<array{r: string|null}>}
     */
    private function student(string $name, array $values): array
    {
        return ['name' => $name, 'cells' => array_map(fn (?string $value): array => ['r' => $value], $values)];
    }

    /**
     * @param  array<string, mixed>  $trends
     * @return array<string, array<string, mixed>>
     */
    private function rowsByName(array $trends): array
    {
        $rows = [];
        foreach ($trends['students'] as $row) {
            $rows[$row['name']] = $row;
        }

        return $rows;
    }

    #[Test]
    public function each_kind_of_trend_is_named_from_the_steps(): void
    {
        $timeline = $this->synthetic([
            $this->student('Sobe', ['60', '65', '70', '75']),
            $this->student('Desce', ['75', '70', '65', '60']),
            $this->student('Igual', ['60', '60', '60', '60']),
            $this->student('Varia', ['60', '70', '65', '75']),
            $this->student('Poucos', [null, null, '60', '70']),
        ], points: 4);

        $rows = $this->rowsByName($this->trends($timeline));

        $this->assertSame('consistent_up', $rows['Sobe']['global']['trend']['state']);
        $this->assertSame('consistent_down', $rows['Desce']['global']['trend']['state']);
        $this->assertSame('stable', $rows['Igual']['global']['trend']['state']);
        $this->assertSame('variable', $rows['Varia']['global']['trend']['state']);
        $this->assertSame('insufficient', $rows['Poucos']['global']['trend']['state'], 'two values are not a trend');
        $this->assertSame(2, $rows['Poucos']['global']['trend']['with_value']);

        // The evidence travels: the steps, with the values and the moments.
        $steps = $rows['Sobe']['global']['trend']['steps'];
        $this->assertSame(['Momento 1', 'Momento 2', '60', '65'], [$steps[0]['from'], $steps[0]['to'], ltrim($steps[0]['previous'], '0') === '' ? '' : (string) (int) $steps[0]['previous'], (string) (int) $steps[0]['current']]);
        $this->assertCount(3, $steps);
    }

    #[Test]
    public function the_counts_spread_the_students_across_the_trends_with_the_total(): void
    {
        $timeline = $this->synthetic([
            $this->student('A', ['60', '65', '70']), $this->student('B', ['60', '65', '70']),
            $this->student('C', ['70', '65', '60']),
            $this->student('D', ['60', '60', '60']),
            $this->student('E', ['60', '70', '65']),
            $this->student('F', ['60', null, null]),
        ], points: 3);

        $global = $this->trends($timeline)['counts']['global'];

        $this->assertSame(6, $global['of']);
        $this->assertSame(['consistent_up' => 2, 'consistent_down' => 1, 'stable' => 1, 'variable' => 1, 'insufficient' => 1], $global['trend']);
    }

    #[Test]
    public function persistent_negative_needs_the_latest_moment_and_the_one_before(): void
    {
        $timeline = $this->synthetic([
            $this->student('Persistente', ['70', '40', '45']),
            $this->student('Tres', ['40', '30', '35']),
            $this->student('So agora', ['70', '70', '40']),
            $this->student('Recuperou', ['40', '45', '70']),
            $this->student('Buraco', ['40', null, '45']),
            $this->student('Sem recente', ['40', '45', null]),
        ], points: 3);

        $trends = $this->trends($timeline);
        $rows = $this->rowsByName($trends);

        $this->assertSame(['yes', 2], [$rows['Persistente']['global']['persistent']['state'], $rows['Persistente']['global']['persistent']['run']]);
        $this->assertSame(['yes', 3], [$rows['Tres']['global']['persistent']['state'], $rows['Tres']['global']['persistent']['run']]);
        $this->assertSame(['no', 1], [$rows['So agora']['global']['persistent']['state'], $rows['So agora']['global']['persistent']['run']]);
        $this->assertSame('no', $rows['Recuperou']['global']['persistent']['state']);
        $this->assertSame(['no', 'no_value'], [$rows['Buraco']['global']['persistent']['state'], $rows['Buraco']['global']['persistent']['stopped_by']]);
        $this->assertSame('no_recent', $rows['Sem recente']['global']['persistent']['state']);

        $counts = $trends['counts']['global'];
        $this->assertSame(2, $counts['persistent']['yes']);
        $this->assertSame(1, $counts['persistent']['no_recent']);
        // Only those with a value at the latest moment can be «still» below the line.
        $this->assertSame(5, $counts['with_latest']);
        $this->assertSame(6, $counts['of']);

        // The values and the moments that justify it travel with the conclusion.
        $evidence = $rows['Persistente']['global']['persistent']['points'];
        $this->assertSame(['Momento 2', 'Momento 3'], array_column($evidence, 'label'));
        $this->assertSame(['40', '45'], array_column($evidence, 'value'));
    }

    #[Test]
    public function two_moments_are_enough_for_a_persistent_negative_and_not_for_a_trend(): void
    {
        $timeline = $this->synthetic([$this->student('Duas', ['40', '45']), $this->student('Outra', ['60', '70'])], points: 2);

        $trends = $this->trends($timeline);
        $rows = $this->rowsByName($trends);

        $this->assertFalse($trends['availability']['trends']);
        $this->assertTrue($trends['availability']['persistence']);
        $this->assertSame('yes', $rows['Duas']['global']['persistent']['state']);
        $this->assertSame('insufficient', $rows['Duas']['global']['trend']['state']);
        $this->assertSame(1, $trends['counts']['global']['persistent']['yes']);
    }

    #[Test]
    public function one_moment_reads_nothing_and_says_so(): void
    {
        $trends = $this->trends($this->synthetic([$this->student('Só um', ['40'])], points: 1));

        $this->assertSame(1, $trends['availability']['comparable_points']);
        $this->assertFalse($trends['availability']['trends']);
        $this->assertFalse($trends['availability']['persistence']);
        $this->assertStringContainsString('nem as tendências (3) nem os resultados negativos persistentes (2)', $trends['availability']['reason']);
        $this->assertSame('insufficient', $this->rowsByName($trends)['Só um']['global']['persistent']['state']);
    }

    #[Test]
    public function a_moment_under_other_criteria_is_left_out_and_breaks_a_run_that_crosses_it(): void
    {
        $timeline = $this->synthetic([
            $this->student('Atravessa', ['40', '40', '45']),
            $this->student('Depois', ['70', '70', '40']),
        ], points: 3, pointOver: ['p2' => ['percentage_signature' => 'OUTROS-PESOS']]);

        $trends = $this->trends($timeline);
        $rows = $this->rowsByName($trends);

        $this->assertSame(2, $trends['availability']['comparable_points']);
        $this->assertSame(['Momento 2'], array_column($trends['left_out'], 'label'));
        $this->assertSame(['criteria_differ'], array_column($trends['left_out'][0]['reasons'], 'code'));
        $this->assertSame([true, false, true], array_column($trends['points'], 'comparable'));

        // Negative at moments 1 and 3, with a change of rule between them: not consecutive.
        $this->assertSame(['no', 1, 'left_out'], [$rows['Atravessa']['global']['persistent']['state'], $rows['Atravessa']['global']['persistent']['run'], $rows['Atravessa']['global']['persistent']['stopped_by']]);
    }

    #[Test]
    public function a_change_of_scale_in_the_middle_leaves_that_moment_out_too(): void
    {
        $timeline = $this->synthetic([$this->student('X', ['40', '40', '45'])], points: 3, pointOver: ['p2' => ['level_signature' => 'OUTRA-ESCALA']]);

        $trends = $this->trends($timeline);

        $this->assertSame(['scale_differs'], array_column($trends['left_out'][0]['reasons'], 'code'));
        $this->assertSame('no', $this->rowsByName($trends)['X']['global']['persistent']['state']);
    }

    #[Test]
    public function without_a_line_between_positive_and_negative_nothing_is_called_persistent(): void
    {
        $timeline = $this->synthetic([$this->student('Sem linha', ['40', '40', '45'])], points: 3, withBands: false);

        $row = $this->rowsByName($this->trends($timeline))['Sem linha'];

        $this->assertSame('unknown', $row['global']['persistent']['state']);
        $this->assertNotSame('yes', $row['global']['persistent']['state']);
        // The trend is about values, and the values are there.
        $this->assertSame('variable', $row['global']['trend']['state']);
    }

    #[Test]
    public function domains_are_read_with_the_same_two_rules_and_counted_with_their_totals(): void
    {
        $students = [
            ['name' => 'A', 'cells' => [['r' => '60', 'd' => ['40', '70']], ['r' => '60', 'd' => ['45', '75']], ['r' => '60', 'd' => ['48', '80']]]],
            ['name' => 'B', 'cells' => [['r' => '60', 'd' => ['70', '70']], ['r' => '60', 'd' => ['70', '65']], ['r' => '60', 'd' => ['40', '60']]]],
            ['name' => 'C', 'cells' => [['r' => '60', 'd' => [null, '70']], ['r' => '60', 'd' => [null, '70']], ['r' => '60', 'd' => [null, '70']]]],
        ];

        $trends = $this->trends($this->synthetic($students, points: 3, domains: 2));
        $rows = $this->rowsByName($trends);

        $this->assertSame('yes', $rows['A']['domains']['dom-0']['persistent']['state']);
        $this->assertSame('consistent_up', $rows['A']['domains']['dom-0']['trend']['state']);
        $this->assertSame('no', $rows['B']['domains']['dom-0']['persistent']['state']);
        $this->assertSame('insufficient', $rows['C']['domains']['dom-0']['trend']['state'], 'a domain with no value is not read as zero');

        $first = $trends['counts']['domains'][0];
        $this->assertSame(['Domínio 1', 3, 1], [$first['name'], $first['of'], $first['persistent']['yes']]);
        // Two students have a value in the domain at the latest moment: the only ones that can still be negative.
        $this->assertSame(2, $first['with_latest']);
    }

    #[Test]
    public function the_class_trend_is_the_trend_of_the_same_students_at_every_moment(): void
    {
        // E enters at the second moment: she is in the means of everyone, and not in the cohort.
        $timeline = $this->synthetic([
            $this->student('A', ['50', '60', '70']), $this->student('B', ['50', '60', '70']),
            $this->student('C', ['50', '60', '70']), $this->student('D', ['50', '60', '70']),
            $this->student('E', [null, '100', '100']),
        ], points: 3);

        $class = $this->trends($timeline)['class']['global'];

        $this->assertSame(4, $class['cohort']);
        $this->assertSame(['50.0', '60.0', '70.0'], $class['means']);
        $this->assertSame('consistent_up', $class['trend']['state']);
        // Everyone: the mean changes with who is there, and says how many.
        $this->assertSame([['students' => 4, 'mean' => '50.0'], ['students' => 5, 'mean' => '68.0'], ['students' => 5, 'mean' => '76.0']], $class['everyone']);
    }

    #[Test]
    public function a_small_cohort_or_none_is_said(): void
    {
        $small = $this->trends($this->synthetic([$this->student('A', ['50', '60', '70']), $this->student('B', ['50', '60', '70']), $this->student('C', ['50', '60', '70']), $this->student('D', ['50', '60', '70']), $this->student('E', ['50', '60', '70']), $this->student('F', [null, '60', '70'])], points: 3));
        $this->assertSame([], array_column($small['cautions'], 'code'), 'five in the cohort is not few');

        $few = $this->trends($this->synthetic([$this->student('A', ['50', '60', '70']), $this->student('B', ['50', '60', '70']), $this->student('C', [null, '60', '70'])], points: 3));
        $this->assertContains('few_in_cohort', array_column($few['cautions'], 'code'));
        $this->assertStringContainsString('assenta em 2 aluno(s)', implode(' ', array_column($few['cautions'], 'text')));

        $none = $this->trends($this->synthetic([$this->student('A', ['50', null, '70']), $this->student('B', [null, '60', '70'])], points: 3));
        $this->assertSame(0, $none['class']['global']['cohort']);
        $this->assertSame('insufficient', $none['class']['global']['trend']['state']);
        $this->assertStringContainsString('Nenhum aluno tem resultado em todos os momentos', implode(' ', array_column($none['cautions'], 'text')));
    }

    #[Test]
    public function the_words_never_diagnose_forecast_or_recommend(): void
    {
        $timeline = $this->synthetic([$this->student('A', ['40', '40', '45']), $this->student('B', ['60', '65', '70'])], points: 3);
        $all = mb_strtolower((string) json_encode($this->trends($timeline), JSON_UNESCAPED_UNICODE));

        $this->assertStringContainsString('não é um diagnóstico de dificuldades de aprendizagem', $all);

        $rest = str_replace('não é um diagnóstico de dificuldades de aprendizagem', '', $all);
        foreach (['dislexia', 'dificuldade de aprendizagem', 'recomend', 'deveria', 'sugerimos', 'provavelmente', 'vai piorar', 'vai melhorar', 'risco'] as $word) {
            $this->assertStringNotContainsString($word, $rest, "«{$word}» must not be said");
        }
        $this->assertStringContainsString('não prevê o futuro', $all);
    }

    #[Test]
    public function with_no_result_anywhere_it_says_so(): void
    {
        $timeline = $this->timeline();
        foreach ($timeline['points'] as $index => $point) {
            $timeline['points'][$index]['has_results'] = false;
        }

        $trends = $this->trends($timeline);

        $this->assertSame('unavailable', $trends['state']);
        $this->assertSame('Ainda não há resultados em nenhum momento: não há trajetórias para ler.', $trends['reason']);
        $this->assertSame([], $trends['students']);
    }
}
