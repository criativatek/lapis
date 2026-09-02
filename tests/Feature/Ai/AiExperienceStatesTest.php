<?php

namespace Tests\Feature\Ai;

use App\Http\Controllers\Reports\ReportRewriteController;
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
use Illuminate\Support\Carbon;
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

    /**
     * `$answer` is optional so that a test which is about a FAILURE can script
     * one without first scripting an answer nobody will read.
     */
    private function engine(?string $answer = null): FakeAiTextProvider
    {
        $engine = new FakeAiTextProvider('modelo-de-teste');

        if ($answer !== null) {
            $engine->willReturn($answer);
        }

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

    private function enrollment(int $classNumber = 1): Enrollment
    {
        return $this->asTenant(
            fn (): Enrollment => $this->schoolClass()->enrollments()->where('class_number', $classNumber)->firstOrFail(),
        );
    }

    // -------------------------------------------------- copy e «tentar novamente»

    /**
     * THE SYNTHESIS NEVER TELLS A TEACHER THAT THEIR TEXT WAS PRESERVED.
     *
     * There is no text under this panel. «O texto atual foi preservado» was
     * written for «Aperfeiçoar redação», where the teacher's own paragraph is
     * still sitting in the editor, and it travelled to five other features
     * through a shared exception — where it is a reassurance about something
     * that never existed. Neutral copy here; the rewrite keeps its sentence.
     */
    #[Test]
    public function a_failed_synthesis_does_not_claim_a_previous_text_was_kept(): void
    {
        $this->givePlan('pro');
        $this->configureEngine();
        $this->engine()->willFail(AiRequestFailed::truncatedAnswer('MAX_TOKENS, no text produced'));

        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sintese-ia")
            ->assertRedirect()
            ->assertSessionHas('aiSynthesisError');

        $error = session('aiSynthesisError');

        $this->assertStringNotContainsStringIgnoringCase('preservado', $error['message']);
        $this->assertSame('Não foi possível obter uma sugestão neste momento.', $error['message']);
    }

    /**
     * AND IT DOES NOT OFFER A BUTTON THAT CANNOT WORK.
     *
     * A truncation is arithmetic: the ceiling, the prompt and the model's
     * thinking cost are identical on the next press, so the next press fails
     * identically and bills the school for the demonstration. The server says
     * so, and the panel reads it.
     */
    #[Test]
    public function a_deterministic_failure_does_not_offer_to_try_again(): void
    {
        $this->givePlan('pro');
        $this->configureEngine();
        $this->engine()->willFail(AiRequestFailed::truncatedAnswer('MAX_TOKENS, no text produced'));

        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sintese-ia")
            ->assertRedirect();

        $this->assertFalse(session('aiSynthesisError')['retryable']);
    }

    /** A timeout is weather, and the button stays where it is. */
    #[Test]
    public function a_transient_failure_still_offers_to_try_again(): void
    {
        $this->givePlan('pro');
        $this->configureEngine();
        $this->engine()->willFail(AiRequestFailed::timedOut(20));

        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sintese-ia")
            ->assertRedirect();

        $this->assertTrue(session('aiSynthesisError')['retryable']);
    }

    /**
     * AN ANSWER THE PARSER REFUSES IS ALSO DETERMINISTIC — the engine was
     * healthy and said something this application cannot read, and at
     * temperature 0.2 it will mostly say it again.
     */
    #[Test]
    public function an_unreadable_answer_does_not_offer_to_try_again(): void
    {
        $this->givePlan('pro');
        $this->configureEngine();
        $this->engine('Uma resposta fluente, sem um único rótulo de secção.');

        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sintese-ia")
            ->assertRedirect()
            ->assertSessionHas('aiSynthesisError');

        $error = session('aiSynthesisError');

        $this->assertFalse($error['retryable']);
        $this->assertStringNotContainsStringIgnoringCase('preservado', $error['message']);
    }

    /**
     * «AINDA NÃO HÁ EVIDÊNCIA SUFICIENTE» KEEPS ITS BUTTON, and that is the
     * distinction worth drawing: unlike a budget the teacher cannot change,
     * this one is fixed by registering another element — so the next press is
     * exactly the thing that would work.
     */
    #[Test]
    public function too_little_evidence_keeps_the_button_it_can_actually_use(): void
    {
        $this->givePlan('pro');
        $this->configureEngine();
        $this->engine('SINTESE: nunca deveria ser pedido.');

        // The same empty September class as
        // `a_period_with_too_little_evidence_says_so_instead_of_asking_an_engine`
        // — no roster, no instruments, no results.
        $class = $this->asTenant(function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass()->academic_year_id,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/results")
            ->post("/classes/{$class->ulid}/results/analise-ia")
            ->assertRedirect()
            ->assertSessionHas('resultsAiAnalysisError');

        $error = session('resultsAiAnalysisError');

        $this->assertTrue($error['retryable']);
        $this->assertStringContainsString('resultados suficientes', (string) $error['message']);
    }

    /**
     * «APERFEIÇOAR REDAÇÃO» IS THE ONE SCREEN THAT STILL SAYS IT, because it is
     * the one screen where it is true: the teacher's paragraph is still in the
     * editor, untouched, and saying so is worth saying.
     */
    #[Test]
    public function the_rewrite_still_reassures_the_teacher_that_their_text_is_intact(): void
    {
        $controller = new \ReflectionMethod(
            ReportRewriteController::class,
            'preservingText',
        );
        $controller->setAccessible(true);

        $message = $controller->invoke(
            app(ReportRewriteController::class),
            AiRequestFailed::truncatedAnswer('MAX_TOKENS')->publicMessage(),
        );

        $this->assertStringContainsString('O texto atual foi preservado.', $message);
        $this->assertStringContainsString('Não foi possível obter uma sugestão neste momento.', $message);
    }
}
