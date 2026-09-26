<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's own observations for a results-analysis context (design spec
 * §5) — a table of its own because `instruments.internal_notes` is the
 * instrument's own note (form, exports) and the Relatórios module has no
 * "instrumento" scope. `context_kind` carries the future contexts (interim,
 * period, semester); only `instrument` exists in this phase.
 *
 * Recalculated indicators never touch this table — `lock_version` gives the
 * note its own optimistic-concurrency guard, independent of the scores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results_analysis_notes', function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('context_kind', 32);
            // instrument_id first: it is what actually needs the FK's index in
            // MySQL 8 (this table's own lookups filter by it), and the unique
            // constraint below still serves both columns together.
            $table->foreignId('instrument_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['instrument_id', 'context_kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results_analysis_notes');
    }
};
