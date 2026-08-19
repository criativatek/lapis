<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Report templates: how a document is organised, never what it says.
 *
 * A TEMPLATE IS NOT A REPORT. It holds a report type, which sections are in,
 * the order they come in, the tone and a few options — and nothing else. No
 * averages, no classifications, no student names, no generated text. That
 * separation is the whole feature: a template must be reusable across a year
 * and across classes, which a document containing anybody's grades cannot be.
 *
 * SHAPED LIKE `scales` AND `report_library_entries`, because this project
 * already has a pattern for "mostly reference data, sometimes yours":
 * organization_id NULL is a shared system row, a row with one belongs to a
 * school. `user_id` narrows it further to one teacher.
 *
 * WHY A TABLE RATHER THAN report_library_entries. That table holds pedagogical
 * CONTENT — difficulties, the strategies that answer them, the objectives they
 * serve. A template holds STRUCTURE. Overloading `kind` to carry both would
 * mean one table whose rows share no columns and no meaning.
 *
 * WHY THE SECTIONS ARE JSON. A template's section list is read whole, applied
 * whole, and never filtered into: nothing asks "which templates contain
 * `domain_results`". Normalising it would cost a second table that is only ever
 * joined back immediately — and every future addition to what a template
 * configures would need a migration instead of a key.
 *
 * ADDITIVE ONLY. Nothing existing is touched; `reports.template_snapshot` is a
 * new nullable column beside the `template_key` that was already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // NULL = a shared system template, as `scales` does.
            //
            // restrictOnDelete on both, and not only for consistency with
            // `reports`: MySQL refuses a CHECK constraint over a column whose
            // foreign key carries ON DELETE SET NULL or CASCADE, and the CHECK
            // below is what keeps the three kinds from blurring. An
            // organization with templates is already undeletable because it has
            // reports.
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            // The author of a personal template, or the creator of an
            // institutional one. Null on system rows, which nobody authored.
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('kind', 16);
            // Stable across rewordings, and how the seeder finds its own rows
            // again. Null for anything a person created — their templates are
            // identified by ulid and named by them.
            $table->string('key', 64)->nullable();

            $table->string('report_type', 32);
            $table->string('name', 160);
            $table->text('description')->nullable();

            // The whole configuration: sections (key, included, position),
            // tone, options. Read whole, applied whole.
            $table->json('settings');

            // §44: one preferred template per owner per report type. Enforced
            // by the writer rather than by a unique index, because "one per
            // user per type" and "one per organization per type" are two
            // different constraints on the same column.
            $table->boolean('is_default')->default(false);
            // §21, §43: a template that has been used is deactivated, never
            // deleted — history must not break.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['organization_id', 'kind', 'report_type'], 'report_templates_scope_idx');
            $table->index(['user_id', 'report_type']);
            $table->unique(['organization_id', 'kind', 'key'], 'report_templates_key_unique');
        });

        // A template belongs to exactly one of the three worlds, and each has
        // its own shape. Stated in the schema so no code path can produce a
        // "personal" template belonging to nobody.
        $this->addCheck(
            'report_templates',
            'report_templates_kind_scope_check',
            "(kind = 'system' AND organization_id IS NULL AND user_id IS NULL)"
            ." OR (kind = 'personal' AND organization_id IS NOT NULL AND user_id IS NOT NULL)"
            ." OR (kind = 'institutional' AND organization_id IS NOT NULL)",
        );

        Schema::table('reports', function (Blueprint $table): void {
            // §15, §30: WHAT THE TEMPLATE SAID WHEN THIS REPORT WAS CREATED.
            //
            // Not a foreign key on its own, and deliberately not read back from
            // the template at print time: editing a template must never reach
            // backwards into a report that already exists (§14). The snapshot
            // is taken once, at creation, and travels into the frozen document
            // at finalization — so a finished report stays reproducible even
            // after the template it started from has changed or been removed.
            $table->json('template_snapshot')->nullable()->after('template_key');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('template_snapshot');
        });

        Schema::dropIfExists('report_templates');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
