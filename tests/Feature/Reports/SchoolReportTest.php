<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\LearningAttitude;
use App\Domain\Reporting\SectionKey;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\DisciplinarySeverity;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\ProfileVersionStatus;
use App\Models\Report;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\FinalizeReport;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The institutional report (§24–§28).
 *
 * THE THREE THINGS IT MUST NOT DO. It must not pool results across
 * incompatible scales, because «1 a 5» and «0 a 20» are not one axis and a
 * combined rate would be a meaningless number that nonetheless gets quoted. It
 * must not rank anything. And it must not read behaviour off disciplinary
 * occurrences — a count of records is a count of what was written down.
 *
 * These are asserted rather than trusted, because all three failures produce a
 * document that reads perfectly well.
 */
class SchoolReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->owner->personalOrganization();

        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));

        $this->seed(EntitlementsSeeder::class);

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
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

    private function year(): AcademicYear
    {
        return $this->asTenant(fn (): AcademicYear => SchoolClass::where('label', '7.º A')->firstOrFail()->academicYear);
    }

    private function report(): Report
    {
        return $this->asTenant(fn (): Report => app(CreateReport::class)->forSchool(
            year: $this->year(),
            author: $this->owner,
        ));
    }

    private function bodyOf(Report $report, SectionKey $key): ?string
    {
        return $this->asTenant(fn (): ?string => $report->sections()->where('key', $key->value)->first()?->body);
    }

    /**
     * Give every enrolment of a class a confirmed classification on a level of
     * the class's own scale.
     */
    private function classify(SchoolClass $class, bool $positive): void
    {
        $this->asTenant(function () use ($class, $positive): void {
            $version = $class->profileVersion;
            $scale = $version?->scale;

            if ($scale === null) {
                return;
            }

            $level = $scale->levels()->where('is_negative', ! $positive)->orderBy('sequence')->first()
                ?? $scale->levels()->orderBy('sequence')->first();

            $period = $class->academicYear->periods()->orderBy('sequence')->firstOrFail();

            foreach ($class->enrollments()->get() as $enrollment) {
                Classification::create([
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $period->id,
                    'scope' => ClassificationScope::Period,
                    'assessment_profile_version_id' => $version->id,
                    'status' => ClassificationStatus::Confirmed,
                    'final_value' => (string) $level->code,
                    'final_scale_level_id' => $level->id,
                    'confirmed_by' => $this->owner->id,
                    'confirmed_at' => now(),
                ]);
            }
        });
    }

    // ---------------------------------------------------------- §26 scales

    #[Test]
    public function two_scales_are_reported_apart_and_never_pooled_into_one_rate(): void
    {
        $second = $this->asTenant(function (): SchoolClass {
            $original = SchoolClass::where('label', '7.º A')->firstOrFail();

            // A second class on a DIFFERENT scale.
            // A scale of the school's own, with its own words — which is the
            // realistic way a second scale enters a school, and the case §26
            // is about. The system set has only one levelled scale.
            $otherScale = Scale::query()->create([
                'name' => 'Não atingiu / Atingiu / Superou',
                'kind' => 'level',
                'min_value' => 1,
                'max_value' => 3,
            ]);

            foreach ([
                ['code' => 'NA', 'label' => 'Não atingiu', 'sequence' => 1, 'is_negative' => true],
                ['code' => 'A', 'label' => 'Atingiu', 'sequence' => 2, 'is_negative' => false],
                ['code' => 'S', 'label' => 'Superou', 'sequence' => 3, 'is_negative' => false],
            ] as $level) {
                $otherScale->levels()->create($level);
            }

            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $original->academic_year_id,
                'subject_id' => $original->subject_id,
                'label' => '8.º B',
                'grade_level' => '8.º',
            ]);

            // Its OWN profile: only one version per profile may be active, so
            // the second class needs a profile of its own to sit on the other
            // scale.
            $profile = AssessmentProfile::factory()->recycle($this->organization)->create([
                'academic_year_id' => $original->academic_year_id,
                'subject_id' => $original->subject_id,
                'grade_level' => '8.º',
                'name' => 'Português – 8.º Ano',
            ]);

            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create([
                'assessment_profile_id' => $profile->id,
                'scale_id' => $otherScale->id,
                'status' => ProfileVersionStatus::Active,
                'activated_at' => now(),
                'frozen_at' => now(),
            ]);

            $class->forceFill(['assessment_profile_version_id' => $version->id])->save();

            // A factory class has no roster, and a class with no enrolments has
            // no classifications to group.
            foreach (range(1, 3) as $number) {
                Enrollment::factory()->recycle($this->organization)->create([
                    'class_id' => $class->id,
                    'student_id' => Student::factory()->recycle($this->organization)->create()->id,
                    'class_number' => $number,
                    'enrolled_on' => $original->academicYear->starts_on,
                ]);
            }

            return $class->fresh();
        });

        $this->classify($this->asTenant(fn () => SchoolClass::where('label', '7.º A')->firstOrFail()), positive: true);
        $this->classify($second, positive: false);

        $report = $this->report();

        $results = (string) $this->bodyOf($report, SectionKey::SchoolResults);
        $comparability = (string) $this->bodyOf($report, SectionKey::SchoolComparability);

        // Each scale named, each with its own paragraph.
        $this->assertStringContainsString('Na escala', $results);
        $this->assertStringContainsString('não são diretamente comparáveis', $comparability);
        $this->assertStringContainsString('nunca agregados num valor único', $comparability);

        // The structured payload keeps them apart too — nothing downstream can
        // add them up.
        $data = $this->asTenant(fn () => $report->sections()
            ->where('key', SectionKey::SchoolResults->value)->firstOrFail()->data);

        $this->assertGreaterThan(1, count($data['groups']));
    }

    #[Test]
    public function one_scale_throughout_is_stated_as_comparable(): void
    {
        $this->classify($this->asTenant(fn () => SchoolClass::where('label', '7.º A')->firstOrFail()), positive: true);

        $body = (string) $this->bodyOf($this->report(), SectionKey::SchoolComparability);

        $this->assertStringContainsString('mesma escala', $body);
        $this->assertStringContainsString('diretamente comparáveis', $body);
    }

    // ------------------------------------------------------------ §25 rankings

    #[Test]
    public function nothing_is_ordered_by_performance_and_no_teacher_is_named(): void
    {
        $this->classify($this->asTenant(fn () => SchoolClass::where('label', '7.º A')->firstOrFail()), positive: true);

        $text = mb_strtolower($this->asTenant(fn () => $this->report()->sections()
            ->get()->map(fn ($section) => (string) $section->body)->implode("\n")));

        foreach (['melhor turma', 'pior turma', 'ranking', 'melhor professor', 'ordenação de desempenho por'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }

        $this->assertStringNotContainsString(mb_strtolower($this->owner->name), $text);
    }

    #[Test]
    public function the_dimensional_cuts_say_they_are_not_a_ranking(): void
    {
        $this->classify($this->asTenant(fn () => SchoolClass::where('label', '7.º A')->firstOrFail()), positive: true);

        $body = (string) $this->bodyOf($this->report(), SectionKey::SchoolBySubject);

        $this->assertStringContainsString('ordem alfabética', $body);
        $this->assertStringContainsString('não constituem uma ordenação de desempenho', $body);
    }

    // --------------------------------------------------------- §27 behaviour

    #[Test]
    public function behaviour_is_never_read_off_the_disciplinary_records(): void
    {
        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->orderBy('sequence')->firstOrFail();

            // A lot of occurrences, and no characterisation anywhere.
            foreach ($class->enrollments()->get() as $index => $enrollment) {
                EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $period->id,
                    'occurred_at' => $period->starts_on->copy()->addDays($index + 1),
                    'kind' => EvidenceKind::Incident,
                    'disciplinary_severity' => DisciplinarySeverity::Grade4,
                    'description' => 'Ocorrência.',
                    'created_by' => $this->owner->id,
                ]);
            }
        });

        $body = (string) $this->bodyOf($this->report(), SectionKey::SchoolCharacterization);

        $this->assertStringContainsString('Não existem caracterizações validadas', $body);
        $this->assertStringContainsString('não são inferidos a partir dos registos disciplinares', $body);
        // And no count of occurrences masquerading as a behaviour finding.
        $this->assertStringNotContainsString('ocorrência', mb_strtolower($body));
    }

    #[Test]
    public function the_characterisation_summary_reads_finalized_class_reports_and_states_coverage(): void
    {
        $class = $this->asTenant(fn () => SchoolClass::where('label', '7.º A')->firstOrFail());

        $this->asTenant(function () use ($class): void {
            $classReport = app(CreateReport::class)->forClass(
                class: $class,
                author: $this->owner,
                period: $class->academicYear->periods()->orderBy('sequence')->firstOrFail(),
            );

            $classReport->update(['teacher_input' => ['attitude' => LearningAttitude::Positive->value]]);

            app(FinalizeReport::class)->finalize($classReport->fresh(), $this->owner);
        });

        $body = (string) $this->bodyOf($this->report(), SectionKey::SchoolCharacterization);

        $this->assertStringContainsString('relatório de turma finalizado inclui uma caracterização', $body);
        // The coverage travels with the percentage (§27): the sentence says how
        // many classes it is speaking for, never a bare «100%».
        $this->assertMatchesRegularExpression('/(Na única turma|Nas \d+ turmas|Em \d+ de \d+ turmas)/u', $body);
        $this->assertStringContainsString('não resultam de qualquer inferência', $body);
    }

    #[Test]
    public function a_draft_class_report_does_not_feed_the_institutional_figure(): void
    {
        $class = $this->asTenant(fn () => SchoolClass::where('label', '7.º A')->firstOrFail());

        $this->asTenant(function () use ($class): void {
            $classReport = app(CreateReport::class)->forClass(
                class: $class,
                author: $this->owner,
                period: $class->academicYear->periods()->orderBy('sequence')->firstOrFail(),
            );

            // Characterised, but still a draft: somebody still thinking.
            $classReport->update(['teacher_input' => ['attitude' => LearningAttitude::VeryPositive->value]]);
        });

        $body = (string) $this->bodyOf($this->report(), SectionKey::SchoolCharacterization);

        $this->assertStringContainsString('Não existem caracterizações validadas', $body);
    }

    // ------------------------------------------------------------- coverage

    #[Test]
    public function coverage_is_stated_before_the_results(): void
    {
        $this->classify($this->asTenant(fn () => SchoolClass::where('label', '7.º A')->firstOrFail()), positive: true);

        $report = $this->report();

        $positions = $this->asTenant(fn () => $report->sections()
            ->whereIn('key', [SectionKey::SchoolCoverage->value, SectionKey::SchoolResults->value])
            ->pluck('position', 'key'));

        $this->assertLessThan(
            $positions[SectionKey::SchoolResults->value],
            $positions[SectionKey::SchoolCoverage->value],
            'A cobertura vem antes dos resultados — é o enquadramento, não uma ressalva.',
        );

        $this->assertStringContainsString('turma', (string) $this->bodyOf($report, SectionKey::SchoolCoverage));
        // And the sentence agrees in number rather than reading «Das 1 turma».
        $this->assertStringNotContainsString('Das 1 turma', (string) $this->bodyOf($report, SectionKey::SchoolCoverage));
    }

    // ------------------------------------------------------------ privacy

    #[Test]
    public function a_school_report_never_names_a_student(): void
    {
        $this->classify($this->asTenant(fn () => SchoolClass::where('label', '7.º A')->firstOrFail()), positive: true);

        $report = $this->report();

        $this->assertFalse($this->asTenant(fn () => $report->namesStudents()));

        $names = $this->asTenant(fn () => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->enrollments()->with('student.identity')->get()
            ->map(fn ($enrollment) => optional($enrollment->student->identity)->display_name)
            ->filter());

        $text = $this->asTenant(fn () => $report->sections()
            ->get()->map(fn ($section) => (string) $section->body)->implode("\n"));

        foreach ($names as $name) {
            $this->assertStringNotContainsString((string) $name, $text);
        }
    }

    // ------------------------------------------------------------ the plan

    #[Test]
    public function a_pro_organization_cannot_create_a_school_report(): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'pro')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('não inclui este tipo de relatório');

        $this->report();
    }
}
