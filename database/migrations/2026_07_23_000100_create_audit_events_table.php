<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail (§22.4): who did what, when — for the events that touch a
 * student's record. Grade changes and history are legally sensitive (minors,
 * RGPD), so the mandatory aggregate events (§22.5: profile activation, version
 * creation, class migration, classification confirmation, manual override,
 * publication, instrument annulment, period closing, batch export) each leave a
 * row here.
 *
 * Written once and never updated — an audit line that can be edited is not an
 * audit line. Tenant-scoped like everything else; a causer/subject can never be
 * deleted while its trail exists (ON DELETE RESTRICT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Null when the actor is the system (a job, a scheduled close).
            $table->foreignId('causer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 48);
            // A loose reference to the affected record — enough to trace, without a
            // hard FK per possible subject type. Kept as literal copies.
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_ulid', 26)->nullable();
            $table->string('summary', 255)->nullable();
            $table->json('properties')->nullable();
            $table->dateTime('created_at'); // No updated_at: an audit event is immutable.

            $table->index(['organization_id', 'created_at'], 'audit_org_created_idx');
            $table->index(['organization_id', 'subject_type', 'subject_id'], 'audit_org_subject_idx');
        });
        $this->addCheck('audit_events', 'audit_event_check', "event <> ''");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
