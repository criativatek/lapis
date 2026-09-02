<?php

namespace Tests\Feature\Ai;

use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WHAT ACTUALLY LEAVES THE BUILDING, PER USE CASE.
 *
 * Every assertion in this file is written against `FakeAiTextProvider::
 * lastRequest()` — the string that would have gone on the wire — and never
 * against a controller's intentions. A test that checked validation rules would
 * pass just as happily on a version that quietly attached the roster to the
 * prompt (§26).
 *
 * THE FIXTURE IS DELIBERATELY DIRTY. The demo class is given a student whose
 * name, e-mail, telephone number, process number and ULID are all real values
 * of the shapes the sanitiser is supposed to catch, and a record whose free
 * text contains the kind of sentence a teacher actually writes. Then every
 * experience is run over it, and every one of those values is asserted absent
 * from what left. A fixture with only clean data proves nothing.
 *
 * IT ASSERTS BOTH HALVES. «The identifiers did not arrive» is half a test: a
 * payload that sent nothing at all would pass it. Each case also asserts that
 * the PEDAGOGICAL FACTS the reading needs DID arrive — the domain names, the
 * figures, the coverage — because a privacy guarantee that works by sending
 * nothing is a broken feature rather than a safe one.
 */
class AiExperiencePrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    /** Values that must never appear in a payload, whatever the use case. */
    private const FORBIDDEN = [
        'Ana Marques',
        'Bruno Teixeira',
        'ana.marques@escola.test',
        '912345678',
        '20260012345',
        'Rua das Flores 12',
        '2400-123',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();

        $this->seed(EntitlementsSeeder::class);
        // Institucional, so every capability is on and no test in this file is
        // measuring the entitlement layer — `AiEntitlementMatrixTest` does that.
        $this->givePlan('institutional');

        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'modelo-de-teste']);
    }

    // ------------------------------------------------------------------ helpers

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

    /** An engine that answers something the parsers accept, so the flow completes. */
    private function engine(string $answer): FakeAiTextProvider
    {
        $engine = new FakeAiTextProvider('modelo-de-teste');
        $engine->willReturn($answer);
        $this->app->instance(FakeAiTextProvider::class, $engine);

        return $engine;
    }

    /** A six-block answer both new parsers accept. */
    private function sixBlockAnswer(): string
    {
        return implode("\n", [
            'SINTESE: Os resultados deste período mostram um desempenho globalmente positivo.',
            'PADROES: - Os resultados concentram-se nos níveis intermédios.',
            'FORTES: - A Leitura reúne evidência consistente.',
            'POSITIVOS: - A Leitura reúne evidência consistente.',
            'ATENCAO: - A Escrita apresenta maior dispersão entre alunos.',
            'MUDOU: - Não existe período anterior comparável.',
            'SUGESTOES: - Pode ser útil considerar tarefas de escrita mais frequentes.',
            'PROXIMO: - Pode ser útil verificar com o aluno o que sente na escrita.',
            'CAUTELAS: - Alguns resultados assentam apenas em parte dos elementos previstos.',
        ]);
    }

    /**
     * A student whose record is full of exactly the things that must not leave,
     * plus a free-text record of the kind §13 refuses to send.
     */
    private function dirtyUpTheFixture(): void
    {
        $this->asTenant(function (): void {
            $enrollment = $this->enrollment(1);

            // The name is already «Ana Marques» in the demo scenario; the
            // process number is what a school actually keys a student by, and
            // it is added here so a payload carrying it would be caught.
            $enrollment->student->identity->update(['school_number' => '20260012345']);

            // WHERE THE SENSITIVE DATA ACTUALLY LIVES in this application: the
            // free text of a record. Health, a guardian, a telephone number and
            // an address, in one sentence, exactly as a teacher would write it
            // — and exactly what §13 says must not be sent.
            EvidenceRecord::create([
                'class_id' => $this->schoolClass()->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'kind' => EvidenceKind::Difficulty,
                'occurred_at' => now()->subDays(3)->toDateString(),
                'description' => 'A Ana Marques tem acompanhamento médico e a mãe, contactável em 912345678 ou ana.marques@escola.test, pediu reunião. Morada: Rua das Flores 12, 2400-123 Leiria.',
                'created_by' => $this->teacher->getKey(),
            ]);

            // THE SECOND PLACE IT LIVES, and the one an intervention makes
            // almost inevitable: a measure exists FOR a reason, and the reason
            // is frequently a needs or a health statement. `objective`,
            // `description` and `motive_label` are all free text.
            Intervention::create([
                'class_id' => $this->schoolClass()->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'target_type' => 'student',
                'intervention_type' => 'learning_reinforcement',
                'purpose' => 'recovery',
                'domain_relation' => 'none',
                'title' => 'Apoio individualizado',
                'motive_label' => 'Relatório da equipa de saúde escolar sobre a Ana Marques.',
                'strategy_label' => 'Apoio individualizado',
                'objective' => 'Compensar as faltas por consultas de neurologia; a mãe (912345678) acompanha.',
                'description' => 'Encaminhada pelo psicólogo. Diagnóstico em curso. Contacto: ana.marques@escola.test.',
                'status' => 'in_progress',
                'started_on' => now()->subMonth()->toDateString(),
                'created_by' => $this->teacher->getKey(),
            ]);
        });
    }

    /**
     * THE §13 EXCLUSION, TESTED AGAINST EVERY FREE-TEXT FIELD AT ONCE.
     *
     * The followup test below already proves that a record's description does
     * not travel. This one is wider and is the one the Política de Privacidade
     * now points at: the fixture writes health, a diagnosis, a referral, a
     * guardian, a telephone number and an e-mail into FIVE different free-text
     * fields across a record and an intervention, and asserts that not one
     * distinctive word of any of them reaches the engine — in the two
     * experiences that can see a student at all.
     *
     * ASSERTED ON DISTINCTIVE WORDS, not on whole sentences. «neurologia» and
     * «diagnóstico» appear nowhere else in the fixture, so their absence is
     * evidence; a whole-sentence match would pass on a payload that carried
     * half of one.
     */
    #[Test]
    public function no_free_text_about_a_student_reaches_any_engine(): void
    {
        $this->dirtyUpTheFixture();

        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $sensitiveWords = [
            'neurologia', 'psicólogo', 'Diagnóstico', 'saúde escolar',
            'acompanhamento médico', 'pediu reunião', 'Encaminhada',
        ];

        $engine = $this->engine($this->sixBlockAnswer());
        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sintese-ia")
            ->assertRedirect();

        $followup = $engine->lastRequest();
        $this->assertNotNull($followup);

        $engine = $this->engine($this->sixBlockAnswer());
        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/results")
            ->post("/classes/{$class->ulid}/results/analise-ia")
            ->assertRedirect();

        $assessment = $engine->lastRequest();
        $this->assertNotNull($assessment);

        foreach (['síntese de acompanhamento' => $followup, 'análise da avaliação' => $assessment] as $feature => $sent) {
            foreach ($sensitiveWords as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $sent->content,
                    "«{$word}» — texto livre sobre um aluno — chegou ao motor na {$feature}.",
                );
            }

            $this->assertNoIdentifiers($sent->content);
        }

        // AND THE STRUCTURED FACT DID ARRIVE. The synthesis knows there is an
        // intervention in place; it does not know what it says.
        $this->assertStringContainsString('Intervenções registadas: 1', $followup->content);
    }

    // ------------------------------------------------------- avaliação (Resultados)

    #[Test]
    public function the_assessment_reading_sends_pedagogical_facts_and_no_identifiers(): void
    {
        $this->dirtyUpTheFixture();
        $engine = $this->engine($this->sixBlockAnswer());

        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/results")
            ->post("/classes/{$class->ulid}/results/analise-ia")
            ->assertRedirect();

        $sent = $engine->lastRequest();
        $this->assertNotNull($sent, 'The assessment reading never reached the engine.');

        $this->assertNoIdentifiers($sent->content);

        // AND THE FACTS DID ARRIVE. A payload that sent nothing would pass the
        // assertions above and be a broken feature.
        $this->assertStringContainsString('Disciplina: Português', $sent->content);
        $this->assertStringContainsString('Domínios avaliados:', $sent->content);
        $this->assertStringContainsString('Resultado por aluno:', $sent->content);
        $this->assertStringContainsString('Aluno A', $sent->content);
        $this->assertStringContainsString('Alunos com resultado neste período:', $sent->content);
    }

    // ----------------------------------------------- acompanhamento (Evolução)

    #[Test]
    public function the_followup_synthesis_sends_facts_and_states_but_never_free_text(): void
    {
        $this->dirtyUpTheFixture();
        $engine = $this->engine($this->sixBlockAnswer());

        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sintese-ia")
            ->assertRedirect();

        $sent = $engine->lastRequest();
        $this->assertNotNull($sent, 'The followup synthesis never reached the engine.');

        $this->assertNoIdentifiers($sent->content);

        // THE FREE TEXT OF A RECORD IS ABSENT — the §13 exclusion, asserted
        // against the words that would have carried health, family and address
        // information into a prompt.
        $this->assertStringNotContainsString('acompanhamento médico', $sent->content);
        $this->assertStringNotContainsString('pediu reunião', $sent->content);
        $this->assertStringNotContainsString('Rua das Flores', $sent->content);

        // What DID arrive: the structured counts that make the reading possible.
        $this->assertStringContainsString('Disciplina: Português', $sent->content);
        $this->assertStringContainsString('Total de registos:', $sent->content);
        $this->assertStringContainsString('Registos por tipo:', $sent->content);
    }

    // -------------------------------------------------------------- estratégias

    #[Test]
    public function the_strategy_suggester_sends_a_domain_and_a_pattern_and_no_student(): void
    {
        $this->dirtyUpTheFixture();
        $engine = $this->engine(implode("\n", [
            'NOME: Leitura orientada',
            'OBJETIVO: melhorar a leitura em voz alta.',
            'APLICACAO: leitura orientada diária.',
        ]));

        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $domainId = $this->asTenant(function () use ($enrollment): int {
            $progress = app(BuildStudentProgress::class)
                ->for($this->schoolClass(), $enrollment);

            return (int) $progress['domains']['rows'][0]['domain_id'];
        });

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia", [
                'domain_id' => $domainId,
                'purpose' => 'improvement',
            ])
            ->assertRedirect();

        $sent = $engine->lastRequest();
        $this->assertNotNull($sent, 'The strategy suggester never reached the engine.');

        $this->assertNoIdentifiers($sent->content);

        $this->assertStringContainsString('Domínio:', $sent->content);
        $this->assertStringContainsString('Finalidade escolhida: improvement', $sent->content);
    }

    // -------------------------------------------------------- centro de ajuda

    #[Test]
    public function the_help_assistant_sends_documentation_and_the_question_only(): void
    {
        $engine = $this->engine("RESPOSTA: Vá a Turmas e clique em Nova turma.\nARTIGOS: classes.create");

        $this->actingAs($this->teacher)
            ->from('/help')
            ->post('/help/assistente', ['question' => 'Como crio uma turma?'])
            ->assertRedirect();

        $sent = $engine->lastRequest();
        $this->assertNotNull($sent, 'The help assistant never reached the engine.');

        $this->assertNoIdentifiers($sent->content);
        $this->assertStringContainsString('Pergunta do professor: Como crio uma turma?', $sent->content);
    }

    /**
     * A teacher can paste anything into a help box, and the sanitiser is the
     * only barrier there — the Centro de Ajuda deliberately holds no roster,
     * because fetching one to avoid sending student data would be a worse trade
     * than the one it solves.
     */
    #[Test]
    public function contact_details_pasted_into_the_help_box_are_removed_before_sending(): void
    {
        $engine = $this->engine("RESPOSTA: Vá a Turmas e clique em Nova turma.\nARTIGOS: classes.create");

        $this->actingAs($this->teacher)
            ->from('/help')
            ->post('/help/assistente', [
                'question' => 'Como crio uma turma para o aluno n.º 12, e-mail ana.marques@escola.test, tel. 912345678?',
            ])
            ->assertRedirect();

        $sent = $engine->lastRequest();
        $this->assertNotNull($sent);

        $this->assertStringNotContainsString('ana.marques@escola.test', $sent->content);
        $this->assertStringNotContainsString('912345678', $sent->content);
        $this->assertStringContainsString('[email removido]', $sent->content);
        $this->assertStringContainsString('[número removido]', $sent->content);
    }

    // --------------------------------------------------------------- helpers

    private function assertNoIdentifiers(string $payload): void
    {
        foreach (self::FORBIDDEN as $value) {
            $this->assertStringNotContainsString(
                $value,
                $payload,
                "«{$value}» reached the engine. The allowlist in the context builder is the first barrier and it leaked.",
            );
        }

        // No ULID and no UUID. Every identifier this application exposes in a
        // URL is one of the two, and neither is ever needed by a model.
        $this->assertDoesNotMatchRegularExpression(
            '/\b[0-7][0-9ABCDEFGHJKMNPQRSTVWXYZ]{25}\b/',
            $payload,
            'A ULID reached the engine.',
        );

        // Never a raw e-mail of any shape.
        $this->assertDoesNotMatchRegularExpression(
            '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[a-z]{2,}/iu',
            $payload,
            'An e-mail address reached the engine.',
        );

        // And no run of six or more digits, which is what the sanitiser's
        // last-resort rule exists to stop.
        $this->assertDoesNotMatchRegularExpression('/\b\d{6,}\b/', $payload, 'A long number reached the engine.');
    }
}
