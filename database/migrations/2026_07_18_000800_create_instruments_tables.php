<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Instruments, their items, domain allocations and the recorded scores
 * (§12, domain-model.md §4).
 *
 * Everything scored is an item — a written test question, a rubric criterion, or
 * a single oral observation. One scoring unit means one code path in the engine
 * instead of four (§4.2).
 *
 * student_item_scores is where "empty is never zero" (§12.4) becomes structural:
 * points_earned is NULL unless the state is `assessed`, enforced by CHECK on
 * MySQL and by a model guard everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        // organization_id NULL = a shared system type, like scales.
        Schema::create('instrument_types', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('code', 32);
            $table->string('default_purpose', 16)->default('summative');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('instruments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('instrument_type_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->date('applied_on'); // Compared with enrollments.enrolled_on (A3).
            $table->string('status', 16);
            // The three axes are independent by requirement (§4.1): purpose is a
            // label the engine never reads; counts_toward_classification is the
            // only gate into the calculation; weight is separate from both.
            $table->boolean('counts_toward_classification')->default(true);
            $table->string('purpose', 16)->default('summative');
            $table->decimal('total_points', 8, 4)->nullable();
            $table->foreignId('scale_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('weight', 7, 4)->nullable();
            $table->boolean('allow_bonus')->default(false);
            $table->text('internal_notes')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['organization_id', 'class_id', 'academic_period_id', 'applied_on'], 'instruments_org_class_period_date_idx');
            $table->index(['organization_id', 'class_id', 'status'], 'instruments_org_class_status_idx');
            // Supports the calculation engine's "which instruments count?" query.
            $table->index(['organization_id', 'class_id', 'counts_toward_classification', 'applied_on'], 'instruments_engine_idx');
        });
        $this->addCheck('instruments', 'instruments_status_check', "status IN ('draft','prepared','in_correction','completed','published','cancelled','archived')");
        $this->addCheck('instruments', 'instruments_purpose_check', "purpose IN ('diagnostic','formative','summative','other')");

        Schema::create('instrument_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('label', 500)->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->decimal('points_possible', 8, 4);
            $table->string('scoring_mode', 16)->default('points');
            $table->foreignId('scale_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_bonus')->default(false); // Excluded from the denominator.
            $table->string('source_group_label', 120)->nullable(); // Intuitivo "Grupo de Questões" (A9).
            $table->timestamps();

            $table->unique(['instrument_id', 'code']);
            $table->index(['organization_id', 'instrument_id', 'sequence'], 'instrument_items_org_inst_seq_idx');
        });
        $this->addCheck('instrument_items', 'instrument_items_scoring_mode_check', "scoring_mode IN ('points','scale_level')");

        // Scenario A2: one question split 60/40 across two domains.
        Schema::create('item_domain_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->restrictOnDelete();
            $table->decimal('allocation_percent', 7, 4);
            $table->timestamps();

            $table->unique(['instrument_item_id', 'domain_id'], 'ida_item_domain_unique');
            $table->index('domain_id');
        });

        Schema::create('student_item_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Denormalized from the item so the grid can filter without a join.
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->foreignId('instrument_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->string('result_state', 32)->default('pending');
            // NULL unless assessed. Never 0 to mean "no value" (§12.4).
            $table->decimal('points_earned', 8, 4)->nullable();
            $table->foreignId('scale_level_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('state_reason', 255)->nullable();
            $table->dateTime('assessed_at')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['instrument_item_id', 'enrollment_id'], 'sis_item_enrollment_unique');
            $table->index(['organization_id', 'instrument_id', 'enrollment_id'], 'sis_org_inst_enrollment_idx');
            $table->index(['enrollment_id', 'result_state'], 'sis_enrollment_state_idx');
        });
        $this->addCheck('student_item_scores', 'sis_state_check', "result_state IN ('pending','assessed','absent','absent_justified','exempt','not_applicable','annulled','under_review')");
        // The literal translation of "never treat an empty cell as zero" into the
        // database: unless the state is `assessed`, a number in that row is
        // physically impossible. An application bug writing 0 for an absent
        // student is refused by MySQL, not discovered in June.
        $this->addCheck('student_item_scores', 'sis_empty_is_not_zero_check', "result_state = 'assessed' OR points_earned IS NULL");
        $this->addCheck('student_item_scores', 'sis_assessed_has_value_check', "result_state <> 'assessed' OR points_earned IS NOT NULL OR scale_level_id IS NOT NULL");

        // Exists only to CONTRADICT the derived late-entry rule (§11.4, A3).
        // Normally zero rows: applicability is derived by comparing applied_on
        // with enrolled_on, so fixing a typo'd enrollment date fixes everything.
        Schema::create('enrollment_instrument_applicability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('reason', 500); // Mandatory — an exception must be justified.
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at');
            $table->timestamps();

            $table->unique(['enrollment_id', 'instrument_id'], 'eia_enrollment_instrument_unique');
        });
        $this->addCheck('enrollment_instrument_applicability', 'eia_decision_check', "decision IN ('include','exclude')");
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_instrument_applicability');
        Schema::dropIfExists('student_item_scores');
        Schema::dropIfExists('item_domain_allocations');
        Schema::dropIfExists('instrument_items');
        Schema::dropIfExists('instruments');
        Schema::dropIfExists('instrument_types');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
