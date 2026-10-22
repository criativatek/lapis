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
use App\Services\Assessment\Advanced\BuildClassTrends;
use App\Services\Assessment\Advanced\BuildStudentYear;
use App\Services\Assessment\Advanced\TimelineCriteria;
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
 * D2 — «Ao longo do ano»: um aluno ao longo de todos os momentos, sobre a turma REAL da demo
 * (Carolina Nunes tem resultado nas duas épocas; Diogo Ferreira e Filipe Andrade só na segunda).
 *
 * O QUE ESTES TESTES DEFENDEM: que o aluno e a turma não discordam sobre quem subiu (a mesma função);
 * que os números são os da progressão; que nada se subtrai sem demonstrar comparabilidade e que o
 * gráfico não atravessa uma mudança de regra; que é Pro até ao serviço e que o Base não recebe sequer
 * a chave; que só este aluno sai da turma; e que o custo da página não cresce com a turma.
 */
class StudentYearTest extends TestCase
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

    private function enrollment(string $name): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => $this->class()->enrollments()->with('student.identity')->get()
            ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === $name));
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
    private function year(string $name, ?string $from = null, ?string $to = null, ?array $timeline = null): array
    {
        $ulid = $this->enrollment($name)->ulid;

        return $this->asTenant(fn (): array => app(BuildStudentYear::class)->for($timeline ?? $this->timeline(), $ulid, $from, $to));
    }

    /** @return array{string, string} */
    private function periodKeys(): array
    {
        return $this->asTenant(function (): array {
            $periods = $this->class()->academicYear->periods()->orderBy('sequence')->get();

            return ['period:'.$periods[0]->ulid, 'period:'.$periods[1]->ulid];
        });
    }

    private function decide(string $name, string $code, int $sequence): void
    {
        [$classUlid, $periodUlid, $levelId] = $this->asTenant(function () use ($code, $sequence): array {
            $class = $this->class();

            return [
                $class->ulid,
                $class->academicYear->periods()->where('sequence', $sequence)->firstOrFail()->ulid,
                $class->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id,
            ];
        });

        $this->actingAs($this->teacher)->post("/classes/{$classUlid}/classifications/{$periodUlid}/{$this->enrollment($name)->ulid}/decide", ['final_scale_level_id' => $levelId])
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

    // ================================================================= seleção

    #[Test]
    public function it_compares_the_two_latest_moments_this_student_has_a_result_in(): void
    {
        [$first, $second] = $this->periodKeys();
        $year = $this->year('Carolina Nunes');

        $this->assertSame('ready', $year['state']);
        $this->assertSame([$first, $second], [$year['from']['key'], $year['to']['key']]);
        $this->assertSame([$first, $second], array_column($year['options'], 'key'));
        $this->assertTrue($year['selection']['defaulted']);
    }

    #[Test]
    public function the_moments_offered_are_the_students_own_not_the_classs(): void
    {
        [, $second] = $this->periodKeys();

        // Diogo Ferreira only has a result in the second period.
        $diogo = $this->year('Diogo Ferreira');

        $this->assertSame('unavailable', $diogo['state']);
        $this->assertSame([$second], array_column($diogo['options'], 'key'));
        $this->assertSame('Este aluno só tem resultado em 1 momento: a comparação precisa de pelo menos 2.', $diogo['reason']);
        $this->assertNull($diogo['result']);
        $this->assertFalse($diogo['series']['enough']);
    }

    #[Test]
    public function a_student_with_no_result_anywhere_is_told_so_and_not_given_an_empty_comparison(): void
    {
        $this->asTenant(function (): void {
            $class = $this->class();
            $organization = $this->teacher->personalOrganization();
            $student = Student::factory()->recycle($organization)->create();
            StudentIdentity::create(['student_id' => $student->getKey(), 'organization_id' => $organization->getKey(), 'display_name' => 'Aluno Sem Resultados']);
            Enrollment::factory()->recycle($organization)->create([
                'class_id' => $class->getKey(), 'student_id' => $student->getKey(), 'class_number' => 9, 'enrolled_on' => '2026-09-14',
            ]);
        });

        $year = $this->year('Aluno Sem Resultados');

        $this->assertSame('unavailable', $year['state']);
        $this->assertSame('Este aluno ainda não tem resultado em nenhum momento: não há nada para comparar.', $year['reason']);
        $this->assertSame([], $year['options']);
    }

    #[Test]
    public function an_enrolment_that_is_not_in_the_timeline_says_so(): void
    {
        $year = $this->asTenant(fn (): array => app(BuildStudentYear::class)->for($this->timeline(), str_repeat('0', 26)));

        $this->assertSame('unavailable', $year['state']);
        $this->assertSame('Este aluno não consta da avaliação desta turma.', $year['reason']);
    }

    // ================================================================= os números são os de sempre

    #[Test]
    public function the_movement_of_the_result_and_of_each_domain_is_the_progressions_evolution(): void
    {
        $year = $this->year('Carolina Nunes');
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->class()));
        $domainUlid = collect($progression['domains'])->pluck('ulid', 'id')->all();
        $student = collect($progression['students'])->firstWhere('name', 'Carolina Nunes');

        $own = $student['periods'][1];
        $this->assertSame($own['evolution']['direction'], $year['result']['movement']['state']);
        $this->assertSame($own['evolution']['points'], $year['result']['movement']['points']);
        $this->assertSame($own['weighted_average'], $year['result']['to']['value']);

        $checked = 0;
        foreach ($own['domains'] as $row) {
            $domain = collect($year['domains'])->firstWhere('ulid', $domainUlid[$row['domain_id']]);

            $this->assertSame($row['evolution']['direction'] ?? 'unavailable', $domain['movement']['state'], $domain['name']);
            $checked += isset($row['evolution']) ? 1 : 0;
        }
        $this->assertGreaterThan(0, $checked, 'the check is not vacuous');
    }

    #[Test]
    public function the_student_and_the_class_never_disagree_about_who_moved(): void
    {
        $this->decide('Carolina Nunes', '2', 1);
        $this->decide('Carolina Nunes', '4', 2);

        $timeline = $this->timeline();
        $class = $this->asTenant(fn (): array => app(BuildAssessmentComparison::class)->between($timeline));

        foreach (['Carolina Nunes', 'Ana Marques', 'Bruno Teixeira', 'Eva Salgado'] as $name) {
            $year = $this->year($name, timeline: $timeline);
            $row = collect($class['students'])->firstWhere('name', $name);

            $this->assertSame($row['movement'], $year['result']['movement'], $name);
            $this->assertSame($row['result_level'], $year['result']['level'], $name);
            $this->assertSame($row['assigned'], $year['assigned'], $name);
            foreach ($year['domains'] as $domain) {
                $this->assertSame($row['domains'][$domain['ulid']]['movement'], $domain['movement'], "{$name}: {$domain['name']}");
            }
        }
    }

    #[Test]
    public function a_change_of_level_and_the_crossing_of_the_scales_line_are_said_for_the_result_and_the_assigned_level(): void
    {
        $this->decide('Carolina Nunes', '2', 1);
        $this->decide('Carolina Nunes', '4', 2);

        $year = $this->year('Carolina Nunes');

        $this->assertSame('to_positive', $year['assigned']['change']['crossed_line']);
        $this->assertSame(['2', '4'], [$year['assigned']['change']['from']['code'], $year['assigned']['change']['to']['code']]);

        // The result's band follows its own figures, on the scale's order.
        $level = $year['result']['level'];
        $this->assertContains($level['state'], ['up', 'same', 'down']);
        $this->assertSame($year['result']['to']['band']['sequence'] <=> $year['result']['from']['band']['sequence'], ['down' => -1, 'same' => 0, 'up' => 1][$level['state']]);

        // Domains carry the bands at both ends and the change.
        foreach ($year['domains'] as $domain) {
            $this->assertArrayHasKey('from_band', $domain);
            $this->assertArrayHasKey('level', $domain);
        }
    }

    // ================================================================= sem comparação artificial

    #[Test]
    public function under_other_criteria_nothing_is_subtracted_and_the_chart_leaves_the_other_moment_out_and_says_so(): void
    {
        [, $second] = $this->periodKeys();
        $moment = $this->keep('Outros pesos');
        $this->rewrite($moment, function (array $payload): array {
            $payload['domains'][0]['weight_percent'] = '99.0000';

            return $payload;
        });

        $year = $this->year('Carolina Nunes', 'moment:'.$moment, $second);

        $this->assertFalse($year['comparability']['percentages']);
        $this->assertSame(['criteria_differ'], array_column($year['comparability']['reasons'], 'code'));
        $this->assertSame(['state' => 'unavailable', 'points' => null, 'reason' => 'not_comparable'], $year['result']['movement']);
        foreach ($year['domains'] as $domain) {
            $this->assertNull($domain['movement']['points']);
        }

        // The chart is drawn on the moments sharing the criteria of the latest one: the photograph is out.
        $this->assertNotContains('moment:'.$moment, array_column($year['series']['points'], 'key'));
        $this->assertSame(1, $year['series']['left_out']);
        $this->assertNotNull($year['series']['note']);
    }

    #[Test]
    public function a_scale_that_is_not_demonstrably_the_same_refuses_the_levels_only(): void
    {
        [, $second] = $this->periodKeys();
        $moment = $this->keep('Antes da escala viajar');
        $this->rewrite($moment, function (array $payload): array {
            unset($payload['scale']);
            $payload['version'] = 2;

            return $payload;
        });

        $year = $this->year('Carolina Nunes', 'moment:'.$moment, $second);

        $this->assertTrue($year['comparability']['percentages']);
        $this->assertFalse($year['comparability']['levels']);
        $this->assertContains($year['result']['movement']['state'], ['up', 'down', 'flat']);
        $this->assertSame('unavailable', $year['result']['level']['state']);
    }

    // ================================================================= o gráfico factual

    #[Test]
    public function the_series_are_the_students_figures_across_the_comparable_moments(): void
    {
        $timeline = $this->timeline();
        $year = $this->year('Carolina Nunes', timeline: $timeline);
        $series = $year['series'];

        $this->assertTrue($series['enough']);
        $this->assertSame(0, $series['left_out']);
        $this->assertNull($series['note']);
        $this->assertSame(array_column($year['options'], 'key'), array_column($series['points'], 'key'));

        $student = collect($timeline['students'])->firstWhere('name', 'Carolina Nunes');
        foreach ($series['points'] as $index => $point) {
            $this->assertSame($student['cells'][$point['key']]['result']['value'], $series['result'][$index]);
        }

        // A domain with no value anywhere has no line; one with values keeps its gaps (never a zero).
        foreach ($series['domains'] as $domain) {
            $this->assertCount(count($series['points']), $domain['values']);
            $this->assertNotEmpty(array_filter($domain['values'], fn ($value): bool => $value !== null));
        }
    }

    #[Test]
    public function a_kept_moment_enters_the_series_when_it_shares_the_criteria_and_is_marked_as_a_photograph(): void
    {
        $moment = $this->keep('Fotografia de dezembro');
        $series = $this->year('Carolina Nunes')['series'];

        $kept = collect($series['points'])->firstWhere('key', 'moment:'.$moment);

        $this->assertNotNull($kept);
        $this->assertSame(['kept_moment', 'frozen'], [$kept['kind'], $kept['basis_kind']]);
        $this->assertCount(3, $series['points']);
    }

    // ================================================================= origem, cautelas

    #[Test]
    public function the_origin_of_the_data_is_said_for_each_end(): void
    {
        $year = $this->year('Carolina Nunes');

        $this->assertSame('Calculado com os dados de hoje', $year['from']['basis']['label']);
        $this->assertStringContainsString('calculados com os dados de hoje', $year['basis_note']);

        $moment = $this->keep('Momento');
        [, $second] = $this->periodKeys();
        $mixed = $this->year('Carolina Nunes', 'moment:'.$moment, $second);
        $this->assertSame('Guardado a 15/12/2026', $mixed['from']['basis']['label']);
        $this->assertStringContainsString('inclui as correções feitas entretanto', $mixed['basis_note']);
    }

    #[Test]
    public function the_explanations_say_which_reading_this_is_and_that_a_fall_is_not_a_diagnosis(): void
    {
        $year = $this->year('Carolina Nunes');

        $this->assertSame(['reading', 'movement', 'levels', 'comparable', 'basis'], array_column($year['explanations'], 'key'));
        $this->assertStringContainsString('leitura contínua', $year['explanations'][0]['text']);
        $this->assertStringContainsString('não explicam causas nem fazem um diagnóstico', $year['explanations'][4]['text']);
    }

    // ================================================================= privacidade

    #[Test]
    public function only_this_student_leaves_the_class_and_no_count_of_classmates_travels(): void
    {
        $this->decide('Carolina Nunes', '4', 2);
        $this->asTenant(fn () => Classification::query()->update(['override_reason' => 'MOTIVO-LIVRE-RESERVADO']));

        $json = (string) json_encode($this->year('Carolina Nunes'), JSON_UNESCAPED_UNICODE);

        foreach (['Ana Marques', 'Bruno Teixeira', 'Diogo Ferreira', 'Eva Salgado', 'Filipe Andrade'] as $classmate) {
            $this->assertStringNotContainsString($classmate, $json, "{$classmate} must not travel");
        }
        $this->assertStringNotContainsString('MOTIVO-LIVRE-RESERVADO', $json);
        foreach (['students_total', 'students_with_result', 'enrollment_id', 'domain_id', 'period_id', 'scale_level_id', 'student_id', 'self_assessment'] as $forbidden) {
            $this->assertStringNotContainsString("\"{$forbidden}\"", $json, "«{$forbidden}» must not travel");
        }
    }

    // ================================================================= Pro até ao serviço

    #[Test]
    public function the_service_refuses_what_the_plan_does_not_include(): void
    {
        $timeline = $this->timeline();
        $ulid = $this->enrollment('Carolina Nunes')->ulid;
        $this->subscribe('base');

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        app(BuildStudentYear::class)->for($timeline, $ulid);
    }

    #[Test]
    public function a_suspended_subscription_does_not_get_a_new_reading_either(): void
    {
        $timeline = $this->timeline();
        $ulid = $this->enrollment('Carolina Nunes')->ulid;
        $this->subscribe('pro', SubscriptionStatus::Suspended);

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        app(BuildStudentYear::class)->for($timeline, $ulid);
    }

    // ================================================================= a página «Evolução do Aluno»

    private function page(string $name = 'Carolina Nunes', string $query = ''): TestResponse
    {
        $class = $this->asTenant(fn (): string => $this->class()->ulid);

        return $this->actingAs($this->teacher)->get("/classes/{$class}/evolucao/{$this->enrollment($name)->ulid}{$query}");
    }

    #[Test]
    public function a_pro_teacher_sees_the_section_on_the_student_page(): void
    {
        [$first, $second] = $this->periodKeys();

        $this->page()->assertOk()->assertInertia(fn ($page) => $page
            ->component('student-progress/Show')
            ->where('yearReading.state', 'ready')
            ->where('yearReading.from.key', $first)
            ->where('yearReading.to.key', $second)
            ->has('yearReading.domains')
            ->has('yearReading.series.points')
            ->has('pro'));

        // Reversed in the URL: ordered in time by the server.
        $this->page('Carolina Nunes', '?de='.$second.'&para='.$first)->assertOk()->assertInertia(fn ($page) => $page
            ->where('yearReading.selection.swapped', true)
            ->where('yearReading.from.key', $first));
    }

    #[Test]
    public function a_base_organization_receives_no_trace_of_the_section(): void
    {
        $this->subscribe('base');

        $response = $this->page()->assertOk();

        $response->assertInertia(fn ($page) => $page->component('student-progress/Show')->missing('yearReading')->missing('pro'));
        $this->assertStringNotContainsString('yearReading', (string) $response->getContent());
    }

    #[Test]
    public function a_suspended_organization_does_not_receive_it_either(): void
    {
        $this->subscribe('pro', SubscriptionStatus::Suspended);

        $this->page()->assertOk()->assertInertia(fn ($page) => $page->missing('yearReading'));
    }

    #[Test]
    public function a_malformed_moment_in_the_url_is_a_validation_error(): void
    {
        $this->page('Carolina Nunes', '?de=nao-e-um-momento')->assertSessionHasErrors('de');
    }

    #[Test]
    public function the_page_carries_only_this_students_year(): void
    {
        $this->page('Carolina Nunes')->assertOk()->assertInertia(function ($page): void {
            $year = (string) json_encode($page->toArray()['props']['yearReading'], JSON_UNESCAPED_UNICODE);

            foreach (['Ana Marques', 'Bruno Teixeira', 'Diogo Ferreira', 'Eva Salgado', 'Filipe Andrade'] as $classmate) {
                $this->assertStringNotContainsString($classmate, $year);
            }
        });
    }

    #[Test]
    public function a_class_the_teacher_is_not_assigned_to_stays_forbidden_with_the_section_in_place(): void
    {
        $other = $this->asTenant(function (): SchoolClass {
            $demo = $this->class();

            return SchoolClass::create([
                'academic_year_id' => $demo->academic_year_id, 'subject_id' => $demo->subject_id,
                'label' => '7.º B', 'grade_level' => '7.º', 'status' => 'active',
            ]);
        });

        $this->actingAs($this->teacher)->get("/classes/{$other->ulid}/evolucao/{$this->enrollment('Carolina Nunes')->ulid}")->assertForbidden();
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
        $enrollment = $this->enrollment('Carolina Nunes')->ulid;
        $class = $this->asTenant(fn (): string => $this->class()->ulid);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->teacher)->get("/classes/{$class}/evolucao/{$enrollment}")->assertOk();
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

    #[Test]
    public function the_section_does_not_change_what_the_rest_of_the_page_says(): void
    {
        // The Base reading of the same page, key by key: nothing the student page already said moved.
        $proProps = [];
        $this->page()->assertInertia(function ($page) use (&$proProps): void {
            $proProps = $page->toArray()['props'];
        });
        $this->subscribe('base');
        $baseProps = [];
        $this->page()->assertInertia(function ($page) use (&$baseProps): void {
            $baseProps = $page->toArray()['props'];
        });

        $this->assertSame($baseProps['moments'], $proProps['moments']);
        $this->assertSame($baseProps['domains'], $proProps['domains']);
        $this->assertSame($baseProps['headline'], $proProps['headline']);
    }
    // ================================================================= tendências e resultados negativos persistentes (D4)

    #[Test]
    public function the_students_trajectories_are_the_same_reading_the_class_makes_of_that_student(): void
    {
        $this->keep('Momento A');
        $this->keep('Momento B');
        $timeline = $this->timeline();
        $class = $this->asTenant(fn (): array => app(BuildClassTrends::class)->for($timeline));

        foreach (['Carolina Nunes', 'Ana Marques', 'Diogo Ferreira'] as $name) {
            $trends = $this->year($name, timeline: $timeline)['trends'];
            $row = collect($class['students'])->firstWhere('name', $name);

            $this->assertSame($row['global'], $trends['result'], $name);
            $this->assertSame(array_column($class['points'], 'key'), array_column($trends['points'], 'key'));
            foreach ($trends['domains'] as $domain) {
                $this->assertSame($row['domains'][$domain['ulid']]['trend'], $domain['trend'], "{$name}: {$domain['name']}");
                $this->assertSame($row['domains'][$domain['ulid']]['persistent'], $domain['persistent'], "{$name}: {$domain['name']}");
            }
        }
    }

    #[Test]
    public function with_a_series_of_four_the_student_has_a_trend_with_its_steps(): void
    {
        $this->keep('Momento A');
        $this->keep('Momento B');

        $trends = $this->year('Carolina Nunes')['trends'];

        $this->assertTrue($trends['availability']['trends']);
        $this->assertNotSame('insufficient', $trends['result']['trend']['state']);
        $this->assertSame($trends['result']['trend']['with_value'] - 1, count($trends['result']['trend']['steps']));
        $this->assertSame(['Momento A', 'Momento B', '1.º Semestre', '2.º Semestre'], array_column($trends['points'], 'label'));
    }

    #[Test]
    public function a_student_with_one_moment_still_gets_the_persistence_answer_and_no_trend(): void
    {
        // Diogo Ferreira only has a result in the second period.
        $trends = $this->year('Diogo Ferreira')['trends'];

        $this->assertSame('insufficient', $trends['result']['trend']['state']);
        $this->assertContains($trends['result']['persistent']['state'], ['no', 'unknown']);
        $this->assertSame([null, true], [$trends['result']['values'][0], $trends['result']['values'][1] !== null]);
    }

    #[Test]
    public function a_moment_under_other_criteria_is_left_out_of_the_students_line_and_said(): void
    {
        $this->keep('Antes');
        $other = $this->keep('Outros pesos');
        $this->rewrite($other, function (array $payload): array {
            $payload['domains'][0]['weight_percent'] = '99.0000';

            return $payload;
        });

        $trends = $this->year('Carolina Nunes')['trends'];

        $this->assertSame(['Outros pesos'], array_column($trends['left_out'], 'label'));
        $this->assertSame(['criteria_differ'], array_column($trends['left_out'][0]['reasons'], 'code'));
    }

    /**
     * A timeline written by hand, three points under the same criteria, with one student.
     *
     * @param  list<string|null>  $values
     * @return array<string, mixed>
     */
    private function syntheticFor(array $values): array
    {
        $scale = ['name' => 'Escala de teste', 'kind' => 'levels', 'levels' => [
            ['code' => '1', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true],
            ['code' => '2', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false],
        ]];
        $figure = fn (?string $value): array => ['value' => $value, 'band' => $value === null ? null : ((float) $value < 50
            ? ['code' => '1', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true]
            : ['code' => '2', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false]), 'coverage_warning' => false];

        $points = [];
        $cells = [];
        foreach ($values as $index => $value) {
            $key = 'period:p'.($index + 1);
            $points[] = [
                'key' => $key, 'kind' => 'period', 'label' => 'Momento '.($index + 1), 'period_ulid' => 'p'.($index + 1), 'closes_the_year' => false,
                'basis' => ['kind' => 'live', 'label' => 'Calculado com os dados de hoje', 'as_of' => null],
                'level_signature' => TimelineCriteria::levels($scale), 'percentage_signature' => 'P', 'notices' => [], 'ordinal' => $index + 1,
                'students_total' => 1, 'students_with_result' => $value === null ? 0 : 1, 'partial_coverage' => 0, 'has_results' => true, 'few_students' => true,
            ];
            $cells[$key] = ['result' => $figure($value), 'domains' => ['dom-0' => $figure($value)], 'classification' => null, 'participation' => null];
        }

        return [
            'class_ulid' => 'cls', 'scale' => $scale, 'domains' => [['ulid' => 'dom-0', 'name' => 'Escrita', 'weight_percent' => '100.0000']],
            'points' => $points,
            'students' => [['enrollment_ulid' => 'enr-0', 'name' => 'Aluno', 'class_number' => 1, 'cells' => $cells]],
            'availability' => ['points_with_results' => count($values), 'comparable_points' => count($values), 'comparison' => true, 'trends' => true, 'reason' => null],
            'notices' => [], 'context' => [],
        ];
    }

    #[Test]
    public function two_consecutive_moments_below_the_line_including_the_latest_are_said_with_their_values(): void
    {
        $year = $this->asTenant(fn (): array => app(BuildStudentYear::class)->for($this->syntheticFor(['70', '40', '45']), 'enr-0'));
        $result = $year['trends']['result'];

        $this->assertSame('yes', $result['persistent']['state']);
        $this->assertSame(['Momento 2', 'Momento 3'], array_column($result['persistent']['points'], 'label'));
        $this->assertSame(['40', '45'], array_column($result['persistent']['points'], 'value'));
        $this->assertSame('variable', $result['trend']['state']);
        $this->assertSame('yes', $year['trends']['domains'][0]['persistent']['state']);
    }

    #[Test]
    public function the_distinction_between_a_trend_and_a_persistent_negative_holds_for_the_student(): void
    {
        $two = $this->asTenant(fn (): array => app(BuildStudentYear::class)->for($this->syntheticFor(['40', '45']), 'enr-0'));

        $this->assertSame('yes', $two['trends']['result']['persistent']['state']);
        $this->assertSame('insufficient', $two['trends']['result']['trend']['state']);
        $this->assertFalse($two['trends']['availability']['trends']);
        $this->assertTrue($two['trends']['availability']['persistence']);
    }

    #[Test]
    public function a_gap_is_not_a_zero_and_it_breaks_the_run_for_the_student(): void
    {
        $year = $this->asTenant(fn (): array => app(BuildStudentYear::class)->for($this->syntheticFor(['40', null, '45']), 'enr-0'));
        $result = $year['trends']['result'];

        $this->assertSame(['40', null, '45'], $result['values']);
        $this->assertSame('no', $result['persistent']['state']);
        $this->assertSame('no_value', $result['persistent']['stopped_by']);
        $this->assertSame('insufficient', $result['trend']['state']);
    }

    #[Test]
    public function the_students_trajectories_carry_no_classmates_and_no_counts_of_them(): void
    {
        $this->keep('Momento A');
        $json = (string) json_encode($this->year('Carolina Nunes')['trends'], JSON_UNESCAPED_UNICODE);

        foreach (['Ana Marques', 'Bruno Teixeira', 'Diogo Ferreira', 'Eva Salgado', 'Filipe Andrade'] as $classmate) {
            $this->assertStringNotContainsString($classmate, $json);
        }
        foreach (['students_total', 'students_with_result', 'enrollment_id', 'domain_id', 'period_id'] as $forbidden) {
            $this->assertStringNotContainsString("\"{$forbidden}\"", $json);
        }
    }

    #[Test]
    public function the_page_carries_the_students_trends_only_for_pro(): void
    {
        $this->page()->assertOk()->assertInertia(fn ($page) => $page
            ->has('yearReading.trends.result.trend.state')
            ->has('yearReading.trends.domains')
            ->has('yearReading.trends.availability'));

        $this->subscribe('base');
        $response = $this->page()->assertOk();
        $response->assertInertia(fn ($page) => $page->missing('yearReading'));
        $this->assertStringNotContainsString('persistent', (string) $response->getContent());
    }
}
