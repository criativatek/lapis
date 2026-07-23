<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's logbook (§14, domain-model.md §10.2): observations, incidents,
 * participation, contacts — the qualitative record beside the grades. Evidence
 * NEVER enters the calculation (§14.3): it has no weight and no FK into results.
 * It can be flagged to appear in a report, and can be tied to a student (or the
 * whole class when enrollment_id is null) and optionally to a domain.
 *
 * Accessory links (criterion, instrument, lesson) and multi-student tagging
 * (evidence_participants) are left for a later slice — a single-student or
 * class-level note covers the daily use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_records', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            // Null = a class-level note, not about one student.
            $table->foreignId('enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('academic_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('quick_rating_scale_level_id')->nullable()->constrained('scale_levels')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->string('kind', 32);
            $table->string('description', 1000);
            $table->boolean('include_in_report')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['organization_id', 'class_id', 'occurred_at'], 'evidence_org_class_at_idx');
            $table->index(['organization_id', 'enrollment_id', 'occurred_at'], 'evidence_org_enrollment_at_idx');
            $table->index(['organization_id', 'include_in_report', 'academic_period_id'], 'evidence_org_report_period_idx');
        });
        $this->addCheck(
            'evidence_records',
            'evidence_kind_check',
            "kind IN ('homework','incident','positive_behaviour','participation','progress','difficulty','support','contact','activity','note')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_records');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
