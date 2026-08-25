<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which turmas an acontecimento is about — zero, one, or many.
 *
 * RELATIONAL AND NOT JSON, deliberately. A list of turma ids stored in a column
 * cannot be joined, cannot be constrained, and goes quietly stale the moment a
 * turma is removed; this project is relational throughout, and a pivot is the
 * only shape here that a foreign key can actually defend.
 *
 * `cascadeOnDelete` on BOTH sides, because a pivot row is meaningless without
 * either half of it: deleting the acontecimento takes its attachments with it,
 * and so does deleting the turma. Note what this does NOT do — deleting an
 * acontecimento removes rows in THIS table and nowhere else. The turma itself,
 * its avaliações, a sua estrutura e o seu horário keep their own lifecycles.
 *
 * No surrogate key: the pair IS the identity, and the primary key over it is
 * also the index the «which turmas does this event have» read uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_event_school_class', function (Blueprint $table): void {
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            // The turmas table is `classes` (SchoolClass), so the constraint has
            // to be named rather than derived from the column.
            $table->foreignId('school_class_id')->constrained('classes')->cascadeOnDelete();

            $table->primary(['calendar_event_id', 'school_class_id'], 'calendar_event_school_class_pk');
            $table->index('school_class_id', 'calendar_event_school_class_class_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_school_class');
    }
};
