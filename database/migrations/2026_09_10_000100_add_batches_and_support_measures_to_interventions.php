<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->ulid('created_batch_ulid')->nullable()->after('id');
            $table->index('created_batch_ulid');
        });

        Schema::create('intervention_support_measures', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('intervention_id')->constrained()->cascadeOnDelete();
            $table->string('support_measure_level', 32);
            $table->string('support_measure_code', 64);
            $table->string('legal_mapping_source', 32)->nullable();
            $table->timestamps();
            $table->unique(
                ['intervention_id', 'support_measure_level', 'support_measure_code'],
                'intervention_support_measure_unique',
            );
        });

        $this->addCheck('intervention_support_measures', 'intervention_support_measures_level_check',
            "support_measure_level IN ('universal','selective','additional')");
        $this->addCheck('intervention_support_measures', 'intervention_support_measures_code_check',
            "support_measure_code IN ('pedagogical_differentiation','curricular_accommodation','academic_focus_small_group','non_significant_curricular_adaptation','anticipation_learning_reinforcement','psychopedagogical_support','tutorial_support','significant_curricular_adaptation','personal_social_autonomy_skills')");
        $this->addCheck('intervention_support_measures', 'intervention_support_measures_source_check',
            "legal_mapping_source IS NULL OR legal_mapping_source IN ('system_direct','system_suggested_confirmed','manual')");

        DB::table('interventions')
            ->whereNotNull('support_measure_level')
            ->whereNotNull('support_measure_code')
            ->select('id', 'support_measure_level', 'support_measure_code', 'legal_mapping_source')
            ->orderBy('id')
            ->chunkById(500, function ($interventions): void {
                foreach ($interventions as $intervention) {
                    DB::table('intervention_support_measures')->insertOrIgnore([
                        'ulid' => (string) Str::ulid(),
                        'intervention_id' => $intervention->id,
                        'support_measure_level' => $intervention->support_measure_level,
                        'support_measure_code' => $intervention->support_measure_code,
                        'legal_mapping_source' => $intervention->legal_mapping_source,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        $this->dropCheck('intervention_support_measures', 'intervention_support_measures_source_check');
        $this->dropCheck('intervention_support_measures', 'intervention_support_measures_code_check');
        $this->dropCheck('intervention_support_measures', 'intervention_support_measures_level_check');
        Schema::dropIfExists('intervention_support_measures');

        Schema::table('interventions', function (Blueprint $table) {
            $table->dropIndex(['created_batch_ulid']);
            $table->dropColumn('created_batch_ulid');
        });
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    protected function dropCheck(string $table, string $name): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        }
    }
};
