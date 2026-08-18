<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\PlanningCompliance;
use App\Domain\Reporting\SectionKey;
use App\Models\AcademicPeriod;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportSection;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Reporting\ComposeReport;
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
 * The class report, end to end.
 *
 * TWO KINDS OF ASSERTION LIVE HERE and the second kind matters more.
 *
 * The first checks that the sentences say what the data says — that the average
 * quoted in the prose is the average Estatística would give, named by the right
 * term.
 *
 * The second checks that the sentences DO NOT say what the data does not say
 * (§67). A report with no logbook entries must not claim good behaviour; a low
 * average must not become a statement about effort; a student with no
 * classification must never be counted as a failure. Those are the failures that
 * would not look like bugs — they would look like a report.
 */
class ClassReportTest extends TestCase
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

    private function givePlan(string $key): void
    {
        $this->seed(EntitlementsSeeder::class);

        $plan = Plan::where('key', $key)->firstOrFail();

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }

    /**
     * @param  list<string>|null  $sections
     */
    private function report(?int $sequence = 1, ?array $sections = null): Report
    {
        return $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $sequence === null ? null : $this->period($sequence),
            sectionKeys: $sections,
        ));
    }

    private function bodyOf(Report $report, SectionKey $key): ?string
    {
        return $this->asTenant(
            fn (): ?string => $report->sections()->where('key', $key->value)->first()?->body,
        );
    }

    private function wholeText(Report $report): string
    {
        return $this->asTenant(fn (): string => $report->sections()
            ->get()
            ->map(fn (ReportSection $section) => (string) $section->body)
            ->implode("\n"));
    }

    // ---------------------------------------------------------- it generates

    #[Test]
    public function creating_a_report_builds_its_sections_and_writes_them(): void
    {
        $this->givePlan('base');

        $report = $this->report(1);

        $sections = $this->asTenant(fn () => $report->sections()->get());

        $this->assertNotEmpty($sections);
        // Every allowed section gets a row; `included` is what decides printing.
        $this->assertTrue($sections->contains(fn (ReportSection $section) => $section->key === SectionKey::OverallAssessment->value));
        $this->assertNotNull($this->bodyOf($report, SectionKey::ClassIdentification));
    }

    #[Test]
    public function the_first_paragraph_states_the_class_and_the_temporal_scope(): void
    {
        $this->givePlan('base');

        $body = (string) $this->bodyOf($this->report(1), SectionKey::ClassIdentification);

        $this->assertStringContainsString('7.º A', $body);
        $this->assertStringContainsString('6 alunos', $body);
        // §29: never temporally ambiguous.
        $this->assertStringContainsString('1.º Semestre', $body);
    }

    #[Test]
    public function the_headline_quotes_the_number_estatistica_would_give_and_names_the_reading(): void
    {
        $this->givePlan('base');

        $statistics = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(1)));

        $average = $statistics['summary']['primary_average'];
        $this->assertNotNull($average, 'A turma demo tem resultados no 1.º Semestre.');

        $expected = rtrim(rtrim(str_replace('.', ',', (string) $average), '0'), ',');
        $body = (string) $this->bodyOf($this->report(1), SectionKey::OverallAssessment);

        $this->assertStringContainsString($expected.'%', $body);
        // The reading is NAMED. «a média» alone is ambiguous from the second
        // period onwards, and the module never writes it (§7).
        $this->assertMatchesRegularExpression('/Média Ponderada( Acumulada)?/u', $body);
    }

    #[Test]
    public function students_without_a_result_are_named_as_such_and_never_as_failures(): void
    {
        $this->givePlan('base');

        $body = (string) $this->bodyOf($this->report(1), SectionKey::OverallAssessment);

        if (str_contains($body, 'não têm ainda resultado') || str_contains($body, 'não tem ainda resultado')) {
            $this->assertStringContainsString('não entra', $body);
        }

        $this->assertStringNotContainsString('insucesso', mb_strtolower($body));
        $this->assertStringNotContainsString('reprovou', mb_strtolower($body));
    }

    // ------------------------------------------------------- non-invention

    #[Test]
    public function a_class_with_no_logbook_entries_never_gets_a_sentence_about_behaviour(): void
    {
        $this->givePlan('base');

        $text = mb_strtolower($this->wholeText($this->report(1)));

        // No records were created for the demo class in this test.
        $this->assertStringContainsString('não foram encontrados registos', $text);

        // And none of these may appear anywhere in the document.
        foreach (['o comportamento foi', 'não houve problemas', 'bom comportamento', 'sem ocorrências'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "O relatório não pode afirmar «{$forbidden}».");
        }
    }

    #[Test]
    public function a_low_result_never_becomes_a_statement_about_effort_or_character(): void
    {
        $this->givePlan('base');

        $this->asTenant(function (): void {
            // Push every score to the floor: the worst case the data can
            // produce, and the one most likely to tempt a generator into
            // explaining WHY.
            StudentItemScore::query()
                ->where('result_state', ResultState::Assessed)
                ->update(['points_earned' => '0']);
        });

        $text = mb_strtolower($this->wholeText($this->report(1)));

        foreach ([
            'falta de estudo',
            'não estuda',
            'não estudam',
            'preguiç',
            'desinteresse',
            'falta de interesse',
            'alunos fracos',
            'maus alunos',
            'turma problemática',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "O relatório não pode inferir «{$forbidden}».");
        }
    }

    #[Test]
    public function without_a_registered_intervention_the_report_says_registered_and_not_needed(): void
    {
        $this->givePlan('base');

        $body = (string) $this->bodyOf($this->report(1), SectionKey::InterventionsSummary);

        $this->assertStringContainsString('Não foram registadas intervenções', $body);
        $this->assertStringNotContainsString('necessári', mb_strtolower($body));
    }

    #[Test]
    public function an_unanswered_planning_question_produces_no_section_at_all(): void
    {
        $this->givePlan('base');

        $report = $this->report(1);

        $this->assertNull($this->bodyOf($report, SectionKey::PlanningCompliance));
    }

    #[Test]
    public function the_planning_section_transcribes_the_teachers_answer(): void
    {
        $this->givePlan('base');

        $report = $this->report(1);

        $this->asTenant(function () use ($report): void {
            $report->update(['teacher_input' => [
                'planning' => [
                    'compliance' => PlanningCompliance::PartiallyComplied->value,
                    'postponed_content' => ['Texto poético', 'Sintaxe'],
                ],
            ]]);

            app(ComposeReport::class)->generate($report);
        });

        $body = (string) $this->bodyOf($report->fresh() ?? $report, SectionKey::PlanningCompliance);

        $this->assertStringContainsString('parcialmente cumprida', $body);
        $this->assertStringContainsString('Texto poético e Sintaxe', $body);
        $this->assertStringContainsString('retomados no início do período seguinte', $body);
    }

    // ------------------------------------------------------- plan boundaries

    #[Test]
    public function a_base_organization_gets_no_interpretive_sections_at_all(): void
    {
        $this->givePlan('base');

        $keys = $this->asTenant(fn () => $this->report(1)->sections()->pluck('key')->all());

        $this->assertNotContains(SectionKey::Difficulties->value, $keys);
        $this->assertNotContains(SectionKey::BehaviourAttitude->value, $keys);
        $this->assertNotContains(SectionKey::ImprovementProposals->value, $keys);
        // And the descriptive ones are all there.
        $this->assertContains(SectionKey::OverallAssessment->value, $keys);
        $this->assertContains(SectionKey::PlanningCompliance->value, $keys);
    }

    #[Test]
    public function a_posted_section_key_the_plan_forbids_never_becomes_a_row(): void
    {
        $this->givePlan('base');

        // As a hand-rolled request would.
        $report = $this->report(1, [
            SectionKey::OverallAssessment->value,
            SectionKey::Difficulties->value,
        ]);

        $keys = $this->asTenant(fn () => $report->sections()->pluck('key')->all());

        $this->assertNotContains(SectionKey::Difficulties->value, $keys);
    }

    // -------------------------------------------------------------- editing

    #[Test]
    public function regenerating_never_overwrites_what_the_teacher_wrote(): void
    {
        $this->givePlan('base');

        $report = $this->report(1);

        $this->asTenant(function () use ($report): void {
            $section = $report->sections()->where('key', SectionKey::OverallAssessment->value)->firstOrFail();
            $section->update(['body' => 'A minha própria redação.', 'edited' => true]);

            app(ComposeReport::class)->generate($report);
        });

        $this->assertSame('A minha própria redação.', $this->bodyOf($report, SectionKey::OverallAssessment));
    }

    #[Test]
    public function regenerating_one_section_explicitly_does_replace_it(): void
    {
        $this->givePlan('base');

        $report = $this->report(1);

        $section = $this->asTenant(function () use ($report): ReportSection {
            $section = $report->sections()->where('key', SectionKey::OverallAssessment->value)->firstOrFail();
            $section->update(['body' => 'A minha própria redação.', 'edited' => true]);

            app(ComposeReport::class)->regenerate($section);

            return $section->fresh() ?? $section;
        });

        $this->assertNotSame('A minha própria redação.', $section->body);
        $this->assertFalse($section->edited);
    }

    #[Test]
    public function restoring_puts_back_the_last_automatic_text(): void
    {
        $this->givePlan('base');

        $report = $this->report(1);

        $restored = $this->asTenant(function () use ($report): ReportSection {
            $section = $report->sections()->where('key', SectionKey::OverallAssessment->value)->firstOrFail();
            $generated = $section->generated_body;

            $section->update(['body' => 'Outra coisa.', 'edited' => true]);
            app(ComposeReport::class)->restore($section);

            $fresh = $section->fresh() ?? $section;
            $this->assertSame($generated, $fresh->body);

            return $fresh;
        });

        $this->assertFalse($restored->edited);
    }

    #[Test]
    public function a_finalized_report_refuses_to_be_regenerated(): void
    {
        $this->givePlan('base');

        $report = $this->asTenant(fn () => Report::factory()
            ->recycle($this->organization)
            ->finalized()
            ->create(['class_id' => $this->schoolClass()->id, 'created_by' => $this->teacher->id]));

        $this->expectException(\LogicException::class);

        $this->asTenant(fn () => app(ComposeReport::class)->generate($report));
    }

    // ------------------------------------------------------------- the units

    #[Test]
    public function homework_is_reported_in_records_and_never_converted_into_students(): void
    {
        $this->givePlan('base');

        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $period = $this->period(1);
            $enrollments = $class->enrollments()->orderBy('class_number')->take(2)->get();

            foreach (range(1, 10) as $index) {
                EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollments[$index % 2]->id,
                    'academic_period_id' => $period->id,
                    'occurred_at' => $period->starts_on->copy()->addDays($index),
                    'kind' => EvidenceKind::Homework,
                    'homework_status' => $index <= 8
                        ? HomeworkStatus::Done
                        : HomeworkStatus::NotDone,
                    'description' => 'Verificação de TPC.',
                    'created_by' => $this->teacher->id,
                ]);
            }
        });

        $body = (string) $this->bodyOf($this->report(1), SectionKey::ClassRecords);

        $this->assertStringContainsString('10 verificações', $body);
        $this->assertStringContainsString('80%', $body);
        // §70: the denominator is stated, and it is records — not people.
        $this->assertStringContainsString('registos de verificação e não a alunos', $body);
        $this->assertStringNotContainsString('20% dos alunos', $body);
    }
}
