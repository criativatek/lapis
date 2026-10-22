<?php

namespace Tests\Feature\Assessment\Advanced;

use App\Models\AcademicPeriod;
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
use App\Services\Assessment\Advanced\BuildAssessmentTimeline;
use App\Services\Assessment\Advanced\TimelineComparability;
use App\Services\Assessment\Advanced\TimelineMovement;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Assessment\AssessmentCutoff;
use App\Support\Entitlements\Entitlements;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The foundation of the advanced analysis (D0): the class laid along time — over the REAL
 * demo class, whose two periods both have results.
 *
 * What it holds: that the timeline is the progression's figures and the frozen photographs'
 * and nothing else (parity, to the value); that a kept moment never reads the present; that
 * what cannot be shown comparable is not; that «enough data» is said plainly; that it is Pro
 * all the way down (the SERVICE refuses); and that nothing private travels and the cost does
 * not grow with the class.
 */
class BuildAssessmentTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        // Inside the 1.º Semestre of the demo (14/09/2026 to 29/01/2027).
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->subscribe('pro');

        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $class->academicYear->periods()->where('sequence', 1)->firstOrFail());
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

    /**
     * @return array<string, mixed>
     */
    private function timeline(bool $withKeptMoments = true, ?array $progression = null): array
    {
        return $this->asTenant(fn (): array => app(BuildAssessmentTimeline::class)->for($this->class(), $progression, $withKeptMoments));
    }

    /**
     * @return array<string, mixed>
     */
    private function progression(): array
    {
        return $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->class()));
    }

    private function decide(string $name, string $code): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid, $levelId] = $this->asTenant(function () use ($name, $code): array {
            $class = $this->class();

            return [
                $class->ulid,
                $class->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid,
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

    /** Rewrites a kept payload and its seal, as a photograph of another era would have been. */
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
     * @param  array<string, mixed>  $timeline
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $timeline): array
    {
        $byName = [];

        foreach ($timeline['students'] as $student) {
            $byName[$student['name']] = $student;
        }

        return $byName;
    }

    // ================================================================= Pro, até ao serviço

    #[Test]
    public function the_service_itself_refuses_an_organization_without_advanced_analytics(): void
    {
        $this->subscribe('base');
        $this->expectException(AdvancedAnalyticsUnavailable::class);

        $this->timeline();
    }

    #[Test]
    public function pro_may_and_a_blocked_module_may_not_and_a_suspended_subscription_may_not(): void
    {
        $this->assertNotEmpty($this->timeline()['points']);

        // A suspended Pro is read-only: like every other Pro reading, no new insight is produced.
        $this->subscribe('pro', SubscriptionStatus::Suspended);
        $this->assertSame('read_only', app(Entitlements::class)->accessStateFor($this->teacher->personalOrganization(), 'advanced_analytics')->value);

        try {
            $this->timeline();
            $this->fail('a suspended organization must not receive the advanced reading');
        } catch (AdvancedAnalyticsUnavailable) {
            $this->addToAssertionCount(1);
        }

        // An override that takes the module away wins over the plan.
        $this->subscribe('pro');
        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'module_id' => Module::where('key', 'advanced_analytics')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        $this->timeline();
    }

    #[Test]
    public function without_an_organization_in_context_nothing_is_built(): void
    {
        $this->expectException(AdvancedAnalyticsUnavailable::class);

        $class = $this->asTenant(fn (): SchoolClass => $this->class());
        app(BuildAssessmentTimeline::class)->for($class);
    }

    #[Test]
    public function nothing_in_the_application_uses_the_timeline_yet_so_no_base_path_can_reach_it(): void
    {
        // D0 has no screen and no route. The first step that adds one must add the module gate with
        // it — and, in doing so, update this list on purpose.
        $users = [];

        foreach ((new Finder)->files()->in([base_path('app'), base_path('routes'), base_path('resources/js')])->name(['*.php', '*.vue', '*.ts']) as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), 'BuildAssessmentTimeline')) {
                $users[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame(['app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Assessment'.DIRECTORY_SEPARATOR.'Advanced'.DIRECTORY_SEPARATOR.'BuildAssessmentTimeline.php'], $users);
    }

    // ================================================================= a linha temporal é a progressão

    #[Test]
    public function the_points_are_the_periods_of_the_class_in_order_and_say_they_were_calculated_today(): void
    {
        $timeline = $this->timeline();
        $periods = $this->progression()['periods'];

        $this->assertSame(array_map(fn (array $period): string => 'period:'.$period['ulid'], $periods), array_column($timeline['points'], 'key'));
        $this->assertSame([1, 2], array_column($timeline['points'], 'ordinal'));
        $this->assertSame(array_column($periods, 'label'), array_column($timeline['points'], 'label'));

        foreach ($timeline['points'] as $point) {
            $this->assertSame('live', $point['basis']['kind']);
            $this->assertSame('Calculado com os dados de hoje', $point['basis']['label']);
            $this->assertNull($point['basis']['as_of']);
        }
    }

    #[Test]
    public function every_figure_is_the_progressions_to_the_value(): void
    {
        $timeline = $this->timeline();
        $progression = $this->progression();
        $domainUlid = collect($progression['domains'])->pluck('ulid', 'id')->all();
        $byName = $this->byName($timeline);

        $this->assertCount(count($progression['students']), $timeline['students']);

        foreach ($progression['students'] as $student) {
            foreach ($progression['periods'] as $index => $period) {
                $row = $student['periods'][$index];
                $cell = $byName[$student['name']]['cells']['period:'.$period['ulid']];

                $this->assertSame($row['weighted_average'], $cell['result']['value'], "{$student['name']}: {$period['label']}: result");
                $this->assertSame((bool) $row['coverage_warning'], $cell['result']['coverage_warning']);

                foreach ($row['domains'] as $domainRow) {
                    $this->assertSame($domainRow['weighted_average'], $cell['domains'][$domainUlid[$domainRow['domain_id']]]['value'], "{$student['name']}: {$period['label']}: domain");
                }

                $this->assertSame($row['classification']['final']['code'] ?? null, $cell['classification']['assigned']['code'] ?? null, "{$student['name']}: {$period['label']}: assigned level");
                $this->assertSame($row['classification']['status'] ?? null, $cell['classification']['status'] ?? null);
            }
        }
    }

    #[Test]
    public function the_movement_between_two_periods_is_the_progressions_evolution_for_the_result_and_every_domain(): void
    {
        $timeline = $this->timeline();
        $progression = $this->progression();
        [$first, $second] = $timeline['points'];
        $comparability = TimelineComparability::between($first, $second);
        $domainUlid = collect($progression['domains'])->pluck('ulid', 'id')->all();
        $compared = 0;

        foreach ($progression['students'] as $student) {
            $cells = $this->byName($timeline)[$student['name']]['cells'];
            $own = $student['periods'][1];

            $movement = TimelineMovement::between($cells[$first['key']]['result'], $cells[$second['key']]['result'], $comparability);
            $this->assertSame($own['evolution'], $movement['percentages'], "{$student['name']}: result");
            $compared += $movement['percentages'] === null ? 0 : 1;

            foreach ($own['domains'] as $domainRow) {
                $ulid = $domainUlid[$domainRow['domain_id']];
                $domainMovement = TimelineMovement::between($cells[$first['key']]['domains'][$ulid] ?? null, $cells[$second['key']]['domains'][$ulid] ?? null, $comparability);

                $this->assertSame($domainRow['evolution'], $domainMovement['percentages'], "{$student['name']}: domain");
            }
        }

        $this->assertGreaterThan(0, $compared, 'the demo class has students with both periods: the check is not vacuous');
    }

    #[Test]
    public function the_change_of_level_is_read_on_the_scales_own_order_and_line(): void
    {
        $timeline = $this->timeline();
        [$first, $second] = $timeline['points'];
        $comparability = TimelineComparability::between($first, $second);
        $levels = collect($timeline['scale']['levels'])->keyBy('code');
        $seen = 0;

        foreach ($timeline['students'] as $student) {
            $before = $student['cells'][$first['key']]['result'];
            $after = $student['cells'][$second['key']]['result'];
            $change = TimelineMovement::between($before, $after, $comparability)['level'];

            if ($before['band'] === null || $after['band'] === null) {
                $this->assertSame('unavailable', $change['state'], $student['name']);

                continue;
            }

            $seen++;
            $from = $levels[$before['band']['code']];
            $to = $levels[$after['band']['code']];

            $this->assertSame($to['sequence'] <=> $from['sequence'], ['down' => -1, 'same' => 0, 'up' => 1][$change['state']], $student['name']);
            $this->assertSame(
                match (true) {
                    $from['is_negative'] && ! $to['is_negative'] => 'to_positive',
                    ! $from['is_negative'] && $to['is_negative'] => 'to_negative',
                    default => null,
                },
                $change['crossed_line'],
                $student['name'],
            );
        }

        $this->assertGreaterThan(0, $seen);
    }

    // ================================================================= um momento guardado é o que foi guardado

    #[Test]
    public function a_kept_moment_is_a_frozen_point_that_never_reads_the_present(): void
    {
        $this->decide('Carolina Nunes', '3');
        $exportUlid = $this->keep('Momento de novembro');

        $before = $this->timeline();
        $kept = collect($before['points'])->firstWhere('key', 'moment:'.$exportUlid);

        $this->assertNotNull($kept);
        $this->assertSame('kept_moment', $kept['kind']);
        $this->assertSame('Momento de novembro', $kept['label']);
        $this->assertSame('frozen', $kept['basis']['kind']);
        $this->assertSame('Guardado a 15/12/2026', $kept['basis']['label']);
        $this->assertSame('2026-12-15', $kept['basis']['as_of']);

        // Sorted: the photograph of a period stands BEFORE that period read live.
        $keys = array_column($before['points'], 'key');
        $this->assertSame(['moment:'.$exportUlid, 'period:'.collect($before['points'])->firstWhere('kind', 'period')['period_ulid']], array_slice($keys, 0, 2));

        // The teacher changes their mind, and another student is given a level.
        $this->decide('Carolina Nunes', '5');
        $this->decide('Ana Marques', '5');
        $after = $this->timeline();

        $cell = fn (array $timeline, string $name, string $key): array => $this->byName($timeline)[$name]['cells'][$key];

        $this->assertSame($cell($before, 'Carolina Nunes', 'moment:'.$exportUlid), $cell($after, 'Carolina Nunes', 'moment:'.$exportUlid), 'not one figure of a kept moment moves');
        $this->assertSame($cell($before, 'Ana Marques', 'moment:'.$exportUlid), $cell($after, 'Ana Marques', 'moment:'.$exportUlid));
        $this->assertSame('3', $cell($after, 'Carolina Nunes', 'moment:'.$exportUlid)['classification']['assigned']['code']);

        $livePoint = 'period:'.collect($after['points'])->firstWhere('kind', 'period')['period_ulid'];
        $this->assertSame('5', $cell($after, 'Carolina Nunes', $livePoint)['classification']['assigned']['code'], 'while the live period follows');
    }

    #[Test]
    public function a_kept_moment_and_the_live_period_it_belongs_to_are_comparable_while_nothing_changed_in_the_criteria(): void
    {
        $exportUlid = $this->keep('Momento');
        $timeline = $this->timeline();
        $kept = collect($timeline['points'])->firstWhere('key', 'moment:'.$exportUlid);
        $live = collect($timeline['points'])->firstWhere('kind', 'period');

        $this->assertNotNull($kept['level_signature'], 'a photograph kept now carries its scale');
        $this->assertSame($live['level_signature'], $kept['level_signature']);
        $this->assertSame($live['percentage_signature'], $kept['percentage_signature']);
        $this->assertSame(['percentages' => true, 'levels' => true, 'reasons' => []], TimelineComparability::between($kept, $live));

        // And, as it was kept, its figures ARE the live ones of that day.
        $byName = $this->byName($timeline);
        foreach ($byName as $name => $student) {
            $this->assertSame($student['cells']['moment:'.$exportUlid]['result']['value'], $student['cells'][$live['key']]['result']['value'], $name);
        }
    }

    #[Test]
    public function a_photograph_from_before_the_scale_travelled_is_never_assumed_comparable(): void
    {
        $exportUlid = $this->keep('Momento antigo');
        $this->rewrite($exportUlid, function (array $payload): array {
            unset($payload['scale']);
            $payload['version'] = 2;

            return $payload;
        });

        $timeline = $this->timeline();
        $kept = collect($timeline['points'])->firstWhere('key', 'moment:'.$exportUlid);
        $live = collect($timeline['points'])->firstWhere('kind', 'period');

        $this->assertNull($kept['level_signature']);
        $this->assertSame(TimelineComparability::SCALE_UNKNOWN, $kept['notices'][0]['code']);

        $verdict = TimelineComparability::between($kept, $live);

        $this->assertTrue($verdict['percentages'], 'the domains and weights are in the photograph: the percentages can be shown comparable');
        $this->assertFalse($verdict['levels']);
        $this->assertSame([TimelineComparability::SCALE_UNKNOWN], array_column($verdict['reasons'], 'code'));
        $this->assertNotSame('', $verdict['reasons'][0]['text']);

        // Its levels are named, never ranked: no sequence, so no direction.
        $cell = $this->byName($timeline)['Carolina Nunes']['cells']['moment:'.$exportUlid]['result'];
        if ($cell['band'] !== null) {
            $this->assertNull($cell['band']['sequence']);
            $this->assertSame('unavailable', TimelineMovement::level($cell['band'], $this->byName($timeline)['Carolina Nunes']['cells'][$live['key']]['result']['band'], false)['state']);
        }
    }

    #[Test]
    public function a_change_of_scale_or_of_weights_between_two_moments_is_said_and_refused(): void
    {
        $scaleChanged = $this->keep('Outra escala');
        $this->rewrite($scaleChanged, function (array $payload): array {
            $payload['scale']['levels'][0]['label'] = 'Outro nome de nível';

            return $payload;
        });

        $weightsChanged = $this->keep('Outros pesos');
        $this->rewrite($weightsChanged, function (array $payload): array {
            $payload['domains'][0]['weight_percent'] = '99.0000';

            return $payload;
        });

        $timeline = $this->timeline();
        $live = collect($timeline['points'])->firstWhere('kind', 'period');

        $scale = TimelineComparability::between(collect($timeline['points'])->firstWhere('key', 'moment:'.$scaleChanged), $live);
        $this->assertTrue($scale['percentages']);
        $this->assertFalse($scale['levels']);
        $this->assertSame([TimelineComparability::SCALE_DIFFERS], array_column($scale['reasons'], 'code'));

        $weights = TimelineComparability::between(collect($timeline['points'])->firstWhere('key', 'moment:'.$weightsChanged), $live);
        $this->assertFalse($weights['percentages']);
        $this->assertTrue($weights['levels']);
        $this->assertSame([TimelineComparability::CRITERIA_DIFFER], array_column($weights['reasons'], 'code'));

        // Refused means no movement is reported across it: not a figure that merely looks small.
        $cells = $this->byName($timeline)['Carolina Nunes']['cells'];
        $movement = TimelineMovement::between($cells['moment:'.$weightsChanged]['result'], $cells[$live['key']]['result'], $weights);
        $this->assertFalse($movement['comparable']);
        $this->assertNull($movement['percentages']);
    }

    #[Test]
    public function a_moment_that_cannot_be_trusted_or_is_not_a_standalone_reading_is_left_out_and_says_so(): void
    {
        $tampered = $this->keep('Adulterado');
        $accumulated = $this->keep('Acumulado');

        $this->asTenant(function () use ($tampered, $accumulated): void {
            $export = EvaluationSheetExport::where('ulid', $tampered)->firstOrFail();
            $payload = $export->payload;
            $payload['students'][0]['overall']['normalized_value'] = '1.000000';
            // The seal is NOT recomputed: exactly what the integrity check exists to catch.
            DB::table('evaluation_sheet_exports')->where('id', $export->id)->update(['payload' => json_encode($payload)]);

            DB::table('evaluation_sheet_exports')->where('ulid', $accumulated)->update(['scope' => ClassificationScope::Accumulated->value]);
        });

        $timeline = $this->timeline();

        $this->assertSame([], array_values(array_filter($timeline['points'], fn (array $point): bool => $point['kind'] === 'kept_moment')));
        $this->assertEqualsCanonicalizing(['moment_integrity', 'moment_not_standalone'], array_column($timeline['notices'], 'code'));
        $this->assertContains('Adulterado', array_column($timeline['notices'], 'moment'));
        $this->assertContains('Acumulado', array_column($timeline['notices'], 'moment'));
    }

    #[Test]
    public function the_kept_moments_can_be_left_out_altogether(): void
    {
        $this->keep('Momento');

        $this->assertCount(2, $this->timeline(withKeptMoments: false)['points']);
        $this->assertCount(3, $this->timeline()['points']);
    }

    // ================================================================= dados suficientes, ditos

    #[Test]
    public function it_says_plainly_how_much_can_be_compared(): void
    {
        $availability = $this->timeline()['availability'];

        // Two periods with results: a comparison, not yet a trend.
        $this->assertSame(2, $availability['points_with_results']);
        $this->assertTrue($availability['comparison']);
        $this->assertFalse($availability['trends']);
        $this->assertSame('Existem 2 momentos comparáveis: as tendências precisam de pelo menos 3.', $availability['reason']);

        // A third comparable point — a photograph kept now — makes it a trend of three.
        $this->keep('Momento');
        $three = $this->timeline()['availability'];

        $this->assertSame(3, $three['comparable_points']);
        $this->assertTrue($three['trends']);
        $this->assertNull($three['reason']);
    }

    #[Test]
    public function three_moments_under_different_criteria_are_not_a_trend_of_three(): void
    {
        $first = $this->keep('Pesos de antes');
        $this->rewrite($first, function (array $payload): array {
            $payload['domains'][0]['weight_percent'] = '99.0000';

            return $payload;
        });

        $availability = $this->timeline()['availability'];

        // Three with results, but the photograph stands apart: the two live periods are the comparable pair.
        $this->assertSame(3, $availability['points_with_results']);
        $this->assertSame(2, $availability['comparable_points']);
        $this->assertTrue($availability['comparison']);
        $this->assertFalse($availability['trends']);
    }

    #[Test]
    public function a_period_with_few_students_with_a_result_is_flagged(): void
    {
        $points = $this->timeline()['points'];

        // The demo's first period has four students with a result; the second has six.
        $this->assertSame([4, 6], array_column($points, 'students_with_result'));
        $this->assertSame([6, 6], array_column($points, 'students_total'));
        $this->assertSame([true, false], array_column($points, 'few_students'));
        $this->assertSame(5, BuildAssessmentTimeline::FEW_STUDENTS);
    }

    #[Test]
    public function a_class_without_a_profile_has_an_empty_timeline_that_says_why(): void
    {
        $timeline = $this->asTenant(function (): array {
            $demo = $this->class();
            $class = SchoolClass::create([
                'academic_year_id' => $demo->academic_year_id, 'subject_id' => $demo->subject_id,
                'label' => '7.º Sem Perfil', 'grade_level' => '7.º', 'status' => 'active',
            ]);

            return app(BuildAssessmentTimeline::class)->for($class);
        });

        $this->assertSame([], $timeline['points']);
        $this->assertFalse($timeline['availability']['comparison']);
        $this->assertSame('Esta turma não tem perfil de avaliação associado, por isso não há resultados para analisar.', $timeline['availability']['reason']);
        $this->assertSame(['no_profile'], array_column($timeline['notices'], 'code'));
    }

    // ================================================================= privacidade, desempenho, reutilização

    #[Test]
    public function nothing_private_travels(): void
    {
        $this->decide('Carolina Nunes', '4');
        $this->asTenant(function (): void {
            Classification::query()
                ->where('enrollment_id', $this->class()->enrollments()->with('student.identity')->get()
                    ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === 'Carolina Nunes')->getKey())
                ->update(['override_reason' => 'MOTIVO-LIVRE-RESERVADO']);
        });
        $this->keep('Momento privado');

        $json = (string) json_encode($this->timeline(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('MOTIVO-LIVRE-RESERVADO', $json);

        // No numeric identifier of anything: told by ulid and by position.
        foreach (['enrollment_id', 'domain_id', 'period_id', 'scale_level_id', 'scale_id', 'student_id', 'override_reason', 'self_assessment'] as $forbidden) {
            $this->assertStringNotContainsString("\"{$forbidden}\"", $json, "«{$forbidden}» must not travel");
        }
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

    /** @return array{int, int} queries and students */
    private function measure(): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $timeline = $this->timeline();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, count($timeline['students'])];
    }

    #[Test]
    public function the_cost_does_not_grow_with_the_class(): void
    {
        $this->keep('Momento');
        $this->measure(); // warm every one-off

        [$small, $smallStudents] = $this->measure();
        $this->enrolUpTo(30);
        [$large, $largeStudents] = $this->measure();

        $this->assertSame(6, $smallStudents);
        $this->assertSame(30, $largeStudents);
        $this->assertSame($small, $large, "Six students cost {$small} queries and thirty cost {$large}: the cost grows with the class.");
    }

    #[Test]
    public function a_progression_already_in_hand_is_reused_and_one_for_another_cutoff_is_refused(): void
    {
        $progression = $this->progression();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $reused = $this->timeline(progression: $progression);
        $withProgression = count(DB::getQueryLog());
        DB::flushQueryLog();
        $own = $this->timeline();
        $withoutProgression = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($own, $reused, 'the same timeline');
        $this->assertLessThan($withoutProgression, $withProgression, 'the second walk over the class\'s year does not happen');

        // A photograph presented as the present is refused, as Estatística refuses it.
        $cut = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->class(), AssessmentCutoff::on('2026-11-15')));

        $this->expectException(InvalidArgumentException::class);
        $this->timeline(progression: $cut);
    }

    #[Test]
    public function the_organization_of_another_teacher_sees_none_of_it(): void
    {
        $stranger = User::factory()->create();
        $class = $this->asTenant(fn (): SchoolClass => $this->class());

        // The class of one organization, asked for inside another: the tenant scope answers nothing, not the demo.
        $timeline = app(CurrentOrganization::class)->runFor($stranger->personalOrganization(), function () use ($stranger): array {
            OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
                ['organization_id' => $stranger->personalOrganization()->id],
                ['plan_id' => Plan::where('key', 'pro')->firstOrFail()->id, 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::parse('2026-01-01 00:00:00'), 'ends_at' => null],
            );
            app(Entitlements::class)->flush();

            return [
                'periods' => AcademicPeriod::query()->count(),
                'exports' => EvaluationSheetExport::query()->count(),
            ];
        });

        $this->assertSame(0, $timeline['periods']);
        $this->assertSame(0, $timeline['exports']);
        $this->assertNotNull($class->ulid);
    }
}
