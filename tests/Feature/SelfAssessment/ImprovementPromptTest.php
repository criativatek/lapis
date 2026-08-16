<?php

namespace Tests\Feature\SelfAssessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\SchoolClass;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\User;
use App\Services\Assessment\SelfAssessmentRecorder;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Support\Assessment\ImprovementPrompt;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «O que preciso de melhorar no próximo…» — worded for the calendar the class
 * actually has.
 *
 * A year of two semesters has no «próximo período», and the last moment of any
 * year has no next anything. Neither the number of periods nor their names is
 * known here: the next one is the next by sequence, and what it is called comes
 * from its own kind.
 */
class ImprovementPromptTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORED = 'O que preciso de melhorar no próximo período?';

    private function period(AcademicPeriodKind $kind): AcademicPeriod
    {
        return new AcademicPeriod(['kind' => $kind->value]);
    }

    // ------------------------------------------------- 1. o que se segue

    #[Test]
    public function a_year_of_semesters_asks_about_the_next_semester(): void
    {
        $this->assertSame(
            'O que preciso de melhorar no próximo semestre?',
            ImprovementPrompt::forPeriod(self::AUTHORED, $this->period(AcademicPeriodKind::Semester)),
        );
    }

    #[Test]
    public function a_year_of_terms_asks_about_the_next_term(): void
    {
        $this->assertSame(
            'O que preciso de melhorar no próximo período?',
            ImprovementPrompt::forPeriod(self::AUTHORED, $this->period(AcademicPeriodKind::Term)),
        );
    }

    #[Test]
    public function any_other_shape_of_period_is_named_by_its_own_kind(): void
    {
        // Nothing here knows about semesters and terms in particular: whatever a
        // school configures is what the student is asked about.
        $this->assertSame(
            'O que preciso de melhorar no próximo trimestre?',
            ImprovementPrompt::forPeriod(self::AUTHORED, $this->period(AcademicPeriodKind::Trimester)),
        );
        $this->assertSame(
            'O que preciso de melhorar no próximo módulo?',
            ImprovementPrompt::forPeriod(self::AUTHORED, $this->period(AcademicPeriodKind::Module)),
        );
    }

    #[Test]
    public function the_last_moment_of_the_year_points_at_nothing_that_does_not_exist(): void
    {
        // The 2.º Semestre, the 3.º Período, or whatever the last one is: there
        // is no next one to name, and the question says so.
        $this->assertSame(
            'O que preciso de continuar a melhorar?',
            ImprovementPrompt::forPeriod(self::AUTHORED, null),
        );
    }

    #[Test]
    public function a_prompt_a_teacher_wrote_is_left_exactly_as_they_wrote_it(): void
    {
        $theirs = 'E agora, o que vais treinar em casa?';

        $this->assertSame($theirs, ImprovementPrompt::forPeriod($theirs, $this->period(AcademicPeriodKind::Semester)));
        $this->assertSame($theirs, ImprovementPrompt::forPeriod($theirs, null));
    }

    // ------------------------------- 2. detetado pela sequência, na BD

    #[Test]
    public function the_next_period_is_found_by_sequence_and_never_by_counting(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $periods = $class->academicYear->periods()->orderBy('sequence')->get();

            $this->assertGreaterThan(1, $periods->count());

            // Every period but the last has a successor…
            foreach ($periods->slice(0, -1) as $period) {
                $next = ImprovementPrompt::nextAfter($period);

                $this->assertNotNull($next);
                $this->assertGreaterThan($period->sequence, $next->sequence);
            }

            // …and the last has none, whichever one that turns out to be.
            $this->assertNull(ImprovementPrompt::nextAfter($periods->last()));
        });
    }

    // -------------------------------------- 3. o que o formulário mostra

    /**
     * @return array<string, string> prompt of the improvement question, per period label
     */
    private function promptsByPeriod(User $teacher): array
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $enrollment = $class->enrollments()->orderBy('class_number')->firstOrFail();
            $prompts = [];

            foreach ($class->academicYear->periods()->orderBy('sequence')->get() as $period) {
                $props = app(SelfAssessmentRecorder::class)->formProps($class, $period, $enrollment);

                foreach ($props['questions'] as $question) {
                    if ($question['role'] === SelfAssessmentQuestionRole::Improvement->value) {
                        $prompts[$period->label] = $question['prompt'];
                    }
                }
            }

            return $prompts;
        });
    }

    #[Test]
    public function the_form_asks_each_period_in_its_own_terms(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        $prompts = $this->promptsByPeriod($teacher);

        $this->assertSame('O que preciso de melhorar no próximo semestre?', $prompts['1.º Semestre']);
        $this->assertSame('O que preciso de continuar a melhorar?', $prompts['2.º Semestre']);
    }

    #[Test]
    public function the_students_own_form_shows_exactly_the_same_question(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        // Both forms are built by the same recorder and rendered by the same
        // component, so there is nowhere for them to diverge (§5).
        $recorder = (string) file_get_contents(app_path('Services/Assessment/SelfAssessmentRecorder.php'));
        $this->assertStringContainsString('ImprovementPrompt::forPeriod', $recorder);

        foreach (['Edit', 'PublicEdit'] as $page) {
            $source = (string) file_get_contents(resource_path("js/pages/self-assessments/{$page}.vue"));

            $this->assertStringContainsString('SelfAssessmentBlocks', $source);
            // Neither page words the question itself.
            $this->assertStringNotContainsString('melhorar', $source);
        }
    }

    #[Test]
    public function the_question_is_still_identified_by_its_role_and_its_answers_are_untouched(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $template = app(SelfAssessmentTemplateProvider::class)->forClass($class);
            $question = $template->questions->firstWhere('role', SelfAssessmentQuestionRole::Improvement);

            // The wording travels to the screen; the question in the table keeps
            // the sentence it was authored with, and its role is what says which
            // question it is.
            $this->assertNotNull($question);
            $this->assertSame(self::AUTHORED, $question->prompt);
            $this->assertSame('text', $question->answer_kind);
        });
    }
}
