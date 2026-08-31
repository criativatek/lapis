<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Makes historical authorship a fact a row can be MISSING, instead of a
 * condition a row has to satisfy in order to exist.
 *
 * WHY. Five author columns were written `NOT NULL` on the assumption that
 * whoever writes a pedagogical record is always a live account in the same
 * installation. That holds while a record is CREATED in the app — every
 * creation path stamps the authenticated user and nothing here changes
 * that. It stops holding the moment a record is RESTORED from a backup:
 * a teacher changes email, a class changes teacher, a school transfers
 * responsibility, data is restored into a different authorised account.
 * In all four the record is genuine and the original author simply cannot
 * be mapped to an account in this installation. The importer's only
 * options were to invent an author or to refuse the row, and it refused —
 * which made an entire category of legitimate restore impossible
 * (docs/backup-schema.md, "Autoria").
 *
 * So `NULL` here means exactly one thing: **the original author could not
 * be mapped, and this system refused to guess**. It is never written by
 * the app's own creation paths — `EvidenceController`, `InterventionController`,
 * `CaptureInterimAssessment`, `CreateReport` and `DeriveReport` all stamp
 * the authenticated user, and none of them became optional. A `NULL` in
 * one of these columns is therefore an import provenance statement, not a
 * missing value someone forgot to fill.
 *
 * WHAT THIS DELIBERATELY DOES NOT ADD. No column, and no table, copies the
 * original author's email into the destination organization. The safe
 * historical reference already exists and already has a retention policy
 * attached: `data_imports.canonical_snapshot` keeps what the backup said,
 * including every `*_by_email`, and `PruneDataImports` governs how long.
 * Duplicating a colleague's identifier onto rows in an organization that
 * has no relationship with them would outlive that policy and widen who
 * can read it — the opposite of what §22.4 asks for.
 *
 * READS ALREADY FAIL CLOSED on a null author, which is why no policy
 * changes with this migration: `ReportPolicy::authored()` compares
 * `(int) null === (int) $user->id` and is false for every real id;
 * `ReportListing::visibleTo()` matches `created_by = <id>` and simply does
 * not match; `RecordsReportSource::query()` narrows by
 * `whereKey($report->created_by)` and narrows to nothing. An imported
 * report with no resolved author is reachable through the class the
 * importer teaches, never through an authorship claim nobody made.
 */
return new class extends Migration
{
    /**
     * Table => author column. Every one of these is a `foreignId` onto
     * `users` with `ON DELETE RESTRICT`, created that way and restored that
     * way — see {@see restoreNotNull()}.
     *
     * @var array<string, string>
     */
    private const AUTHOR_COLUMNS = [
        'interim_assessments' => 'created_by',
        'evidence_records' => 'created_by',
        'interventions' => 'created_by',
        'intervention_reviews' => 'reviewed_by',
        'reports' => 'created_by',
    ];

    /**
     * Widening only. MySQL accepts `NOT NULL` → `NULL` on a column that
     * carries a foreign key without touching the key, so nothing is dropped
     * here and every FK survives verbatim.
     */
    public function up(): void
    {
        foreach (self::AUTHOR_COLUMNS as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->foreignId($column)->nullable()->change();
            });
        }
    }

    /**
     * ALL OR NOTHING, and it took a real MySQL to learn why.
     *
     * The obvious `down()` — five `nullable(false)->change()` calls — passes
     * on SQLite, because SQLite rebuilds the whole table, and FAILS on
     * MySQL with `1832: Cannot change column 'created_by': used in a
     * foreign key constraint`. Narrowing a column back is refused while its
     * key is attached, even though widening it was allowed. It failed on
     * the fifth table, leaving four columns reverted, one not, and the
     * `migrations` row still saying the migration was applied. A rollback
     * that half-happens is worse than one that refuses.
     *
     * So this runs in three phases, and the first two mutate nothing:
     *
     *   1. DATA GUARD — if any column that is about to become `NOT NULL`
     *      still holds `NULL`, refuse before touching a single table and
     *      name the tables. The rows stay; §31 does not trade historical
     *      data for a schema change.
     *   2. SCHEMA PRE-FLIGHT — every table/column must actually exist and
     *      actually be nullable. A partially-reverted schema (the exact
     *      state the old `down()` could leave behind) is reported instead
     *      of being half-corrected again.
     *   3. APPLY — per table: drop the key, narrow the column, put the key
     *      back exactly as it was. If any table fails, every table already
     *      done in this run is widened again and the failure is rethrown,
     *      so the schema lands back on the post-`up()` state rather than
     *      somewhere in between.
     */
    public function down(): void
    {
        $this->refuseIfAnyAuthorIsUnresolved();
        $this->refuseIfSchemaIsNotAsExpected();

        /** @var array<string, string> $reverted */
        $reverted = [];

        try {
            foreach (self::AUTHOR_COLUMNS as $table => $column) {
                $this->restoreNotNull($table, $column);
                $reverted[$table] = $column;
            }
        } catch (Throwable $exception) {
            foreach ($reverted as $table => $column) {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->foreignId($column)->nullable()->change();
                });
            }

            Log::error('migration.rollback_reverted_partial_work', [
                'migration' => self::class,
                'undone' => array_keys($reverted),
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Phase 1. Nothing is mutated: this only reads.
     */
    private function refuseIfAnyAuthorIsUnresolved(): void
    {
        $blocking = [];

        foreach (self::AUTHOR_COLUMNS as $table => $column) {
            $count = DB::table($table)->whereNull($column)->count();

            if ($count > 0) {
                $blocking[] = "{$table}.{$column} ({$count})";
            }
        }

        if ($blocking === []) {
            return;
        }

        $message = 'Não é possível reverter: existem registos importados sem autoria resolvida em '
            .implode(', ', $blocking)
            .'. Resolva a autoria desses registos antes de reverter — nenhum é apagado por esta migração.';

        Log::error('migration.rollback_blocked', ['migration' => self::class, 'blocking' => $blocking]);

        throw new RuntimeException($message);
    }

    /**
     * Phase 2. Also read-only. Catches the one state the broken `down()`
     * could leave behind — some columns already `NOT NULL`, others not —
     * rather than silently "fixing" half of it a second time.
     */
    private function refuseIfSchemaIsNotAsExpected(): void
    {
        $problems = [];

        foreach (self::AUTHOR_COLUMNS as $table => $column) {
            if (! Schema::hasTable($table)) {
                $problems[] = "{$table} (tabela inexistente)";

                continue;
            }

            if (! Schema::hasColumn($table, $column)) {
                $problems[] = "{$table}.{$column} (coluna inexistente)";

                continue;
            }

            if (! $this->isNullable($table, $column)) {
                $problems[] = "{$table}.{$column} (já é NOT NULL)";
            }
        }

        if ($problems === []) {
            return;
        }

        $message = 'Não é possível reverter: o esquema não está no estado que esta migração deixou — '
            .implode(', ', $problems)
            .'. Verifique se uma reversão anterior ficou a meio antes de tentar de novo.';

        Log::error('migration.rollback_schema_unexpected', ['migration' => self::class, 'problems' => $problems]);

        throw new RuntimeException($message);
    }

    /**
     * Phase 3, one table. The key comes off, the column narrows, the key
     * goes back with the definition it was created with
     * (`->constrained('users')->restrictOnDelete()`), which produces the
     * same `{table}_{column}_foreign` name and the same `ON DELETE
     * RESTRICT` — the `finally` is what guarantees a failure never leaves a
     * table without its key.
     *
     * SQLite reaches the same place by a different road: it has no
     * `ALTER … DROP CONSTRAINT`, so `dropForeign` by COLUMN (never by name,
     * which it refuses outright) is folded into the table rebuild it does
     * anyway.
     */
    private function restoreNotNull(string $table, string $column): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropForeign([$column]);
        });

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->foreignId($column)->nullable(false)->change();
            });
        } finally {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->foreign($column)->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    private function isNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return (bool) $definition['nullable'];
            }
        }

        return false;
    }
};
