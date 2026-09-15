<?php

namespace Tests\Feature\Lessons;

use App\Models\LessonStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.146.0 — a migração do resultado real: backfill `taught` → `taught`, nada
 * mais inferido, e reversível.
 */
class LessonOutcomeMigrationTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    #[Test]
    public function the_migration_backfills_only_taught_lessons_and_rolls_back(): void
    {
        $this->bootLessonFixtures();
        $taught = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'status' => LessonStatus::Taught]);
        $prepared = $this->makeLesson(['starts_at' => '2026-10-02 09:30:00', 'status' => LessonStatus::Prepared]);

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_10_000700_add_outcome_to_lessons_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('lessons', 'outcome'));
        $this->assertFalse(Schema::hasColumn('lessons', 'outcome_recorded_by'));

        $migration->up();
        $this->assertSame('taught', DB::table('lessons')->where('id', $taught->id)->value('outcome'));
        $this->assertNull(DB::table('lessons')->where('id', $prepared->id)->value('outcome'));
    }
}
