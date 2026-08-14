<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives an instrument a structure of its own: groups (Grupo I, II, III), which
 * are how a test is physically laid out, as distinct from domains, which are
 * what each question assesses.
 *
 * The two were being conflated because only domains existed: the form showed
 * sections per domain, so a teacher reading "Oralidade" and "Gramática" as
 * groups produced two Q2 in one instrument and hit UNIQUE(instrument_id, code)
 * as a 500. A question belongs to exactly one group and may still split across
 * several domains — that is why a group cannot be a domain.
 *
 * The unique key moves accordingly, from (instrument_id, code) to
 * (instrument_group_id, code): Oralidade/Q2 and Gramática/Q2 become legal,
 * while two Q2 in the same group stay refused.
 *
 * Additive and ordered so no window exists without uniqueness protection: the
 * new key is in place before the old one is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Phase 1: the groups themselves.
        Schema::create('instrument_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // An instrument's groups die with it, exactly as its items already do.
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            // NULL is the implicit group: a simple instrument has one and the
            // teacher never sees it. A named group is one they created.
            $table->string('label', 120)->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->timestamps();

            // Labels are deliberately NOT unique — two groups may legitimately
            // share a name, because identity is the id, never the text.
            $table->unique(['instrument_id', 'sequence'], 'instrument_groups_instrument_sequence_unique');
            $table->index(['organization_id', 'instrument_id'], 'instrument_groups_org_instrument_idx');
        });

        // ---- Phase 2: the item's link to its group, nullable for the backfill.
        Schema::table('instrument_items', function (Blueprint $table) {
            $table->foreignId('instrument_group_id')
                ->nullable()
                ->after('instrument_id')
                ->constrained('instrument_groups')
                // An item is never silently destroyed because its group was
                // deleted: the group must be emptied first (§21).
                ->restrictOnDelete();
        });

        // ---- Phase 3: one implicit group per existing instrument.
        //
        // No inference: groups are NOT guessed from domain, title, sequence,
        // source_group_label or code. Every existing instrument gets exactly one
        // unnamed group holding all of its items, which preserves today's
        // semantics precisely. Instruments with no items get one too, so any
        // item added later already has somewhere to live.
        DB::table('instruments')->orderBy('id')->chunkById(200, function ($instruments): void {
            foreach ($instruments as $instrument) {
                $groupId = DB::table('instrument_groups')->insertGetId([
                    'ulid' => (string) Str::ulid(),
                    'organization_id' => $instrument->organization_id,
                    'instrument_id' => $instrument->id,
                    'label' => null,
                    'sequence' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Only the foreign key is written. id, ulid, code, sequence,
                // points, labels and source_group_label are untouched, and no
                // row is created or deleted — so scores and allocations, which
                // hang off instrument_items.id, cannot be affected.
                DB::table('instrument_items')
                    ->where('instrument_id', $instrument->id)
                    ->update(['instrument_group_id' => $groupId]);
            }
        });

        // ---- Phase 4: refuse to continue if a single item was left behind.
        $orphans = DB::table('instrument_items')->whereNull('instrument_group_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Backfill incompleto: {$orphans} questões ficaram sem grupo. A migração foi interrompida antes de tornar a coluna obrigatória."
            );
        }

        // ---- Phase 5: now it can be required.
        Schema::table('instrument_items', function (Blueprint $table) {
            $table->foreignId('instrument_group_id')->nullable(false)->change();
        });

        // ---- Phase 6: the new key, BEFORE the old one goes.
        Schema::table('instrument_items', function (Blueprint $table) {
            $table->unique(['instrument_group_id', 'code'], 'instrument_items_group_code_unique');
        });

        // ---- Phase 7: and only now the old one.
        //
        // MySQL will not drop it while it is the only index backing the
        // instrument_id foreign key — (instrument_id, code) doubles as that
        // key's supporting index. So a plain index on instrument_id is created
        // first, taking over that role, and only then can the unique go.
        // (SQLite has no such requirement, which is why this only shows up
        // against the real database.)
        Schema::table('instrument_items', function (Blueprint $table) {
            $table->index('instrument_id', 'instrument_items_instrument_id_idx');
        });

        Schema::table('instrument_items', function (Blueprint $table) {
            $table->dropUnique('instrument_items_instrument_id_code_unique');
        });
    }

    public function down(): void
    {
        // Reversible only while no instrument actually uses groups: restoring
        // UNIQUE(instrument_id, code) over an instrument holding Oralidade/Q2
        // and Gramática/Q2 would have to destroy one of them. Refuse instead of
        // choosing which question to lose.
        $conflicting = DB::table('instrument_items')
            ->select('instrument_id', 'code')
            ->groupBy('instrument_id', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($conflicting > 0) {
            throw new RuntimeException(
                "Não é possível reverter: existem {$conflicting} códigos de questão repetidos entre grupos do mesmo instrumento. Reverter exigiria apagar questões."
            );
        }

        Schema::table('instrument_items', function (Blueprint $table) {
            $table->unique(['instrument_id', 'code'], 'instrument_items_instrument_id_code_unique');
            $table->dropUnique('instrument_items_group_code_unique');
            $table->dropConstrainedForeignId('instrument_group_id');
        });

        // The composite unique backs the foreign key again, so the standalone
        // index added on the way up is no longer needed.
        Schema::table('instrument_items', function (Blueprint $table) {
            $table->dropIndex('instrument_items_instrument_id_idx');
        });

        Schema::dropIfExists('instrument_groups');
    }
};
