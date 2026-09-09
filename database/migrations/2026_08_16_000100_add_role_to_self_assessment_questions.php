<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a self-assessment question a stated purpose.
 *
 * Additive and nullable, and deliberately WITHOUT a backfill.
 *
 * Every question that exists today was created by SelfAssessmentTemplateProvider
 * as a per-domain scale question, and those are identified by their `domain_id`
 * exactly as they always were — they neither need a role nor get one. There is
 * therefore no existing question whose purpose could be inferred, and inferring
 * one from its wording or its position would be guessing about answers students
 * have already given.
 *
 * Null means «identified some other way», which for every row in the table
 * right now is true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('self_assessment_questions', function (Blueprint $table): void {
            $table->string('role', 24)->nullable()->after('domain_id');

            // A template may carry each role once. Two «global» questions would
            // be two overall judgements, and nothing could say which one the
            // results screen meant.
            $table->unique(['self_assessment_template_id', 'role'], 'saq_template_role_unique');
        });
    }

    /**
     * The key comes off first, and that is not tidiness.
     *
     * `self_assessment_template_id` is the leftmost column of the unique index
     * added above, so InnoDB adopts that index to support the column's foreign
     * key and then refuses to drop it — «Cannot drop index
     * 'saq_template_role_unique': needed in a foreign key constraint» (MySQL
     * 1553) — even though the index the key created for itself is still there.
     * Dropping the key first breaks that hold; the `finally` puts it back with
     * the definition it was created with (`->constrained()->cascadeOnDelete()`),
     * so a failure in between never leaves the table without its key.
     *
     * `dropForeign` names it by COLUMN, never by name: that is the only form
     * SQLite accepts, and it folds into the table rebuild SQLite does anyway.
     */
    public function down(): void
    {
        Schema::table('self_assessment_questions', function (Blueprint $table): void {
            $table->dropForeign(['self_assessment_template_id']);
        });

        try {
            Schema::table('self_assessment_questions', function (Blueprint $table): void {
                $table->dropUnique('saq_template_role_unique');
                $table->dropColumn('role');
            });
        } finally {
            Schema::table('self_assessment_questions', function (Blueprint $table): void {
                $table->foreign('self_assessment_template_id')
                    ->references('id')
                    ->on('self_assessment_templates')
                    ->cascadeOnDelete();
            });
        }
    }
};
