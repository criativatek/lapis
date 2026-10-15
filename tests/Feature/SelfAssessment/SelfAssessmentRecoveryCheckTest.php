<?php

namespace Tests\Feature\SelfAssessment;

use App\Models\AuditEvent;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentStatus;
use App\Models\SelfAssessmentTemplate;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\SelfAssessmentRecorder;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `lapis:self-assessment-recovery-check` — which recovery applies to 0.167.0
 * (docs/self-assessment-recovery.md). A code-only rollback to 0.166.0 is a full
 * recovery only while nothing exists that 0.166.0 cannot read: no number, no
 * student with two self-assessments in one period, no started new one.
 */
class SelfAssessmentRecoveryCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        $this->inTenant(function (): void {
            $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();

            $profile = app(ProfileBuilder::class)->create(
                ['academic_year_id' => $this->class->academic_year_id, 'subject_id' => $this->class->subject_id, 'name' => 'Avaliação final'],
                Scale::where('name', 'Escala 0 a 20')->firstOrFail()->id,
                [['name' => 'Conhecimento', 'weight' => 100]],
            );

            $this->class->update(['assessment_profile_version_id' => app(ActivateProfileVersion::class)->activate($profile->draftVersion(), $this->teacher)->id]);
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    #[Test]
    public function with_nothing_the_old_code_cannot_read_it_is_scenario_a(): void
    {
        $this->artisan('lapis:self-assessment-recovery-check')
            ->expectsOutputToContain('respostas numéricas: 0')
            ->expectsOutputToContain('Cenário a)')
            ->assertExitCode(0);
    }

    #[Test]
    public function one_numeric_answer_makes_it_scenario_b(): void
    {
        $this->inTenant(function (): void {
            $template = app(SelfAssessmentTemplateProvider::class)->forClass($this->class);
            $global = $template->questions->firstWhere('role', SelfAssessmentQuestionRole::Global);

            app(SelfAssessmentRecorder::class)->save(
                $this->class,
                $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail(),
                $this->class->enrollments()->orderBy('class_number')->firstOrFail(),
                ['numbers' => [$global->id => '16'], 'template' => $template->ulid],
                SelfAssessmentFilledBy::Student,
            );
        });

        $this->artisan('lapis:self-assessment-recovery-check')
            ->expectsOutputToContain('respostas numéricas: 1')
            ->expectsOutputToContain('Cenário b)')
            ->assertExitCode(1);
    }

    #[Test]
    public function two_self_assessments_of_one_student_in_one_period_make_it_scenario_b(): void
    {
        $this->inTenant(function (): void {
            $enrollment = $this->class->enrollments()->orderBy('class_number')->firstOrFail();
            $period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            foreach ([false, true] as $active) {
                $template = SelfAssessmentTemplate::create([
                    'assessment_profile_version_id' => $this->class->assessment_profile_version_id,
                    'class_id' => $this->class->id,
                    'name' => 'Autoavaliação',
                    'is_active' => $active,
                ]);

                SelfAssessment::create([
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $period->id,
                    'self_assessment_template_id' => $template->id,
                    'status' => SelfAssessmentStatus::Submitted,
                    'filled_by' => SelfAssessmentFilledBy::Student,
                    'submitted_at' => now(),
                ]);
            }
        });

        $this->artisan('lapis:self-assessment-recovery-check')
            ->expectsOutputToContain('alunos com duas autoavaliações num período: 1')
            ->expectsOutputToContain('Cenário b)')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_started_new_self_assessment_makes_it_scenario_b(): void
    {
        $this->inTenant(fn () => AuditEvent::create(['event' => 'self_assessment.renewed', 'created_at' => now()]));

        $this->artisan('lapis:self-assessment-recovery-check')
            ->expectsOutputToContain('novas autoavaliações iniciadas: 1')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_says_whether_the_read_only_mode_is_on(): void
    {
        $this->artisan('lapis:self-assessment-recovery-check')->expectsOutputToContain('modo só de leitura: desligado');

        config(['lapis.self_assessment.read_only' => true]);

        $this->artisan('lapis:self-assessment-recovery-check')->expectsOutputToContain('modo só de leitura: ligado (restauros de backup suspensos)');
    }
}
