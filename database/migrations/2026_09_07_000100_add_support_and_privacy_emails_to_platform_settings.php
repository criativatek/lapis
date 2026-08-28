<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three public addresses instead of one, because they are answered by
 * different people under different obligations.
 *
 * `contact_email` alone was doing three jobs: the commercial «Falar connosco»
 * on the Institucional card, the footer's «Contacto», and the data-protection
 * line. They are not the same conversation. A GDPR request carries a ONE-MONTH
 * legal deadline (RGPD art. 12.º/3) from the moment it arrives; a request for
 * a quote does not. Sending both to one inbox means the deadline is only met
 * by whoever happens to read it.
 *
 * `contact_email` KEEPS ITS MEANING — the commercial/institutional address,
 * which is what the deployed CTA already uses. The two new columns are the
 * ones that were missing.
 *
 * BOTH FALL BACK TO `contact_email` (in the model, not here): an operator who
 * has configured only the one address keeps exactly the behaviour they have
 * today, and can split them later without anything going dark in between.
 *
 * Still never falls back to `mail_from_address` — that is the system sender.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->string('support_email')->nullable()->after('contact_email');
            $table->string('privacy_email')->nullable()->after('support_email');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['support_email', 'privacy_email']);
        });
    }
};
