<?php

namespace Tests\Feature\SelfAssessment;

use App\Models\AcademicPeriod;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentTemplate;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\MigrateClassProfile;
use App\Services\Assessment\ProfileBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The self-assessment asks on the scale the class is ASSESSED on — the class's
 * own profile version, not the profile's newest one, and never a 1–5 assumed
 * by default (§15).
 *
 * Reproduces what was reported in production: a profile first graded in
 * «Percentagem (0 a 100)», a class associated with that version, the profile
 * later edited to «Escala 1 a 5» and the new version activated. The editor
 * shows 1–5; the class is still on the percentage version, because activating
 * a version never moves a class (§10.2). A percentage has no levels to choose
 * from, and the form used to offer a select holding nothing but «—».
 */
class SelfAssessmentScaleOfTheClassTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private string $enrollmentUlid;

    private AssessmentProfileVersion $percentageVersion;

    private AssessmentProfileVersion $levelVersion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        $this->inTenant(function (): void {
            $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $this->period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $this->enrollmentUlid = (string) $this->class->enrollments()->orderBy('class_number')->firstOrFail()->ulid;

            $builder = app(ProfileBuilder::class);
            $activate = app(ActivateProfileVersion::class);

            $profile = $builder->create(
                ['academic_year_id' => $this->class->academic_year_id, 'subject_id' => $this->class->subject_id, 'name' => 'Avaliação final'],
                Scale::where('name', 'Percentagem (0 a 100)')->firstOrFail()->id,
                [['name' => 'Conhecimento', 'weight' => 60], ['name' => 'Comportamento', 'weight' => 40]],
            );
            $this->percentageVersion = $activate->activate($profile->draftVersion(), $this->teacher);

            // Associated while the class has no decisions — the plain swap.
            $this->class->update(['assessment_profile_version_id' => $this->percentageVersion->id]);

            // The profile is then edited to 1–5 and the new version activated.
            $builder->update(
                AssessmentProfile::whereKey($profile->id)->firstOrFail(),
                ['name' => 'Avaliação final', 'academic_year_id' => $this->class->academic_year_id, 'subject_id' => $this->class->subject_id],
                Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
                [['name' => 'Conhecimento', 'weight' => 60], ['name' => 'Comportamento', 'weight' => 40]],
            );
            $this->levelVersion = $activate->activate(
                AssessmentProfile::whereKey($profile->id)->firstOrFail()->draftVersion(),
                $this->teacher,
            );
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

    private function formUrl(): string
    {
        return "/classes/{$this->class->ulid}/self-assessments/{$this->period->ulid}/{$this->enrollmentUlid}";
    }

    /**
     * @return array<string, mixed>
     */
    private function formPage(): array
    {
        $response = $this->actingAs($this->teacher)->get($this->formUrl());
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        /** @var array<string, mixed> $props */
        $props = $page['props'];

        return $props;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<array<string, mixed>>
     */
    private function scaleQuestions(array $props): array
    {
        /** @var list<array<string, mixed>> $questions */
        $questions = $props['questions'];

        return array_values(array_filter($questions, fn (array $question): bool => $question['answer_kind'] === 'scale'));
    }

    private function migrateToTheLevelVersion(): void
    {
        $this->inTenant(fn () => app(MigrateClassProfile::class)->migrate(
            SchoolClass::whereKey($this->class->id)->firstOrFail(),
            $this->levelVersion,
            'A turma passa a ser avaliada na escala de 1 a 5.',
            $this->teacher,
        ));
    }

    // ------------------------------------------------- a escala sem níveis

    #[Test]
    public function the_class_stays_on_the_version_it_was_associated_with_after_a_newer_one_is_activated(): void
    {
        // The premise of the report, pinned: the profile's active version is
        // 1–5, the class's is still the percentage one.
        $this->inTenant(function (): void {
            $class = SchoolClass::whereKey($this->class->id)->firstOrFail();

            $this->assertSame($this->percentageVersion->id, $class->assessment_profile_version_id);
            $this->assertSame('Percentagem (0 a 100)', $class->profileVersion?->scale->name);
            $this->assertSame($this->levelVersion->id, $class->profileVersion?->profile->current_version_id);
        });
    }

    #[Test]
    public function a_question_on_a_scale_without_levels_says_so_instead_of_offering_an_empty_choice(): void
    {
        $props = $this->formPage();
        $questions = $this->scaleQuestions($props);

        // Every domain, and the overall judgement — on the class's own scale.
        $this->assertCount(3, $questions);

        foreach ($questions as $question) {
            // Nothing to choose from, and nothing invented to fill the gap.
            $this->assertSame([], $question['levels']);
            // The form names the scale, so the empty list is explained rather
            // than looking like a fault.
            $this->assertSame('Percentagem (0 a 100)', $question['scale']);
        }

        // And the teacher is told which version and which scale the class is
        // actually assessed on — and that the profile has moved on since.
        $this->assertSame([
            'profile' => 'Avaliação final',
            'version' => 1,
            'scale' => 'Percentagem (0 a 100)',
            'scale_has_levels' => false,
            'current_version' => 2,
        ], $props['assessedBy']);
    }

    #[Test]
    public function a_level_of_another_scale_is_not_accepted_on_a_scale_without_levels(): void
    {
        $props = $this->formPage();
        $global = collect($this->scaleQuestions($props))->firstWhere('role', 'global');
        $this->assertNotNull($global);

        $four = $this->inTenant(fn (): int => Scale::where('name', 'Escala 1 a 5')->firstOrFail()->levels()->where('code', '4')->firstOrFail()->id);

        $this->actingAs($this->teacher)
            ->post($this->formUrl(), ['answers' => [$global['id'] => $four], 'texts' => []])
            ->assertRedirect();

        // A 4 «on a percentage» is not a value the class's scale has; the
        // answer is not stored as one.
        $this->inTenant(function () use ($global): void {
            $this->assertFalse(SelfAssessmentResponse::query()
                ->where('self_assessment_question_id', $global['id'])
                ->whereNotNull('scale_level_id')
                ->exists());
        });
    }

    // -------------------------------------- a turma muda de versão de perfil

    #[Test]
    public function a_template_nobody_answered_follows_the_class_to_its_new_version(): void
    {
        // Opened once on the percentage version — the template is created then.
        $before = $this->formPage();
        $this->assertSame([], $this->scaleQuestions($before)[0]['levels']);

        $this->migrateToTheLevelVersion();

        $after = $this->formPage();
        $questions = $this->scaleQuestions($after);

        $this->assertCount(3, $questions);

        foreach ($questions as $question) {
            $this->assertSame(['1', '2', '3', '4', '5'], array_column($question['levels'], 'code'));
            $this->assertSame('Escala 1 a 5', $question['scale']);
        }

        $global = collect($questions)->firstWhere('role', SelfAssessmentQuestionRole::Global->value);
        $this->assertNotNull($global);
        $this->assertSame('Nível que proponho para a minha avaliação neste período', $global['prompt']);

        // The template derived from the old version is retired, not deleted.
        $this->inTenant(function (): void {
            $templates = SelfAssessmentTemplate::where('class_id', $this->class->id)->orderBy('id')->get();

            $this->assertCount(2, $templates);
            $this->assertFalse($templates[0]->is_active);
            $this->assertSame($this->percentageVersion->id, $templates[0]->assessment_profile_version_id);
            $this->assertCount(7, $templates[0]->questions, 'as perguntas do modelo retirado ficam como estavam');
            $this->assertTrue($templates[1]->is_active);
            $this->assertSame($this->levelVersion->id, $templates[1]->assessment_profile_version_id);
        });
    }

    #[Test]
    public function after_the_migration_a_level_is_chosen_and_stored(): void
    {
        $this->formPage();
        $this->migrateToTheLevelVersion();

        $questions = $this->scaleQuestions($this->formPage());
        $global = collect($questions)->firstWhere('role', 'global');
        $domain = collect($questions)->firstWhere('domain', 'Comportamento');
        $this->assertNotNull($global);
        $this->assertNotNull($domain);

        $four = collect($global['levels'])->firstWhere('code', '4');
        $three = collect($domain['levels'])->firstWhere('code', '3');
        $this->assertNotNull($four);
        $this->assertNotNull($three);

        $this->actingAs($this->teacher)
            ->post($this->formUrl(), ['answers' => [$global['id'] => $four['id'], $domain['id'] => $three['id']], 'texts' => []])
            ->assertRedirect("/classes/{$this->class->ulid}/self-assessments/{$this->period->ulid}");

        $this->inTenant(function () use ($global, $domain, $four, $three): void {
            $responses = SelfAssessmentResponse::whereIn('self_assessment_question_id', [$global['id'], $domain['id']])
                ->get()->keyBy('self_assessment_question_id');

            $this->assertSame($four['id'], $responses[$global['id']]->scale_level_id);
            $this->assertSame($three['id'], $responses[$domain['id']]->scale_level_id);
            $this->assertSame('Escala 1 a 5', ScaleLevel::whereKey($four['id'])->firstOrFail()->scale->name);
        });

        // Shown back on the form as the level that was chosen.
        $reloaded = collect($this->scaleQuestions($this->formPage()))->keyBy('id');
        $this->assertSame($four['id'], $reloaded[$global['id']]['answer_level_id']);
        $this->assertSame($three['id'], $reloaded[$domain['id']]['answer_level_id']);
    }

    #[Test]
    public function a_template_with_answers_is_never_swapped_under_them(): void
    {
        // A written answer recorded on the percentage template.
        $rationale = collect($this->formPage()['questions'])->firstWhere('role', 'rationale');
        $this->assertNotNull($rationale);

        $this->actingAs($this->teacher)
            ->post($this->formUrl(), ['answers' => [], 'texts' => [$rationale['id'] => 'Estudei mais para os testes.']])
            ->assertRedirect();

        $this->migrateToTheLevelVersion();

        $after = $this->formPage();

        // Same template, same questions, the answer still where it was: what a
        // student already said is not reassigned to questions they never saw.
        $this->assertSame('Estudei mais para os testes.', collect($after['questions'])->firstWhere('id', $rationale['id'])['answer_text'] ?? null);

        $this->inTenant(function (): void {
            $templates = SelfAssessmentTemplate::where('class_id', $this->class->id)->get();

            $this->assertCount(1, $templates);
            $this->assertTrue($templates[0]->is_active);
            $this->assertSame(1, SelfAssessment::where('self_assessment_template_id', $templates[0]->id)->count());
        });
    }

    #[Test]
    public function a_class_already_on_a_scale_with_levels_is_left_exactly_as_it_was(): void
    {
        // The demo class on «Escala 1 a 5» from the start: one template, the
        // same one on every visit, five levels on every scale question.
        $this->inTenant(fn () => SchoolClass::whereKey($this->class->id)->firstOrFail()->update(['assessment_profile_version_id' => $this->levelVersion->id]));

        $first = $this->formPage();
        $second = $this->formPage();

        $this->assertSame(array_column($first['questions'], 'id'), array_column($second['questions'], 'id'));

        foreach ($this->scaleQuestions($second) as $question) {
            $this->assertCount(5, $question['levels']);
        }

        $this->assertTrue($second['assessedBy']['scale_has_levels']);
        $this->assertNull($second['assessedBy']['current_version'], 'a turma já está na versão ativa do perfil');
        $this->inTenant(fn () => $this->assertSame(1, SelfAssessmentTemplate::where('class_id', $this->class->id)->count()));
    }
}
