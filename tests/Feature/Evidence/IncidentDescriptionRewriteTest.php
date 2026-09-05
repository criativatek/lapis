<?php

namespace Tests\Feature\Evidence;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiTextRequest;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Evidence\Ai\IncidentDescriptionAssistant;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Aperfeiçoar redação» on the disciplinary occurrence description, before the
 * record is ever saved (SUP-U8FMAE) — modelled on
 * `tests/Feature/Reports/WritingAssistantTest.php`, the equivalent feature on
 * report sections.
 *
 * NO TEST HERE TOUCHES THE INTERNET. Every one runs against a fake engine that
 * answers what the test told it to — including, deliberately, badly.
 */
class IncidentDescriptionRewriteTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected FakeAiTextProvider $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();

        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));

        $this->seed(EntitlementsSeeder::class);
        $this->givePlan('pro');

        $this->configureEngine();
    }

    protected function givePlan(string $key): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', $key)->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }

    protected function configureEngine(): void
    {
        config(['lapis.ai.driver' => 'fake']);

        $this->engine = new FakeAiTextProvider('modelo-de-teste');

        $this->app->instance(FakeAiTextProvider::class, $this->engine);
    }

    protected function noEngine(): void
    {
        config(['lapis.ai.driver' => null]);
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

    private function rewrite(SchoolClass $class, string $description, string $kind = 'incident'): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/records")
            ->post("/classes/{$class->ulid}/records/aperfeicoar-descricao", [
                'kind' => $kind,
                'description' => $description,
            ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function suggestion(): ?array
    {
        $suggestion = session('incidentRewrite');

        return is_array($suggestion) ? $suggestion : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rewriteError(): ?array
    {
        $error = session('incidentRewriteError');

        return is_array($error) ? $error : null;
    }

    // ------------------------------------------------------------- availability

    #[Test]
    public function base_has_no_writing_assistant(): void
    {
        $this->givePlan('base');

        $this->asTenant(function (): void {
            $assistant = app(IncidentDescriptionAssistant::class);

            $this->assertFalse($assistant->isAvailable());
            $this->assertSame('plan', $assistant->unavailableReason());
        });
    }

    #[Test]
    public function pro_has_the_writing_assistant(): void
    {
        $this->asTenant(function (): void {
            $this->assertTrue(app(IncidentDescriptionAssistant::class)->isAvailable());
        });
    }

    #[Test]
    public function without_a_configured_engine_the_feature_is_unavailable_and_says_which(): void
    {
        $this->noEngine();

        $this->asTenant(function (): void {
            $assistant = app(IncidentDescriptionAssistant::class);

            $this->assertFalse($assistant->isAvailable());
            $this->assertSame('provider', $assistant->unavailableReason());
        });
    }

    #[Test]
    public function a_base_organization_that_posts_anyway_is_refused(): void
    {
        $this->givePlan('base');

        $class = $this->schoolClass();

        $this->rewrite($class, 'O aluno interrompeu repetidamente a aula.')->assertRedirect();

        $this->assertNull($this->suggestion());
    }

    #[Test]
    public function only_the_incident_kind_may_be_rewritten(): void
    {
        $class = $this->schoolClass();

        $this->rewrite($class, 'Participou com interesse.', kind: 'participation');

        $this->assertNull($this->suggestion());
        $this->assertNotNull($this->rewriteError());
    }

    #[Test]
    public function the_records_screen_reports_availability_on_the_server(): void
    {
        $this->noEngine();

        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/records")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai.available', false)
                ->where('ai.reason', 'provider'));
    }

    // --------------------------------------------------------- happy path

    #[Test]
    public function a_valid_rewrite_is_offered(): void
    {
        $class = $this->schoolClass();

        $this->engine->willReturn('O aluno interrompeu a aula por diversas vezes.');

        $this->rewrite($class, 'O aluno interrompeu várias vezes a aula.');

        $suggestion = $this->suggestion();

        $this->assertNotNull($suggestion);
        $this->assertSame('O aluno interrompeu a aula por diversas vezes.', $suggestion['text']);
    }

    #[Test]
    public function nothing_is_ever_saved_by_this_endpoint(): void
    {
        $class = $this->schoolClass();

        $this->engine->willReturn('O aluno interrompeu a aula por diversas vezes.');

        $this->rewrite($class, 'O aluno interrompeu várias vezes a aula.');

        $this->assertSame(
            0,
            $this->asTenant(fn (): int => $class->fresh()->evidenceRecords()->count()),
        );
    }

    #[Test]
    public function student_names_leave_as_pseudonyms_and_come_back_as_names(): void
    {
        $class = $this->schoolClass();

        $name = $this->asTenant(
            fn (): string => (string) $class->activeEnrollments()
                ->with('student.identity')->orderBy('class_number')->firstOrFail()
                ->student->identity->display_name,
        );

        $this->engine->willReturnUsing(
            fn (AiTextRequest $request): string => str_replace('empurrou', 'colidiu com', $request->content),
        );

        $this->rewrite($class, "{$name} empurrou um colega no intervalo.");

        $suggestion = $this->suggestion();

        $sent = $this->engine->lastRequest();
        $this->assertNotNull($sent);
        $this->assertStringNotContainsString($name, $sent->content);
        $this->assertStringContainsString('Aluno A', $sent->content);

        $this->assertStringContainsString($name, (string) $suggestion['text']);
        $this->assertStringNotContainsString('Aluno A', (string) $suggestion['text']);
        $this->assertTrue($suggestion['pseudonymised']);
    }

    #[Test]
    public function figures_leave_as_markers_and_come_back_as_figures(): void
    {
        $class = $this->schoolClass();

        $this->engine->willReturnUsing(
            fn (AiTextRequest $request): string => str_replace('Interrompeu', 'Ora, interrompeu', $request->content),
        );

        $this->rewrite($class, 'Interrompeu a aula às 14h30, pela terceira vez.');

        $suggestion = $this->suggestion();

        $sent = $this->engine->lastRequest();
        $this->assertNotNull($sent);
        $this->assertDoesNotMatchRegularExpression('/\d/u', $sent->content);

        $this->assertStringContainsString('14h30', (string) $suggestion['text']);
    }

    // ------------------------------------------------------- guard refusals

    #[Test]
    public function a_changed_time_is_refused(): void
    {
        $class = $this->schoolClass();

        $this->engine->willReturn('O aluno chegou atrasado às 14h45.');

        $this->rewrite($class, 'O aluno chegou atrasado às 14h30.');

        $this->assertNull($this->suggestion()['text']);
    }

    #[Test]
    public function an_invented_diagnosis_is_not_applied(): void
    {
        $class = $this->schoolClass();

        $this->engine->willAppend('O comportamento sugere défice de atenção.');

        $this->rewrite($class, 'O aluno recusou-se a realizar a tarefa proposta.');

        $this->assertNull($this->suggestion()['text']);
    }

    #[Test]
    public function an_invented_measure_is_not_applied(): void
    {
        $class = $this->schoolClass();

        $this->engine->willAppend('Recomenda-se a abertura de processo disciplinar.');

        $this->rewrite($class, 'O aluno recusou-se a realizar a tarefa proposta.');

        $this->assertNull($this->suggestion()['text']);
    }

    #[Test]
    public function markup_is_never_shown_to_a_teacher(): void
    {
        $class = $this->schoolClass();

        $this->engine->willReturn('<p>O aluno recusou-se a realizar a tarefa proposta.</p>');

        $this->rewrite($class, 'O aluno recusou-se a realizar a tarefa proposta.');

        $this->assertNull($this->suggestion()['text']);
    }

    // -------------------------------------------------------------- failures

    #[Test]
    public function a_provider_error_preserves_the_text_and_says_nothing_technical(): void
    {
        $class = $this->schoolClass();

        $this->engine->willFail(AiRequestFailed::refused(500));

        $this->rewrite($class, 'O aluno recusou-se a realizar a tarefa proposta.');

        $error = $this->rewriteError();

        $this->assertIsArray($error);
        $this->assertStringNotContainsString('500', $error['message']);
        $this->assertStringContainsString('Não foi possível', $error['message']);
    }

    #[Test]
    public function too_many_requests_gets_its_own_sentence(): void
    {
        $class = $this->schoolClass();

        $this->engine->willFail(AiRequestFailed::refused(429));

        $this->rewrite($class, 'O aluno recusou-se a realizar a tarefa proposta.');

        $this->assertStringContainsString('demasiados pedidos', (string) $this->rewriteError()['message']);
    }

    #[Test]
    public function another_organizations_class_is_not_reachable(): void
    {
        $class = $this->schoolClass();

        $stranger = User::factory()->create(['email' => 'outro@lapis.test']);

        $this->actingAs($stranger)
            ->from('/records')
            ->post("/classes/{$class->ulid}/records/aperfeicoar-descricao", [
                'kind' => 'incident',
                'description' => 'Algo aconteceu.',
            ])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ audit

    #[Test]
    public function the_trail_records_the_call_without_recording_the_text(): void
    {
        $class = $this->schoolClass();

        $this->engine->willReturn('O aluno interrompeu a aula por diversas vezes.');

        $this->rewrite($class, 'O aluno interrompeu várias vezes a aula.');

        $event = $this->asTenant(fn (): ?AuditEvent => AuditEvent::where('event', 'evidence.rewrite_suggested')->latest('id')->first());

        $this->assertNotNull($event);

        $properties = $event->properties;

        $this->assertSame('lapis-incident-rewrite/1', $properties['prompt_version']);
        $this->assertSame('fake', $properties['provider']);
        $this->assertSame('modelo-de-teste', $properties['model']);
        $this->assertTrue($properties['accepted_by_guard']);
        $this->assertArrayHasKey('input_hash', $properties);
        $this->assertArrayHasKey('output_hash', $properties);

        $encoded = json_encode($properties, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('interrompeu', (string) $encoded);
    }
}
