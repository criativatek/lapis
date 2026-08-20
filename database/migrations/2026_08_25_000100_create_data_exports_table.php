<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user-requested export of their own accessible data (Fatia 4) — a ZIP
 * built synchronously and kept on the private `local` disk for a short,
 * configurable window (`config('retention.data_export_availability_hours')`).
 *
 * This is deliberately NOT a queue-job record: everything in this app runs
 * synchronously today (no `ShouldQueue` job exists anywhere yet), so a row
 * only ever gets created once the outcome (ready/failed) is already known.
 * `disk_path` is a token folder, not the organization's real identifier —
 * see `App\Actions\DataExports\GenerateDataExport`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 16);
            $table->string('disk_path', 255)->nullable();
            $table->unsignedInteger('byte_size')->nullable();
            $table->string('failed_reason', 255)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'requested_by']);
            $table->index('expires_at');
        });
        $this->addCheck('data_exports', 'data_exports_status_check', "status IN ('ready','failed')");
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
