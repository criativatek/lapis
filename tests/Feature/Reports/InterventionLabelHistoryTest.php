<?php

namespace Tests\Feature\Reports;

use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Reporting\Source\StudentReportSource;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * That renaming a designation does not rewrite how old records are described.
 *
 * A report groups interventions by type and labels each group. That label used
 * to be read live from the enum, so «Reforço das aprendizagens» became
 * «Antecipação e reforço das aprendizagens» on a record made a year before the
 * rename, the next time anybody regenerated the report.
 *
 * The fix is NOT "use the stored title". This codebase tried that and reverted
 * it: `title` is written as `strategy_label ?? type->label()`, so it is often
 * not a type designation at all, and on imported rows it holds whatever an old
 * process put there — which is how «Legado sem dominio» reached a printed
 * document as a kind of pedagogical action. The snapshot has a column of its
 * own so that it can never be confused with free text.
 */
class InterventionLabelHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const RENAMED = 'Antecipação e reforço das aprendizagens';

    private const SUPERSEDED = 'Reforço das aprendizagens';

    /** @return array{class: SchoolClass, teacher: User} */
    private function seedClass(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => [
            'class' => SchoolClass::where('label', '7.º A')->firstOrFail(),
            'teacher' => $teacher,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function intervention(User $teacher, SchoolClass $class, array $attributes = []): Intervention
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => Intervention::query()->create(array_merge([
            'class_id' => $class->id,
            'target_type' => InterventionTargetType::SchoolClass,
            'intervention_type' => InterventionType::LearningReinforcement,
            'domain_relation' => InterventionDomainRelation::None,
            'title' => self::SUPERSEDED,
            'description_source' => InterventionDescriptionSource::Manual,
            'status' => InterventionStatus::New,
            'started_on' => '2026-09-10',
            'created_by' => $teacher->id,
        ], $attributes)));
    }

    #[Test]
    public function a_record_with_a_snapshot_keeps_the_designation_it_was_recorded_under(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $intervention = $this->intervention($teacher, $class, [
            'intervention_type_label' => self::SUPERSEDED,
        ]);

        // The enum has already been renamed underneath it — that is the whole
        // scenario — and the record is unmoved.
        $this->assertSame(self::RENAMED, InterventionType::LearningReinforcement->label());
        $this->assertSame(self::SUPERSEDED, $intervention->typeLabel());
    }

    #[Test]
    public function a_record_written_today_carries_the_current_designation(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $this->actingAs($teacher)->postJson("/classes/{$class->ulid}/interventions", [
            'target_type' => 'class',
            'enrollment_ids' => [],
            'intervention_type' => InterventionType::LearningReinforcement->value,
            'domain_relation' => 'none',
            'description' => null,
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
            'legal_framing' => null,
            'confirm_suggested_framing' => false,
            'support_measure_level' => null,
            'support_measure_code' => null,
            'evaluation_adaptation_code' => null,
        ])->assertRedirect();

        $intervention = Intervention::withoutGlobalScopes()->latest('id')->firstOrFail();

        // Stamped at write time, so the next rename cannot move it either.
        $this->assertSame(self::RENAMED, $intervention->intervention_type_label);
        $this->assertSame(self::RENAMED, $intervention->typeLabel());
    }

    #[Test]
    public function a_legacy_record_without_a_snapshot_still_reads_through_the_live_enum(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $intervention = $this->intervention($teacher, $class, [
            // Recorded before the column existed.
            'intervention_type_label' => null,
            'title' => 'Apoio combinado com a DT',
        ]);

        // The fallback is not a degraded answer — it is what every report did
        // for every record until now, so nothing regresses by being null.
        $this->assertSame(self::RENAMED, $intervention->typeLabel());
    }

    #[Test]
    public function a_record_with_no_type_at_all_reports_no_designation(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $intervention = $this->intervention($teacher, $class, [
            'intervention_type' => null,
            'intervention_type_label' => null,
            'title' => 'Legado sem dominio',
        ]);

        // Absent, never falsified. And emphatically not the title: that string
        // is the placeholder an old import generated, and printing it as a
        // pedagogical category is the bug this design exists to avoid.
        $this->assertNull($intervention->typeLabel());
    }

    #[Test]
    public function the_report_labels_a_group_with_the_snapshot_not_the_current_enum(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $enrollmentId = app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            fn () => $class->enrollments()->orderBy('class_number')->value('id'),
        );

        $this->intervention($teacher, $class, [
            'enrollment_id' => $enrollmentId,
            'target_type' => InterventionTargetType::Student,
            'intervention_type_label' => self::SUPERSEDED,
            'available_for_reports' => true,
        ]);

        $facts = app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            fn () => $this->interventionFactsFor($class, $enrollmentId),
        );

        $this->assertNotEmpty($facts['types']);
        $this->assertSame(self::SUPERSEDED, $facts['types'][0]['label']);
        // The grouping key is still the stable code — only the wording is
        // historical, and two records of the same type never split into two
        // groups because their designations differ.
        $this->assertSame('learning_reinforcement', $facts['types'][0]['type']);
    }

    #[Test]
    public function records_of_one_type_stay_one_group_across_a_rename(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $enrollmentId = app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            fn () => $class->enrollments()->orderBy('class_number')->value('id'),
        );

        // One recorded before the rename, one after.
        $this->intervention($teacher, $class, [
            'enrollment_id' => $enrollmentId,
            'target_type' => InterventionTargetType::Student,
            'intervention_type_label' => self::SUPERSEDED,
        ]);
        $this->intervention($teacher, $class, [
            'enrollment_id' => $enrollmentId,
            'target_type' => InterventionTargetType::Student,
            'intervention_type_label' => self::RENAMED,
        ]);

        $facts = app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            fn () => $this->interventionFactsFor($class, $enrollmentId),
        );

        $this->assertCount(1, $facts['types'], 'O agrupamento é pelo código, não pela designação.');
        $this->assertSame(2, $facts['types'][0]['count']);
    }

    #[Test]
    public function the_migration_recovers_a_snapshot_only_where_one_is_provably_stored(): void
    {
        $migration = require database_path('migrations/2026_09_20_000400_add_intervention_type_label_snapshot.php');
        $migration->down();

        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        // A row whose title is exactly the designation the app generated for
        // its type: provably a snapshot.
        $recoverable = $this->intervention($teacher, $class, ['title' => self::SUPERSEDED]);

        // A row whose title is the teacher's own words: NOT a designation, and
        // guessing it were one would print it as a pedagogical category.
        $free = $this->intervention($teacher, $class, ['title' => 'Combinado com a DT']);

        // The placeholder an old import wrote. The one that must never be
        // recovered as a category.
        $placeholder = $this->intervention($teacher, $class, [
            'intervention_type' => null,
            'title' => 'Legado sem dominio',
        ]);

        $migration->up();

        $this->assertSame(self::SUPERSEDED, DB::table('interventions')->where('id', $recoverable->id)->value('intervention_type_label'));
        $this->assertNull(DB::table('interventions')->where('id', $free->id)->value('intervention_type_label'));
        $this->assertNull(DB::table('interventions')->where('id', $placeholder->id)->value('intervention_type_label'));

        // Nothing was rewritten: the titles are untouched.
        $this->assertSame('Combinado com a DT', DB::table('interventions')->where('id', $free->id)->value('title'));
        $this->assertSame('Legado sem dominio', DB::table('interventions')->where('id', $placeholder->id)->value('title'));
    }

    /**
     * @return array<string, mixed>
     */
    private function interventionFactsFor(SchoolClass $class, int $enrollmentId): array
    {
        $report = new Report([
            'class_id' => $class->id,
            'enrollment_id' => $enrollmentId,
            'academic_period_id' => null,
        ]);
        $report->enrollment_id = $enrollmentId;

        $source = app(StudentReportSource::class);

        $method = new \ReflectionMethod($source, 'interventionFacts');

        /** @var array<string, mixed> $facts */
        $facts = $method->invoke($source, $report, $class);

        return $facts;
    }
}
