<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Academic years and their periods (§9, docs/domain-model.md §2.2).
 *
 * The academic year is the calculation boundary: no calculation crosses it, so
 * prior-year data never enters the current year's numbers. Periods are not
 * limited to two — semesters, terms, trimesters and modules are all supported.
 * `kind` here is a label only; whether a period is cumulative is a versioned
 * pedagogical rule that lives with the profile, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('label', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16);
            $table->char('country_code', 2)->default('PT');
            $table->string('region_code', 8)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'label']);
            $table->index(['organization_id', 'status']);
        });

        // Enforced in the database as defense-in-depth (§21.7), on top of the
        // Form Request validation. ADD CONSTRAINT is a no-op on SQLite (the test
        // driver), so these guards are asserted by CI, which runs on MySQL —
        // exactly the split ADR-0001 documents.
        $this->addCheck('academic_years', 'academic_years_status_check', "status IN ('draft','active','closed','archived')");
        $this->addCheck('academic_years', 'academic_years_dates_check', 'ends_on > starts_on');

        Schema::create('academic_periods', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('label', 64);
            $table->string('kind', 16);
            $table->unsignedTinyInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16);
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['academic_year_id', 'sequence']);
            // Named explicitly — the auto-generated name exceeds MySQL's 64-char limit.
            $table->index(['organization_id', 'academic_year_id', 'starts_on'], 'academic_periods_org_year_start_index');
        });

        $this->addCheck('academic_periods', 'academic_periods_kind_check', "kind IN ('semester','term','trimester','module','other')");
        $this->addCheck('academic_periods', 'academic_periods_status_check', "status IN ('draft','open','closed','archived')");
        $this->addCheck('academic_periods', 'academic_periods_dates_check', 'ends_on > starts_on');
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_periods');
        Schema::dropIfExists('academic_years');
    }

    /**
     * Add a CHECK constraint on engines that support ALTER TABLE ADD CONSTRAINT.
     * SQLite does not, so it is skipped there — CI (MySQL) is where these run.
     */
    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
