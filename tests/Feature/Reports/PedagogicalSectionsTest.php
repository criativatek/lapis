<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\BehaviourRating;
use App\Domain\Reporting\ComplementaryIndicator;
use App\Domain\Reporting\IndicatorStanding;
use App\Domain\Reporting\LearningAttitude;
use App\Domain\Reporting\SectionKey;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportLibraryEntry;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Reporting\ComposeReport;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\ReportLibraryProvider;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Database\Seeders\ReportLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The interpretive sections (§8–§15).
 *
 * EVERY WORD IN THEM IS THE TEACHER'S, and these tests exist to keep it that
 * way. The failure mode is not a crash: it is a paragraph that reads perfectly
 * well and asserts something nobody said — «o comportamento foi bom» in a class
 * whose teacher answered nothing, a strategy proposed for a difficulty nobody
 * validated, a student named on a document that was never authorised to name
 * them.
 */
class PedagogicalSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->seed(ReportLibrarySeeder::class);
        $this->organization = $this->teacher->personalOrganization();

        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));

        $this->givePro();
    }

    private function givePro(): void
    {
        $this->seed(EntitlementsSeeder::class);

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'pro')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
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

    private function report(): Report
    {
        return $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->schoolClass()->academicYear->periods()->where('sequence', 1)->firstOrFail(),
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function withInput(Report $report, array $input, bool $nameStudents = false): Report
    {
        return $this->asTenant(function () use ($report, $input, $nameStudents): Report {
            $report->update([
                'teacher_input' => array_replace($report->teacher_input ?? [], $input),
                'options' => ['name_students' => $nameStudents],
            ]);

            app(ComposeReport::class)->generate($report);

            return $report->fresh() ?? $report;
        });
    }

    private function bodyOf(Report $report, SectionKey $key): ?string
    {
        return $this->asTenant(fn (): ?string => $report->sections()->where('key', $key->value)->first()?->body);
    }

    // ------------------------------------------------- behaviour & attitude

    #[Test]
    public function without_an_answer_there_is_no_behaviour_section_at_all(): void
    {
        $report = $this->report();

        $this->assertNull($this->bodyOf($report, SectionKey::BehaviourAttitude));
    }

    #[Test]
    public function not_characterising_is_a_real_answer_and_still_produces_nothing(): void
    {
        $report = $this->withInput($this->report(), [
            'behaviour' => BehaviourRating::NotCharacterised->value,
            'attitude' => LearningAttitude::NotCharacterised->value,
        ]);

        $this->assertNull($this->bodyOf($report, SectionKey::BehaviourAttitude));
    }

    #[Test]
    public function behaviour_and_attitude_are_two_separate_sentences(): void
    {
        $report = $this->withInput($this->report(), [
            'behaviour' => BehaviourRating::Good->value,
            'attitude' => LearningAttitude::Irregular->value,
        ]);

        $body = (string) $this->bodyOf($report, SectionKey::BehaviourAttitude);

        $this->assertStringContainsString('O comportamento da turma foi, globalmente, bom.', $body);
        $this->assertStringContainsString('A atitude face às aprendizagens revelou-se irregular.', $body);
    }

    #[Test]
    public function flagged_indicators_are_grouped_by_the_direction_the_teacher_gave_them(): void
    {
        $report = $this->withInput($this->report(), [
            'indicators' => [
                ['indicator' => ComplementaryIndicator::Participation->value, 'standing' => IndicatorStanding::Strength->value],
                ['indicator' => ComplementaryIndicator::Collaboration->value, 'standing' => IndicatorStanding::Strength->value],
                ['indicator' => ComplementaryIndicator::Organisation->value, 'standing' => IndicatorStanding::ToImprove->value],
            ],
        ]);

        $body = (string) $this->bodyOf($report, SectionKey::BehaviourAttitude);

        $this->assertStringContainsString('Destacam-se positivamente a participação e a colaboração.', $body);
        $this->assertStringContainsString('Requer reforço a organização.', $body);
    }

    // ------------------------------------------------------------ difficulties

    #[Test]
    public function no_difficulty_is_ever_inferred_from_a_low_result(): void
    {
        $this->asTenant(fn () => StudentItemScore::query()
            ->where('result_state', ResultState::Assessed)
            ->update(['points_earned' => '0']));

        $report = $this->report();

        // The worst possible data, and still nothing: a difficulty exists
        // because somebody validated it (§13).
        $this->assertNull($this->bodyOf($report, SectionKey::Difficulties));
        $this->assertNull($this->bodyOf($report, SectionKey::ImprovementProposals));
    }

    #[Test]
    public function a_validated_difficulty_is_stated_and_may_quote_the_domain_the_teacher_associated(): void
    {
        $domain = $this->asTenant(fn () => Domain::query()
            ->where('subject_id', $this->schoolClass()->subject_id)
            ->orderBy('name')
            ->firstOrFail());

        $report = $this->withInput($this->report(), [
            'difficulties' => [[
                'code' => 'writing_planning',
                'label' => 'Planificação da escrita',
                'domain' => $domain->name,
                'note' => null,
                'strategies' => [],
            ]],
        ]);

        $body = (string) $this->bodyOf($report, SectionKey::Difficulties);

        // The label as the teacher chose it, not bent into the sentence.
        $this->assertStringContainsString('Planificação da escrita', $body);
        // A figure placed beside a judgement the teacher made — never a claim
        // that one caused the other.
        $this->assertStringNotContainsString('devido', mb_strtolower($body));
        $this->assertStringNotContainsString('por causa', mb_strtolower($body));
    }

    // ---------------------------------------------------------- the triple

    #[Test]
    public function a_proposal_names_the_difficulty_it_answers_and_the_objective_it_serves(): void
    {
        $report = $this->withInput($this->report(), [
            'difficulties' => [[
                'code' => 'writing_planning',
                'label' => 'Planificação da escrita',
                'domain' => null,
                'note' => null,
                'strategies' => [[
                    'code' => 'writing_planning_1',
                    'label' => 'Guiões de planificação prévia e revisão orientada',
                    'objective' => 'melhorar a organização, a coerência e a clareza textual',
                ]],
            ]],
        ]);

        $body = (string) $this->bodyOf($report, SectionKey::ImprovementProposals);

        $this->assertStringContainsString('Para a dificuldade «Planificação da escrita», propõe-se', $body);
        $this->assertStringContainsString('guiões de planificação prévia e revisão orientada', $body);
        $this->assertStringContainsString('com o objetivo de melhorar a organização', $body);
        // No stray space before the comma that joins the objective clause.
        $this->assertStringNotContainsString(' ,', $body);
    }

    #[Test]
    public function a_difficulty_with_no_chosen_strategy_produces_no_proposal(): void
    {
        $report = $this->withInput($this->report(), [
            'difficulties' => [[
                'code' => 'writing_planning',
                'label' => 'Planificação da escrita',
                'domain' => null,
                'note' => null,
                'strategies' => [],
            ]],
        ]);

        // The difficulty is stated. Nothing is proposed for it, because nothing
        // was chosen — the system never fills the gap (§15).
        $this->assertNotNull($this->bodyOf($report, SectionKey::Difficulties));
        $this->assertNull($this->bodyOf($report, SectionKey::ImprovementProposals));
    }

    #[Test]
    public function the_chosen_strategy_is_copied_so_rewording_the_library_never_rewrites_the_report(): void
    {
        $report = $this->asTenant(function (): Report {
            $report = $this->report();

            $report->update(['teacher_input' => [
                'difficulties' => app(ReportLibraryProvider::class)->resolveDifficulties([[
                    'code' => 'writing_planning',
                    'strategies' => [['code' => 'writing_planning_1']],
                ]]),
            ]]);

            app(ComposeReport::class)->generate($report);

            return $report->fresh() ?? $report;
        });

        $before = (string) $this->bodyOf($report, SectionKey::ImprovementProposals);
        $this->assertStringContainsString('guiões de planificação prévia', $before);

        // The library moves on.
        $this->asTenant(fn () => ReportLibraryEntry::withoutGlobalScope('organization')
            ->where('code', 'writing_planning_1')
            ->update(['label' => 'Outra coisa completamente diferente']));

        $this->asTenant(fn () => app(ComposeReport::class)->generate($report));

        $this->assertSame($before, $this->bodyOf($report, SectionKey::ImprovementProposals));
    }

    // ----------------------------------------------------------- naming rules

    #[Test]
    public function flagged_students_are_counted_but_not_named_without_explicit_permission(): void
    {
        $enrollment = $this->asTenant(fn () => $this->schoolClass()->enrollments()->orderBy('class_number')->firstOrFail());

        $report = $this->withInput(
            $this->report(),
            ['students_requiring_attention' => [['enrollment_id' => $enrollment->id, 'note' => 'Acompanhar a leitura.']]],
            nameStudents: false,
        );

        $body = (string) $this->bodyOf($report, SectionKey::StudentsRequiringAttention);

        $this->assertStringContainsString('Foi assinalado 1 aluno', $body);
        $this->assertStringContainsString('identificação individual não foi incluída', $body);

        $name = $this->asTenant(fn () => optional($enrollment->student->identity)->display_name);
        $this->assertNotNull($name);
        $this->assertStringNotContainsString($name, $body);
        // Nor does the name travel in the structured payload.
        $data = $this->asTenant(fn () => $report->sections()
            ->where('key', SectionKey::StudentsRequiringAttention->value)->firstOrFail()->data);
        $this->assertFalse($data['identified']);
        $this->assertArrayNotHasKey('students', $data);
    }

    #[Test]
    public function with_permission_the_names_and_the_teachers_notes_are_printed(): void
    {
        $enrollment = $this->asTenant(fn () => $this->schoolClass()->enrollments()->orderBy('class_number')->firstOrFail());
        $name = $this->asTenant(fn () => optional($enrollment->student->identity)->display_name);

        $report = $this->withInput(
            $this->report(),
            ['students_requiring_attention' => [['enrollment_id' => $enrollment->id, 'note' => 'Acompanhar a leitura.']]],
            nameStudents: true,
        );

        $body = (string) $this->bodyOf($report, SectionKey::StudentsRequiringAttention);

        $this->assertStringContainsString((string) $name, $body);
        $this->assertStringContainsString('Acompanhar a leitura.', $body);
    }

    #[Test]
    public function nobody_is_ever_flagged_automatically(): void
    {
        $this->asTenant(fn () => StudentItemScore::query()
            ->where('result_state', ResultState::Assessed)
            ->update(['points_earned' => '0']));

        $report = $this->report();

        // Every student at the floor, and still no list. A list of the lowest
        // results is not a list of students who need following (§57).
        $this->assertNull($this->bodyOf($report, SectionKey::StudentsRequiringAttention));
    }
}
