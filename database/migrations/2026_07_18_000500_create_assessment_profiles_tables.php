<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment profiles and their versions (§10, domain-model.md §3).
 *
 * The profile is the stable container name. ALL the calculation rule lives in
 * assessment_profile_versions and its children, so a result can point at one
 * version row and know exactly how it was calculated. Editing an active profile
 * never mutates history — it creates a new draft version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_profiles', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('grade_level', 16)->nullable();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('is_institutional_template')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['organization_id', 'academic_year_id', 'subject_id', 'grade_level', 'name'], 'assessment_profiles_identity_unique');
            $table->index(['organization_id', 'academic_year_id', 'subject_id'], 'assessment_profiles_org_year_subject_index');
        });

        Schema::create('assessment_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version_number');
            $table->string('status', 16);
            $table->foreignId('scale_id')->constrained('scales')->restrictOnDelete();
            $table->string('domain_weight_mode', 24)->default('must_total_100');
            $table->string('period_result_mode', 32)->default('weighted_domain_average');
            // No invented default for the pedagogical rules — the teacher/PO sets
            // these (Q2, Q3, Q4). Columns exist so activation can freeze them.
            $table->string('accumulated_mode', 32)->nullable();
            $table->string('absence_mode', 32)->nullable();
            $table->string('rounding_mode', 16)->nullable();
            $table->unsignedTinyInteger('rounding_scale')->default(0);
            $table->string('rounding_stage', 16)->default('final_only');
            $table->json('minimum_rules')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('frozen_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_version_id')->nullable();
            $table->string('change_note', 500)->nullable();
            $table->unsignedBigInteger('created_from_version_id')->nullable();
            $table->timestamps();

            $table->unique(['assessment_profile_id', 'version_number'], 'apv_profile_version_unique');
            $table->index(['organization_id', 'status'], 'apv_org_status_index');
            $table->index(['assessment_profile_id', 'status'], 'apv_profile_status_index');
        });

        // At most one active version per profile — enforced by the database, not
        // just PHP. A double-click on "Activate" must not create two active
        // versions and corrupt every later calculation (§5.4). MySQL has no
        // partial unique index, so a generated column that is 1 only when active
        // (NULL otherwise, and NULLs are ignored by unique indexes) does the job.
        // CASE WHEN is portable across MySQL and SQLite, so the guard is tested too.
        DB::statement("ALTER TABLE assessment_profile_versions ADD COLUMN active_flag TINYINT UNSIGNED AS (CASE WHEN status = 'active' THEN 1 ELSE NULL END) STORED");
        DB::statement('CREATE UNIQUE INDEX apv_one_active_version ON assessment_profile_versions (assessment_profile_id, active_flag)');

        $this->addCheck('assessment_profile_versions', 'apv_status_check', "status IN ('draft','active','superseded','retired')");
        $this->addCheck('assessment_profile_versions', 'apv_weight_mode_check', "domain_weight_mode IN ('must_total_100','free')");

        // current_version_id points back at a version — added now that both tables
        // exist. It is a read shortcut to the active version, not the source of truth.
        Schema::table('assessment_profiles', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('assessment_profile_versions')->restrictOnDelete();
        });

        Schema::create('profile_version_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_profile_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->restrictOnDelete();
            $table->decimal('weight_percent', 7, 4);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedTinyInteger('expected_element_count')->nullable();
            $table->unsignedTinyInteger('minimum_element_count')->nullable();
            $table->timestamps();

            $table->unique(['assessment_profile_version_id', 'domain_id'], 'pvd_version_domain_unique');
            $table->index('domain_id');
        });

        Schema::create('profile_version_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_profile_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->boolean('is_cumulative')->default(false);
            $table->decimal('period_weight_percent', 7, 4)->nullable();
            $table->boolean('contributes_to_accumulated')->default(true);
            $table->timestamps();

            $table->unique(['assessment_profile_version_id', 'academic_period_id'], 'pvp_version_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_profiles', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('profile_version_periods');
        Schema::dropIfExists('profile_version_domains');
        Schema::dropIfExists('assessment_profile_versions');
        Schema::dropIfExists('assessment_profiles');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
