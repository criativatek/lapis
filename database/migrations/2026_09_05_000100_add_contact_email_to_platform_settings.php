<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public contact address, so «Falar connosco» on the landing page has a
 * real destination.
 *
 * IT IS A SETTING, NOT A CONSTANT IN THE CODE. The address a school writes to
 * changes for reasons that have nothing to do with a deploy — a new mailbox, a
 * different person answering — and the operator already edits the platform's
 * mail configuration from the backoffice. This belongs beside it.
 *
 * DISTINCT FROM `mail_from_address`, which is the system SENDER (the
 * no-reply the verification mails leave from). Nobody should be invited to
 * write to that one.
 *
 * Nullable with no default on purpose: there is no address to invent, and the
 * landing page renders without the call to action rather than pointing it
 * somewhere it does not belong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->string('contact_email')->nullable()->after('mail_from_name');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('contact_email');
        });
    }
};
