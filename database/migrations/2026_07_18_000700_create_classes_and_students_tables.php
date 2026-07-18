<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classes, teachers, students, identities and enrollments (§11, domain-model.md §2.2).
 *
 * Student identity is kept separate from pedagogical data (§11.2, §22.2): `students`
 * holds only a non-identifying pseudonym; the name lives encrypted in
 * `student_identities` (ADR-0004). A result never links to a student directly — it
 * links to an `enrollment` (the student-in-a-class pair), which is why `enrolled_on`
 * carries the calculation-relevant entry date (§11.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('grade_level', 16)->nullable();
            $table->string('course_code', 32)->nullable();
            $table->string('label', 64);
            // The active profile version this class is currently assessed by. History
            // lives in results and class_profile_migrations, not here.
            $table->foreignId('assessment_profile_version_id')->nullable()
                ->constrained('assessment_profile_versions')->restrictOnDelete();
            $table->string('status', 16);
            $table->timestamps();

            $table->unique(['organization_id', 'academic_year_id', 'subject_id', 'label'], 'classes_identity_unique');
            $table->index(['organization_id', 'academic_year_id', 'status'], 'classes_org_year_status_index');
        });
        $this->addCheck('classes', 'classes_status_check', "status IN ('preparation','active','closed','archived')");

        // Pure pivot — the organization is reachable via the class, so no
        // organization_id here (§ domain-model.md §2.2: UNIQUE(class_id, user_id) + role).
        Schema::create('class_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 16);
            $table->timestamps();

            $table->unique(['class_id', 'user_id']);
            $table->index('user_id');
        });
        $this->addCheck('class_teachers', 'class_teachers_role_check', "role IN ('owner','co_teacher','observer')");

        // Pedagogical, non-identifying. This table holds NO name.
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('pseudonym_code', 16);
            $table->timestamps();

            $table->unique(['organization_id', 'pseudonym_code']);
        });

        // Identifying data — separate table, own Policy, encrypted at rest (ADR-0004).
        Schema::create('student_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->unique('student_id');
            // Redundant by design: lets the tenant Policy work without a join to students.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->binary('display_name'); // Laravel `encrypted` cast (AES-256-GCM via APP_KEY).
            $table->char('display_name_index', 64)->nullable(); // blind index (HMAC) for exact search.
            $table->binary('school_number')->nullable();
            $table->date('birth_date')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'display_name_index'], 'student_identities_org_name_index');
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('class_number')->nullable();
            $table->date('enrolled_on'); // The calculation engine depends on this (§11.4).
            $table->date('left_on')->nullable();
            $table->string('status', 16);
            $table->boolean('is_late_entry')->default(false); // UI marker only; the engine uses enrolled_on.
            $table->string('late_entry_note', 255)->nullable();
            $table->timestamps();

            // enrolled_on in the key so a student can leave and re-enter the same
            // class; a bare (class_id, student_id) would block re-entry (Q9).
            $table->unique(['class_id', 'student_id', 'enrolled_on'], 'enrollments_class_student_date_unique');
            $table->index(['organization_id', 'class_id', 'status'], 'enrollments_org_class_status_index');
            $table->index(['organization_id', 'student_id'], 'enrollments_org_student_index');
        });
        $this->addCheck('enrollments', 'enrollments_status_check', "status IN ('active','transferred_out','left','concluded')");
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('student_identities');
        Schema::dropIfExists('students');
        Schema::dropIfExists('class_teachers');
        Schema::dropIfExists('classes');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
