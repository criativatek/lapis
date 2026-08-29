<?php

namespace Tests\Feature\Ai;

use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE STATES A TEACHER ACTUALLY MEETS, for the two experiences the AI-complete
 * slice added.
 *
 * The common UX pattern (§21) is: disclosure, an explicit button, loading, a
 * result, retry, a quota message, an unavailable state, an error, and a human
 * action afterwards. Some of those are the panel component's job and are tested
 * in `AiReadingPanel.test.ts`; the ones the SERVER decides are here — because a
 * screen that draws a button the server would refuse is the failure mode §41
 * exists to prevent, and only a server test can catch it.
 *
 * THE PAGE ALWAYS WORKS. Every test below asserts a 200 on the underlying
 * screen, whatever the AI's state. Resultados and Evolução are not AI features
 * with a page attached; they are pages that happen to offer a reading.
 */
class AiExperienceStatesTest extends TestCase
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

        $this->seed(EntitlementsSeeder::class);
    }

    // ---------------------------------------------------------- não incluído

    #[Test]
    public function a_base_organization_sees_the_results_page_with_the_reading_locked(): void
    {
        $this->givePlan('base');
        $this->configureEngine();

        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ai.available', false)
                ->where('ai.reason', 'plan'));
    }

    #[Test]
    public function a_base_organization_that_posts_anyway_is_refused_with_a_sentence(): void
    {
        $this->givePlan('base');
        $this->configureEngine();

        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/results")
            ->post("/classes/{$class->ulid}/results/analise-ia")
            ->assertRedirect()
            ->assertSessionHas('resultsAiAnalysisError');

        $this->assertStringContainsString(
            'não está incluída no plano',
            (string) session('resultsAiAnalysisError')['message'],
        );
    }

    #[Test]
    public function a_base_organization_sees_the_followup_synthesis_locked(): void
    {
        $this->givePlan('base');
        $this->configureEngine();

        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiSynthesis.available', false)
                ->where('aiSynthesis.reason', 'plan'));
    }

    // ---------------------------------------------------------- não configurado

    /**
     * A Pro school with no engine is a different problem from a Base school,
     * and is told so: one is being offered an upgrade, the other is waiting on
     * whoever administers the installation (§41).
     */
    #[Test]
    public function a_pro_organization_with_no_engine_is_told_it_is_the_installation(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.driver' => null]);

        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ai.available', false)
                ->where('ai.reason', 'off'));
    }

    // ------------------------------------------------------------ disponível

    #[Test]
    public function a_pro_organization_gets_a_structured_reading_of_the_results(): void
    {
        $this->givePlan('pro');
        $this->configureEngine();
        $this->engine(implode("\n", [
            'SINTESE: Os resultados deste período são globalmente positivos.',
            'PADROES: - Os resultados concentram-se nos níveis intermédios.',
            'FORTES: - A Leitura reúne evidência consistente.',
            'ATENCAO: - A Escrita apresenta maior dispersão.',
            'SUGESTOES: - Pode ser útil considerar tarefas de escrita mais frequentes.',
            'CAUTELAS: - Alguns resultados assentam apenas em parte dos elementos.',
        ]));

        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/results")
            ->post("/classes/{$class->ulid}/results/analise-ia")
            ->assertRedirect()
            ->assertSessionHas('resultsAiAnalysis');

        $analysis = session('resultsAiAnalysis');

        // SIX NAMED LISTS, NEVER A BLOB. The screen never meets raw model
        // output, and «nunca apresentar JSON cru» (§16) is structural.
        $this->assertSame('Os resultados deste período são globalmente positivos.', $analysis['summary']);
        $this->assertSame(['A Leitura reúne evidência consistente.'], $analysis['strengths']);
        $this->assertSame(['Alguns resultados assentam apenas em parte dos elementos.'], $analysis['cautions']);
        $this->assertArrayHasKey('period_ulid', $analysis);
    }

    #[Test]
    public function a_pro_organization_gets_a_structured_followup_synthesis(): void
    {
        $this->givePlan('pro');
        $this->configureEngine();
        $this->engine(implode("\n", [
            'SINTESE: O percurso é globalmente estável.',
            'POSITIVOS: - A Leitura mantém-se consolidada.',
            'ATENCAO: - Há registos de dificuldade neste período.',
            'MUDOU: - O resultado desceu ligeiramente face ao período anterior.',
            'PROXIMO: - Pode ser útil conversar sobre a organização do trabalho.',
            'CAUTELAS: - A evidência do período em curso ainda é escassa.',
        ]));

        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sintese-ia")
            ->assertRedirect()
            ->assertSessionHas('aiSynthesis');

        $synthesis = session('aiSynthesis');

        $this->assertSame('O percurso é globalmente estável.', $synthesis['summary']);
        $this->assertSame(['A Leitura mantém-se consolidada.'], $synthesis['positive_signals']);
        $this->assertSame(['O resultado desceu ligeiramente face ao período anterior.'], $synthesis['what_changed']);
        $this->assertSame($enrollment->ulid, $synthesis['enrollment_ulid']);
    }

    // ------------------------------------------------------- evidência escassa

    /**
     * A THIRD STATE, DISTINCT FROM THE OTHER TWO. «O plano não inclui» never
     * changes by waiting; «ainda não há resultados suficientes» fixes itself
     * the moment the teacher corrects another instrument. Telling one as the
     * other sends them to the wrong place.
     */
    #[Test]
    public function a_period_with_too_little_evidence_says_so_instead_of_asking_an_engine(): void
    {
        $this->givePlan('pro');
        $this->configureEngine();
        $engine = $this->engine('SINTESE: nunca deveria ser pedido.');

        // A CLASS THAT HAS JUST BEEN CREATED — no roster, no instruments, no
        // results. The situation a teacher is actually in in September, and the
        // one where a confident paragraph about three figures would be worst.
        $class = $this->asTenant(function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass()->academic_year_id,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ai.available', true)
                ->where('ai.has_enough_evidence', false));

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/results")
            ->post("/classes/{$class->ulid}/results/analise-ia")
            ->assertRedirect()
            ->assertSessionHas('resultsAiAnalysisError');

        $this->assertStringContainsString(
            'resultados suficientes',
            (string) session('resultsAiAnalysisError')['message'],
        );

        // AND NOTHING WAS ASKED. The refusal happens before the gateway, so it
        // costs no quota and no tokens.
        $this->assertNull($engine->lastRequest());
    }

    // ------------------------------------------------------------------ erro

    /**
     * EVERY FAILURE PRESERVES THE PAGE. The reading is optional; the results
     * table is not.
     */
    #[Test]
    public function an_engine_failure_comes_back_as_a_sentence_on_a_working_page(): void
    {
        $this->givePlan('pro');
        $this->configureEngine();

        $engine = new FakeAiTextProvider('modelo-de-teste');
        $engine->willFail(AiRequestFailed::timedOut(20));
        $this->app->instance(FakeAiTextProvider::class, $engine);

        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/results")
            ->post("/classes/{$class->ulid}/results/analise-ia")
            ->assertRedirect()
            ->assertSessionHas('resultsAiAnalysisError');

        // Never a category, never a status code, never an endpoint.
        $message = (string) session('resultsAiAnalysisError')['message'];
        $this->assertStringNotContainsString('timeout', mb_strtolower($message));
        $this->assertStringNotContainsString('http', mb_strtolower($message));

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}/results")->assertOk();
    }

    /**
     * An unusable answer is refused rather than shown under headings that
     * promise something it does not contain — the feature's own guard, on top
     * of the gateway which deliberately validates nothing (contract §3).
     */
    #[Test]
    public function an_unparseable_answer_is_refused_rather_than_half_shown(): void
    {
        $this->givePlan('pro');
        $this->configureEngine();
        $this->engine('Claro! Aqui está a minha análise: a turma está bem.');

        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/results")
            ->post("/classes/{$class->ulid}/results/analise-ia")
            ->assertRedirect()
            ->assertSessionHas('resultsAiAnalysisError')
            ->assertSessionMissing('resultsAiAnalysis');
    }

    // ---------------------------------------------------------------- helpers

    private function configureEngine(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'modelo-de-teste']);
    }

    private function engine(string $answer): FakeAiTextProvider
    {
        $engine = new FakeAiTextProvider('modelo-de-teste');
        $engine->willReturn($answer);
        $this->app->instance(FakeAiTextProvider::class, $engine);

        return $engine;
    }

    protected function givePlan(string $key): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->getKey()],
            [
                'plan_id' => Plan::where('key', $key)->firstOrFail()->getKey(),
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

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function enrollment(int $classNumber = 1): Enrollment
    {
        return $this->asTenant(
            fn (): Enrollment => $this->schoolClass()->enrollments()->where('class_number', $classNumber)->firstOrFail(),
        );
    }
}
