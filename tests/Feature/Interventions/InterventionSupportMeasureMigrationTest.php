<?php

namespace Tests\Feature\Interventions;

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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InterventionSupportMeasureMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_backfills_every_complete_legacy_pair_and_rolls_back_cleanly(): void
    {
        $migration = require database_path('migrations/2026_09_10_000100_add_batches_and_support_measures_to_interventions.php');
        $migration->down();

        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            Intervention::query()->create([
                'class_id' => $class->id,
                'target_type' => InterventionTargetType::SchoolClass,
                'intervention_type' => InterventionType::PedagogicalDifferentiation,
                'domain_relation' => InterventionDomainRelation::None,
                'title' => 'Diferenciação pedagógica',
                'description_source' => InterventionDescriptionSource::Manual,
                'status' => InterventionStatus::New,
                'started_on' => '2026-09-10',
                'support_measure_level' => SupportMeasureLevel::Universal,
                'support_measure_code' => SupportMeasureCode::PedagogicalDifferentiation,
                'legal_mapping_source' => LegalMappingSource::Manual,
                'created_by' => $teacher->id,
            ]);
        });

        $migration->up();

        $this->assertSame(1, DB::table('intervention_support_measures')->count());
        $this->assertDatabaseHas('intervention_support_measures', [
            'support_measure_level' => SupportMeasureLevel::Universal->value,
            'support_measure_code' => SupportMeasureCode::PedagogicalDifferentiation->value,
            'legal_mapping_source' => LegalMappingSource::Manual->value,
        ]);

        $migration->down();
        $this->assertFalse(Schema::hasTable('intervention_support_measures'));
        $this->assertFalse(Schema::hasColumn('interventions', 'created_batch_ulid'));

        $migration->up();
        $this->assertSame(1, DB::table('intervention_support_measures')->count());
    }
}
