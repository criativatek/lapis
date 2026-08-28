<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per call to an AI engine: what it was for, what it cost, how it ended.
 *
 * WHY A TABLE OF ITS OWN, WHEN `audit_events` EXISTS. They answer different
 * questions and only one of them is answerable by counting. `audit_events`
 * records INTENT — a person did a thing, here is why, here is what it affected —
 * and is read one row at a time by a human looking for an incident. This is a
 * METER: it is read in aggregate («how many requests has this organization made
 * this month», «is this user over the daily ceiling»), it is written on every
 * call including the ones nobody performed deliberately, and the quota check in
 * `AiQuota` is a `count()` over it on the request path. Doing that against
 * `audit_events` would mean a `JSON_EXTRACT` over a tenant-scoped table whose
 * rows are, by design, about something else — and would put a metering index on
 * the audit trail.
 *
 * IT ALSO CLOSES A DEBT THAT WAS WRITTEN DOWN. ADR-0006 §«Dívidas registadas» 3:
 * «os contadores de tokens são gravados desde o primeiro dia, mas não há painel
 * que os leia». They were being written into a JSON blob on an audit row, where
 * nothing could add them up. This is where they can be.
 *
 * WHAT IT DELIBERATELY DOES NOT STORE (§7). No prompt. No answer. No section
 * text, no student, no result, no classification, no name. Not truncated, not
 * hashed-and-also-kept — absent. A metering table that accumulated what teachers
 * wrote about children would be a data-protection problem created in order to
 * measure a bill, and the reporting layer already proved (ADR-0006 §7) that
 * hashes are enough to answer «was what was accepted what was suggested».
 * `subject_hash` is the same idea: enough to see that two calls concerned the
 * same thing, never enough to say what.
 *
 * TENANT-SCOPED, WITH A NULLABLE TENANT. Ordinary traffic belongs to an
 * organization. The backoffice's «testar ligação» does not — an operator testing
 * a credential is not a school using its quota, and billing it to whichever
 * organization happened to be resolved would be wrong twice over. Those rows
 * carry `organization_id` null and `use_case` = `admin_connection_test`, and the
 * global scope keeps them out of every tenant's counting for free.
 *
 * APPEND-ONLY IN PRACTICE, NOT ENFORCED IN SCHEMA. Unlike `audit_events` this is
 * operational data rather than evidence: retention will eventually want to prune
 * it, and a model guard against UPDATE would have to be argued around on the day
 * that happens. `created_at` only, no `updated_at` — there is no such thing as
 * editing a measurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();

            // Null for platform-level calls (the backoffice connection test).
            // nullOnDelete, not restrictOnDelete: an organization that closes
            // and is deleted must not be held open by its meter readings, and
            // the row still measures a real call that really cost money.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // WHAT it was for. `capability` is the entitlement key that had to
            // be granted (help_assistant, ai_pedagogical_analysis, …) and is
            // what quotas are counted by; `use_case` is the finer-grained thing
            // inside it, so one capability's traffic can be told apart without
            // a second entitlement.
            $table->string('capability', 48);
            $table->string('use_case', 48);

            $table->string('provider', 32);
            $table->string('model', 64);

            // Absent stays absent: not every engine reports counts, and a
            // missing measurement recorded as zero is a false one.
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();

            // Wall clock for the whole call, including the parts that are ours.
            $table->unsignedInteger('duration_ms')->nullable();

            // 'succeeded' | 'failed' | 'blocked'. `blocked` is ours and never
            // the engine's: a quota, a rate limit or an entitlement stopped the
            // call before it left the building.
            $table->string('status', 16);

            // One of AiRequestFailed::CATEGORIES, or a blocking reason. Null on
            // success. A closed vocabulary — never a vendor's error string.
            $table->string('error_category', 32)->nullable();

            // Enough to see that two calls were about the same thing. Never
            // enough to say what that thing was.
            $table->char('subject_hash', 64)->nullable();

            $table->dateTime('created_at');

            // The quota query: this organization, this capability, since a date.
            $table->index(['organization_id', 'capability', 'created_at'], 'ai_usage_org_capability_idx');
            // The per-user ceiling, and «what has this teacher been doing».
            $table->index(['user_id', 'capability', 'created_at'], 'ai_usage_user_capability_idx');
            // Cost by engine over a period, for the operator rather than the tenant.
            $table->index(['created_at', 'provider'], 'ai_usage_created_provider_idx');
        });

        $this->addCheck('ai_usage_events', 'ai_usage_status_check', "status in ('succeeded', 'failed', 'blocked')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }

    /**
     * MySQL/MariaDB only, matching the convention the audit_events migration
     * established — SQLite (tests) enforces the same rule through the enum on
     * the model side.
     */
    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
