<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\InterimAssessment;
use App\Models\Organization;
use App\Models\RectificationReason;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Services\Assessment\CompareInterimToPeriodFinal;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ContinuousAssessment;
use App\Services\Assessment\MigrateClassProfile;
use App\Services\Assessment\OpenClassification;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Services\Assessment\RectifyClassification;
use App\Services\Reporting\Source\SchoolReportSource;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every reader of `classifications` sees only the rectified value (spec §8, §9).
 *
 * Carolina's period classification is published at level 4 by the real services
 * and then rectified, by the real service, to level 3 (and, in the chain test,
 * to 2). Each reader is asked what it says about her and must answer with the
 * NEW value, once: the replaced row is history, not a second classification.
 *
 * Pauta, Resultados and the exports are covered by RectifyClassificationTest
 * (`readers_see_only_the_new`); this file covers the other readers.
 */
class RectifiedValueReadersTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private Classification $carolina;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->organization = $this->ana->personalOrganization();
        $this->carolina = $this->asTenant(function (): Classification {
            $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $this->period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($this->class, $this->period);

            $classification = $this->carolinaRow();
            app(ConfirmClassification::class)->confirm($classification, $this->ana, $this->levelId('4'));
            app(PublishClassifications::class)->forPeriod($this->class, $this->period, ClassificationScope::Period);

            return $classification->fresh();
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor(Organization::query()->findOrFail($this->organization->id), $callback);
    }

    private function carolinaRow(): Classification
    {
        $row = Classification::query()
            ->where('academic_period_id', $this->period->id)
            ->where('scope', ClassificationScope::Period)
            ->get()
            ->first(fn (Classification $c) => $c->enrollment->student->identity->display_name === 'Carolina Nunes');

        $this->assertInstanceOf(Classification::class, $row);

        return $row;
    }

    private function levelId(string $code): int
    {
        return $this->class->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id;
    }

    /** Rectifies Carolina's live published row to level $code, and returns the new live row. */
    private function rectifyTo(string $code): Classification
    {
        return $this->asTenant(function () use ($code): Classification {
            $live = Classification::query()
                ->where('enrollment_id', $this->carolina->enrollment_id)
                ->where('academic_period_id', $this->period->id)
                ->where('scope', ClassificationScope::Period)
                ->whereNull('superseded_by_id')
                ->firstOrFail();

            return app(RectifyClassification::class)->rectify(
                $live,
                $this->ana,
                RectificationReason::EntryError,
                null,
                $this->levelId($code),
                null,
                $live->lock_version,
                false,
            );
        });
    }

    private function enrollment(): Enrollment
    {
        return Enrollment::query()->findOrFail($this->carolina->enrollment_id);
    }

    /** How many rows the cell holds in all, replaced ones included. */
    private function rowsInCell(): int
    {
        return DB::table('classifications')
            ->where('enrollment_id', $this->carolina->enrollment_id)
            ->where('academic_period_id', $this->period->id)
            ->where('scope', ClassificationScope::Period->value)
            ->count();
    }

    private function assertOneLiveRow(): void
    {
        $this->assertSame(1, DB::table('classifications')
            ->where('enrollment_id', $this->carolina->enrollment_id)
            ->where('academic_period_id', $this->period->id)
            ->where('scope', ClassificationScope::Period->value)
            ->whereNull('superseded_by_id')->count());
    }

    /** @return array<string, mixed> Carolina's row on the classifications page. */
    private function pageRow(): array
    {
        $response = $this->actingAs($this->ana)->get("/classes/{$this->class->ulid}/classifications/{$this->period->ulid}");
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');
        $rows = collect($page['props']['rows'])->where('name', 'Carolina Nunes');
        $this->assertCount(1, $rows, 'A linha da Carolina aparece uma só vez.');

        return $rows->first();
    }

    /** @return array<string, mixed> */
    private function schoolFacts(): array
    {
        $report = (new Report)->forceFill([
            'academic_year_id' => $this->class->academic_year_id,
            'academic_period_id' => null,
        ]);

        return $this->asTenant(fn (): array => app(SchoolReportSource::class)->factsFor($report));
    }

    /** @return array<string, mixed> Carolina's end-of-year decision as the continuous assessment reads it. */
    private function continuousDecision(): array
    {
        return $this->asTenant(fn (): array => app(ContinuousAssessment::class)->for(
            $this->class,
            collect([$this->period]),
            [$this->period->id => [$this->carolina->enrollment_id => '60.000']],
        )['students'][$this->carolina->enrollment_id]['decision']);
    }

    // ------------------------------------------------------------- the readers

    #[Test]
    public function the_continuous_assessment_reads_the_rectified_decision(): void
    {
        $this->rectifyTo('3');

        $decision = $this->continuousDecision();

        $this->assertSame($this->levelId('3'), $decision['final']['scale_level_id']);
        $this->assertSame('3.000', $decision['final_value']);
        $this->assertSame('published', $decision['status']);
    }

    #[Test]
    public function the_school_report_counts_the_rectified_level_once(): void
    {
        $before = $this->schoolFacts();
        $this->rectifyTo('3');
        $after = $this->schoolFacts();

        // Not counted twice: the same number of classifications and students.
        $this->assertSame($before['coverage']['classifications'], $after['coverage']['classifications']);
        $this->assertSame($before['coverage']['students_classified'], $after['coverage']['students_classified']);

        // Moved from level 4 to level 3 in the distribution, nothing else.
        $bands = fn (array $facts): array => collect($facts['groups'][0]['bands'])->pluck('count', 'code')->all();
        $this->assertSame(($bands($before)['4'] ?? 0) - 1, $bands($after)['4'] ?? 0);
        $this->assertSame(($bands($before)['3'] ?? 0) + 1, $bands($after)['3'] ?? 0);
        $this->assertSame($before['groups'][0]['classified'], $after['groups'][0]['classified']);
    }

    #[Test]
    public function the_dashboard_counts_the_cell_once_and_never_counts_the_replaced_row(): void
    {
        $this->rectifyTo('3');

        $proposed = DB::table('classifications')->where('status', ClassificationStatus::Proposed->value)->count();

        $this->actingAs($this->ana)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('classes.0.pending_confirmation', $proposed)
            // The rectified row is published, and the replaced one is no longer «published».
            ->where('classes.0.pending_publication', 0));

        $this->assertSame(1, DB::table('classifications')->where('status', ClassificationStatus::Superseded->value)->count());
    }

    #[Test]
    public function the_profile_migration_preview_shows_the_rectified_decision_as_kept_once(): void
    {
        $this->rectifyTo('3');

        $preview = $this->asTenant(function (): array {
            $class = SchoolClass::query()->findOrFail($this->class->id);

            return app(MigrateClassProfile::class)->preview($class, $class->profileVersion);
        });

        // One decided cell kept, and it reads the new level — the preview only reads.
        $this->assertSame(1, $preview['kept_result_count']);

        $cell = collect($preview['rows'])->firstWhere('name', 'Carolina Nunes')['cells'][0];
        $this->assertSame('kept', $cell['state']);
        $this->assertStringStartsWith('3', $cell['after_text']);
        $this->assertSame($cell['before_text'], $cell['after_text']);
        $this->assertSame('3.000', $cell['after']);

        $this->assertSame(2, $this->rowsInCell(), 'A pré-visualização não escreve nada.');
    }

    #[Test]
    public function opening_a_classification_returns_the_rectified_row_and_creates_none(): void
    {
        $new = $this->rectifyTo('3');

        $opened = $this->asTenant(fn (): Classification => app(OpenClassification::class)
            ->forDecision($this->class, $this->period, $this->enrollment()));

        $this->assertSame($new->id, $opened->id);
        $this->assertSame($this->levelId('3'), $opened->final_scale_level_id);
        $this->assertSame(2, $this->rowsInCell());
        $this->assertOneLiveRow();
    }

    #[Test]
    public function proposing_skips_the_rectified_row_as_frozen_and_does_not_touch_it(): void
    {
        $new = $this->rectifyTo('3');
        $replaced = DB::table('classifications')->where('id', $this->carolina->id)->first();
        $frozen = DB::table('classifications')->where('id', $new->id)->first();

        $counts = $this->asTenant(fn (): array => app(ProposeClassifications::class)->forPeriod($this->class, $this->period));

        $this->assertGreaterThanOrEqual(1, $counts['skipped_frozen']);
        $this->assertSame(2, $this->rowsInCell());
        $this->assertEquals($frozen, DB::table('classifications')->where('id', $new->id)->first());
        $this->assertEquals($replaced, DB::table('classifications')->where('id', $this->carolina->id)->first());
        $this->assertOneLiveRow();
    }

    #[Test]
    public function the_class_statistics_read_the_rectified_decision_once(): void
    {
        $this->rectifyTo('3');

        $students = $this->asTenant(fn (): array => app(BuildClassStatistics::class)->for($this->class, $this->period)['students']);
        $carolina = collect($students)->where('name', 'Carolina Nunes');

        $this->assertCount(1, $carolina);
        $this->assertSame('3.000', $carolina->first()['classification']['final_value']);
        $this->assertSame($this->levelId('3'), $carolina->first()['classification']['final']['scale_level_id']);
    }

    #[Test]
    public function the_student_progress_reads_the_rectified_decision_once(): void
    {
        $this->rectifyTo('3');

        $progress = $this->asTenant(fn (): array => app(BuildStudentProgress::class)->for($this->class, $this->enrollment()));
        $periodLine = collect($progress['classifications'])->where('period_id', $this->period->id);

        $this->assertCount(1, $periodLine);
        $this->assertTrue($periodLine->first()['is_decided']);
        $this->assertSame('3.000', $periodLine->first()['assigned_value']);
        $this->assertSame($this->levelId('3'), $periodLine->first()['assigned']['scale_level_id']);
    }

    #[Test]
    public function the_interim_comparison_reads_the_rectified_decision_on_the_final_side(): void
    {
        $interim = $this->asTenant(fn (): InterimAssessment => app(CaptureInterimAssessment::class)->capture(
            $this->class, $this->period, Carbon::parse('2026-12-01'), $this->ana, ['name' => 'Intercalar'],
        ));
        $this->rectifyTo('3');

        $comparison = $this->asTenant(fn (): array => app(CompareInterimToPeriodFinal::class)
            ->compare(SchoolClass::query()->findOrFail($this->class->id), $interim));

        $rows = collect($comparison['students'])->where('enrollment_id', $this->carolina->enrollment_id);

        $this->assertCount(1, $rows);
        $this->assertSame('3.000', $rows->first()['final_classification']['final_value']);
        $this->assertSame($this->levelId('3'), $rows->first()['final_classification']['final']['scale_level_id']);
    }

    #[Test]
    public function the_classifications_page_shows_the_new_value_and_the_count(): void
    {
        $new = $this->rectifyTo('3');

        $row = $this->pageRow();

        $this->assertSame($new->ulid, $row['classification']['ulid']);
        $this->assertSame('published', $row['classification']['status']);
        $this->assertSame('3', $row['classification']['decision']['code']);
        $this->assertSame(1, $row['classification']['rectification']['count']);
    }

    // ------------------------------------------------------------- consistency

    #[Test]
    public function after_a_chain_of_two_rectifications_every_reader_answers_with_the_last_value(): void
    {
        $this->rectifyTo('3');
        $last = $this->rectifyTo('2');
        $two = $this->levelId('2');

        $this->assertSame(3, $this->rowsInCell());
        $this->assertOneLiveRow();
        $this->assertSame(2, DB::table('classifications')->where('enrollment_id', $this->carolina->enrollment_id)
            ->where('status', ClassificationStatus::Superseded->value)->count());

        $row = $this->pageRow();
        $this->assertSame('2', $row['classification']['decision']['code']);
        $this->assertSame(2, $row['classification']['rectification']['count']);

        $this->assertSame($two, $this->continuousDecision()['final']['scale_level_id']);

        $this->asTenant(function () use ($last, $two): void {
            $this->assertSame($last->id, app(OpenClassification::class)->forDecision($this->class, $this->period, $this->enrollment())->id);

            $statistics = collect(app(BuildClassStatistics::class)->for($this->class, $this->period)['students'])->where('name', 'Carolina Nunes');
            $this->assertCount(1, $statistics);
            $this->assertSame($two, $statistics->first()['classification']['final']['scale_level_id']);

            $progress = collect(app(BuildStudentProgress::class)->for($this->class, $this->enrollment())['classifications'])
                ->where('period_id', $this->period->id);
            $this->assertCount(1, $progress);
            $this->assertSame($two, $progress->first()['assigned']['scale_level_id']);

            // Proposing leaves the cell at three rows.
            app(ProposeClassifications::class)->forPeriod($this->class, $this->period);
            $this->assertSame(3, $this->rowsInCell());
        });

        // School report: one decided row per student, the replaced ones excluded.
        $this->assertSame(
            DB::table('classifications')->whereIn('status', [ClassificationStatus::Published->value, ClassificationStatus::Confirmed->value])->count(),
            $this->schoolFacts()['coverage']['classifications'],
        );
    }
}
