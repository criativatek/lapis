<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens interventions from «one support measure, one student, with a duration»
 * into «a pedagogical action that may be one-off or ongoing, for a student, a
 * group or a whole class, optionally framed pedagogically/legally».
 *
 * Strictly additive. Nothing existing is dropped or renamed:
 *  - `enrollment_id` becomes nullable but stays, still populated for the
 *    student case, so old code paths and old rows keep working;
 *  - `include_in_report` stays as a legacy column, superseded by
 *    `available_for_reports` (its value is copied across, never lost);
 *  - `status`, `expected_end_on` and `concluded_on` are untouched — an
 *    intervention may still have a lifecycle, it is just no longer required to.
 *
 * Every existing row is migrated: its class is derived from its enrollment, it
 * becomes target_type = student, and its enrollment is copied into the new
 * pivot, which is the canonical list of participants from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervention_enrollment', function (Blueprint $table) {
            $table->id();
            // Pure pivot — the organization is reachable through the
            // intervention, so no organization_id here (same convention as
            // class_teachers).
            $table->foreignId('intervention_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['intervention_id', 'enrollment_id']);
        });

        Schema::table('interventions', function (Blueprint $table) {
            // Nullable for now: filled by the backfill below, then tightened.
            $table->foreignId('class_id')->nullable()->after('organization_id')->constrained('classes')->restrictOnDelete();
            $table->string('target_type', 16)->default('student')->after('class_id');
            // Nullable because rows created before this migration have a free
            // text title and no catalogue type. Everything created from now on
            // sets it; nothing invents a type for the old rows.
            $table->string('intervention_type', 64)->nullable()->after('target_type');
            $table->string('domain_relation', 16)->default('none')->after('domain_id');
            $table->string('description_source', 16)->default('manual')->after('description');
            $table->boolean('available_for_reports')->default(true)->after('include_in_report');
            $table->string('support_measure_level', 16)->nullable()->after('available_for_reports');
            $table->string('support_measure_code', 64)->nullable()->after('support_measure_level');
            $table->string('evaluation_adaptation_code', 64)->nullable()->after('support_measure_code');
            $table->string('legal_mapping_source', 32)->nullable()->after('evaluation_adaptation_code');

            $table->index(['organization_id', 'class_id', 'started_on'], 'interventions_org_class_started_idx');
        });

        $this->backfill();

        Schema::table('interventions', function (Blueprint $table) {
            // Safe now: every row got a class from its (mandatory) enrollment.
            $table->foreignId('class_id')->nullable(false)->change();
            // A class-wide or group intervention has no single enrollment.
            $table->foreignId('enrollment_id')->nullable()->change();
        });

        $this->addCheck('interventions', 'interventions_target_type_check', "target_type IN ('student','group','class')");
        $this->addCheck('interventions', 'interventions_domain_relation_check', "domain_relation IN ('none','specific','all')");
        $this->addCheck('interventions', 'interventions_description_source_check', "description_source IN ('manual','template','ai')");
        $this->addCheck('interventions', 'interventions_support_measure_level_check', "support_measure_level IS NULL OR support_measure_level IN ('universal','selective','additional')");
        $this->addCheck('interventions', 'interventions_legal_mapping_source_check', "legal_mapping_source IS NULL OR legal_mapping_source IN ('system_direct','system_suggested_confirmed','manual')");
    }

    /**
     * Carries every pre-existing intervention into the new shape. Chunked and
     * driven by the query builder rather than a single vendor-specific UPDATE
     * ... JOIN, so it behaves identically on MySQL and on the SQLite used by
     * the test suite.
     */
    protected function backfill(): void
    {
        DB::table('interventions')
            ->select('id', 'enrollment_id', 'domain_id', 'include_in_report')
            ->orderBy('id')
            ->chunkById(500, function ($interventions): void {
                foreach ($interventions as $intervention) {
                    $classId = DB::table('enrollments')->where('id', $intervention->enrollment_id)->value('class_id');

                    DB::table('interventions')->where('id', $intervention->id)->update([
                        'class_id' => $classId,
                        'target_type' => 'student',
                        // The old column had no default of its own at write
                        // time; whatever each row actually holds is preserved.
                        'available_for_reports' => $intervention->include_in_report,
                        // A row with a domain was, by definition, aimed at that
                        // one domain; one without simply had none chosen.
                        'domain_relation' => $intervention->domain_id === null ? 'none' : 'specific',
                    ]);

                    DB::table('intervention_enrollment')->insertOrIgnore([
                        'intervention_id' => $intervention->id,
                        'enrollment_id' => $intervention->enrollment_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        $this->dropCheck('interventions', 'interventions_legal_mapping_source_check');
        $this->dropCheck('interventions', 'interventions_support_measure_level_check');
        $this->dropCheck('interventions', 'interventions_description_source_check');
        $this->dropCheck('interventions', 'interventions_domain_relation_check');
        $this->dropCheck('interventions', 'interventions_target_type_check');

        // Restore the single-student shape before dropping the pivot it was
        // migrated into, so no row is left without a reachable participant.
        DB::table('interventions')->whereNull('enrollment_id')->orderBy('id')->chunkById(500, function ($interventions): void {
            foreach ($interventions as $intervention) {
                $enrollmentId = DB::table('intervention_enrollment')
                    ->where('intervention_id', $intervention->id)
                    ->orderBy('enrollment_id')
                    ->value('enrollment_id');

                if ($enrollmentId !== null) {
                    DB::table('interventions')->where('id', $intervention->id)->update(['enrollment_id' => $enrollmentId]);
                }
            }
        });

        // Anything still without an enrollment (a class-wide intervention) has
        // no representation in the old shape at all — dropping the column would
        // silently discard it, so the rollback stops instead.
        $orphans = DB::table('interventions')->whereNull('enrollment_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Rollback abortado: {$orphans} intervenção(ões) de turma/grupo sem enrollment individual não cabem no modelo anterior. ".
                'Exporte ou converta estes registos antes de reverter esta migração.'
            );
        }

        Schema::table('interventions', function (Blueprint $table) {
            $table->dropIndex('interventions_org_class_started_idx');
            $table->dropConstrainedForeignId('class_id');
            $table->dropColumn([
                'target_type', 'intervention_type', 'domain_relation', 'description_source',
                'available_for_reports', 'support_measure_level', 'support_measure_code',
                'evaluation_adaptation_code', 'legal_mapping_source',
            ]);
        });

        Schema::table('interventions', function (Blueprint $table) {
            $table->foreignId('enrollment_id')->nullable(false)->change();
        });

        Schema::dropIfExists('intervention_enrollment');
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
