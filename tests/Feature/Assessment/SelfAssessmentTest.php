<?php

namespace Tests\Feature\Assessment;

use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentStatus;
use App\Models\User;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Self-assessment (§15): a domain-based reflection filled in interview, compared
 * with the calculated grade but never part of it.
 */
class SelfAssessmentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{string, string, string} class ulid, period ulid, enrollment ulid */
    private function seedContext(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $enrollment = $class->enrollments()->orderBy('class_number')->first();

            return [$class->ulid, $period->ulid, $enrollment->ulid];
        });
    }

    #[Test]
    public function the_template_is_derived_from_the_class_domains(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $template = app(SelfAssessmentTemplateProvider::class)->forClass($class);

            $domains = $class->profileVersion->domains()->count();

            // BLOCO A — one scale question per domain, identified by the domain
            // and carrying no role, plus the student's own overall judgement.
            $perDomain = $template->questions->whereNotNull('domain_id');
            $this->assertCount($domains, $perDomain);
            $this->assertSame('scale', $perDomain->first()->answer_kind);
            $this->assertTrue($perDomain->every(fn ($question): bool => $question->role === null));

            // The overall judgement is a question the student ANSWERS, on the
            // same scale — never the average of the ones above it.
            $global = $template->questions->firstWhere('role', SelfAssessmentQuestionRole::Global);
            $this->assertNotNull($global);
            $this->assertSame('scale', $global->answer_kind);
            $this->assertNull($global->domain_id);

            // BLOCOS B e C — reflection, then the work itself. Written, and
            // outside every calculation.
            foreach ([
                SelfAssessmentQuestionRole::Rationale,
                SelfAssessmentQuestionRole::Improvement,
                SelfAssessmentQuestionRole::Liked,
                SelfAssessmentQuestionRole::Struggled,
            ] as $role) {
                $question = $template->questions->firstWhere('role', $role);

                $this->assertNotNull($question, "falta a pergunta {$role->value}");
                $this->assertSame('text', $question->answer_kind);
                $this->assertNull($question->domain_id);
            }

            $this->assertSame($domains + 5, $template->questions->count());

            // Every question is identified exactly one way: by its domain, or by
            // its role. Never both, never neither.
            foreach ($template->questions as $question) {
                $this->assertTrue($question->isCoherent(), "pergunta incoerente: {$question->prompt}");
            }
        });
    }

    #[Test]
    public function the_edit_view_never_shows_the_student_a_calculated_result(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->get("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}")->assertInertia(
            fn ($page) => $page
                ->component('self-assessments/Edit')
                ->where('questions', fn ($questions) => count($questions) > 0)
                // No percentage, no proposal, no assigned level — the page is
                // collecting a judgement, and a figure beside the question is a
                // figure to agree with (§3).
                ->where('questions', fn ($questions) => collect($questions)->every(
                    fn ($question) => ! array_key_exists('calculated', $question),
                )),
        );
    }

    #[Test]
    public function saving_records_a_submitted_interview_with_responses(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        [$questionId, $rationaleId] = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();
            $questions = app(SelfAssessmentTemplateProvider::class)->forClass($class)->questions;

            return [
                $questions->firstWhere('domain_id', '!=', null)->id,
                $questions->firstWhere('role', SelfAssessmentQuestionRole::Rationale)->id,
            ];
        });
        $level = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => Scale::where('name', 'Escala 1 a 5')->firstOrFail()->levels()->where('code', '4')->firstOrFail()->id);

        $this->actingAs($teacher)->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", [
            'answers' => [$questionId => $level],
            'texts' => [$rationaleId => 'Sinto que melhorei na leitura.'],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($level, $questionId, $rationaleId): void {
            $selfAssessment = SelfAssessment::with('responses')->firstOrFail();
            $this->assertSame(SelfAssessmentStatus::Submitted, $selfAssessment->status);
            $this->assertSame(SelfAssessmentFilledBy::TeacherInterview, $selfAssessment->filled_by);

            $responses = $selfAssessment->responses->keyBy('self_assessment_question_id');
            $this->assertSame($level, $responses[$questionId]->scale_level_id);
            // The written answer against its own question, not merged into a
            // single field somewhere (§10).
            $this->assertSame('Sinto que melhorei na leitura.', $responses[$rationaleId]->text_value);
            $this->assertNull($responses[$rationaleId]->scale_level_id);
        });
    }

    #[Test]
    public function resubmitting_updates_the_same_self_assessment(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $improvementId = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return app(SelfAssessmentTemplateProvider::class)->forClass($class)
                ->questions->firstWhere('role', SelfAssessmentQuestionRole::Improvement)->id;
        });

        $this->actingAs($teacher)->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", ['texts' => [$improvementId => 'Primeira.']]);
        $this->actingAs($teacher)->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", ['texts' => [$improvementId => 'Segunda.']]);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($improvementId): void {
            $this->assertSame(1, SelfAssessment::count());
            $selfAssessment = SelfAssessment::with('responses')->firstOrFail();
            $this->assertCount(1, $selfAssessment->responses);
            $this->assertSame('Segunda.', $selfAssessment->responses->firstWhere('self_assessment_question_id', $improvementId)->text_value);
        });
    }

    #[Test]
    public function an_empty_form_is_a_valid_submission(): void
    {
        // The two questions about the work are optional by design, and nothing
        // else is mandatory either: an unanswered question stays unanswered
        // rather than blocking the student (§12).
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $questions = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return app(SelfAssessmentTemplateProvider::class)->forClass($class)->questions;
        });

        $answers = [];
        $texts = [];

        foreach ($questions as $question) {
            if ($question->answer_kind === 'scale') {
                $answers[$question->id] = null;

                continue;
            }

            $texts[$question->id] = '';
        }

        $this->actingAs($teacher)
            ->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", ['answers' => $answers, 'texts' => $texts])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $selfAssessment = SelfAssessment::with('responses')->firstOrFail();

            // Recorded, and empty. Nothing was invented to fill it.
            $this->assertTrue($selfAssessment->responses->every(
                fn ($response): bool => $response->scale_level_id === null && $response->text_value === null,
            ));
        });
    }

    #[Test]
    public function another_organization_cannot_open_the_self_assessment(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}")->assertNotFound();
    }
}
