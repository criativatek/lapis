<?php

namespace Tests\Feature\Assessment;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The confirmations page reads `finalScaleLevel` once per row
 * (`decisionPayload()`), and the query it built did not eager-load that
 * relation — one extra `select … from scale_levels` per confirmed
 * enrollment. Other scale_levels queries on the same page (the proposal
 * band lookup, the decision options) are constant regardless of how many
 * students are confirmed, so the fixed cost isolates the leak: confirming
 * five more students must not add five more queries.
 */
class ClassificationShowEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: string, 2: int} class ulid, period ulid, confirmed count */
    private function seedWithConfirmed(User $teacher, int $confirmedCount): array
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher, $confirmedCount) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($class, $period);

            $classifications = Classification::query()
                ->where('academic_period_id', $period->id)
                ->where('scope', ClassificationScope::Period)
                ->orderBy('id')
                ->get();

            foreach ($classifications->take($confirmedCount) as $classification) {
                app(ConfirmClassification::class)->confirm($classification, $teacher);
            }

            return [$class->ulid, $period->ulid, $classifications->count()];
        });
    }

    private function scaleLevelQueryCount(User $teacher, string $classUlid, string $periodUlid): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($teacher)
            ->get("/classes/{$classUlid}/classifications/{$periodUlid}")
            ->assertOk();

        $count = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'], 'scale_levels'))
            ->count();

        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function confirming_every_student_does_not_add_a_query_per_row(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        [$classUlid, $periodUlid, $total] = $this->seedWithConfirmed($teacher, 1);
        $withOneConfirmed = $this->scaleLevelQueryCount($teacher, $classUlid, $periodUlid);

        // Confirm the rest of the class (§ DemoDataSeeder: six enrollments).
        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher, $classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            $remaining = Classification::query()
                ->where('academic_period_id', $period->id)
                ->where('scope', ClassificationScope::Period)
                ->where('status', '!=', ClassificationStatus::Confirmed)
                ->get();

            foreach ($remaining as $classification) {
                app(ConfirmClassification::class)->confirm($classification, $teacher);
            }
        });

        $withAllConfirmed = $this->scaleLevelQueryCount($teacher, $classUlid, $periodUlid);

        $this->assertGreaterThan(1, $total, 'fixture assumption: the demo class has more than one enrollment');

        // Without eager-loading finalScaleLevel, each additionally confirmed
        // student adds one more query here — this proves the count stays flat.
        $this->assertSame(
            $withOneConfirmed,
            $withAllConfirmed,
            "expected finalScaleLevel to be eager-loaded: {$withOneConfirmed} scale_levels queries with 1 confirmed student, {$withAllConfirmed} with {$total}"
        );
    }
}
