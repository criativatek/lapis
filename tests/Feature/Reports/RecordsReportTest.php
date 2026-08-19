<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\SectionKey;
use App\Models\AcademicPeriod;
use App\Models\DisciplinarySeverity;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Reporting\CreateReport;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reports by logbook entry (§21–§23, §70, §71).
 *
 * THE UNIT IS THE WHOLE POINT OF THIS FILE. «10 verificações de TPC, realizadas
 * em 8 registos» is a statement about verifications. «20% dos alunos não
 * trabalham» is a statement about two children, is not in the data, and is the
 * single most likely thing a report generator would say by accident. The tests
 * below make it impossible.
 */
class RecordsReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();

        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));

        $this->seed(EntitlementsSeeder::class);

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'base')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', 1)->firstOrFail());
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function report(array $options = [], bool $detailed = false, bool $names = false): Report
    {
        return $this->asTenant(fn (): Report => app(CreateReport::class)->forRecords(
            year: $this->schoolClass()->academicYear,
            author: $this->teacher,
            class: $this->schoolClass(),
            period: $this->period(),
            options: ['detailed' => $detailed, 'name_students' => $names, ...$options],
            sectionKeys: $detailed
                ? ['records_scope', 'records_summary', 'records_distribution', 'records_timeline']
                : null,
        ));
    }

    private function bodyOf(Report $report, SectionKey $key): ?string
    {
        return $this->asTenant(fn (): ?string => $report->sections()->where('key', $key->value)->first()?->body);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dataOf(Report $report, SectionKey $key): ?array
    {
        return $this->asTenant(fn (): ?array => $report->sections()->where('key', $key->value)->first()?->data);
    }

    private function homework(int $done, int $notDone, int $students = 2): void
    {
        $this->asTenant(function () use ($done, $notDone, $students): void {
            $class = $this->schoolClass();
            $period = $this->period();
            $enrollments = $class->enrollments()->orderBy('class_number')->take($students)->get();

            $index = 0;

            foreach (range(1, $done + $notDone) as $offset) {
                EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollments[$index++ % $students]->id,
                    'academic_period_id' => $period->id,
                    'occurred_at' => $period->starts_on->copy()->addDays($offset),
                    'kind' => EvidenceKind::Homework,
                    'homework_status' => $offset <= $done ? HomeworkStatus::Done : HomeworkStatus::NotDone,
                    'description' => 'Verificação de TPC.',
                    'created_by' => $this->teacher->id,
                ]);
            }
        });
    }

    // ---------------------------------------------------------- the units

    #[Test]
    public function homework_is_reported_in_records_and_the_denominator_is_stated(): void
    {
        // Ten checks over two students: the two numbers differ, so a reader
        // could take «80%» for a share of students. This is the case the note
        // exists for.
        $this->homework(done: 8, notDone: 2, students: 2);

        $body = (string) $this->bodyOf($this->report(), SectionKey::RecordsSummary);

        $this->assertStringContainsString('dez verificações', $body);
        $this->assertStringContainsString('80%', $body);
        $this->assertStringContainsString('envolvendo dois alunos', $body);
        $this->assertStringContainsString('refere-se ao número de registos efetuados', $body);

        // The conversion that must never happen.
        $this->assertStringNotContainsString('20% dos alunos', $body);
        $this->assertStringNotContainsString('dos alunos não', $body);
        // And the old formulation, which said what was counted rather than what
        // could be misread.
        $this->assertStringNotContainsString('contam verificações e não alunos', $body);
    }

    #[Test]
    public function nothing_is_explained_when_the_two_counts_are_the_same(): void
    {
        // Six checks over six students. There is nothing to disambiguate: the
        // numbers are equal and the reader cannot go wrong, so a note about the
        // difference would introduce a distinction the data does not have.
        $this->homework(done: 2, notDone: 4, students: 6);

        $body = (string) $this->bodyOf($this->report(), SectionKey::RecordsSummary);

        $this->assertStringContainsString('seis verificações', $body);
        $this->assertStringContainsString('envolvendo seis alunos', $body);
        $this->assertStringContainsString('33,3%', $body);

        $this->assertStringNotContainsString('refere-se ao número de registos', $body);
        $this->assertStringNotContainsString('contam verificações e não alunos', $body);
    }

    #[Test]
    public function one_check_on_one_student_reads_in_the_singular(): void
    {
        $this->homework(done: 1, notDone: 0, students: 1);

        $body = (string) $this->bodyOf($this->report(), SectionKey::RecordsSummary);

        $this->assertStringContainsString('Foi realizada uma verificação', $body);
        $this->assertStringContainsString('envolvendo um aluno', $body);
        $this->assertStringContainsString('em um registo', $body);

        $this->assertStringNotContainsString('Foram realizadas uma', $body);
        $this->assertStringNotContainsString('refere-se ao número de registos', $body);
    }

    #[Test]
    public function several_checks_on_one_student_say_one_student_and_explain_the_share(): void
    {
        // Three checks, one student. «envolvendo um aluno» is the whole point:
        // the module must never turn three records into three people.
        $this->homework(done: 2, notDone: 1, students: 1);

        $body = (string) $this->bodyOf($this->report(), SectionKey::RecordsSummary);

        $this->assertStringContainsString('três verificações', $body);
        $this->assertStringContainsString('envolvendo um aluno', $body);
        // Three events, one person — the share really is over records here.
        $this->assertStringContainsString('refere-se ao número de registos efetuados', $body);

        $this->assertStringNotContainsString('três alunos', $body);
    }

    #[Test]
    public function the_percentage_is_the_same_whatever_the_wording_does(): void
    {
        // The arithmetic belongs to the source and was not touched: 2 of 6 is
        // 33,3% with the note and without it.
        $this->homework(done: 2, notDone: 4, students: 6);
        $same = (string) $this->bodyOf($this->report(), SectionKey::RecordsSummary);
        $this->assertStringContainsString('33,3%', $same);

        $this->refreshDatabaseForDifferentSpread();

        $this->homework(done: 2, notDone: 4, students: 3);
        $fewer = (string) $this->bodyOf($this->report(), SectionKey::RecordsSummary);
        $this->assertStringContainsString('33,3%', $fewer);
        // Same figure, and now the note — because now the counts differ.
        $this->assertStringContainsString('refere-se ao número de registos efetuados', $fewer);
    }

    /** Clears the homework records so a second spread can be laid down in one test. */
    private function refreshDatabaseForDifferentSpread(): void
    {
        $this->asTenant(fn () => EvidenceRecord::query()->where('kind', EvidenceKind::Homework)->forceDelete());
    }

    #[Test]
    public function occurrences_state_both_the_records_and_the_students(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $period = $this->period();
            $enrollments = $class->enrollments()->orderBy('class_number')->take(3)->get();

            // 5 records over 3 students.
            foreach ([0, 1, 2, 0, 1] as $offset => $index) {
                EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollments[$index]->id,
                    'academic_period_id' => $period->id,
                    'occurred_at' => $period->starts_on->copy()->addDays($offset + 1),
                    'kind' => EvidenceKind::Incident,
                    'disciplinary_severity' => DisciplinarySeverity::Grade2,
                    'description' => 'Ocorrência.',
                    'created_by' => $this->teacher->id,
                ]);
            }
        });

        $summary = (string) $this->bodyOf($this->report(), SectionKey::RecordsSummary);
        $distribution = (string) $this->bodyOf($this->report(), SectionKey::RecordsDistribution);

        $this->assertStringContainsString('cinco registos', $summary);
        $this->assertStringContainsString('envolvendo três alunos', $summary);
        $this->assertStringContainsString('Todos os registos dizem respeito a ocorrência disciplinar (três alunos)', $distribution);
    }

    // ------------------------------------------------------------- valence

    #[Test]
    public function records_with_no_direction_are_counted_as_such_and_not_as_neutral(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $period = $this->period();

            foreach ([EvidenceKind::Contact, EvidenceKind::Note, EvidenceKind::Support] as $offset => $kind) {
                EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => null,
                    'academic_period_id' => $period->id,
                    'occurred_at' => $period->starts_on->copy()->addDays($offset + 1),
                    'kind' => $kind,
                    'description' => 'Alguma coisa aconteceu.',
                    'created_by' => $this->teacher->id,
                ]);
            }
        });

        $body = (string) $this->bodyOf($this->report(), SectionKey::RecordsDistribution);

        $this->assertStringContainsString('três não têm sentido definido', $body);
        $this->assertStringContainsString('só é atribuído aos registos cujo tipo o comporta', $body);
        $this->assertStringNotContainsString('sentido neutro', $body);
    }

    // ------------------------------------------------------------- privacy

    #[Test]
    public function the_timeline_carries_no_names_unless_the_teacher_allowed_them(): void
    {
        $this->homework(done: 2, notDone: 1, students: 1);

        $data = $this->dataOf($this->report(detailed: true, names: false), SectionKey::RecordsTimeline);

        $this->assertNotNull($data);
        $this->assertNotEmpty($data['rows']);

        foreach ($data['rows'] as $row) {
            // ABSENT, not blank: the name never leaves the database (§28).
            $this->assertArrayNotHasKey('student', $row);
        }
    }

    #[Test]
    public function with_permission_the_timeline_names_the_students(): void
    {
        $this->homework(done: 2, notDone: 1, students: 1);

        $data = $this->dataOf($this->report(detailed: true, names: true), SectionKey::RecordsTimeline);

        $this->assertNotNull($data);
        $this->assertArrayHasKey('student', $data['rows'][0]);
    }

    #[Test]
    public function the_synthesis_mode_produces_no_timeline_at_all(): void
    {
        $this->homework(done: 2, notDone: 1);

        $report = $this->report(detailed: false);

        $this->assertNull($this->bodyOf($report, SectionKey::RecordsTimeline));
        $this->assertNotNull($this->bodyOf($report, SectionKey::RecordsSummary));
    }

    // --------------------------------------------------------------- scope

    #[Test]
    public function the_scope_paragraph_states_the_filters_before_any_number(): void
    {
        $this->homework(done: 2, notDone: 1);

        $body = (string) $this->bodyOf(
            $this->report(['kinds' => [EvidenceKind::Homework->value]]),
            SectionKey::RecordsScope,
        );

        $this->assertStringContainsString('7.º A', $body);
        $this->assertStringContainsString('1.º Semestre', $body);
        $this->assertStringContainsString('Trabalho de casa', $body);
        $this->assertStringContainsString('sem identificação dos alunos', $body);
        $this->assertStringContainsString('apenas a síntese', $body);
    }

    #[Test]
    public function an_empty_logbook_says_nobody_wrote_anything_and_not_that_nothing_happened(): void
    {
        $body = mb_strtolower((string) $this->bodyOf($this->report(), SectionKey::RecordsSummary));

        $this->assertStringContainsString('não foram encontrados registos', $body);

        foreach (['não houve', 'sem ocorrências', 'sem problemas', 'comportamento'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    #[Test]
    public function a_kind_filter_actually_narrows_the_read(): void
    {
        $this->homework(done: 3, notDone: 0);

        $this->asTenant(function (): void {
            EvidenceRecord::create([
                'class_id' => $this->schoolClass()->id,
                'enrollment_id' => null,
                'academic_period_id' => $this->period()->id,
                'occurred_at' => $this->period()->starts_on->copy()->addDays(20),
                'kind' => EvidenceKind::Note,
                'description' => 'Uma observação.',
                'created_by' => $this->teacher->id,
            ]);
        });

        $all = (string) $this->bodyOf($this->report(), SectionKey::RecordsSummary);
        $onlyHomework = (string) $this->bodyOf(
            $this->report(['kinds' => [EvidenceKind::Homework->value]]),
            SectionKey::RecordsSummary,
        );

        $this->assertStringContainsString('quatro registos', $all);
        $this->assertStringContainsString('três registos', $onlyHomework);
    }

    // -------------------------------------------------------------- tenancy

    #[Test]
    public function a_report_without_a_class_covers_only_the_authors_own_classes(): void
    {
        $this->homework(done: 2, notDone: 0);

        // A colleague's class in the same organization, with its own logbook.
        $colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$colleague->id => ['joined_at' => now()]]);

        $this->asTenant(function () use ($colleague): void {
            $theirClass = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass()->academic_year_id,
                'subject_id' => $this->schoolClass()->subject_id,
                'label' => '8.º B',
            ]);

            $theirClass->teachers()->syncWithoutDetaching([$colleague->id => ['role' => 'owner']]);

            $student = Student::factory()->recycle($this->organization)->create();

            $enrollment = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $theirClass->id,
                'student_id' => $student->id,
                'enrolled_on' => $this->period()->starts_on,
            ]);

            foreach (range(1, 7) as $offset) {
                EvidenceRecord::create([
                    'class_id' => $theirClass->id,
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $this->period()->id,
                    'occurred_at' => $this->period()->starts_on->copy()->addDays($offset),
                    'kind' => EvidenceKind::Note,
                    'description' => 'Registo do colega.',
                    'created_by' => $colleague->id,
                ]);
            }
        });

        $report = $this->asTenant(fn (): Report => app(CreateReport::class)->forRecords(
            year: $this->schoolClass()->academicYear,
            author: $this->teacher,
            class: null,
            period: $this->period(),
        ));

        $body = (string) $this->bodyOf($report, SectionKey::RecordsSummary);

        // Mine only. «Todas as turmas» means mine, never the school's.
        $this->assertStringContainsString('dois registos', $body);
        $this->assertStringNotContainsString('nove registos', $body);
    }
}
