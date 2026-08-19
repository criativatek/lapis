<?php

namespace Tests\Feature\Progress;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentStatus;
use App\Models\SelfAssessmentTemplate;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Support\Assessment\AssessmentCutoff;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The progression is built once and read twice (§8, §9, §12, §21).
 *
 * THE OPTIMISATION IS ONLY ALLOWED TO REMOVE WORK. Two kinds of test say so
 * here, and they are different kinds on purpose:
 *
 *   equivalence   the same call with and without a supplied progression must
 *                 produce the SAME array, assertSame-identical, in every
 *                 scenario the module has — continuous and standalone readings,
 *                 late entry, no result, decided grades, self-assessments,
 *                 empty domains, photographs, partial coverage.
 *
 *   structure     the page must build the progression EXACTLY ONCE. Proved by
 *                 counting calls through a decorator resolved from the
 *                 container, not by watching queries: a query count is a
 *                 consequence and would keep passing if somebody reintroduced
 *                 the second pass while trimming queries elsewhere.
 */
class ProgressionReuseTest extends TestCase
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
    }

    // ------------------------------------------------------------- andaimes

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

    private function enrollment(): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => Enrollment::query()
            ->where('class_id', $this->schoolClass()->getKey())
            ->orderBy('class_number')
            ->firstOrFail());
    }

    private function period(int $sequence = 1): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    /**
     * The same statistics, built both ways.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function bothWays(?AcademicPeriod $period = null, ?AssessmentCutoff $cutoff = null): array
    {
        return $this->asTenant(function () use ($period, $cutoff): array {
            $class = $this->schoolClass();
            $statistics = app(BuildClassStatistics::class);

            $built = $statistics->for($class, $period, $cutoff);

            $progression = app(BuildResultsProgression::class)->for($class, $cutoff);
            $reused = $statistics->for($class, $period, $cutoff, progression: $progression);

            return [$built, $reused];
        });
    }

    private function assertIdenticalBothWays(string $scenario, ?AcademicPeriod $period = null, ?AssessmentCutoff $cutoff = null): void
    {
        [$built, $reused] = $this->bothWays($period, $cutoff);

        // assertSame on the whole structure, not a spot check on a few figures:
        // an optimisation that changed one null into a zero somewhere deep in a
        // domain row would pass any narrower assertion.
        $this->assertSame($built, $reused, "The statistics differ when the progression is reused ({$scenario}).");
    }

    // ------------------------------------------------- §8, §12 equivalência

    #[Test]
    public function scenario_a_a_normal_student_across_every_period(): void
    {
        $this->assertIdenticalBothWays('normal');
    }

    #[Test]
    public function scenario_b_and_c_each_period_read_on_its_own_terms(): void
    {
        // Both readings, asked for by asking about both periods: the canonical
        // scope of the first is standalone and of the second is accumulated,
        // which is what makes these two different questions (§10).
        $this->assertIdenticalBothWays('primeiro período', $this->period(1));
        $this->assertIdenticalBothWays('segundo período', $this->period(2));
    }

    #[Test]
    public function scenario_d_late_entry(): void
    {
        $enrollment = $this->enrollment();
        $last = $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->get()->last());

        $this->asTenant(function () use ($enrollment, $last): void {
            $enrollment->update(['enrolled_on' => $last->starts_on, 'is_late_entry' => true]);
        });

        $this->assertIdenticalBothWays('ingresso tardio');
    }

    #[Test]
    public function scenario_e_a_period_with_no_result_at_all(): void
    {
        $this->asTenant(fn () => DB::table('student_item_scores')->delete());

        $this->assertIdenticalBothWays('sem resultados');
        $this->assertIdenticalBothWays('sem resultados, primeiro período', $this->period(1));
    }

    #[Test]
    public function scenario_f_confirmed_and_published_classifications(): void
    {
        $this->decide($this->enrollment(), $this->period(1), ClassificationStatus::Confirmed);
        $this->decide($this->enrollment(), $this->period(2), ClassificationStatus::Published);

        $this->assertIdenticalBothWays('classificações decididas');
        $this->assertIdenticalBothWays('classificações decididas, segundo período', $this->period(2));
    }

    #[Test]
    public function scenario_g_a_self_assessment(): void
    {
        $this->answerSelfAssessment($this->enrollment(), $this->period(1));

        $this->assertIdenticalBothWays('autoavaliação');
    }

    #[Test]
    public function scenario_h_a_domain_with_no_data(): void
    {
        // One domain emptied: every score allocated to it removed, and the rest
        // left alone. The domain keeps its row and loses its figures.
        $this->asTenant(function (): void {
            $domainId = DB::table('domains')->orderBy('id')->value('id');

            $itemIds = DB::table('item_domain_allocations')
                ->where('domain_id', $domainId)
                ->pluck('instrument_item_id');

            DB::table('student_item_scores')->whereIn('instrument_item_id', $itemIds)->delete();
        });

        $this->assertIdenticalBothWays('domínio sem dados');
    }

    #[Test]
    public function scenario_i_a_photograph_exists(): void
    {
        $this->asTenant(fn () => app(CaptureInterimAssessment::class)->capture(
            $this->schoolClass(),
            $this->period(1),
            Carbon::parse('2026-11-15'),
            $this->teacher,
            ['name' => 'Meio do 1.º Período'],
        ));

        $this->assertIdenticalBothWays('com intercalar');
    }

    #[Test]
    public function scenario_j_partial_coverage_under_a_cutoff(): void
    {
        // A cutoff is the case the context stamp exists for: the progression and
        // the statistics have to be talking about the same day.
        $cutoff = AssessmentCutoff::on('2026-11-15');

        $this->assertIdenticalBothWays('com corte temporal', null, $cutoff);
        $this->assertIdenticalBothWays('com corte temporal, primeiro período', $this->period(1), $cutoff);
    }

    #[Test]
    public function the_whole_student_progress_payload_is_unchanged_by_the_reuse(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->decide($enrollment, $this->period(1), ClassificationStatus::Confirmed);
        $this->answerSelfAssessment($enrollment, $this->period(1));

        // The page, built the way the module does it now — on a reused
        // progression.
        $page = $this->asTenant(fn (): array => app(BuildStudentProgress::class)->for($class, $enrollment));

        // And a statistics built entirely on its own, which is the way every
        // other caller still builds one.
        $independent = $this->asTenant(fn (): array => app(BuildClassStatistics::class)->for($class));

        // The page agrees with an INDEPENDENT build on everything the
        // statistics layer feeds it. If the reuse had changed anything, these
        // are where it would show.
        $this->assertSame($independent['primary']['kind'], $page['reading']['canonical']);

        // The class figure the page shows is the one for the reading it is
        // showing — «class_average» is this period's own work and
        // «accumulated_average» is the year's, and the page does not mix them.
        $this->assertSame(
            $independent['summary'][$page['reading']['kind'] === 'accumulated' ? 'accumulated_average' : 'class_average'],
            $page['classComparison'] === null ? null : $page['classComparison']['class'],
        );
        $this->assertSame($independent['period_series'], $page['classComparison']['series'] ?? []);
        $this->assertNotEmpty($page['moments']);

        // And it is the same page twice: no state was left behind by the first
        // build for the second to pick up (§16 — reuse, never a cache).
        $again = $this->asTenant(fn (): array => app(BuildStudentProgress::class)->for($class, $enrollment));
        $this->assertSame($page, $again);
    }

    // ------------------------------------------------- §9, §21 chamada única

    #[Test]
    public function student_progress_builds_the_progression_exactly_once(): void
    {
        $counter = $this->countProgressionCalls();

        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(fn (): array => app(BuildStudentProgress::class)->for($class, $enrollment));

        // THE TEST THIS TASK EXISTS FOR. It fails the moment somebody removes
        // the parameter, or stops passing it, and lets the statistics walk the
        // class's year a second time.
        $this->assertSame(1, $counter->calls, 'Evolução do Aluno built the results progression more than once.');
    }

    #[Test]
    public function the_page_builds_the_progression_exactly_once(): void
    {
        $counter = $this->countProgressionCalls();

        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk();

        $this->assertSame(1, $counter->calls);
    }

    #[Test]
    public function statistics_on_its_own_still_builds_its_own_progression(): void
    {
        $counter = $this->countProgressionCalls();

        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica")
            ->assertOk();

        // Backward compatibility, asserted rather than assumed: nothing about
        // Estatística changed, and it still asks for what it needs (§4).
        $this->assertSame(1, $counter->calls);
    }

    // ------------------------------------------------------- §5 o contexto

    #[Test]
    public function a_progression_from_another_cutoff_is_refused_rather_than_used(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->asTenant(function (): void {
            $class = $this->schoolClass();

            // Built «até 15 de novembro», handed to a build that says «hoje».
            $photograph = app(BuildResultsProgression::class)
                ->for($class, AssessmentCutoff::on('2026-11-15'));

            app(BuildClassStatistics::class)->for($class, progression: $photograph);
        });
    }

    #[Test]
    public function a_progression_from_another_class_is_refused_rather_than_used(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->asTenant(function (): void {
            $class = $this->schoolClass();

            $other = SchoolClass::factory()->create([
                'organization_id' => $this->organization->getKey(),
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
                'label' => '7.º B',
            ]);

            $theirs = app(BuildResultsProgression::class)->for($other);

            app(BuildClassStatistics::class)->for($class, progression: $theirs);
        });
    }

    #[Test]
    public function a_progression_says_what_it_was_built_for(): void
    {
        $class = $this->schoolClass();

        $open = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($class));
        $cut = $this->asTenant(fn (): array => app(BuildResultsProgression::class)
            ->for($class, AssessmentCutoff::on('2026-11-15')));

        $this->assertSame(['class_id' => (int) $class->getKey(), 'cutoff' => null], $open['context']);
        $this->assertSame(['class_id' => (int) $class->getKey(), 'cutoff' => '2026-11-15'], $cut['context']);
    }

    // -------------------------------------------------------------- suporte

    /**
     * A decorator around the real service that counts how often it is asked.
     *
     * Bound into the container so every consumer — the read model, the
     * statistics, the controller — gets the same instance. It delegates
     * completely: the numbers under test are the real ones, and only the
     * bookkeeping is added.
     */
    private function countProgressionCalls(): object
    {
        $counter = new class
        {
            public int $calls = 0;
        };

        $real = app(BuildResultsProgression::class);

        $this->app->instance(BuildResultsProgression::class, new class($real, $counter) extends BuildResultsProgression
        {
            public function __construct(
                protected BuildResultsProgression $inner,
                protected object $counter,
            ) {}

            public function for(SchoolClass $class, ?AssessmentCutoff $cutoff = null): array
            {
                $this->counter->calls++;

                return $this->inner->for($class, $cutoff);
            }
        });

        return $counter;
    }

    private function answerSelfAssessment(Enrollment $enrollment, AcademicPeriod $period): void
    {
        $this->asTenant(function () use ($enrollment, $period): void {
            $class = $this->schoolClass();
            $scale = $class->profileVersion->scale;
            $level = $scale->levels()->get()->last();

            $template = SelfAssessmentTemplate::create([
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'class_id' => $class->getKey(),
                'name' => 'Autoavaliação de teste',
                'is_active' => true,
            ]);

            $question = SelfAssessmentQuestion::create([
                'self_assessment_template_id' => $template->getKey(),
                'domain_id' => null,
                'role' => SelfAssessmentQuestionRole::Global,
                'prompt' => 'Como avalias o teu desempenho neste período?',
                'answer_kind' => 'scale',
                'scale_id' => $scale->getKey(),
                'sequence' => 1,
            ]);

            $assessment = SelfAssessment::create([
                'enrollment_id' => $enrollment->getKey(),
                'academic_period_id' => $period->getKey(),
                'self_assessment_template_id' => $template->getKey(),
                'status' => SelfAssessmentStatus::Submitted,
                'filled_by' => SelfAssessmentFilledBy::Student,
                'submitted_at' => now()->subDays(2),
            ]);

            SelfAssessmentResponse::create([
                'self_assessment_id' => $assessment->getKey(),
                'self_assessment_question_id' => $question->getKey(),
                'scale_level_id' => $level->getKey(),
            ]);
        });
    }

    private function decide(Enrollment $enrollment, AcademicPeriod $period, ClassificationStatus $status): void
    {
        $this->asTenant(function () use ($enrollment, $period, $status): void {
            $class = $this->schoolClass();
            $level = $class->profileVersion->scale->levels()->get()->last();

            Classification::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('academic_period_id', $period->getKey())
                ->delete();

            Classification::create([
                'enrollment_id' => $enrollment->getKey(),
                'academic_period_id' => $period->getKey(),
                'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'status' => $status,
                'proposed_scale_level_id' => $level->id,
                'proposed_normalized_value' => '80.000000',
                'final_scale_level_id' => $level->id,
                'confirmed_by' => $this->teacher->getKey(),
                'confirmed_at' => now()->subDay(),
                'published_at' => $status === ClassificationStatus::Published ? now()->subDay() : null,
            ]);
        });
    }
}
