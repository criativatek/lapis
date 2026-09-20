<?php

namespace Tests\Feature\Interventions;

use App\Models\EvaluationAdaptationCode;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\LegalMappingSource;
use App\Models\SchoolClass;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The migration that stamps the applicable framework version onto rows that
 * already carry a legal framing, and widens the measure catalogue to the whole
 * of Decreto-Lei n.º 54/2018.
 *
 * What is being defended: it must be additive, its backfill must be
 * deterministic, and its rollback must refuse rather than quietly destroy a
 * record it cannot represent.
 */
class LegalFrameworkSnapshotMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_20_000300_add_legal_framework_snapshot_and_complete_measure_catalogue.php';

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function interventionIn(User $teacher, array $attributes): Intervention
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher, $attributes): Intervention {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return Intervention::query()->create(array_merge([
                'class_id' => $class->id,
                'target_type' => InterventionTargetType::SchoolClass,
                'intervention_type' => InterventionType::PedagogicalDifferentiation,
                'domain_relation' => InterventionDomainRelation::None,
                'title' => 'Diferenciação pedagógica',
                'description_source' => InterventionDescriptionSource::Manual,
                'status' => InterventionStatus::New,
                'started_on' => '2026-09-10',
                'created_by' => $teacher->id,
            ], $attributes));
        });
    }

    private function seededTeacher(): User
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return $teacher;
    }

    #[Test]
    public function it_stamps_only_the_rows_that_were_actually_framed(): void
    {
        $migration = $this->migration();
        $migration->down();

        $teacher = $this->seededTeacher();

        $framed = $this->interventionIn($teacher, [
            'support_measure_level' => SupportMeasureLevel::Universal,
            'support_measure_code' => SupportMeasureCode::PedagogicalDifferentiation,
            'legal_mapping_source' => LegalMappingSource::Manual,
        ]);

        $adaptationOnly = $this->interventionIn($teacher, [
            'intervention_type' => InterventionType::ExtraTime,
            'title' => 'Tempo suplementar',
            'evaluation_adaptation_code' => EvaluationAdaptationCode::ExtraTime,
            'legal_mapping_source' => LegalMappingSource::SystemDirect,
        ]);

        // Never framed at all. Stamping this one would assert that somebody
        // read it under a regime, which nobody did.
        $unframed = $this->interventionIn($teacher, [
            'intervention_type' => InterventionType::TextPlanningSupport,
            'title' => 'Apoio à planificação textual',
        ]);

        $migration->up();

        $this->assertSame('pt-inclusive-education-2018', DB::table('interventions')->where('id', $framed->id)->value('legal_framework_code'));
        $this->assertSame('pt-inclusive-education-2018', DB::table('interventions')->where('id', $adaptationOnly->id)->value('legal_framework_code'));
        $this->assertNull(DB::table('interventions')->where('id', $unframed->id)->value('legal_framework_code'));
    }

    #[Test]
    public function it_is_additive_and_no_existing_framing_is_lost(): void
    {
        $migration = $this->migration();
        $migration->down();

        $teacher = $this->seededTeacher();
        $intervention = $this->interventionIn($teacher, [
            'support_measure_level' => SupportMeasureLevel::Selective,
            'support_measure_code' => SupportMeasureCode::TutorialSupport,
            'legal_mapping_source' => LegalMappingSource::Manual,
        ]);

        $migration->up();

        $row = DB::table('interventions')->where('id', $intervention->id)->first();

        // Everything that was there is still there, unchanged.
        $this->assertSame(SupportMeasureLevel::Selective->value, $row->support_measure_level);
        $this->assertSame(SupportMeasureCode::TutorialSupport->value, $row->support_measure_code);
        $this->assertSame(LegalMappingSource::Manual->value, $row->legal_mapping_source);
        $this->assertSame('Diferenciação pedagógica', $row->title);
    }

    #[Test]
    public function the_rollback_removes_only_what_the_migration_added(): void
    {
        $migration = $this->migration();
        $migration->down();

        $teacher = $this->seededTeacher();
        $intervention = $this->interventionIn($teacher, [
            'support_measure_level' => SupportMeasureLevel::Universal,
            'support_measure_code' => SupportMeasureCode::PedagogicalDifferentiation,
            'legal_mapping_source' => LegalMappingSource::Manual,
        ]);

        $migration->up();
        $this->assertTrue(Schema::hasColumn('interventions', 'legal_framework_code'));
        $this->assertTrue(Schema::hasColumn('intervention_support_measures', 'legal_framework_code'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('interventions', 'legal_framework_code'));
        $this->assertFalse(Schema::hasColumn('intervention_support_measures', 'legal_framework_code'));
        // The row itself survives the round trip untouched.
        $this->assertDatabaseHas('interventions', [
            'id' => $intervention->id,
            'support_measure_code' => SupportMeasureCode::PedagogicalDifferentiation->value,
        ]);

        // And it is re-appliable.
        $migration->up();
        $this->assertTrue(Schema::hasColumn('interventions', 'legal_framework_code'));
    }

    #[Test]
    public function the_rollback_refuses_rather_than_discard_a_measure_the_old_catalogue_cannot_hold(): void
    {
        $migration = $this->migration();

        $teacher = $this->seededTeacher();
        $intervention = $this->interventionIn($teacher, [
            'support_measure_level' => SupportMeasureLevel::Additional,
            'support_measure_code' => SupportMeasureCode::IndividualTransitionPlan,
            'legal_mapping_source' => LegalMappingSource::Manual,
        ]);

        // A measure only the widened catalogue knows.
        DB::table('intervention_support_measures')->insert([
            'ulid' => (string) Str::ulid(),
            'intervention_id' => $intervention->id,
            'support_measure_level' => SupportMeasureLevel::Additional->value,
            'support_measure_code' => SupportMeasureCode::IndividualTransitionPlan->value,
            'legal_mapping_source' => LegalMappingSource::Manual->value,
            'legal_framework_code' => 'pt-inclusive-education-2018',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/individual_transition_plan/');

        // Refusing is the point: the alternative is a rollback that silently
        // drops a real decision about a real child to fit an older schema.
        $migration->down();
    }

    #[Test]
    public function every_measure_of_the_widened_catalogue_can_actually_be_stored(): void
    {
        // SQLite ignores CHECK constraints, so this proves the column widths
        // and the write path locally, and proves the CHECK list on CI's MySQL.
        $teacher = $this->seededTeacher();
        $intervention = $this->interventionIn($teacher, []);

        foreach (SupportMeasureCode::cases() as $measure) {
            DB::table('intervention_support_measures')->insert([
                'ulid' => (string) Str::ulid(),
                'intervention_id' => $intervention->id,
                'support_measure_level' => $measure->currentPortugueseLevel()->value,
                'support_measure_code' => $measure->value,
                'legal_mapping_source' => LegalMappingSource::Manual->value,
                'legal_framework_code' => 'pt-inclusive-education-2018',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            count(SupportMeasureCode::cases()),
            DB::table('intervention_support_measures')->where('intervention_id', $intervention->id)->count(),
        );
    }

    #[Test]
    public function every_evaluation_adaptation_code_fits_its_column(): void
    {
        // The «VARCHAR too short for its own value» trap: filled_by VARCHAR(16)
        // once rejected a 17-character enum value on MySQL while SQLite let it
        // through. The longest code here is checked against the declared width.
        foreach (EvaluationAdaptationCode::cases() as $code) {
            $this->assertLessThanOrEqual(64, strlen($code->value), $code->value);
        }

        foreach (SupportMeasureCode::cases() as $code) {
            $this->assertLessThanOrEqual(64, strlen($code->value), $code->value);
        }

        foreach (InterventionType::cases() as $type) {
            $this->assertLessThanOrEqual(64, strlen($type->value), $type->value);
        }
    }
}
