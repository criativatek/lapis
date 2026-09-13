<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the same authorship correction 2026_09_22_000100 already made to
 * the pedagogical-follow-up tier (interim assessments, evidence records,
 * interventions/reviews, reports) to the lesson/attendance tier a backup can
 * now restore: `lessons.created_by`, `lesson_plans.created_by` and
 * `cancelled_lesson_occurrences.cancelled_by`.
 *
 * Same reasoning, not repeated in full here — see that migration's docblock.
 * `NULL` means exactly one thing: the original author could not be mapped to
 * an account in THIS installation, and this system refused to guess. It is
 * never written by the app's own creation paths (`MarkLessonAsTaught`,
 * `SaveLessonSequence`, lesson deletion) — every one of them still stamps the
 * authenticated user, and none of them became optional.
 *
 * `lesson_summaries.reviewed_by`, `lessons.attendance_recorded_by` and
 * `lesson_attendances.updated_by` are already nullable — they record an
 * optional review/consolidation step, not a mandatory author, so nothing
 * changes here for them.
 */
return new class extends Migration
{
    /**
     * Table => author column, same `foreignId … ON DELETE RESTRICT` shape as
     * 2026_09_22_000100.
     *
     * @var array<string, string>
     */
    private const AUTHOR_COLUMNS = [
        'lessons' => 'created_by',
        'lesson_plans' => 'created_by',
        'cancelled_lesson_occurrences' => 'cancelled_by',
    ];

    public function up(): void
    {
        foreach (self::AUTHOR_COLUMNS as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->foreignId($column)->nullable()->change();
            });
        }
    }

    /**
     * Same all-or-nothing three-phase shape as 2026_09_22_000100::down() —
     * see that migration for the full MySQL `1832` rationale.
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
