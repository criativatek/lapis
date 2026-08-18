<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the school IS, on a document — beside `organizations`, not inside it.
 *
 * The organizations row is the technical tenant: a name, an owner, a timezone,
 * a locale. What goes on the letterhead of a report is a different kind of
 * thing and there is a lot of it, so it lives in its own table — the same
 * separation `student_identities` already makes for a student's real-world
 * details, for the same reason.
 *
 * ONE ROW PER ORGANIZATION, or none. A school that has never filled this in
 * has no row at all, which is what lets «ainda não configurado» be a real
 * state rather than a table full of empty strings.
 *
 * EVERYTHING IS NULLABLE except the tenant it belongs to. A teacher may know
 * the school's name today and its NIF next week, and nothing here should stop
 * them saving what they do know.
 *
 * NOT VERSIONED, deliberately. When Relatórios gains a «finalizar» step it may
 * want to snapshot the identity as it stood — the same way an Avaliação
 * intercalar snapshots a class — but that is a decision for the module that
 * needs it, not a table shape to guess at now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();

            // The words that head a document.
            $table->string('official_name')->nullable();
            $table->string('short_name', 120)->nullable();

            // Where it is.
            $table->string('address', 255)->nullable();
            // A string, never a number: «1000-001» is not arithmetic, and a
            // leading zero must survive.
            $table->string('postal_code', 32)->nullable();
            $table->string('locality', 120)->nullable();
            $table->string('country', 64)->nullable();

            // How to reach it.
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // What a Portuguese school is known by administratively. Optional:
            // a teacher writing a report for their own class needs none of it.
            $table->string('school_code', 32)->nullable();
            $table->string('tax_number', 32)->nullable();
            $table->string('department', 120)->nullable();

            // A line a school may want at the foot of every document.
            $table->string('footer_note', 255)->nullable();

            // A path on the private disk, never the image itself.
            $table->string('logo_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_identities');
    }
};
