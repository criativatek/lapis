<?php

namespace Tests\Feature\Assessment;

use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
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

            // One scale question per domain of the class's profile version.
            $this->assertSame($class->profileVersion->domains()->count(), $template->questions->count());
            $this->assertSame('scale', $template->questions->first()->answer_kind);
        });
    }

    #[Test]
    public function the_edit_view_shows_the_calculated_result_beside_each_domain(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->get("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}")->assertInertia(
            fn ($page) => $page
                ->component('self-assessments/Edit')
                ->where('questions', fn ($questions) => count($questions) > 0),
        );
    }

    #[Test]
    public function saving_records_a_submitted_interview_with_responses(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $questionId = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return app(SelfAssessmentTemplateProvider::class)->forClass($class)->questions->first()->id;
        });
        $level = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => Scale::where('name', 'Escala 1 a 5')->firstOrFail()->levels()->where('code', '4')->firstOrFail()->id);

        $this->actingAs($teacher)->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", [
            'reflection' => 'Sinto que melhorei na leitura.',
            'answers' => [$questionId => $level],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($level): void {
            $selfAssessment = SelfAssessment::with('responses')->firstOrFail();
            $this->assertSame(SelfAssessmentStatus::Submitted, $selfAssessment->status);
            $this->assertSame(SelfAssessmentFilledBy::TeacherInterview, $selfAssessment->filled_by);
            $this->assertSame('Sinto que melhorei na leitura.', $selfAssessment->reflection);
            $this->assertSame($level, $selfAssessment->responses->first()->scale_level_id);
        });
    }

    #[Test]
    public function resubmitting_updates_the_same_self_assessment(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", ['reflection' => 'Primeira.']);
        $this->actingAs($teacher)->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", ['reflection' => 'Segunda.']);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(1, SelfAssessment::count());
            $this->assertSame('Segunda.', SelfAssessment::firstOrFail()->reflection);
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
