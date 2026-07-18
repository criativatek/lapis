<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scales and their levels (§10.4, domain-model.md §2.3).
 *
 * A scale with organization_id NULL is a shared system scale (1–5, 0–20, 0–100).
 * A scale freezes on first use by an active profile version (copy-on-write via
 * frozen_at) rather than having its own version table.
 *
 * scale_levels is where numeric→qualitative conversion lives. Its band and
 * normalized columns are NULLABLE on purpose: §10.4 forbids inventing a numeric
 * value or a level threshold for a qualitative descriptor. Absent bands mean the
 * engine shows the raw value instead of guessing a level (this is question Q1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scales', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('kind', 24);
            $table->decimal('min_value', 6, 3)->nullable();
            $table->decimal('max_value', 6, 3)->nullable();
            $table->dateTime('frozen_at')->nullable();
            $table->foreignId('derived_from_scale_id')->nullable()->constrained('scales')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'kind']);
        });
        $this->addCheck('scales', 'scales_kind_check', "kind IN ('numeric','level','percentage','qualitative','custom')");

        Schema::create('scale_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scale_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('label', 64);
            $table->unsignedTinyInteger('sequence');
            $table->decimal('numeric_value', 6, 3)->nullable();
            $table->decimal('normalized_value', 9, 6)->nullable();
            $table->decimal('band_min_normalized', 9, 6)->nullable();
            $table->decimal('band_max_normalized', 9, 6)->nullable();
            $table->boolean('is_negative')->default(false);
            $table->timestamps();

            $table->unique(['scale_id', 'code']);
            $table->unique(['scale_id', 'sequence']);
            $table->index(['scale_id', 'band_min_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scale_levels');
        Schema::dropIfExists('scales');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
