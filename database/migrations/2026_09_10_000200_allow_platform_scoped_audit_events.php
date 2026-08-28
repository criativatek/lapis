<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An audit event may now belong to the PLATFORM rather than to an organization.
 *
 * THE PROBLEM THIS SOLVES. §9 of the AI Core brief requires that changing the AI
 * configuration — and above all creating or replacing the credential — leaves a
 * trail. `audit_events` is that trail, and it is where a decision of this weight
 * belongs. But every row in it has so far been a tenant's row: `organization_id`
 * was NOT NULL, and the platform backoffice has no tenant. Turning the engine on
 * for the whole SaaS is not something that happened to one school.
 *
 * THE ALTERNATIVES, AND WHY NOT. Stamping the acting admin's own organization
 * would file a platform-wide act inside one teacher's audit log, where its owner
 * can read it (`AuditEvent::scopeVisibleTo`) and where it is simply not true. A
 * second `ai_configuration_events` table would be a parallel audit trail — the
 * exact duplication the brief forbids, and one that the next platform-level
 * event (the SMTP password, which is equally unaudited today) would have to be
 * added to as well. Making the column nullable extends the trail the project
 * already has to cover the platform, once, for everything.
 *
 * THESE ROWS ARE INVISIBLE TO TENANTS BY CONSTRUCTION, and that is the point
 * rather than a side effect. `BelongsToOrganization` scopes every query to
 * `organization_id = <resolved tenant>`, and NULL never equals a number in SQL —
 * so a platform row cannot appear in any organization's audit log without
 * somebody explicitly writing `withoutGlobalScope('organization')`. The existing
 * `creating` hook already supports this: it fills the tenant only when the
 * attribute is ABSENT, so a row that says `organization_id => null` out loud is
 * left alone. Nothing about tenant behaviour changes.
 *
 * THE FOREIGN KEY AND THE INDEXES STAY. A nullable FK is still an FK for every
 * row that has a value, and MySQL's composite indexes on
 * (organization_id, …) index NULLs fine.
 *
 * REVERSIBILITY, HONESTLY. `down()` cannot restore NOT NULL while platform rows
 * exist, and there is no organization to move them to — inventing one would be
 * worse than saying so. It therefore DELETES the platform-scoped rows before
 * restoring the constraint. That is destructive and it is the only correct
 * answer available: this migration is reversible in a development database, and
 * in production it is a one-way door that should be treated as one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `unsignedBigInteger`, not `foreignId`: the column already carries its
        // constraint and this only relaxes nullability. `foreignId()->change()`
        // would restate the relationship and, on MySQL, try to add a second one.
        Schema::table('audit_events', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // The only rows that can be null are the ones this migration made
        // possible. See the class docblock: there is nowhere to move them to.
        DB::table('audit_events')->whereNull('organization_id')->delete();

        Schema::table('audit_events', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
        });
    }
};
