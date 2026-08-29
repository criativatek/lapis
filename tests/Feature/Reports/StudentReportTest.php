<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\SectionKey;
use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Reporting\CreateReport;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;

/**
 * The individual report.
 *
 * ITS NUMBERS AND ITS CLASS'S NUMBERS COME FROM ONE READ, which is what these
 * assert first: «acima da média da turma (66,4%)» must quote the same 66,4% the
 * class report and Estatística would give, or the comparison is between two
 * different moments.
 *
 * The rest is about what an individual report must not do to a named child: it
 * must not turn an absence into a zero, must not count what was written about
 * the class as theirs, must not print a proposal as a grade, and must not
 * explain a self-assessment.
 */
class StudentReportTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

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

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    /** The student with a result in the first period, so the sentences have content. */
    private function enrollment(): Enrollment
    {
        return $this->asTenant(function (): Enrollment {
            $statistics = app(BuildClassStatistics::class)->for($this->schoolClass(), $this->period(1));

            foreach ($statistics['students'] as $student) {
                if (($student['primary_average'] ?? null) !== null) {
                    return Enrollment::findOrFail($student['enrollment_id']);
                }
            }

            return $this->schoolClass()->enrollments()->orderBy('class_number')->firstOrFail();
        });
    }

    private function report(?Enrollment $enrollment = null): Report
    {
        $enrollment ??= $this->enrollment();

        return $this->asTenant(fn (): Report => app(CreateReport::class)->forStudent(
            enrollment: $enrollment,
            author: $this->teacher,
            period: $this->period(1),
        ));
    }

    private function bodyOf(Report $report, SectionKey $key): ?string
    {
        return $this->asTenant(fn (): ?string => $report->sections()->where('key', $key->value)->first()?->body);
    }

    // ------------------------------------------------------------ agreement

    #[Test]
    public function the_student_and_the_class_figures_come_from_one_read(): void
    {
        // «Comparação contextual com turma» is Pro and Institucional (Matriz
        // §3 and §4), so the sentence this asserts only exists on a plan that
        // holds `advanced_analytics`. The subject of the test is unchanged:
        // when the comparison IS produced, its class figure is the very one
        // BuildClassStatistics computed, never a second average taken here.
        $this->subscribeOrganizationTo($this->organization, 'pro');

        $report = $this->report();

        $statistics = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(1)));

        $classAverage = $statistics['summary']['primary_average'];
        $this->assertNotNull($classAverage);

        $expected = rtrim(rtrim(str_replace('.', ',', (string) $classAverage), '0'), ',');
        $body = (string) $this->bodyOf($report, SectionKey::StudentSynthesis);

        $this->assertStringContainsString($expected.'%', $body);
        $this->assertMatchesRegularExpression('/(acima|abaixo|[Cc]oincide)/u', $body);
    }

    #[Test]
    public function the_identification_names_the_student_and_the_scope(): void
    {
        $enrollment = $this->enrollment();
        $name = $this->asTenant(fn () => optional($enrollment->student->identity)->display_name);

        $body = (string) $this->bodyOf($this->report($enrollment), SectionKey::StudentIdentification);

        $this->assertStringContainsString((string) $name, $body);
        $this->assertStringContainsString('7.º A', $body);
        $this->assertStringContainsString('1.º Semestre', $body);
    }

    // ---------------------------------------------------- absence ≠ failure

    #[Test]
    public function a_student_with_no_result_is_told_apart_from_a_student_with_a_bad_one(): void
    {
        $enrollment = $this->asTenant(function (): Enrollment {
            $statistics = app(BuildClassStatistics::class)->for($this->schoolClass(), $this->period(1));

            foreach ($statistics['students'] as $student) {
                if (($student['primary_average'] ?? null) === null) {
                    return Enrollment::findOrFail($student['enrollment_id']);
                }
            }

            // The demo class has one; if it ever stops having one, make one.
            $enrollment = $this->schoolClass()->enrollments()->orderBy('class_number')->firstOrFail();
            StudentItemScore::query()->where('enrollment_id', $enrollment->id)->delete();

            return $enrollment;
        });

        $body = (string) $this->bodyOf($this->report($enrollment), SectionKey::StudentSynthesis);

        $this->assertStringContainsString('Não existem resultados apurados', $body);
        $this->assertStringContainsString('não corresponde a um resultado negativo', $body);
        $this->assertStringNotContainsString('0%', $body);
    }

    #[Test]
    public function a_domain_with_no_evidence_is_not_reported_as_a_weak_domain(): void
    {
        $body = (string) $this->bodyOf($this->report(), SectionKey::StudentDomainPerformance);

        if (str_contains($body, 'Não existe')) {
            $this->assertStringContainsString('não corresponde a um resultado negativo', $body);
        }

        $this->assertStringNotContainsString('— 0%', $body);
    }

    // ------------------------------------------------------- what is theirs

    #[Test]
    public function class_wide_logbook_entries_are_not_counted_as_the_students(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $period = $this->period(1);

            // Five entries about the class as a whole, none about anybody.
            foreach (range(1, 5) as $index) {
                EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => null,
                    'academic_period_id' => $period->id,
                    'occurred_at' => $period->starts_on->copy()->addDays($index),
                    'kind' => EvidenceKind::Note,
                    'description' => 'Observação sobre a turma.',
                    'created_by' => $this->teacher->id,
                ]);
            }
        });

        $body = (string) $this->bodyOf($this->report(), SectionKey::StudentRecords);

        $this->assertStringContainsString('Não foram encontrados registos', $body);
        $this->assertStringNotContainsString('5 registos', $body);
    }

    #[Test]
    public function a_class_wide_intervention_is_named_apart_from_one_directed_at_the_student(): void
    {
        $enrollment = $this->enrollment();

        $this->asTenant(function (): void {
            $class = $this->schoolClass();

            Intervention::create([
                'class_id' => $class->id,
                'enrollment_id' => null,
                'academic_period_id' => $this->period(1)->id,
                'target_type' => InterventionTargetType::SchoolClass,
                'intervention_type' => InterventionType::LearningReinforcement,
                'domain_relation' => InterventionDomainRelation::None,
                'title' => 'Reforço das aprendizagens',
                'description_source' => InterventionDescriptionSource::Template,
                'status' => InterventionStatus::InProgress,
                'started_on' => $this->period(1)->starts_on,
                'include_in_report' => false,
                'available_for_reports' => true,
                'created_by' => $this->teacher->id,
            ]);
        });

        $body = (string) $this->bodyOf($this->report($enrollment), SectionKey::StudentRecords);

        $this->assertStringContainsString('dirigida à turma', $body);
        $this->assertStringNotContainsString('dirigida ao aluno', $body);
    }

    // ------------------------------------------------------ the four voices

    #[Test]
    public function a_proposal_is_never_printed_as_a_grade(): void
    {
        $body = (string) $this->bodyOf($this->report(), SectionKey::StudentClassification);

        // The demo class has no confirmed classification in the first period.
        $this->assertStringContainsString('Ainda não foram atribuídas', $body);
        $this->assertStringNotContainsString('proposta do sistema foi', $body);
    }

    #[Test]
    public function the_self_assessment_is_reported_without_being_explained(): void
    {
        $body = (string) $this->bodyOf($this->report(), SectionKey::StudentSelfAssessment);

        foreach (['falta de confiança', 'insegurança', 'sobrestima', 'não tem noção'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, mb_strtolower($body));
        }
    }

    // ------------------------------------------------------------ structure

    #[Test]
    public function the_individual_report_gets_its_own_sections_and_not_the_class_ones(): void
    {
        $keys = $this->asTenant(fn () => $this->report()->sections()->pluck('key')->all());

        $this->assertContains(SectionKey::StudentIdentification->value, $keys);
        $this->assertContains(SectionKey::StudentClassification->value, $keys);
        $this->assertNotContains(SectionKey::ClassIdentification->value, $keys);
        $this->assertNotContains(SectionKey::ClassDistribution->value, $keys);
    }

    #[Test]
    public function an_individual_report_names_its_subject_without_needing_permission(): void
    {
        $report = $this->report();

        // §28: naming is what an individual report is for. The option that
        // governs class-wide documents does not apply to it.
        $this->assertTrue($this->asTenant(fn () => $report->namesStudents()));
    }
}
