<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support interventions (§14, domain-model.md §10.1): the measures a teacher puts
 * in place for a student, with their own lifecycle (new → in progress → concluded)
 * and periodic reviews of effectiveness. Like evidence, an intervention NEVER
 * enters the calculation (§14.3) — behaviour and academic classification stay
 * separate. A long intervention is reviewed several times, so reviews are a child
 * table, not a single column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interventions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Tied to the enrollment, not the student: an intervention belongs to a
            // class/year context.
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('new');
            $table->date('started_on');
            $table->date('expected_end_on')->nullable();
            $table->date('concluded_on')->nullable();
            $table->boolean('include_in_report')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['organization_id', 'enrollment_id', 'status'], 'interventions_org_enroll_status_idx');
            $table->index(['organization_id', 'status', 'started_on'], 'interventions_org_status_started_idx');
        });
        $this->addCheck('interventions', 'interventions_status_check', "status IN ('new','in_progress','concluded','cancelled')");

        Schema::create('intervention_reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('intervention_id')->constrained()->cascadeOnDelete();
            $table->date('reviewed_on');
            $table->string('effectiveness', 24)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'intervention_id', 'reviewed_on'], 'intervention_reviews_idx');
        });
        $this->addCheck('intervention_reviews', 'intervention_reviews_effectiveness_check', "effectiveness IS NULL OR effectiveness IN ('not_effective','partially_effective','effective','inconclusive')");
    }

    public function down(): void
    {
        Schema::dropIfExists('intervention_reviews');
        Schema::dropIfExists('interventions');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
