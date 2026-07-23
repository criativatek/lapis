<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Self-assessment (§15, domain-model.md §10.3): the student reflects on their own
 * learning, by domain. It is NEVER part of the calculation — there is no FK from
 * here into results; the comparison with the calculated grade is made on read, by
 * domain and period ("comparação informativa"). §15 allows the teacher to fill it
 * in an interview, so `filled_by` records who did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_assessment_templates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_profile_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->restrictOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('self_assessment_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('self_assessment_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('prompt', 500);
            $table->string('answer_kind', 16);
            $table->foreignId('scale_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->timestamps();
        });
        $this->addCheck('self_assessment_questions', 'saq_answer_kind_check', "answer_kind IN ('scale','text','boolean')");

        Schema::create('self_assessments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('self_assessment_template_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            // 24, not the spec's 16: the value 'teacher_interview' is 17 chars.
            $table->string('filled_by', 24);
            $table->text('reflection')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['enrollment_id', 'academic_period_id', 'self_assessment_template_id'], 'sa_enroll_period_template_unique');
        });
        $this->addCheck('self_assessments', 'sa_status_check', "status IN ('draft','submitted','reviewed')");
        $this->addCheck('self_assessments', 'sa_filled_by_check', "filled_by IN ('student','teacher_interview')");

        Schema::create('self_assessment_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('self_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('self_assessment_question_id')->constrained()->restrictOnDelete();
            $table->foreignId('scale_level_id')->nullable()->constrained('scale_levels')->restrictOnDelete();
            $table->text('text_value')->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->timestamps();

            $table->unique(['self_assessment_id', 'self_assessment_question_id'], 'sar_assessment_question_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_assessment_responses');
        Schema::dropIfExists('self_assessments');
        Schema::dropIfExists('self_assessment_questions');
        Schema::dropIfExists('self_assessment_templates');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
