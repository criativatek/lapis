<?php

namespace Tests\Feature\SelfAssessment;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentTemplate;
use App\Models\User;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The form the student actually fills in (§15).
 *
 * Three blocks, in order: what they think of their own performance, why, and
 * what the period was like to work through. Every question is identified either
 * by the domain it is about or by its stated role — never by its wording, and
 * never by where it happens to sit.
 *
 * And nothing calculated reaches the page. The comparison between what the
 * student says and what the evidence says is the point of the exercise, and it
 * is made afterwards, in Resultados — a percentage shown while they are still
 * deciding is a number to agree with.
 */
class SelfAssessmentFormTest extends TestCase
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

    private function teacher(): User
    {
        return User::where('email', 'ana.martins@lapis.test')->firstOrFail();
    }

    /**
     * @return Collection<int, SelfAssessmentQuestion>
     */
    private function questionsOf(string $classUlid): Collection
    {
        return app(CurrentOrganization::class)->runFor($this->teacher()->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return app(SelfAssessmentTemplateProvider::class)->forClass($class)->questions;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formQuestions(string $classUlid, string $periodUlid, string $enrollmentUlid): array
    {
        $response = $this->actingAs($this->teacher())
            ->get("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}");

        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        /** @var list<array<string, mixed>> $questions */
        $questions = $page['props']['questions'];

        return $questions;
    }

    // ------------------------------------------------------------ 1. os blocos

    #[Test]
    public function the_form_is_read_in_three_blocks(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $questions = $this->formQuestions($classUlid, $periodUlid, $enrollmentUlid);
        $blocks = array_values(array_unique(array_column($questions, 'block')));

        // In this order, and with nothing left over: a question identified
        // neither by a domain nor by a role would land in a fourth group.
        $this->assertSame(['performance', 'reflection', 'work'], $blocks);
    }

    #[Test]
    public function the_performance_block_is_the_real_domains_and_then_the_overall_judgement(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $performance = array_values(array_filter(
            $this->formQuestions($classUlid, $periodUlid, $enrollmentUlid),
            fn (array $question): bool => $question['block'] === 'performance',
        ));

        $domainCount = app(CurrentOrganization::class)->runFor(
            $this->teacher()->personalOrganization(),
            fn (): int => SchoolClass::where('ulid', $classUlid)->firstOrFail()->profileVersion->domains()->count(),
        );

        // As many as the profile version has — never a fixed five.
        $this->assertCount($domainCount + 1, $performance);

        foreach (array_slice($performance, 0, $domainCount) as $question) {
            $this->assertNull($question['role'], 'uma pergunta de domínio não tem papel');
            $this->assertNotNull($question['domain']);
            $this->assertSame('scale', $question['answer_kind']);
        }

        // The overall judgement closes the block, after the parts it is a
        // judgement about.
        $global = $performance[$domainCount];
        $this->assertSame('global', $global['role']);
        $this->assertNull($global['domain']);
        $this->assertSame('scale', $global['answer_kind']);
        $this->assertNotEmpty($global['levels'], 'a global é respondida na escala do contexto');
    }

    #[Test]
    public function the_two_reflections_are_two_questions_and_not_one_box(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $questions = collect($this->formQuestions($classUlid, $periodUlid, $enrollmentUlid));

        $reflection = $questions->where('block', 'reflection')->values();
        $this->assertSame(['rationale', 'improvement'], $reflection->pluck('role')->all());

        $work = $questions->where('block', 'work')->values();
        $this->assertSame(['liked', 'struggled'], $work->pluck('role')->all());

        // Each one written on its own, with its own prompt and its own answer.
        foreach ($reflection->concat($work) as $question) {
            $this->assertSame('text', $question['answer_kind']);
            $this->assertNotSame('', trim((string) $question['prompt']));
        }
    }

    // -------------------------------------------------- 1b. a voz do formulário

    #[Test]
    public function every_generated_prompt_is_written_in_the_students_own_voice(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $questions = collect($this->formQuestions($classUlid, $periodUlid, $enrollmentUlid));
        $byRole = $questions->keyBy(fn (array $question): string => (string) ($question['role'] ?? 'domain'));

        foreach ($questions->whereNull('role') as $question) {
            // Built from the domain the question points at, whatever it is
            // called — never from a list of subject names.
            $this->assertSame("Como avalio o meu desempenho em {$question['domain']}?", $question['prompt']);
        }

        $this->assertSame('Nível que proponho para a minha avaliação neste período', $byRole['global']['prompt']);
        $this->assertSame('Porque proponho este nível?', $byRole['rationale']['prompt']);
        $this->assertSame('O que preciso de melhorar no próximo período?', $byRole['improvement']['prompt']);

        // Already the student's voice, and left alone.
        $this->assertSame('Atividade de que mais gostei', $byRole['liked']['prompt']);
        $this->assertSame('Atividade em que senti mais dificuldades', $byRole['struggled']['prompt']);

        // Nothing left addressing the student from outside.
        foreach ($questions as $question) {
            foreach (['te avalias', 'propões', 'precisas', 'a tua ', 'o teu '] as $secondPerson) {
                $this->assertStringNotContainsString($secondPerson, (string) $question['prompt']);
            }
        }
    }

    #[Test]
    public function the_wording_follows_the_scale_and_is_never_fixed_to_levels(): void
    {
        $provider = app(SelfAssessmentTemplateProvider::class);

        $global = new ReflectionMethod($provider, 'globalPrompt');
        $written = new ReflectionMethod($provider, 'writtenPrompts');

        $levels = new Scale(['name' => 'Escala de níveis', 'kind' => 'level', 'min_value' => '1', 'max_value' => '5']);
        $numeric = new Scale(['name' => 'Escala numérica', 'kind' => 'numeric', 'min_value' => '0', 'max_value' => '20']);

        // Basic education proposes a NÍVEL; a numeric scale a CLASSIFICAÇÃO.
        $this->assertSame('Nível que proponho para a minha avaliação neste período', $global->invoke($provider, $levels));
        $this->assertSame('Classificação que proponho para a minha avaliação neste período', $global->invoke($provider, $numeric));

        $rationaleOf = fn (Scale $scale): string => collect($written->invoke($provider, $scale))
            ->first(fn (array $pair): bool => $pair[0] === SelfAssessmentQuestionRole::Rationale)[1];

        $this->assertSame('Porque proponho este nível?', $rationaleOf($levels));
        $this->assertSame('Porque proponho esta classificação?', $rationaleOf($numeric));

        // And the scale itself comes from the class, never from a name the
        // provider knows by heart.
        $source = (string) file_get_contents(app_path('Services/Assessment/SelfAssessmentTemplateProvider.php'));
        $this->assertStringNotContainsString('Escala 1 a 5', $source);
    }

    // --------------------------------------------------------- 2. o «Calculado»

    #[Test]
    public function nothing_calculated_reaches_the_page(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $response = $this->actingAs($this->teacher())
            ->get("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}");

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');
        $props = $page['props'];

        // Not hidden with CSS — not sent. The student's browser never receives
        // the class's calculated results at all.
        foreach ($props['questions'] as $question) {
            $this->assertArrayNotHasKey('calculated', $question);
        }

        foreach (['calculated', 'domainResults', 'proposal', 'classification'] as $absent) {
            $this->assertArrayNotHasKey($absent, $props);
        }

        // And the service that builds the form no longer even asks for them.
        $source = (string) file_get_contents(app_path('Services/Assessment/SelfAssessmentRecorder.php'));
        $this->assertStringNotContainsString('ClassResultsCalculator', $source);
        $this->assertStringNotContainsString('normalizedValue', $source);
    }

    // -------------------------------------------------------- 3. a persistência

    #[Test]
    public function the_overall_judgement_is_stored_against_the_question_that_asked_it(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $questions = $this->questionsOf($classUlid);
        $global = $questions->firstWhere('role', SelfAssessmentQuestionRole::Global);
        $domain = $questions->firstWhere('domain_id', '!=', null);

        $level = app(CurrentOrganization::class)->runFor(
            $this->teacher()->personalOrganization(),
            fn () => Scale::where('name', 'Escala 1 a 5')->firstOrFail()->levels()->where('code', '4')->firstOrFail(),
        );
        $other = app(CurrentOrganization::class)->runFor(
            $this->teacher()->personalOrganization(),
            fn () => Scale::where('name', 'Escala 1 a 5')->firstOrFail()->levels()->where('code', '2')->firstOrFail(),
        );

        $this->actingAs($this->teacher())->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", [
            'answers' => [$global->id => $level->id, $domain->id => $other->id],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->teacher()->personalOrganization(), function () use ($global, $domain, $level, $other, $enrollmentUlid, $periodUlid): void {
            $selfAssessment = SelfAssessment::with('responses')->firstOrFail();
            $responses = $selfAssessment->responses->keyBy('self_assessment_question_id');

            // The level the student chose, against the global question — and
            // the domain answer left exactly where it was, never confused with
            // it (§7).
            $this->assertSame($level->id, $responses[$global->id]->scale_level_id);
            $this->assertSame($other->id, $responses[$domain->id]->scale_level_id);

            // On this student's enrollment, in this period.
            $this->assertSame($enrollmentUlid, $selfAssessment->enrollment->ulid);
            $this->assertSame(
                AcademicPeriod::where('ulid', $periodUlid)->firstOrFail()->id,
                $selfAssessment->academic_period_id,
            );
        });
    }

    #[Test]
    public function a_blank_overall_judgement_stays_blank(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $questions = $this->questionsOf($classUlid);
        $global = $questions->firstWhere('role', SelfAssessmentQuestionRole::Global);
        $domains = $questions->whereNotNull('domain_id');

        $level = app(CurrentOrganization::class)->runFor(
            $this->teacher()->personalOrganization(),
            fn () => Scale::where('name', 'Escala 1 a 5')->firstOrFail()->levels()->where('code', '5')->firstOrFail()->id,
        );

        $answers = [$global->id => null];

        foreach ($domains as $question) {
            $answers[$question->id] = $level;
        }

        $this->actingAs($this->teacher())
            ->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", ['answers' => $answers])
            ->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->teacher()->personalOrganization(), function () use ($global): void {
            $response = SelfAssessment::with('responses')->firstOrFail()
                ->responses->firstWhere('self_assessment_question_id', $global->id);

            // Every domain answered and the overall one left blank is a real
            // state, and it stays that state. Not the average of the five.
            $this->assertNull($response->scale_level_id);
        });
    }

    #[Test]
    public function each_written_answer_is_kept_apart_from_the_others(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $questions = $this->questionsOf($classUlid);
        $written = [
            SelfAssessmentQuestionRole::Rationale->value => 'Porque li mais este período.',
            SelfAssessmentQuestionRole::Improvement->value => 'Preciso de melhorar a ortografia.',
            SelfAssessmentQuestionRole::Liked->value => 'O debate sobre o Sermão.',
            SelfAssessmentQuestionRole::Struggled->value => 'A análise das orações subordinadas.',
        ];

        $texts = [];

        foreach ($written as $role => $answer) {
            $texts[$questions->firstWhere('role', SelfAssessmentQuestionRole::from($role))->id] = $answer;
        }

        $this->actingAs($this->teacher())
            ->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", ['texts' => $texts])
            ->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->teacher()->personalOrganization(), function () use ($questions, $written): void {
            $responses = SelfAssessment::with('responses')->firstOrFail()->responses->keyBy('self_assessment_question_id');

            foreach ($written as $role => $answer) {
                $questionId = $questions->firstWhere('role', SelfAssessmentQuestionRole::from($role))->id;

                $this->assertSame($answer, $responses[$questionId]->text_value, "a resposta {$role} ficou noutro sítio");
            }
        });
    }

    #[Test]
    public function a_level_that_does_not_belong_to_the_questions_scale_is_refused(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $global = $this->questionsOf($classUlid)->firstWhere('role', SelfAssessmentQuestionRole::Global);

        $foreign = app(CurrentOrganization::class)->runFor($this->teacher()->personalOrganization(), function (): int {
            $elsewhere = Scale::create(['name' => 'Escala de outra turma', 'kind' => 'level', 'min_value' => '1', 'max_value' => '3']);

            return $elsewhere->levels()->create(['code' => 'X', 'label' => 'De outra escala', 'sequence' => 1])->id;
        });

        $this->actingAs($this->teacher())
            ->post("/classes/{$classUlid}/self-assessments/{$periodUlid}/{$enrollmentUlid}", ['answers' => [$global->id => $foreign]])
            ->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->teacher()->personalOrganization(), function (): void {
            $this->assertSame(0, SelfAssessment::firstOrFail()->responses()->count());
        });
    }

    // ------------------------------------------------------ 4. compatibilidade

    #[Test]
    public function a_template_that_predates_roles_keeps_its_questions_and_gains_the_missing_ones(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $domain = Domain::firstOrFail();
            $scale = Scale::where('name', 'Escala 1 a 5')->firstOrFail();

            // A template exactly as it was authored before roles existed: domain
            // questions and nothing else.
            $template = SelfAssessmentTemplate::create([
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'class_id' => $class->id,
                'name' => 'Autoavaliação anterior',
                'is_active' => true,
            ]);
            $original = $template->questions()->create([
                'domain_id' => $domain->id,
                'prompt' => "Como te avalias em {$domain->name}?",
                'answer_kind' => 'scale',
                'scale_id' => $scale->id,
                'sequence' => 1,
            ]);

            $completed = app(SelfAssessmentTemplateProvider::class)->forClass($class);

            // Same template, same question, same id — and still identified by
            // its domain. Nothing was given a role after the fact, which would
            // be deciding today what an answer given earlier meant.
            $this->assertSame($template->id, $completed->id);
            $kept = $completed->questions->firstWhere('id', $original->id);
            $this->assertNotNull($kept);
            $this->assertNull($kept->role);
            $this->assertSame($domain->id, $kept->domain_id);

            // And the questions it never had are there now, each with a new id.
            foreach (SelfAssessmentQuestionRole::cases() as $role) {
                $question = $completed->questions->firstWhere('role', $role);

                $this->assertNotNull($question, "falta a pergunta {$role->value}");
                $this->assertNotSame($original->id, $question->id);
            }

            // Asked for twice, completed once.
            $again = app(SelfAssessmentTemplateProvider::class)->forClass($class);
            $this->assertCount($completed->questions->count(), $again->questions);
        });
    }

    #[Test]
    public function rewording_the_prompts_leaves_anything_a_teacher_wrote_alone(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        [$generated, $edited, $global] = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $scale = Scale::where('name', 'Escala 1 a 5')->firstOrFail();
            $domains = Domain::orderBy('id')->take(2)->get();

            $template = SelfAssessmentTemplate::create([
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'class_id' => $class->id,
                'name' => 'Autoavaliação anterior',
                'is_active' => true,
            ]);

            return [
                // Exactly what the provider used to write…
                $template->questions()->create([
                    'domain_id' => $domains[0]->id,
                    'prompt' => "Como te avalias em {$domains[0]->name}?",
                    'answer_kind' => 'scale',
                    'scale_id' => $scale->id,
                    'sequence' => 1,
                ]),
                // …and something a teacher rewrote for their own class.
                $template->questions()->create([
                    'domain_id' => $domains[1]->id,
                    'prompt' => 'Diz lá, achas que trabalhaste bem a escrita este período?',
                    'answer_kind' => 'scale',
                    'scale_id' => $scale->id,
                    'sequence' => 2,
                ]),
                $template->questions()->create([
                    'role' => SelfAssessmentQuestionRole::Global,
                    'prompt' => 'No conjunto, que nível propões para a tua avaliação neste período?',
                    'answer_kind' => 'scale',
                    'scale_id' => $scale->id,
                    'sequence' => 3,
                ]),
            ];
        });

        $migration = require database_path('migrations/2026_08_16_000200_reword_generated_self_assessment_prompts.php');
        $migration->up();

        $domainName = app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            fn (): string => Domain::orderBy('id')->first()->name,
        );

        $this->assertSame("Como avalio o meu desempenho em {$domainName}?", $generated->fresh()->prompt);
        $this->assertSame('Nível que proponho para a minha avaliação neste período', $global->fresh()->prompt);

        // Untouched. The migration only recognises sentences this app wrote.
        $this->assertSame('Diz lá, achas que trabalhaste bem a escrita este período?', $edited->fresh()->prompt);

        // And it is reversible.
        $migration->down();
        $this->assertSame("Como te avalias em {$domainName}?", $generated->fresh()->prompt);
        $this->assertSame('No conjunto, que nível propões para a tua avaliação neste período?', $global->fresh()->prompt);
        $this->assertSame('Diz lá, achas que trabalhaste bem a escrita este período?', $edited->fresh()->prompt);
    }
}
