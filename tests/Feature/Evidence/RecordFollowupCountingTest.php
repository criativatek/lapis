<?php

namespace Tests\Feature\Evidence;

use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\RecordFollowupCategory;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Evidence\Followup\AssessmentPeriodTimeline;
use App\Services\Evidence\Followup\FollowupWindow;
use App\Services\Evidence\Followup\RecordFollowupCounter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Evidence\Concerns\BuildsFollowupFixtures;
use Tests\TestCase;

/**
 * THE count (RecordFollowupCounter): what an occurrence is, and what never
 * contaminates it — other kinds, other students, other classes, other
 * periods, other organizations, deleted rows, whole-class rows.
 */
class RecordFollowupCountingTest extends TestCase
{
    use BuildsFollowupFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFollowupFixtures();
    }

    private function window(): FollowupWindow
    {
        return $this->inTenant($this->organization, fn (): FollowupWindow => FollowupWindow::forPeriod(
            $this->firstPeriod,
            AssessmentPeriodTimeline::forYear($this->schoolClass->academic_year_id),
        ));
    }

    private function occurrencesOf(int $enrollmentId, RecordFollowupCategory $category, ?SchoolClass $class = null, ?FollowupWindow $window = null): int
    {
        return $this->inTenant($this->organization, fn (): int => app(RecordFollowupCounter::class)
            ->countFor(($class ?? $this->schoolClass)->id, $enrollmentId, $category, $window ?? $this->window()));
    }

    #[Test]
    public function only_homework_explicitly_not_done_counts_never_partial_done_or_unchecked(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Homework, '2026-10-01', HomeworkStatus::NotDone);
        $this->record($ana, EvidenceKind::Homework, '2026-10-02', HomeworkStatus::PartiallyDone);
        $this->record($ana, EvidenceKind::Homework, '2026-10-03', HomeworkStatus::Done);
        // 2026-10-04: never checked — no row at all.

        $this->assertSame(1, $this->occurrencesOf($ana->id, RecordFollowupCategory::HomeworkNotDone));
    }

    #[Test]
    public function each_category_counts_on_its_own_and_they_never_add_up(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::MissingMaterial, '2026-10-02');
        $this->record($ana, EvidenceKind::Homework, '2026-10-03');
        $this->record($ana, EvidenceKind::Incident, '2026-10-04');

        $this->assertSame(1, $this->occurrencesOf($ana->id, RecordFollowupCategory::Lateness));
        $this->assertSame(1, $this->occurrencesOf($ana->id, RecordFollowupCategory::MissingMaterial));
        $this->assertSame(1, $this->occurrencesOf($ana->id, RecordFollowupCategory::HomeworkNotDone));
    }

    #[Test]
    public function two_legitimate_records_on_the_same_day_are_two_occurrences(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-05 08:15:00');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-05 13:30:00');

        $this->assertSame(2, $this->occurrencesOf($ana->id, RecordFollowupCategory::Lateness));
    }

    #[Test]
    public function the_pedagogical_date_decides_the_period_not_when_it_was_typed_in(): void
    {
        $ana = $this->enroll('Ana Costa');
        // All typed today (12/10/2026): the last day of the first period, with
        // a time; the first day of the second; and a day in the holiday gap.
        $this->record($ana, EvidenceKind::Lateness, '2026-12-16 16:40:00');
        $this->record($ana, EvidenceKind::Lateness, '2027-01-04');
        $this->record($ana, EvidenceKind::Lateness, '2026-12-18');

        $second = $this->inTenant($this->organization, fn (): FollowupWindow => FollowupWindow::forPeriod(
            $this->secondPeriod,
            AssessmentPeriodTimeline::forYear($this->schoolClass->academic_year_id),
        ));

        $this->assertSame(1, $this->occurrencesOf($ana->id, RecordFollowupCategory::Lateness));
        $this->assertSame(1, $this->occurrencesOf($ana->id, RecordFollowupCategory::Lateness, window: $second));
    }

    #[Test]
    public function deleted_whole_class_other_student_class_and_organization_rows_never_count(): void
    {
        $ana = $this->enroll('Ana Costa');
        $rui = $this->enroll('Rui Sousa');
        $otherClass = $this->secondClass();
        $anaElsewhere = $this->enroll('Ana Costa', [], $otherClass);

        $deleted = $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->inTenant($this->organization, fn () => $deleted->delete());
        $this->inTenant($this->organization, fn () => EvidenceRecord::create([
            'class_id' => $this->schoolClass->id, 'enrollment_id' => null, 'occurred_at' => '2026-10-02',
            'kind' => 'lateness', 'description' => 'Turma toda atrasada.', 'created_by' => $this->teacher->id,
        ]));
        $this->record($rui, EvidenceKind::Lateness, '2026-10-03');
        $this->record($anaElsewhere, EvidenceKind::Lateness, '2026-10-04', class: $otherClass);

        // Another school, same dates.
        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->inTenant($strangerOrganization, function () use ($strangerOrganization, $stranger): void {
            $class = SchoolClass::factory()->recycle($strangerOrganization)->create();
            $class->teachers()->attach($stranger, ['role' => 'owner']);
        });

        $this->assertSame(0, $this->occurrencesOf($ana->id, RecordFollowupCategory::Lateness));
        $this->assertSame(1, $this->occurrencesOf($rui->id, RecordFollowupCategory::Lateness));
        $this->assertSame(1, $this->occurrencesOf($anaElsewhere->id, RecordFollowupCategory::Lateness, $otherClass));
    }

    /**
     * «Alunos» counts what the per-student list shows — one line per student
     * per class — so the tile and the list never disagree.
     */
    #[Test]
    public function the_summary_counts_students_and_every_occurrence_without_multiplying(): void
    {
        $ana = $this->enroll('Ana Costa');
        $rui = $this->enroll('Rui Sousa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-08');
        $this->record($rui, EvidenceKind::Lateness, '2026-10-02');
        $this->record($rui, EvidenceKind::MissingMaterial, '2026-10-02');

        // Groups exist and must not multiply anything.
        $group = $this->makeGroup('T1');
        $this->addToGroup($ana, $group);
        $this->addToGroup($rui, $group);

        $summary = $this->inTenant($this->organization, fn (): array => app(RecordFollowupCounter::class)
            ->summary([$this->schoolClass->id], $this->window()));

        $this->assertSame(['students' => 2, 'occurrences' => 4], $summary['lateness']);
        $this->assertSame(['students' => 1, 'occurrences' => 1], $summary['missing_material']);
        $this->assertSame(['students' => 0, 'occurrences' => 0], $summary['homework_not_done']);
    }

    #[Test]
    public function a_custom_range_includes_both_whole_days(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01 00:00:00');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-31 23:30:00');
        $this->record($ana, EvidenceKind::Lateness, '2026-11-01 00:00:00');

        $range = FollowupWindow::forRange(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $this->assertSame(2, $this->occurrencesOf($ana->id, RecordFollowupCategory::Lateness, window: $range));
    }
}
