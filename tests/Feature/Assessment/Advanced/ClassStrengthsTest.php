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
use App\Services\Assessment\Advanced\BuildAssessmentComparison;
use App\Services\Assessment\Advanced\BuildAssessmentTimeline;
use App\Services\Assessment\Advanced\BuildClassStrengths;
use App\Services\Assessment\Advanced\TimelineCriteria;
use App\Services\Assessment\BuildClassStatistics;
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
 * D3 — Pontos fortes e dificuldades da turma, num momento.
 *
 * Duas famílias de testes. As sobre a turma REAL da demo defendem que os números são os de sempre
 * (paridade com a Estatística), que um momento guardado usa a sua escala e não a de hoje, e que a
 * página é Pro, isolada e de custo constante. As sobre uma linha temporal SINTÉTICA — pequena e
 * escrita à mão — defendem a regra de cada destaque: o empate não destaca, a contagem traz o
 * denominador, o que o dado não sustenta não se diz.
 */
class ClassStrengthsTest extends TestCase
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
    private function strengths(?string $moment = null, ?array $timeline = null): array
    {
        return $this->asTenant(fn (): array => app(BuildClassStrengths::class)->for($timeline ?? $this->timeline(), $moment));
    }

    /** @return array{string, string} */
    private function periodKeys(): array
    {
        return $this->asTenant(function (): array {
            $periods = $this->class()->academicYear->periods()->orderBy('sequence')->get();

            return ['period:'.$periods[0]->ulid, 'period:'.$periods[1]->ulid];
        });
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

    // ================================================================= um momento, escolhido

    #[Test]
    public function by_default_it_reads_the_latest_moment_with_results_and_offers_the_others(): void
    {
        [$first, $second] = $this->periodKeys();
        $strengths = $this->strengths();

        $this->assertSame('ready', $strengths['state']);
        $this->assertSame($second, $strengths['moment']['key']);
        $this->assertSame(['moment' => $second, 'defaulted' => true], $strengths['selection']);
        $this->assertSame([$first, $second], array_column($strengths['options'], 'key'));

        $explicit = $this->strengths($first);
        $this->assertSame(['moment' => $first, 'defaulted' => false], $explicit['selection']);

        // A key that is not a moment with results falls back, and says it did.
        $fallback = $this->strengths('period:'.str_repeat('0', 26));
        $this->assertTrue($fallback['selection']['defaulted']);
        $this->assertSame($second, $fallback['moment']['key']);
    }

    #[Test]
    public function it_needs_no_second_moment(): void
    {
        $timeline = $this->timeline();
        $timeline['points'][0]['has_results'] = false;

        $strengths = $this->strengths(timeline: $timeline);
        $comparison = $this->asTenant(fn (): array => app(BuildAssessmentComparison::class)->between($timeline));

        $this->assertSame('ready', $strengths['state']);
        $this->assertSame('unavailable', $comparison['state']);
    }

    #[Test]
    public function with_no_result_anywhere_it_says_so(): void
    {
        $timeline = $this->timeline();
        foreach ($timeline['points'] as $index => $point) {
            $timeline['points'][$index]['has_results'] = false;
        }

        $strengths = $this->strengths(timeline: $timeline);

        $this->assertSame('unavailable', $strengths['state']);
        $this->assertSame('Ainda não há resultados em nenhum momento: não há nada para analisar.', $strengths['reason']);
        $this->assertSame([], $strengths['domains']);
        $this->assertSame([], $strengths['highlights']['strengths']);
    }

    // ================================================================= os números são os de sempre

    #[Test]
    public function each_domains_mean_and_student_count_are_the_ones_estatistica_shows(): void
    {
        foreach ([1, 2] as $sequence) {
            [$series, $domainUlid] = $this->asTenant(function () use ($sequence): array {
                $class = $this->class();
                $statistics = app(BuildClassStatistics::class)->for($class, $class->academicYear->periods()->where('sequence', $sequence)->firstOrFail());

                return [
                    collect($statistics['period_series'])->firstWhere('sequence', $sequence),
                    collect($statistics['domains'])->pluck('ulid', 'id')->all(),
                ];
            });

            $periodKey = $this->periodKeys()[$sequence - 1];
            $strengths = $this->strengths($periodKey);
            $this->assertSame($series['class_average'], $strengths['result']['mean'], "period {$sequence}: overall");
            $this->assertSame($series['students_with_result'], $strengths['result']['students']['with_value']);

            $checked = 0;
            foreach ($series['domains'] as $domain) {
                $row = collect($strengths['domains'])->firstWhere('ulid', $domainUlid[$domain['domain_id']]);

                $this->assertSame($domain['average'], $row['mean'], "period {$sequence}: {$row['name']}");
                $this->assertSame($domain['students_with_result'], $row['students']['with_value']);
                $checked += $domain['average'] === null ? 0 : 1;
            }
            $this->assertGreaterThan(0, $checked, "period {$sequence}: the check is not vacuous");
        }
    }

    #[Test]
    public function every_domain_is_listed_and_one_with_no_result_is_said_not_dropped(): void
    {
        $timeline = $this->timeline();
        $strengths = $this->strengths(timeline: $timeline);

        $this->assertSame(array_column($timeline['domains'], 'ulid'), array_column($strengths['domains'], 'ulid'));

        foreach ($strengths['domains'] as $row) {
            $with = $row['students']['with_value'];
            $this->assertSame(count($timeline['students']), $row['students']['of']);

            if ($with === 0) {
                $this->assertSame('none', $row['coverage']['state']);
                $this->assertNull($row['mean']);
                $this->assertNull($row['below_positive']);
            } elseif ($with < 5) {
                $this->assertSame('few', $row['coverage']['state']);
            }
        }
    }

    #[Test]
    public function the_students_below_the_positive_level_are_counted_on_the_scales_own_line(): void
    {
        $timeline = $this->timeline();
        $strengths = $this->strengths(timeline: $timeline);
        $key = $strengths['moment']['key'];

        $this->assertTrue($strengths['negative_line']['known']);

        foreach ($strengths['domains'] as $row) {
            if ($row['below_positive'] === null) {
                continue;
            }

            $expected = 0;
            foreach ($timeline['students'] as $student) {
                $figure = $student['cells'][$key]['domains'][$row['ulid']] ?? null;
                $expected += ($figure['band']['is_negative'] ?? false) === true ? 1 : 0;
            }

            $this->assertSame($expected, $row['below_positive']['count'], $row['name']);
            $this->assertSame($row['students']['with_value'], $row['below_positive']['of']);
        }

        // A student listed has two or more domains below the line — and the list agrees with the count.
        $below = $strengths['students_below'];
        $this->assertCount($below['two_or_more'], $below['students']);
        $this->assertSame($below['none'] + $below['one'] + $below['two_or_more'], $below['of']);
        foreach ($below['students'] as $student) {
            $this->assertGreaterThanOrEqual(2, count($student['domains']));
        }
    }

    // ================================================================= a escala e os critérios do momento

    #[Test]
    public function a_kept_moment_is_read_with_its_own_frozen_figures_not_todays(): void
    {
        $moment = $this->keep('Fotografia');
        $before = $this->strengths('moment:'.$moment);

        // Today's data change; the photograph does not.
        $this->asTenant(fn () => Classification::query()->update(['final_scale_level_id' => null]));
        $after = $this->strengths('moment:'.$moment);

        $this->assertSame($before['domains'], $after['domains']);
        $this->assertSame($before['moment']['basis']['kind'], 'frozen');
        $this->assertSame('Guardado a 15/12/2026', $before['moment']['basis']['label']);
        $this->assertStringContainsString('fotografia guardada', $before['basis_note']);
    }

    #[Test]
    public function a_photograph_from_before_the_scale_travelled_does_not_borrow_todays_line(): void
    {
        $moment = $this->keep('Antes da escala viajar');
        $this->rewrite($moment, function (array $payload): array {
            unset($payload['scale']);
            $payload['version'] = 2;

            return $payload;
        });

        $strengths = $this->strengths('moment:'.$moment);

        $this->assertFalse($strengths['negative_line']['known']);
        $this->assertSame('scale_unknown', $strengths['negative_line']['reason']);
        $this->assertNotEmpty($strengths['negative_line']['text']);
        $this->assertFalse($strengths['scale']['known']);
        $this->assertFalse($strengths['students_below']['known']);
        foreach ($strengths['domains'] as $row) {
            $this->assertNull($row['below_positive'], "{$row['name']}: no count without a line");
        }
        $this->assertNull($strengths['result']['below_positive']);

        // The means are still there; what depends on the line is not said.
        $this->assertNotNull($strengths['result']['mean']);
        $keys = array_column($strengths['highlights']['strengths'], 'key');
        $this->assertNotContains('none_below', $keys);
        $keys = array_column($strengths['highlights']['attention'], 'key');
        $this->assertNotContains('highest_share_below', $keys);
        $this->assertNotContains('below_in_two_or_more', $keys);
    }

    #[Test]
    public function a_moment_on_another_scale_says_it_is_not_todays(): void
    {
        $moment = $this->keep('Outra escala');
        $this->rewrite($moment, function (array $payload): array {
            $payload['scale']['levels'][0]['label'] = 'Outro nome de nível';

            return $payload;
        });

        $strengths = $this->strengths('moment:'.$moment);
        $current = $this->strengths($this->periodKeys()[0]);

        $this->assertFalse($strengths['scale']['same_as_current']);
        $this->assertSame([], $strengths['scale']['levels']);
        $this->assertTrue($current['scale']['same_as_current']);
        $this->assertNotEmpty($current['scale']['levels']);
    }

    // ================================================================= Pro, privacidade, custo

    #[Test]
    public function the_service_refuses_what_the_plan_does_not_include(): void
    {
        $timeline = $this->timeline();
        $this->subscribe('base');

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        app(BuildClassStrengths::class)->for($timeline);
    }

    #[Test]
    public function a_suspended_subscription_does_not_get_a_new_reading_either(): void
    {
        $timeline = $this->timeline();
        $this->subscribe('pro', SubscriptionStatus::Suspended);

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        app(BuildClassStrengths::class)->for($timeline);
    }

    #[Test]
    public function nothing_private_travels_and_the_only_names_are_the_ones_below_the_line_in_two_domains(): void
    {
        $this->asTenant(fn () => Classification::query()->update(['override_reason' => 'MOTIVO-LIVRE-RESERVADO']));

        $strengths = $this->strengths();
        $json = (string) json_encode($strengths, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('MOTIVO-LIVRE-RESERVADO', $json);
        foreach (['enrollment_id', 'domain_id', 'period_id', 'scale_level_id', 'scale_id', 'student_id', 'override_reason', 'self_assessment'] as $forbidden) {
            $this->assertStringNotContainsString("\"{$forbidden}\"", $json, "«{$forbidden}» must not travel");
        }

        // A name appears only inside `students_below.students`.
        $listed = array_column($strengths['students_below']['students'], 'name');
        $without = $strengths;
        $without['students_below']['students'] = [];
        $rest = (string) json_encode($without, JSON_UNESCAPED_UNICODE);
        $names = $this->asTenant(fn (): array => $this->class()->enrollments()->with('student.identity')->get()->map(fn (Enrollment $enrollment) => $enrollment->student->identity->display_name)->all());
        foreach ($names as $name) {
            $this->assertStringNotContainsString($name, $rest, "{$name} is named outside the list of who is below the line in two domains");
        }
        $this->assertSame(array_values(array_unique($listed)), $listed);
    }

    // ================================================================= a página

    private function page(string $query = ''): TestResponse
    {
        $ulid = $this->asTenant(fn (): string => $this->class()->ulid);

        return $this->actingAs($this->teacher)->get("/classes/{$ulid}/analise-avaliacao{$query}");
    }

    #[Test]
    public function the_page_carries_the_strengths_beside_the_comparison(): void
    {
        [$first, $second] = $this->periodKeys();

        $this->page()->assertOk()->assertInertia(fn ($page) => $page
            ->component('assessment-analysis/Show')
            ->where('strengths.state', 'ready')
            ->where('strengths.moment.key', $second)
            ->has('strengths.domains')
            ->where('comparison.state', 'ready'));

        $this->page('?momento='.$first)->assertOk()->assertInertia(fn ($page) => $page
            ->where('strengths.moment.key', $first)
            ->where('strengths.selection.defaulted', false)
            ->where('comparison.from.key', $first));
    }

    #[Test]
    public function a_malformed_moment_is_a_validation_error(): void
    {
        $this->page('?momento=lixo')->assertSessionHasErrors('momento');
    }

    #[Test]
    public function a_base_organization_receives_no_strengths_at_all(): void
    {
        $this->subscribe('base');

        $response = $this->page()->assertForbidden();

        $this->assertStringNotContainsString('strengths', (string) $response->getContent());
        $this->assertStringNotContainsString('students_below', (string) $response->getContent());
    }

    #[Test]
    public function the_students_page_and_the_other_pages_of_the_base_plan_do_not_change(): void
    {
        $this->subscribe('base');
        $ulid = $this->asTenant(fn (): string => $this->class()->ulid);
        $period = $this->asTenant(fn (): string => $this->class()->academicYear->periods()->orderBy('sequence')->first()->ulid);

        $response = $this->actingAs($this->teacher)->get("/classes/{$ulid}/results/estatistica/{$period}")->assertOk();

        $this->assertStringNotContainsString('students_below', (string) $response->getContent());
        $this->assertStringNotContainsString('highlights', (string) $response->getContent());
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
        $this->pageQueries();

        $small = $this->pageQueries();
        $this->enrolUpTo(30);
        $large = $this->pageQueries();

        $this->assertSame($small, $large, "Six students cost {$small} queries and thirty cost {$large}: the cost grows with the class.");
    }

    #[Test]
    public function the_reading_itself_costs_only_the_plans_question(): void
    {
        $timeline = $this->timeline();
        $this->strengths(timeline: $timeline);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->strengths(timeline: $timeline);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $queries);
    }

    // ================================================================= a regra de cada destaque (linha temporal sintética)

    /** The scale of the synthetic timeline: below 50 is the negative level. */
    private function syntheticScale(): array
    {
        return ['name' => 'Escala de teste', 'kind' => 'levels', 'levels' => [
            ['code' => '1', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true],
            ['code' => '2', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false],
        ]];
    }

    /**
     * A one-moment timeline written by hand: each student is a list of domain values (null = no value).
     *
     * @param  list<list<string|null>>  $students  one row per student, one column per domain (d1, d2, …)
     * @param  list<string>  $domainNames
     * @return array<string, mixed>
     */
    private function synthetic(array $students, array $domainNames = ['Escrita', 'Leitura', 'Oralidade'], bool $withScale = true): array
    {
        $scale = $this->syntheticScale();
        $band = fn (?string $value): ?array => $value === null || ! $withScale ? null : ((float) $value < 50
            ? ['code' => '1', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true]
            : ['code' => '2', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false]);
        $figure = fn (?string $value): array => ['value' => $value, 'band' => $band($value), 'coverage_warning' => false];

        $domains = [];
        foreach ($domainNames as $index => $name) {
            $domains[] = ['ulid' => 'dom-'.$index, 'name' => $name, 'weight_percent' => '33.3333'];
        }

        $rows = [];
        foreach ($students as $number => $values) {
            $cell = ['result' => $figure('60'), 'domains' => [], 'classification' => null, 'participation' => null];
            foreach ($values as $index => $value) {
                $cell['domains']['dom-'.$index] = $figure($value);
            }
            $rows[] = ['enrollment_ulid' => 'enr-'.$number, 'name' => 'Aluno '.($number + 1), 'class_number' => $number + 1, 'cells' => ['period:one' => $cell]];
        }

        return [
            'class_ulid' => 'cls',
            'scale' => $scale,
            'domains' => $domains,
            'points' => [[
                'key' => 'period:one', 'kind' => 'period', 'label' => '1.º Semestre', 'period_ulid' => 'one', 'closes_the_year' => false,
                'basis' => ['kind' => 'live', 'label' => 'Calculado com os dados de hoje', 'as_of' => null],
                'level_signature' => $withScale ? TimelineCriteria::levels($scale) : null, 'percentage_signature' => 'p', 'notices' => [], 'ordinal' => 1,
                'students_total' => count($students), 'students_with_result' => count($students), 'partial_coverage' => 0, 'has_results' => true, 'few_students' => count($students) < 5,
            ]],
            'students' => $rows,
            'availability' => ['points_with_results' => 1, 'comparable_points' => 1, 'comparison' => false, 'trends' => false, 'reason' => 'x'],
            'notices' => [],
            'context' => [],
        ];
    }

    #[Test]
    public function a_unique_highest_and_lowest_mean_are_named_with_their_students(): void
    {
        // Escrita 80, Leitura 60, Oralidade 40 — six students each.
        $students = array_fill(0, 6, ['80', '60', '40']);

        $ranking = $this->strengths(timeline: $this->synthetic($students))['ranking'];

        $this->assertSame('ok', $ranking['state']);
        $this->assertSame(['Escrita', '80.0', 6], [$ranking['highest']['name'], $ranking['highest']['mean'], $ranking['highest']['students']]);
        $this->assertSame(['Oralidade', '40.0', 6], [$ranking['lowest']['name'], $ranking['lowest']['mean'], $ranking['lowest']['students']]);
        $this->assertSame([], $ranking['highest_tie']);

        // The highlight says how far apart the two ends are, so the claim rests on the numbers shown.
        $highlights = $this->strengths(timeline: $this->synthetic($students))['highlights'];
        $this->assertStringContainsString('«Escrita» tem a média mais alta dos domínios: 80,0% (a mais baixa é 40,0%); 6 aluno(s) com valor.', $highlights['strengths'][0]['text']);
        $this->assertStringContainsString('«Oralidade» tem a média mais baixa dos domínios: 40,0% (a mais alta é 80,0%); 6 aluno(s) com valor.', implode("\n", array_column($highlights['attention'], 'text')));
    }

    #[Test]
    public function a_tie_names_no_winner_and_says_so(): void
    {
        // Escrita 80, Leitura 80, Oralidade 40.
        $strengths = $this->strengths(timeline: $this->synthetic(array_fill(0, 6, ['80', '80', '40'])));

        $this->assertNull($strengths['ranking']['highest']);
        $this->assertSame(['Escrita', 'Leitura'], $strengths['ranking']['highest_tie']);
        $this->assertSame('Oralidade', $strengths['ranking']['lowest']['name']);
        $this->assertNotContains('highest_mean', array_column($strengths['highlights']['strengths'], 'key'), 'a tie highlights nobody');

        // The same at the bottom.
        $low = $this->strengths(timeline: $this->synthetic(array_fill(0, 6, ['80', '40', '40'])));
        $this->assertNull($low['ranking']['lowest']);
        $this->assertSame(['Leitura', 'Oralidade'], $low['ranking']['lowest_tie']);
        $this->assertNotContains('lowest_mean', array_column($low['highlights']['attention'], 'key'));
    }

    #[Test]
    public function equal_means_everywhere_or_a_single_domain_highlight_nothing(): void
    {
        $equal = $this->strengths(timeline: $this->synthetic(array_fill(0, 6, ['70', '70', '70'])));
        $this->assertSame('all_equal', $equal['ranking']['state']);
        $this->assertNull($equal['ranking']['highest']);
        $this->assertNull($equal['ranking']['lowest']);

        $single = $this->strengths(timeline: $this->synthetic(array_fill(0, 6, ['70', null, null])));
        $this->assertSame('insufficient', $single['ranking']['state']);
        $this->assertNull($single['ranking']['highest']);
    }

    #[Test]
    public function counts_travel_with_their_denominators(): void
    {
        // 8 students: Escrita below the line in 3 of 8; Leitura has a value in only 6.
        $students = [
            ['40', '70', '70'], ['40', '70', '70'], ['40', '70', '70'],
            ['80', '70', '70'], ['80', '70', '70'], ['80', '70', '70'],
            ['80', null, '70'], ['80', null, '70'],
        ];

        $strengths = $this->strengths(timeline: $this->synthetic($students));
        $escrita = collect($strengths['domains'])->firstWhere('name', 'Escrita');
        $leitura = collect($strengths['domains'])->firstWhere('name', 'Leitura');

        $this->assertSame(['count' => 3, 'of' => 8, 'share' => '37.5'], $escrita['below_positive']);
        $this->assertSame(['with_value' => 6, 'of' => 8], $leitura['students']);
        $this->assertSame(['count' => 0, 'of' => 6, 'share' => '0.0'], $leitura['below_positive']);

        $texts = implode("\n", array_column($strengths['highlights']['attention'], 'text'));
        $this->assertStringContainsString('«Escrita»: 3 de 8 aluno(s) abaixo do nível positivo (37,5%), a maior proporção entre os domínios.', $texts);
        $this->assertStringContainsString('«Leitura» tem valor em 6 de 8 aluno(s).', $texts);
    }

    #[Test]
    public function the_proportion_below_the_line_is_compared_across_different_denominators_and_a_tie_names_nobody(): void
    {
        // Escrita: 1 of 4 below (25 %). Leitura: 2 of 6 below (33,3 %). Oralidade: 0 of 5.
        $students = [
            ['40', '40', '80'], ['80', '40', '80'], ['80', '80', '80'], ['80', '80', '80'],
            [null, '80', '80'], [null, '80', null],
        ];
        $strengths = $this->strengths(timeline: $this->synthetic($students));
        $keys = array_column($strengths['highlights']['attention'], 'key');
        $texts = implode("\n", array_column($strengths['highlights']['attention'], 'text'));

        $this->assertContains('highest_share_below', $keys);
        $this->assertStringContainsString('«Leitura»: 2 de 6 aluno(s) abaixo do nível positivo (33,3%)', $texts);

        // Escrita 2/8 and Leitura 2/8 are the same proportion: a tie at the top, so nobody is named.
        $tied = $this->strengths(timeline: $this->synthetic([
            ['40', '40', '80'], ['40', '40', '80'], ['80', '80', '80'], ['80', '80', '80'],
            ['80', '80', '80'], ['80', '80', '80'], ['80', '80', '80'], ['80', '80', '80'],
        ]));
        $this->assertNotContains('highest_share_below', array_column($tied['highlights']['attention'], 'key'));

        // 1/4 against 1/8 is not a tie, although the counts are equal: the proportions decide.
        $unique = $this->strengths(timeline: $this->synthetic([
            ['40', '40', '80'], ['80', '80', '80'], ['80', '80', '80'], ['80', '80', '80'],
            [null, '80', '80'], [null, '80', '80'], [null, '80', '80'], [null, '80', '80'],
        ]));
        $text = implode("\n", array_column($unique['highlights']['attention'], 'text'));
        $this->assertStringContainsString('«Escrita»: 1 de 4 aluno(s) abaixo do nível positivo (25,0%)', $text);
    }

    #[Test]
    public function a_domain_with_no_student_below_the_line_is_a_strength_stated_with_its_denominator(): void
    {
        $strengths = $this->strengths(timeline: $this->synthetic([['80', '40', '80'], ['80', '80', '80'], ['80', '80', '80'], ['80', '80', '80'], ['80', '80', '80'], ['80', '80', '80']]));

        $texts = implode("\n", array_column($strengths['highlights']['strengths'], 'text'));

        $this->assertStringContainsString('Nenhum aluno abaixo do nível positivo em «Escrita» (0 de 6).', $texts);
        $this->assertStringContainsString('Nenhum aluno abaixo do nível positivo em «Oralidade» (0 de 6).', $texts);
        $this->assertStringNotContainsString('em «Leitura» (0 de', $texts);
    }

    #[Test]
    public function students_below_the_line_in_two_or_more_domains_are_counted_and_listed(): void
    {
        $students = [
            ['40', '40', '80'],   // two
            ['40', '40', '40'],   // three
            ['40', '80', '80'],   // one
            ['80', '80', '80'],   // none
            ['40', null, null],   // one value only: counted, below in one
            [null, null, null],   // no value anywhere: not in the denominator
        ];
        $strengths = $this->strengths(timeline: $this->synthetic($students));
        $below = $strengths['students_below'];

        $this->assertTrue($below['known']);
        $this->assertSame(5, $below['of']);
        $this->assertSame([1, 2, 2], [$below['none'], $below['one'], $below['two_or_more']]);
        $this->assertSame(['Aluno 1', 'Aluno 2'], array_column($below['students'], 'name'));
        $this->assertSame([2, 3], array_map(fn (array $student): int => count($student['domains']), $below['students']));
        $this->assertSame(3, $below['students'][0]['domains_with_value']);

        $attention = implode("\n", array_column($strengths['highlights']['attention'], 'text'));
        $this->assertStringContainsString('2 de 5 aluno(s) com resultado abaixo do nível positivo em dois ou mais domínios.', $attention);
    }

    #[Test]
    public function without_a_line_between_positive_and_negative_nothing_is_counted_below_it(): void
    {
        $strengths = $this->strengths(timeline: $this->synthetic(array_fill(0, 6, ['40', '40', '80']), withScale: false));

        $this->assertFalse($strengths['negative_line']['known']);
        $this->assertSame('scale_unknown', $strengths['negative_line']['reason']);
        $this->assertFalse($strengths['students_below']['known']);
        $this->assertSame([], $strengths['students_below']['students']);
        foreach ($strengths['domains'] as $row) {
            $this->assertNull($row['below_positive']);
        }
        $this->assertNotContains('none_below', array_column($strengths['highlights']['strengths'], 'key'));
        $this->assertNotContains('below_in_two_or_more', array_column($strengths['highlights']['attention'], 'key'));
    }

    #[Test]
    public function coverage_is_said_for_a_domain_with_no_result_few_students_partial_coverage_and_incompleteness(): void
    {
        $students = [
            ['80', '80', null], ['80', '80', null], ['80', '80', null], ['80', '80', null],
            ['80', '80', null], ['80', '80', null], ['80', null, null], ['80', null, null],
        ];
        // Leitura has 6 of 8; Oralidade has none; Escrita is partial for two students.
        $timeline = $this->synthetic($students);
        $timeline['students'][0]['cells']['period:one']['domains']['dom-0']['coverage_warning'] = true;
        $timeline['students'][1]['cells']['period:one']['domains']['dom-0']['coverage_warning'] = true;
        // And a fourth domain with a value for only three students.
        $timeline['domains'][] = ['ulid' => 'dom-3', 'name' => 'Escuta', 'weight_percent' => null];
        foreach ([0, 1, 2] as $index) {
            $timeline['students'][$index]['cells']['period:one']['domains']['dom-3'] = ['value' => '70', 'band' => ['code' => '2', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false], 'coverage_warning' => false];
        }

        $strengths = $this->strengths(timeline: $timeline);
        $rows = collect($strengths['domains'])->keyBy('name');

        $this->assertSame('incomplete', $rows['Escrita']['coverage']['state']);
        $this->assertSame(2, $rows['Escrita']['coverage']['partial']);
        $this->assertSame('incomplete', $rows['Leitura']['coverage']['state']);
        $this->assertSame('none', $rows['Oralidade']['coverage']['state']);
        $this->assertSame('few', $rows['Escuta']['coverage']['state']);

        $texts = implode("\n", array_column($strengths['highlights']['attention'], 'text'));
        $this->assertStringContainsString('«Escrita»: cobertura parcial em 2 de 8 aluno(s) com valor.', $texts);
        $this->assertStringContainsString('«Leitura» tem valor em 6 de 8 aluno(s).', $texts);
        $this->assertStringContainsString('Sem resultados em «Oralidade» neste momento.', $texts);
        $this->assertStringContainsString('«Escuta» tem valor em apenas 3 de 8 aluno(s): leia-o com cautela.', $texts);
    }

    #[Test]
    public function few_students_with_a_result_carry_a_caution(): void
    {
        $strengths = $this->strengths(timeline: $this->synthetic(array_fill(0, 3, ['80', '60', '40'])));

        $this->assertSame(['few_students'], array_column($strengths['cautions'], 'code'));
        $this->assertStringContainsString('apenas 3 aluno(s)', $strengths['cautions'][0]['text']);
    }

    #[Test]
    public function the_words_never_diagnose_or_recommend(): void
    {
        $strengths = $this->strengths(timeline: $this->synthetic(array_fill(0, 6, ['80', '60', '40'])));
        $all = mb_strtolower((string) json_encode($strengths, JSON_UNESCAPED_UNICODE));

        $this->assertStringContainsString('não é um diagnóstico de dificuldades de aprendizagem', $all);

        $rest = str_replace('não é um diagnóstico de dificuldades de aprendizagem', '', $all);
        foreach (['dislexia', 'dificuldade de aprendizagem', 'recomend', 'deveria', 'sugerimos', 'provavelmente'] as $word) {
            $this->assertStringNotContainsString($word, $rest, "«{$word}» must not be said");
        }
    }
}
