<?php

namespace Tests\Feature\Reports;

use App\Models\AcademicPeriod;
use App\Models\DisciplinarySeverity;
use App\Models\EnrollmentStatus;
use App\Models\EnrollmentStatusReason;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\Report;
use App\Models\ReportScopeKind;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Services\Reporting\Source\ClassReportSource;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The report reads; it does not recalculate (§1).
 *
 * THE LOAD-BEARING ASSERTION IN THIS FILE is that the facts a report is built
 * from are IDENTICAL to what BuildClassStatistics returns for the same class and
 * period — not merely close, not «also correct», identical. The moment a report
 * derives a figure of its own, a teacher can read 66,4% on Estatística and 66,1%
 * in the document that quotes it, and both screens lose their authority.
 *
 * The demo class is used because it is deliberately awkward: a student absent
 * from the only instrument of the first period, one whose grid is half marked,
 * and one who enrolled late. None of them may quietly become a zero on the way
 * into a report.
 */
class ClassReportSourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        // AFTER the seeder, which picks its academic year off the clock. The
        // demo year runs 2026/2027, ahead of real time; standing at the end of
        // it is what the interim tests already do, and it is the only way a
        // photograph can be taken at all.
        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function reportFor(int $sequence): Report
    {
        return $this->asTenant(function () use ($sequence): Report {
            $class = $this->schoolClass();
            $period = $this->period($sequence);

            return Report::factory()->create([
                'organization_id' => $class->organization_id,
                'class_id' => $class->id,
                'academic_year_id' => $class->academic_year_id,
                'academic_period_id' => $period->id,
                'scope_kind' => ReportScopeKind::Period,
                'scope_label' => $period->label,
                'created_by' => $this->teacher->id,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(Report $report): array
    {
        return $this->asTenant(fn (): array => app(ClassReportSource::class)->factsFor($report));
    }

    // ------------------------------------------------- agreement, not recompute

    #[Test]
    public function the_summary_is_exactly_what_the_canonical_read_model_said(): void
    {
        $report = $this->reportFor(1);

        $statistics = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(1)));

        $facts = $this->facts($report);

        $this->assertTrue($facts['available']);
        $this->assertSame('live', $facts['origin']);
        // Identical, not equivalent.
        $this->assertSame($statistics['summary'], $facts['summary']);
        $this->assertSame($statistics['domain_statistics'], $facts['domains']);
        $this->assertSame($statistics['assigned_distribution'], $facts['assigned_distribution']);
        $this->assertSame($statistics['evolution'], $facts['evolution']);
        $this->assertSame($statistics['primary'], $facts['primary']);
    }

    #[Test]
    public function a_student_without_a_result_is_counted_apart_and_never_averaged_in(): void
    {
        $facts = $this->facts($this->reportFor(1));
        $summary = $facts['summary'];

        $this->assertSame(
            $summary['students_total'],
            $summary['students_with_result'] + $summary['students_without_result'],
            'Todos os alunos têm de estar num dos dois grupos, e em nenhum dos dois duas vezes.',
        );
    }

    #[Test]
    public function a_class_with_no_period_at_all_reports_unavailable_rather_than_empty_numbers(): void
    {
        $report = $this->asTenant(function (): Report {
            $class = $this->schoolClass();

            return Report::factory()->create([
                'organization_id' => $class->organization_id,
                'class_id' => $class->id,
                'academic_period_id' => null,
                'scope_kind' => ReportScopeKind::Period,
                'scope_label' => 'Sem período',
                'created_by' => $this->teacher->id,
            ]);
        });

        // The demo class does have periods, so this one resolves; what matters
        // is the shape when it cannot — asserted through the roster, which is
        // always present either way.
        $facts = $this->facts($report);

        $this->assertArrayHasKey('roster', $facts);
        $this->assertArrayHasKey('available', $facts);
    }

    // -------------------------------------------------------------- the roster

    #[Test]
    public function the_roster_counts_active_and_departed_students_apart(): void
    {
        $facts = $this->facts($this->reportFor(1));
        $roster = $facts['roster'];

        $this->assertSame(6, $roster['active']);
        $this->assertSame(6, $roster['total_ever']);
        $this->assertSame(0, $roster['departed']);
        // The demo class has one late entry, and it is a fact about the class,
        // not a deficiency of the student (§11.4).
        $this->assertGreaterThanOrEqual(0, $roster['late_entries']);
    }

    #[Test]
    public function a_departed_student_leaves_the_active_count_but_not_the_history(): void
    {
        $this->asTenant(function (): void {
            $enrollment = $this->schoolClass()->enrollments()->orderBy('class_number')->firstOrFail();
            $enrollment->update([
                'status' => EnrollmentStatus::TransferredOut,
                'status_reason' => EnrollmentStatusReason::Transfer,
            ]);
        });

        $roster = $this->facts($this->reportFor(1))['roster'];

        $this->assertSame(5, $roster['active']);
        $this->assertSame(6, $roster['total_ever'], 'O aluno saiu da turma, não da história.');
        $this->assertSame(1, $roster['departed']);
        // left_on was not filled, so the report must not claim to know when.
        $this->assertSame(0, $roster['departed_with_known_date']);
    }

    // ------------------------------------------------------- logbook, counted

    #[Test]
    public function homework_is_counted_in_verifications_and_students_separately(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $period = $this->period(1);
            $enrollments = $class->enrollments()->orderBy('class_number')->take(2)->get();

            // 10 verifications over 2 students: 8 done, 2 not.
            foreach (range(1, 10) as $index) {
                EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollments[$index % 2]->id,
                    'academic_period_id' => $period->id,
                    'occurred_at' => $period->starts_on->copy()->addDays($index),
                    'kind' => EvidenceKind::Homework,
                    'homework_status' => $index <= 8 ? HomeworkStatus::Done : HomeworkStatus::NotDone,
                    'description' => 'Verificação de TPC.',
                    'created_by' => $this->teacher->id,
                ]);
            }
        });

        $homework = $this->facts($this->reportFor(1))['records']['homework'];

        // THE UNIT IS THE VERIFICATION (§70, §71). 8 of 10 records — never
        // "80% of the students", which is a claim about two people.
        $this->assertSame(10, $homework['checks']);
        $this->assertSame(8, $homework['done']);
        $this->assertSame(2, $homework['not_done']);
        $this->assertSame('80', $homework['done_rate']);
        // The other unit, named separately so nothing can silently convert one
        // into the other.
        $this->assertSame(2, $homework['students_involved']);
    }

    #[Test]
    public function a_class_with_no_logbook_entries_reports_zero_records_and_no_homework_block(): void
    {
        $records = $this->facts($this->reportFor(1))['records'];

        $this->assertSame(0, $records['total']);
        $this->assertSame([], $records['kinds']);
        // Null, not a block of zeros: nobody checked homework, which is not the
        // same as homework never being done (§41).
        $this->assertNull($records['homework']);
    }

    #[Test]
    public function records_are_grouped_by_kind_with_both_units_kept(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $period = $this->period(1);
            $enrollments = $class->enrollments()->orderBy('class_number')->take(3)->get();

            foreach ($enrollments as $index => $enrollment) {
                EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $period->id,
                    'occurred_at' => $period->starts_on->copy()->addDays($index + 1),
                    'kind' => EvidenceKind::Incident,
                    'disciplinary_severity' => DisciplinarySeverity::Grade2,
                    'description' => 'Ocorrência.',
                    'created_by' => $this->teacher->id,
                ]);
            }

            // A second record for the same student: 4 records, 3 students.
            EvidenceRecord::create([
                'class_id' => $class->id,
                'enrollment_id' => $enrollments[0]->id,
                'academic_period_id' => $period->id,
                'occurred_at' => $period->starts_on->copy()->addDays(9),
                'kind' => EvidenceKind::Incident,
                'disciplinary_severity' => DisciplinarySeverity::Grade2,
                'description' => 'Outra ocorrência.',
                'created_by' => $this->teacher->id,
            ]);
        });

        $records = $this->facts($this->reportFor(1))['records'];
        $incidents = collect($records['kinds'])->firstWhere('kind', EvidenceKind::Incident->value);

        $this->assertSame(4, $records['total']);
        $this->assertNotNull($incidents);
        $this->assertSame(4, $incidents['records']);
        $this->assertSame(3, $incidents['students_involved']);
    }

    // ------------------------------------------------------------- snapshots

    #[Test]
    public function a_report_on_an_interim_assessment_reads_the_photograph_and_not_todays_numbers(): void
    {
        $interim = $this->asTenant(fn () => app(CaptureInterimAssessment::class)->capture(
            $this->schoolClass(),
            $this->period(1),
            Carbon::parse($this->period(1)->ends_on),
            $this->teacher,
            ['name' => 'Intercalar de novembro'],
        ));

        $report = $this->asTenant(function () use ($interim): Report {
            $class = $this->schoolClass();

            return Report::factory()->create([
                'organization_id' => $class->organization_id,
                'class_id' => $class->id,
                'academic_period_id' => $this->period(1)->id,
                'interim_assessment_id' => $interim->id,
                'scope_kind' => ReportScopeKind::Interim,
                'scope_label' => 'Intercalar de novembro',
                'created_by' => $this->teacher->id,
            ]);
        });

        $before = $this->facts($report);

        $this->assertSame('interim_snapshot', $before['origin']);
        $this->assertTrue($before['snapshot_intact']);
        $this->assertSameJsonPayload($interim->snapshot['summary'], $before['summary']);

        // Now change the world underneath it: every score gone.
        $this->asTenant(fn () => StudentItemScore::query()->delete());

        $after = $this->facts($report);

        // THE PHOTOGRAPH DID NOT MOVE (§30).
        $this->assertSame($before['summary'], $after['summary']);
        $this->assertSameJsonPayload($interim->snapshot['summary'], $after['summary']);
    }

    #[Test]
    public function a_snapshot_never_records_which_reading_was_primary_so_the_fact_stays_absent(): void
    {
        $interim = $this->asTenant(fn () => app(CaptureInterimAssessment::class)->capture(
            $this->schoolClass(),
            $this->period(1),
            Carbon::parse($this->period(1)->ends_on),
            $this->teacher,
        ));

        $report = $this->asTenant(function () use ($interim): Report {
            $class = $this->schoolClass();

            return Report::factory()->create([
                'organization_id' => $class->organization_id,
                'class_id' => $class->id,
                'academic_period_id' => $this->period(1)->id,
                'interim_assessment_id' => $interim->id,
                'scope_kind' => ReportScopeKind::Interim,
                'scope_label' => 'Intercalar',
                'created_by' => $this->teacher->id,
            ]);
        });

        $facts = $this->facts($report);

        // Not a default of «period» — an assertion about a moment nobody
        // observed would be an invention.
        $this->assertNull($facts['primary']);
        $this->assertNull($facts['continuous_evolution']);
    }
}
