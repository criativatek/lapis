<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation into an institutional organization (Fatia 3).
 *
 * The token itself is never stored — only its hash, the same shape the
 * framework's own password_reset_tokens table uses. Lookup at acceptance time
 * hashes the raw token from the URL and matches it here; nothing that leaks
 * this table (a backup, a slow query log) hands out a usable token.
 *
 * No status enum: `accepted_at` / `cancelled_at` ARE the status, the same
 * pattern academic_years and assessment_profile_versions already use for
 * "when did this happen" rather than "what state is this in" — a row that
 * was accepted keeps that timestamp forever, which a bare status column would
 * throw away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('email', 255);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            // Not unique: an organization may legitimately invite the same
            // address more than once over time (declined, expired, invited
            // again). "At most one PENDING invitation per email" is an
            // application-level rule (renew in place), not a DB constraint,
            // because "pending" depends on expires_at > now() and MySQL
            // generated columns cannot express that.
            $table->index(['organization_id', 'email']);
        });
        $this->addCheck('organization_invitations', 'organization_invitations_email_check', "email <> ''");
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
