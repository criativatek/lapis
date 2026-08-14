<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The jurisdiction an organization operates under — an ISO 3166-1 alpha-2 code
 * that decides which legal framework the Interventions module resolves.
 *
 * Strictly additive and deliberately without a backfill. NULL means "never
 * stated", which is a different thing from "Portugal": while it is NULL the
 * resolver falls back to config('lapis.default_jurisdiction') so existing
 * organizations keep the exact behaviour they have today, and once an
 * organization states a jurisdiction of its own that fallback stops applying
 * to it. Backfilling every row to 'PT' would erase that distinction and turn a
 * compatibility bridge into a permanent assumption.
 *
 * No CHECK constraint: ISO 3166-1 has around 250 codes and enumerating them in
 * the schema would age badly. Validation belongs in the application, alongside
 * the institutional onboarding that will eventually set this.
 *
 * Deliberately NOT derived from `locale` or `timezone`. A school in Portugal
 * may work in English; a school abroad may work in Portuguese. Language and
 * law are independent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->char('jurisdiction', 2)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('jurisdiction');
        });
    }
};
