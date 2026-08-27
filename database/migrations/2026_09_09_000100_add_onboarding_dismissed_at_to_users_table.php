<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a teacher dismissed the "Primeiros passos" onboarding checklist on the
 * Dashboard (A1a, Onboarding & Help).
 *
 * THE ONLY PERSISTED STATE THIS CHECKLIST HAS. Which of its steps are done is
 * never stored — DashboardController::firstSteps() derives every item live
 * from whether a class/enrollment/instrument/score already exists, the same
 * "evitar checkboxes meramente declarativos" principle readiness() already
 * follows a few methods above it. Dismissing only hides the card; there is no
 * progress recorded here for it to reset.
 *
 * NOT append-only like `anonymized_at`: nothing about first steps is
 * irreversible, so OnboardingController::restore() clears this back to null
 * to bring the card back — a real un-dismiss, not a second column, not a log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_dismissed_at');
        });
    }
};
