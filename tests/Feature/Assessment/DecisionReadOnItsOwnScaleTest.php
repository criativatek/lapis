<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Export\DecidedLevelValues;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InovarGridFixture;
use Tests\Support\MigratesPercentageClassToLevels;
use Tests\TestCase;

/**
 * A decision is read on the scale it was TAKEN on, not on the class's scale
 * today.
 *
 * The production case: a class assessed on «Percentagem (0 a 100)» is migrated
 * to «Escala 1 a 5», and the one published decision it holds is a bare 65. It
 * used to appear as «65» under «Nível atribuído», as «65.000» in the Pauta and
 * would have been written as level «65» in the Inovar grid. It is 65 %, and
 * that is what every screen and export now says — without the number ever being
 * converted, recalculated or reinterpreted.
 *
 * The second half is as important as the first: every account that is NOT in
 * this situation reads exactly what it read before.
 */
class DecisionReadOnItsOwnScaleTest extends TestCase
{
    use MigratesPercentageClassToLevels, RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTeacherAndDemo();
        // Dentro do 1.º Semestre do cenário de demonstração.
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));
        $this->subscribeToPro();
    }

    private function subscribeToPro(): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->teacher->personalOrganization()->getKey())->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        app(Entitlements::class)->flush();
    }

    /**
     * @param  array{class: SchoolClass, period: AcademicPeriod}  $scenario
     * @return array<string, mixed>
     */
    private function classificationsRow(array $scenario, string $studentName): array
    {
        $response = $this->actingAs($this->teacher)
            ->get("/classes/{$scenario['class']->ulid}/classifications/{$scenario['period']->ulid}");
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        $row = collect($page['props']['rows'])->firstWhere('name', $studentName);
        $this->assertNotNull($row, "linha de {$studentName}");

        return $row;
    }

    /**
     * @param  array{class: SchoolClass, period: AcademicPeriod}  $scenario
     * @return array<string, mixed>
     */
    private function sheetClassification(array $scenario, string $studentName): array
    {
        return $this->inTenant(function () use ($scenario, $studentName): array {
            $sheet = app(BuildEvaluationSheet::class)->for(
                SchoolClass::whereKey($scenario['class']->id)->firstOrFail(),
                $scenario['period'],
            );

            $student = collect($sheet['students'])->firstWhere('name', $studentName);

            return $student['classification'];
        });
    }

    /**
     * @param  array{class: SchoolClass, period: AcademicPeriod}  $scenario
     * @return array<string, mixed>
     */
    private function resultsClassification(array $scenario, string $studentName): array
    {
        return $this->inTenant(function () use ($scenario, $studentName): array {
            $progression = app(BuildResultsProgression::class)->for(SchoolClass::whereKey($scenario['class']->id)->firstOrFail());
            $student = collect($progression['students'])->firstWhere('name', $studentName);
            $period = collect($student['periods'])->firstWhere('period_id', $scenario['period']->id);

            return $period['classification'];
        });
    }

    /**
     * @param  array{class: SchoolClass, period: AcademicPeriod}  $scenario
     */
    private function enrollmentIdOf(array $scenario, string $studentName): int
    {
        return $this->inTenant(fn (): int => (int) $this->classificationOf($studentName, $scenario['period']->id)->enrollment_id);
    }

    // ===================================================== o caso de produção

    #[Test]
    public function the_published_65_survives_the_migration_untouched_and_is_read_as_a_percentage(): void
    {
        $scenario = $this->percentageClassWithProposals('65');

        $published = $this->inTenant(fn (): Classification => $this->classificationOf('Carolina Nunes', $scenario['period']->id));
        $this->assertNull($published->final_scale_level_id);
        $this->assertSame('65.000', $published->final_value);
        $this->assertTrue($published->status->isPublished());
        $attributesBefore = $published->fresh()->getAttributes();

        $this->migrateToTheLevelVersion($scenario['class']);

        // (a) A decisão fica intacta na base de dados, coluna a coluna —
        // incluindo a versão de perfil que a produziu.
        $after = $this->inTenant(fn (): Classification => Classification::whereKey($published->id)->firstOrFail());
        $this->assertEquals($attributesBefore, $after->getAttributes());
        $this->assertSame($this->percentageVersion->id, (int) $after->assessment_profile_version_id);

        // E a turma passou de facto a ser avaliada em 1–5.
        $this->assertSame($this->levelVersion->id, (int) $this->demoClass()->assessment_profile_version_id);

        // (b) Classificações: um valor em percentagem, com o nome da escala em
        // que foi tomado — nunca «65» numa coluna de nível.
        $decision = $this->classificationsRow($scenario, 'Carolina Nunes')['classification']['decision'];
        $this->assertSame('65.000', $decision['code']);
        $this->assertNull($decision['scale_level_id']);
        $this->assertTrue($decision['is_percentage']);
        $this->assertSame('Percentagem (0 a 100)', $decision['scale_name']);

        // (c) A Pauta e os Resultados trazem as chaves novas.
        $sheet = $this->sheetClassification($scenario, 'Carolina Nunes');
        $this->assertSame('65.000', $sheet['final_value']);
        $this->assertNull($sheet['final_scale_level_id']);
        $this->assertTrue($sheet['final_value_is_percentage']);
        $this->assertSame('Percentagem (0 a 100)', $sheet['final_value_scale']);

        $results = $this->resultsClassification($scenario, 'Carolina Nunes');
        $this->assertNull($results['final']);
        $this->assertSame('65.000', $results['final_value']);
        $this->assertTrue($results['final_value_is_percentage']);
        $this->assertSame('Percentagem (0 a 100)', $results['final_value_scale']);

        // Uma proposta em aberto não tem decisão, logo não tem nada disto.
        $open = $this->resultsClassification($scenario, 'Eva Salgado');
        $this->assertNull($open['final_value']);
        $this->assertFalse($open['final_value_is_percentage']);
        $this->assertNull($open['final_value_scale']);
    }

    #[Test]
    public function inovar_never_writes_a_decision_from_another_scale_as_a_level(): void
    {
        $scenario = $this->percentageClassWithProposals('65');
        $this->migrateToTheLevelVersion($scenario['class']);
        $carolinaId = $this->enrollmentIdOf($scenario, 'Carolina Nunes');

        $this->inTenant(function () use ($scenario, $carolinaId): void {
            $levels = app(DecidedLevelValues::class);

            // (d) Nenhum nível para esta matrícula…
            $this->assertArrayNotHasKey($carolinaId, $levels->for($scenario['period'], ClassificationScope::Period, [$carolinaId]));
            // …e sabe-se porquê.
            $this->assertSame(
                [$carolinaId => 'Percentagem (0 a 100)'],
                $levels->onAnotherScale($scenario['period'], ClassificationScope::Period, [$carolinaId]),
            );
        });
    }

    #[Test]
    public function the_inovar_review_says_why_the_level_column_stays_empty(): void
    {
        $scenario = $this->percentageClassWithProposals('65');
        $this->migrateToTheLevelVersion($scenario['class']);

        $class = $scenario['class'];
        $period = $scenario['period'];

        $grid = $this->inTenant(function () use ($class): array {
            $numbers = [];
            $next = 1234;

            foreach (SchoolClass::whereKey($class->id)->firstOrFail()->enrollments()->with('student.identity')->orderBy('class_number')->get() as $enrollment) {
                $number = str_pad((string) $next++, 6, '0', STR_PAD_LEFT);
                $enrollment->student->identity->update(['school_number' => $number]);
                $numbers[$enrollment->student->identity->display_name] = $number;
            }

            $names = SchoolClass::whereKey($class->id)->firstOrFail()->profileVersion
                ->domains()->with('domain')->get()->pluck('domain.name')->sort()->values()->all();
            $columns = [];

            foreach (array_slice($names, 0, 3) as $index => $name) {
                $columns[chr(ord('D') + $index)] = $name;
            }

            return [
                'path' => (new InovarGridFixture)->build(['domains' => $columns, 'students' => array_flip($numbers), 'level_column' => 'I']),
            ];
        });

        $base = "/classes/{$class->ulid}/pauta-avaliacao/inovar/{$period->ulid}";

        $upload = $this->actingAs($this->teacher)->post($base, [
            'template' => UploadedFile::fake()->createWithContent('grelha.xls', (string) file_get_contents($grid['path'])),
        ]);
        $upload->assertOk();

        /** @var array<string, mixed> $page */
        $page = $upload->viewData('page');

        $carolina = collect($page['props']['preparation']['students'])->firstWhere('name', 'Carolina Nunes');
        $this->assertNull($carolina['level']);

        $this->actingAs($this->teacher)->post("{$base}/{$page['props']['token']}", [
            'include_level' => true,
            'level_column' => 'I',
        ])->assertRedirect();

        $export = $this->inTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());

        $this->assertContains(
            'Carolina Nunes: decisão tomada na escala «Percentagem (0 a 100)» — não é um nível desta escala; a coluna do nível fica vazia.',
            $export->payload['warnings'],
        );
        // E não a afirmação, falsa, de que não há decisão.
        $this->assertNotContains(
            'Carolina Nunes: sem classificação decidida — a coluna do nível fica vazia na grelha exportada.',
            $export->payload['warnings'],
        );
    }

    #[Test]
    public function the_pauta_csv_writes_65_percent_and_never_a_bare_65(): void
    {
        $scenario = $this->percentageClassWithProposals('65');
        $this->migrateToTheLevelVersion($scenario['class']);

        $class = $scenario['class'];
        $period = $scenario['period'];

        $this->actingAs($this->teacher)->post("/classes/{$class->ulid}/pauta-avaliacao/{$period->ulid}/guardar", [
            'moment_label' => 'Fim do semestre',
            'effective_at' => '2026-12-15',
            'scope' => ClassificationScope::Period->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $exportUlid = $this->inTenant(fn (): string => EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail()->ulid);

        $csv = $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/pauta-avaliacao/historico/{$exportUlid}/csv");
        $csv->assertOk();

        $line = collect(explode("\n", (string) $csv->getContent()))
            ->first(fn (string $candidate): bool => str_contains($candidate, 'Carolina Nunes'));

        $this->assertNotNull($line);
        $this->assertStringContainsString('65%', $line);
        $this->assertStringContainsString('Decisão do professor', $line);
        $this->assertStringNotContainsString('65.000', $line);
        $this->assertDoesNotMatchRegularExpression('/(^|[;,"])65([;,"]|$)/', $line);
    }

    #[Test]
    public function statistics_do_not_place_a_decision_from_another_scale_in_a_band_of_this_one(): void
    {
        $scenario = $this->percentageClassWithProposals('65');
        $this->migrateToTheLevelVersion($scenario['class']);

        $statistics = $this->inTenant(fn (): array => app(BuildClassStatistics::class)->for(
            SchoolClass::whereKey($scenario['class']->id)->firstOrFail(),
            $scenario['period'],
        ));

        $distribution = $statistics['assigned_distribution'];

        // Decidida, mas numa escala que esta não pode ler: fica de fora dos
        // níveis, contada à parte — nunca num nível onde nunca foi colocada.
        $this->assertSame('levels', $distribution['mode']);
        $this->assertSame(0, $distribution['classified']);
        $this->assertSame(1, $distribution['unplaced']);
        $this->assertSame(0, array_sum(array_column($distribution['bands'], 'count')));
    }

    // ===================================== as outras contas não mudam

    #[Test]
    public function a_class_on_levels_with_a_level_decision_reads_exactly_as_before(): void
    {
        $scenario = $this->inTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);

            $level = $class->profileVersion->scale->levels()->where('code', '4')->firstOrFail();
            app(ConfirmClassification::class)->confirm(
                $this->classificationOf('Carolina Nunes', $period->id),
                $this->teacher,
                $level->id,
            );

            return ['class' => $class, 'period' => $period];
        });

        $decision = $this->classificationsRow($scenario, 'Carolina Nunes')['classification']['decision'];
        $this->assertSame('4', $decision['code']);
        $this->assertNotNull($decision['scale_level_id']);
        $this->assertFalse($decision['is_percentage']);
        $this->assertNull($decision['scale_name']);

        $sheet = $this->sheetClassification($scenario, 'Carolina Nunes');
        $this->assertSame('4', $sheet['final_scale_level_code']);
        $this->assertFalse($sheet['final_value_is_percentage']);
        $this->assertNull($sheet['final_value_scale']);

        $results = $this->resultsClassification($scenario, 'Carolina Nunes');
        $this->assertSame('4', $results['final']['code']);
        $this->assertFalse($results['final_value_is_percentage']);
        $this->assertNull($results['final_value_scale']);

        $carolinaId = $this->enrollmentIdOf($scenario, 'Carolina Nunes');

        $this->inTenant(function () use ($scenario, $carolinaId): void {
            $levels = app(DecidedLevelValues::class);

            $this->assertSame([$carolinaId => '4'], $levels->for($scenario['period'], ClassificationScope::Period, [$carolinaId]));
            $this->assertSame([], $levels->onAnotherScale($scenario['period'], ClassificationScope::Period, [$carolinaId]));
        });
    }

    #[Test]
    public function a_class_on_a_numeric_scale_keeps_reading_its_decision_as_a_plain_number(): void
    {
        $scenario = $this->inTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            $version = $this->activateVersionOn($class, 'Escala 0 a 20');
            $class->update(['assessment_profile_version_id' => $version->id]);
            $class->refresh();

            app(ProposeClassifications::class)->forPeriod($class, $period);
            app(ConfirmClassification::class)->confirm($this->classificationOf('Carolina Nunes', $period->id), $this->teacher, null, '16');

            return ['class' => $class, 'period' => $period];
        });

        $decision = $this->classificationsRow($scenario, 'Carolina Nunes')['classification']['decision'];
        $this->assertSame('16.000', $decision['code']);
        $this->assertFalse($decision['is_percentage']);
        $this->assertNull($decision['scale_name']);

        $sheet = $this->sheetClassification($scenario, 'Carolina Nunes');
        $this->assertFalse($sheet['final_value_is_percentage']);
        $this->assertNull($sheet['final_value_scale']);

        $results = $this->resultsClassification($scenario, 'Carolina Nunes');
        $this->assertFalse($results['final_value_is_percentage']);
        $this->assertNull($results['final_value_scale']);

        $carolinaId = $this->enrollmentIdOf($scenario, 'Carolina Nunes');

        $this->inTenant(function () use ($scenario, $carolinaId): void {
            $levels = app(DecidedLevelValues::class);

            // Exportada para o Inovar como sempre foi: o número, sem zeros à direita.
            $this->assertSame([$carolinaId => '16'], $levels->for($scenario['period'], ClassificationScope::Period, [$carolinaId]));
            $this->assertSame([], $levels->onAnotherScale($scenario['period'], ClassificationScope::Period, [$carolinaId]));
        });
    }

    #[Test]
    public function a_percentage_class_that_never_migrated_reads_65_as_a_percentage_of_its_own_scale(): void
    {
        $scenario = $this->percentageClassWithProposals('65');

        $decision = $this->classificationsRow($scenario, 'Carolina Nunes')['classification']['decision'];
        $this->assertTrue($decision['is_percentage']);
        $this->assertNull($decision['scale_name']);

        $sheet = $this->sheetClassification($scenario, 'Carolina Nunes');
        $this->assertTrue($sheet['final_value_is_percentage']);
        $this->assertNull($sheet['final_value_scale']);

        $results = $this->resultsClassification($scenario, 'Carolina Nunes');
        $this->assertTrue($results['final_value_is_percentage']);
        $this->assertNull($results['final_value_scale']);

        $carolinaId = $this->enrollmentIdOf($scenario, 'Carolina Nunes');

        // Sem migração não há outra escala: o Inovar recebe o que sempre recebeu.
        $this->inTenant(function () use ($scenario, $carolinaId): void {
            $levels = app(DecidedLevelValues::class);

            $this->assertSame([$carolinaId => '65'], $levels->for($scenario['period'], ClassificationScope::Period, [$carolinaId]));
            $this->assertSame([], $levels->onAnotherScale($scenario['period'], ClassificationScope::Period, [$carolinaId]));
        });
    }
}
