<?php

namespace Tests\Feature\StudentProgress;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\DisciplinarySeverity;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentStatus;
use App\Models\StudentItemScore;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Services\Assessment\Progress\BuildStudentStrengths;
use App\Services\Assessment\Progress\StudentProgressNarrative;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Services\Reporting\Narrative\Phrase;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acompanhamento do Aluno — the panel end to end, Base and Pro.
 *
 * BUILT ON THE SAME DEMO SCENARIO ana.martins@lapis.test WILL OPEN BY HAND
 * afterwards: 7.º A, six students, two periods of results. Nothing here
 * mutates that data — every fixture this file adds (records, interventions,
 * self-assessments) is created fresh, per test, and the aggregated tables
 * are asserted unchanged at the end.
 */
class StudentProgressPanelTest extends TestCase
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
        $this->givePlan('base');
    }

    // ------------------------------------------------------------- andaimes

    protected function givePlan(string $key): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', $key)->firstOrFail()->id,
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

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function enrollment(int $classNumber): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => $this->schoolClass()->enrollments()->where('class_number', $classNumber)->firstOrFail());
    }

    private function domain(string $code): Domain
    {
        return $this->asTenant(fn (): Domain => Domain::where('code', $code)->firstOrFail());
    }

    private function visit(Enrollment $enrollment, ?string $leitura = null): TestResponse
    {
        $class = $this->schoolClass();
        $query = $leitura === null ? '' : '?leitura='.$leitura;

        return $this->actingAs($this->teacher)->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}{$query}");
    }

    /** @return array<string, mixed> */
    private function progress(Enrollment $enrollment): array
    {
        return $this->asTenant(fn (): array => app(BuildStudentProgress::class)->for($this->schoolClass(), $enrollment));
    }

    /** @return array<string, mixed> */
    private function firstDomain(Enrollment $enrollment): array
    {
        return $this->progress($enrollment)['domains']['rows'][0];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(Enrollment $enrollment, EvidenceKind $kind, string $occurredAt, array $extra = []): array
    {
        return $this->asTenant(function () use ($enrollment, $kind, $occurredAt, $extra): array {
            $record = EvidenceRecord::create([
                'class_id' => $enrollment->class_id,
                'enrollment_id' => $enrollment->id,
                'kind' => $kind->value,
                'occurred_at' => $occurredAt,
                'description' => 'Registo de teste.',
                'created_by' => $this->teacher->id,
                ...$extra,
            ]);

            return ['id' => $record->id];
        });
    }

    // --------------------------------------------------------------- §1: isolamento

    #[Test]
    public function another_organizations_teacher_cannot_reach_this_students_panel(): void
    {
        $enrollment = $this->enrollment(1);
        $class = $this->schoolClass();

        $stranger = User::factory()->create(['email' => 'estranho@lapis.test']);

        $this->actingAs($stranger)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertNotFound();
    }

    // ------------------------------------------------------------ §2: acesso Base

    #[Test]
    public function base_reaches_the_panel_and_its_base_sections(): void
    {
        $this->visit($this->enrollment(1))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('factualAlerts')
                ->has('strengths')
                ->missing('pro')
                ->where('ai.available', false)
                ->where('ai.reason', 'plan'));
    }

    // ---------------------------------------------------------- §3: gating Pro

    #[Test]
    public function pro_gating_is_enforced_on_the_server_even_when_requested_directly(): void
    {
        $this->givePlan('pro');

        $this->visit($this->enrollment(1))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pro.estado360.dimensions', 8)
                ->has('pro.analyticalAlerts')
                ->has('pro.positiveSignals')
                ->has('pro.potentialities')
                ->has('pro.whatChanged')
                ->has('pro.evolutionAfterStrategy')
                ->has('pro.conversationPrep'));

        // The SAME route, the same student, only the plan changed — and the
        // key genuinely disappears from the payload rather than arriving
        // empty for the browser to hide.
        $this->givePlan('base');

        $this->visit($this->enrollment(1))
            ->assertInertia(fn ($page) => $page->missing('pro'));
    }

    // ---------------------------------------------------------- §4: a escala real

    #[Test]
    public function the_panel_respects_the_classs_real_scale_not_a_hardcoded_0_100(): void
    {
        $this->visit($this->enrollment(1))
            ->assertInertia(fn ($page) => $page
                ->where('scale.classifies_by_level', true)
                ->has('scale.levels', 5));
    }

    // -------------------------------------------------- §5: autoavaliação qualitativa

    #[Test]
    public function self_assessment_renders_qualitatively_never_an_invented_percentage(): void
    {
        $enrollment = $this->enrollment(1);
        $class = $this->schoolClass();
        $period = $this->period(1);

        $this->submitSelfAssessment($class, $period, $enrollment, '4');

        // Read the payload directly for a precise shape assertion.
        $progress = $this->asTenant(fn () => app(BuildStudentProgress::class)
            ->for($class, $enrollment));

        $entry = collect($progress['selfAssessments'])->firstWhere('period_id', $period->id);

        $this->assertNotNull($entry);
        $this->assertIsArray($entry['self_assessment']);
        $this->assertArrayHasKey('code', $entry['self_assessment']);
        $this->assertArrayHasKey('label', $entry['self_assessment']);
        // Never a percentage: a level's code is short and not a decimal string.
        $this->assertDoesNotMatchRegularExpression('/^\d+[.,]\d+$/', (string) $entry['self_assessment']['code']);
    }

    /**
     * Panel review §3/§7: the periods are named once, by label, and the
     * reading is named by its real label — never "o resultado", and never
     * "(encerrado em ...)" folded into the comparison sentence a second
     * time. The one detailed date mention lives in "Base da comparação"
     * (Show.vue), not asserted here since this test is Inertia-only.
     */
    #[Test]
    public function comparable_moment_sentences_name_periods_by_label_the_real_reading_and_the_delta(): void
    {
        $enrollment = $this->enrollment(1);
        $progress = $this->progress($enrollment);
        $comparison = $progress['sinceLast'];
        $movement = $progress['reading']['kind'] === 'accumulated'
            ? $progress['headline']['continuous_evolution']
            : $progress['headline']['evolution'];

        $this->assertIsArray($comparison);
        $this->assertIsArray($movement);

        $from = Phrase::percentage($comparison['from']);
        $to = Phrase::percentage($comparison['to']);
        $points = Phrase::number(ltrim((string) $movement['points'], '-'));
        $fromLabel = $comparison['from_label'];
        $toLabel = $comparison['to_label'];
        $direction = $movement['direction'] === 'up' ? 'subida' : 'descida';
        $expected = $movement['direction'] === 'flat'
            ? "Do {$fromLabel} para o {$toLabel}, a ".mb_strtolower($progress['reading']['label'])." passou de {$from} para {$to} — sem variação ({$points} p.p.)."
            : "Do {$fromLabel} para o {$toLabel}, a ".mb_strtolower($progress['reading']['label'])." passou de {$from} para {$to} — {$direction} de {$points} p.p.";

        $narrative = app(StudentProgressNarrative::class)->for($progress);
        $this->assertNotNull($narrative);
        $this->assertStringContainsString($expected, $narrative);
        $this->assertStringNotContainsString('encerrado em', $narrative);

        $this->givePlan('pro');
        $this->visit($enrollment)->assertInertia(function ($page) use ($fromLabel, $toLabel, $from, $to, $points) {
            $page->where('pro.estado360.dimensions', function ($dimensions) use ($fromLabel, $toLabel, $from, $to, $points) {
                $detail = collect($dimensions)->firstWhere('key', 'tendencia')['detail'];

                $this->assertStringContainsString("Do {$fromLabel} para o {$toLabel}", $detail);
                $this->assertStringContainsString("{$from} para {$to}", $detail);
                $this->assertStringContainsString("{$points} p.p.", $detail);
                $this->assertStringNotContainsString('encerrado em', $detail);
                $this->assertStringNotContainsString('o resultado passou', $detail);

                return true;
            });
        });
    }

    private function submitSelfAssessment(SchoolClass $class, AcademicPeriod $period, Enrollment $enrollment, string $levelCode): void
    {
        $level = $this->levelWithCode($levelCode);

        $questions = $this->asTenant(fn () => app(SelfAssessmentTemplateProvider::class)->forClass($class)->questions);
        $global = $questions->firstWhere('role', SelfAssessmentQuestionRole::Global);

        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/self-assessments/{$period->ulid}/{$enrollment->ulid}", [
                'answers' => [$global->id => $level->id],
            ])
            ->assertRedirect();
    }

    private function levelWithCode(string $code): ScaleLevel
    {
        return $this->asTenant(fn (): ScaleLevel => Scale::where('name', 'Escala 1 a 5')->firstOrFail()
            ->levels()->where('code', $code)->firstOrFail());
    }

    private function decideClassification(SchoolClass $class, AcademicPeriod $period, Enrollment $enrollment, string $levelCode): void
    {
        // Generates the proposal first — the teacher's own explicit step,
        // never automatic (§3.3 of the classifications brief).
        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/classifications/{$period->ulid}/propose")
            ->assertSessionHasNoErrors();

        $classification = $this->asTenant(fn (): Classification => Classification::where('academic_period_id', $period->id)
            ->where('enrollment_id', $enrollment->id)
            ->where('scope', ClassificationScope::Period->value)
            ->firstOrFail());

        $level = $this->levelWithCode($levelCode);

        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/classifications/{$period->ulid}/{$enrollment->ulid}/decide", [
                'final_scale_level_id' => $level->id,
            ])
            ->assertSessionHasNoErrors();
    }

    // ----------------------------------------------------- §6: alertas factuais

    #[Test]
    public function factual_alerts_fire_on_real_underlying_facts(): void
    {
        // «Atenção automática» and «Pontos fortes identificados
        // automaticamente» are Pro and Institucional (Matriz §4, §5); this
        // test is about WHAT they say, so it runs on the plan that produces
        // them. That Base receives neither is asserted in
        // tests/Feature/Entitlements/BaseProBoundaryTest.php.
        $this->givePlan('pro');

        $enrollment = $this->enrollment(2);
        $period = $this->period(2);

        $this->record($enrollment, EvidenceKind::Homework, $period->starts_on->addDays(5)->toDateString(), [
            'homework_status' => HomeworkStatus::NotDone->value,
        ]);
        $this->record($enrollment, EvidenceKind::Incident, $period->starts_on->addDays(6)->toDateString(), [
            'disciplinary_severity' => 'g4',
        ]);

        $this->visit($enrollment)->assertInertia(fn ($page) => $page
            ->where('factualAlerts', fn ($alerts) => collect($alerts)->pluck('key')->contains('unresolved_homework')
                && collect($alerts)->pluck('key')->contains('disciplinary_incidents')));
    }

    #[Test]
    public function factual_alerts_do_not_fire_when_the_facts_are_absent(): void
    {
        // «Atenção automática» and «Pontos fortes identificados
        // automaticamente» are Pro and Institucional (Matriz §4, §5); this
        // test is about WHAT they say, so it runs on the plan that produces
        // them. That Base receives neither is asserted in
        // tests/Feature/Entitlements/BaseProBoundaryTest.php.
        $this->givePlan('pro');

        // Nº 3, Carolina — DemoDataSeeder gives her no records, no interventions.
        $enrollment = $this->enrollment(3);

        $this->visit($enrollment)->assertInertia(fn ($page) => $page->where('factualAlerts', []));
    }

    /**
     * Panel review, «Atenção» só contém o que pede alguma coisa ao professor:
     * uma autoavaliação SUBMETIDA está por analisar e explica-se; uma já
     * revista não pede nada e não aparece; um rascunho é do aluno e nunca
     * chegou ao professor.
     */
    #[Test]
    public function a_submitted_self_assessment_is_flagged_as_awaiting_the_teachers_analysis(): void
    {
        // «Atenção automática» and «Pontos fortes identificados
        // automaticamente» are Pro and Institucional (Matriz §4, §5); this
        // test is about WHAT they say, so it runs on the plan that produces
        // them. That Base receives neither is asserted in
        // tests/Feature/Entitlements/BaseProBoundaryTest.php.
        $this->givePlan('pro');

        $enrollment = $this->enrollment(3);
        $class = $this->schoolClass();
        $period = $this->period(2);

        $this->submitSelfAssessment($class, $period, $enrollment, '4');

        $this->visit($enrollment)->assertInertia(fn ($page) => $page
            ->where('factualAlerts', function ($alerts) use ($period) {
                $alert = collect($alerts)->firstWhere('key', 'self_assessment_awaiting_review');

                $this->assertNotNull($alert, 'A autoavaliação submetida devia estar assinalada em «Atenção».');
                $this->assertSame(
                    "Existe uma autoavaliação submetida para o {$period->label} por analisar.",
                    $alert['sentence'],
                );

                return true;
            }));
    }

    #[Test]
    public function an_already_reviewed_self_assessment_asks_nothing_and_never_appears_in_attention(): void
    {
        $enrollment = $this->enrollment(3);
        $class = $this->schoolClass();
        $period = $this->period(2);

        $this->submitSelfAssessment($class, $period, $enrollment, '4');

        // Reviewed is a real state of the enum; no user-facing flow reaches it
        // yet, so it is set directly here — what is under test is the alert's
        // reading of the status, not the (still absent) review flow.
        $this->asTenant(fn () => SelfAssessment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('academic_period_id', $period->id)
            ->update(['status' => SelfAssessmentStatus::Reviewed, 'reviewed_at' => now()]));

        $this->visit($enrollment)->assertInertia(fn ($page) => $page
            ->where('factualAlerts', fn ($alerts) => ! collect($alerts)
                ->pluck('key')
                ->contains('self_assessment_awaiting_review')));
    }

    #[Test]
    public function a_draft_self_assessment_never_appears_in_attention(): void
    {
        $enrollment = $this->enrollment(3);
        $class = $this->schoolClass();
        $period = $this->period(2);

        $this->submitSelfAssessment($class, $period, $enrollment, '4');

        $this->asTenant(fn () => SelfAssessment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('academic_period_id', $period->id)
            ->update(['status' => SelfAssessmentStatus::Draft, 'submitted_at' => null]));

        $this->visit($enrollment)->assertInertia(fn ($page) => $page
            ->where('factualAlerts', fn ($alerts) => ! collect($alerts)
                ->pluck('key')
                ->contains('self_assessment_awaiting_review')));
    }

    #[Test]
    public function a_single_light_incident_is_named_exactly_and_never_dramatized(): void
    {
        $this->givePlan('pro');
        $enrollment = $this->enrollment(3);
        $period = $this->period(2);
        $this->record($enrollment, EvidenceKind::Incident, $period->starts_on->addDays(4)->toDateString(), [
            'disciplinary_severity' => DisciplinarySeverity::Grade3->value,
        ]);

        $this->visit($enrollment)->assertInertia(function ($page) {
            $page->where('pro.estado360.dimensions', function ($dimensions) {
                $attitude = collect($dimensions)->firstWhere('key', 'atitudes_comportamento');

                $this->assertSame('registo_isolado', $attitude['state']);
                $this->assertSame('Registo isolado', $attitude['state_label']);
                $this->assertSame('1 ocorrência disciplinar registada no período: Perturbação ligeira da aula.', $attitude['detail']);
                $this->assertStringNotContainsString('requer atenção', mb_strtolower($attitude['detail']));

                return true;
            });
        });
    }

    #[Test]
    public function multiple_incidents_are_grouped_by_real_severity_and_compared_only_to_a_valid_previous_period(): void
    {
        $this->givePlan('pro');
        $enrollment = $this->enrollment(3);
        $period1 = $this->period(1);
        $period2 = $this->period(2);

        $this->record($enrollment, EvidenceKind::Incident, $period1->starts_on->addDays(2)->toDateString(), [
            'disciplinary_severity' => DisciplinarySeverity::Grade2->value,
        ]);
        foreach ([DisciplinarySeverity::Grade2, DisciplinarySeverity::Grade3, DisciplinarySeverity::Grade4] as $offset => $severity) {
            $this->record($enrollment, EvidenceKind::Incident, $period2->starts_on->addDays(3 + $offset)->toDateString(), [
                'disciplinary_severity' => $severity->value,
            ]);
        }

        $this->visit($enrollment)->assertInertia(function ($page) use ($period1, $period2) {
            $page->where('pro.estado360.dimensions', function ($dimensions) use ($period1, $period2) {
                $detail = collect($dimensions)->firstWhere('key', 'atitudes_comportamento')['detail'];

                $this->assertStringStartsWith('3 ocorrências disciplinares registadas no período: 2 ligeiras e 1 moderada.', $detail);
                $this->assertStringContainsString($period1->label, $detail);
                $this->assertStringContainsString($period2->label, $detail);
                $this->assertStringContainsString('a frequência aumentou de 1 para 3 ocorrências.', $detail);

                return true;
            });
        });
    }

    #[Test]
    public function academic_engagement_surfaces_partially_done_and_explains_insufficient_evidence_with_counts(): void
    {
        $this->givePlan('pro');
        $enrollment = $this->enrollment(3);
        $period = $this->period(2);

        $this->record($enrollment, EvidenceKind::Homework, $period->starts_on->addDays(4)->toDateString(), [
            'homework_status' => HomeworkStatus::NotDone->value,
        ]);
        $this->record($enrollment, EvidenceKind::Homework, $period->starts_on->addDays(5)->toDateString(), [
            'homework_status' => HomeworkStatus::PartiallyDone->value,
        ]);

        $this->visit($enrollment)->assertInertia(function ($page) {
            $page->where('pro.estado360.dimensions', function ($dimensions) {
                $engagement = collect($dimensions)->firstWhere('key', 'empenho_academico');

                $this->assertSame('sem_evidencia', $engagement['state']);
                $this->assertSame(
                    'Existem 2 registos de trabalho de casa: 1 parcialmente realizado e 1 não realizado. Não há ainda evidência suficiente para identificar uma tendência consistente.',
                    $engagement['detail'],
                );
                $this->assertStringNotContainsString('Misto', $engagement['detail']);

                return true;
            });
        });
    }

    // -------------------------------------------- §7: leitura analítica (Pro)

    #[Test]
    public function pro_analytical_alerts_add_interpretation_on_top_of_the_same_facts(): void
    {
        $this->givePlan('pro');

        $enrollment = $this->enrollment(2);
        $period1 = $this->period(1);
        $period2 = $this->period(2);

        // More negative records in period 2 than in period 1 — the fact both
        // Base and Pro read, the interpretation only Pro adds.
        $this->record($enrollment, EvidenceKind::Difficulty, $period1->starts_on->addDays(3)->toDateString());
        $this->record($enrollment, EvidenceKind::Difficulty, $period2->starts_on->addDays(3)->toDateString());
        $this->record($enrollment, EvidenceKind::Difficulty, $period2->starts_on->addDays(10)->toDateString());

        $this->visit($enrollment)->assertInertia(fn ($page) => $page
            ->where('pro.analyticalAlerts', fn ($alerts) => collect($alerts)->pluck('key')->contains('negative_records_increase')));
    }

    #[Test]
    public function the_self_assessment_discrepancy_is_a_neutral_direction_never_a_diagnosis(): void
    {
        $this->givePlan('pro');

        $enrollment = $this->enrollment(1);
        $class = $this->schoolClass();
        $period = $this->period(1);

        // The student rated themselves lower than the level the teacher
        // eventually assigned.
        $this->submitSelfAssessment($class, $period, $enrollment, '2');
        $this->decideClassification($class, $period, $enrollment, '4');

        $this->visit($enrollment)->assertInertia(function ($page) {
            $page->where('pro.analyticalAlerts', function ($alerts) {
                $sentence = collect($alerts)->pluck('sentence')->implode(' ');

                $this->assertStringContainsString('abaixo da classificação atribuída', $sentence);
                // Never a psychological reading — the task's own NÃO/SIM pair.
                $this->assertStringNotContainsString('desmotivado', mb_strtolower($sentence));

                return true;
            });
        });
    }

    // ------------------------------------------------- §8: estado neutro

    #[Test]
    public function dimensions_return_a_neutral_state_rather_than_fabricating_one(): void
    {
        $this->givePlan('pro');

        // Nº 3, Carolina — no records, no interventions at all.
        $enrollment = $this->enrollment(3);

        $this->visit($enrollment)->assertInertia(function ($page) {
            $page->where('pro.estado360.dimensions', function ($dimensions) {
                $byKey = collect($dimensions)->keyBy('key');

                $this->assertSame('sem_evidencia', $byKey['empenho_academico']['state']);
                $this->assertSame('sem_registos', $byKey['atitudes_comportamento']['state']);
                $this->assertSame('sem_intervencao', $byKey['acompanhamento']['state']);

                return true;
            });
        });
    }

    // ---------------------------------------- §9: sinais positivos, pontos fortes

    #[Test]
    public function positive_signals_surface_and_not_everything_is_framed_as_a_problem(): void
    {
        $this->givePlan('pro');

        $enrollment = $this->enrollment(1);
        $period = $this->period(2);

        $this->record($enrollment, EvidenceKind::PositiveBehaviour, $period->starts_on->addDays(2)->toDateString());

        $this->visit($enrollment)->assertInertia(fn ($page) => $page
            ->where('strengths', fn ($rows) => collect($rows)->pluck('key')->contains('positive_behaviour'))
            ->where('pro.estado360.dimensions', function ($dimensions) {
                $byKey = collect($dimensions)->keyBy('key');

                return $byKey['atitudes_comportamento']['state'] === 'positivo';
            }));
    }

    #[Test]
    public function pontos_fortes_is_populated_from_real_domain_data(): void
    {
        // «Atenção automática» and «Pontos fortes identificados
        // automaticamente» are Pro and Institucional (Matriz §4, §5); this
        // test is about WHAT they say, so it runs on the plan that produces
        // them. That Base receives neither is asserted in
        // tests/Feature/Entitlements/BaseProBoundaryTest.php.
        $this->givePlan('pro');

        $this->visit($this->enrollment(1))->assertInertia(function ($page) {
            $page->where('strengths', function ($rows) {
                $sentences = collect($rows)->pluck('sentence')->implode(' ');

                $this->assertStringContainsString('ponto forte atual', $sentences);
                $this->assertStringContainsString('%', $sentences);

                return true;
            });
        });
    }

    #[Test]
    public function domain_highlights_and_recent_instruments_carry_real_values_and_accessible_movement_data(): void
    {
        $progress = $this->progress($this->enrollment(1));
        $highlights = $progress['domains']['highlights'];

        foreach (['highest', 'lowest', 'largest_rise', 'largest_fall'] as $key) {
            if ($highlights[$key] !== null) {
                $this->assertArrayHasKey('value', $highlights[$key]);
                $this->assertIsString($highlights[$key]['value']);
                $this->assertTrue(is_numeric($highlights[$key]['value']));
            }
        }

        $this->assertNotNull($highlights['highest']);
        $strengths = $this->asTenant(fn (): array => app(BuildStudentStrengths::class)
            ->for($this->schoolClass(), $this->enrollment(1), $progress, $this->period(2)));
        $current = collect($strengths)->firstWhere('key', 'highest_domain');
        $this->assertStringContainsString('ponto forte atual', $current['sentence']);
        $this->assertStringContainsString('%', $current['sentence']);

        if ($highlights['largest_rise'] !== null) {
            $progressing = collect($strengths)->firstWhere('key', 'largest_rise');
            $this->assertStringContainsString('domínio em progressão', $progressing['sentence']);
            $this->assertStringContainsString('p.p.', $progressing['sentence']);
        }

        $withResults = collect($progress['recentInstruments'])->whereNotNull('result');
        $withEvolution = $withResults->whereNotNull('evolution');

        $this->assertNotEmpty($withResults);
        $this->assertNotEmpty($withEvolution);
        $withEvolution->each(function (array $instrument): void {
            $this->assertContains($instrument['evolution']['direction'], ['up', 'down', 'flat']);
            $this->assertIsString($instrument['evolution']['points']);
            $this->assertNotNull($instrument['scale_label']);
        });
    }

    // --------------------------------------------- §10: potencialidades prudentes

    /**
     * §3 of the panel review, round 2: "Margem de progressão" now states the
     * real, already-known value of the domain it names ("A prioridade de
     * consolidação é Gramática, atualmente o domínio com resultado mais
     * baixo (37,5%)."), which is a fact already on the page — never a
     * FUTURE, invented one. What must never appear is a number this method
     * made up: any percentage in this narrative has to be exactly the real
     * lowest-domain value `consolidationPriority()` reads, never a
     * predicted grade the data does not support.
     */
    #[Test]
    public function potentialities_states_only_the_real_domain_value_never_an_invented_prediction(): void
    {
        $this->givePlan('pro');

        foreach ([1, 2, 3, 4, 5, 6] as $classNumber) {
            $enrollment = $this->enrollment($classNumber);
            $lowest = $this->progress($enrollment)['domains']['highlights']['lowest'];

            $this->visit($enrollment)->assertInertia(function ($page) use ($lowest) {
                $page->where('pro.potentialities', function ($potentialities) use ($lowest) {
                    $narrative = $potentialities['narrative'];

                    if ($narrative === null || preg_match('/\d+(?:,\d+)?%/', $narrative, $match) !== 1) {
                        return true;
                    }

                    $this->assertIsArray($lowest, 'Uma percentagem apareceu em "Margem de progressão" sem um domínio mais baixo real para a justificar.');
                    $this->assertSame(Phrase::percentage($lowest['value']), $match[0]);

                    return true;
                });
            });
        }
    }

    #[Test]
    public function potentialities_have_three_explicit_groups_and_never_a_bare_domain_list(): void
    {
        $this->givePlan('pro');

        $this->visit($this->enrollment(1))->assertInertia(function ($page) {
            $page->where('pro.potentialities', function ($potentialities) {
                $this->assertArrayHasKey('strengths', $potentialities);
                $this->assertArrayHasKey('progressing', $potentialities);
                $this->assertArrayHasKey('next_step', $potentialities);
                $this->assertArrayNotHasKey('domains', $potentialities);

                foreach ($potentialities['strengths'] as $row) {
                    $this->assertStringContainsString('ponto forte atual', $row['detail']);
                }

                foreach ($potentialities['progressing'] as $row) {
                    $this->assertStringContainsString('maior evolução recente', $row['detail']);
                }

                return true;
            });
        });
    }

    #[Test]
    public function initial_ai_purpose_suggestions_use_real_highlight_values_and_omit_missing_highlights(): void
    {
        $sawMissingHighlight = false;

        foreach ([1, 2, 3, 4, 5, 6] as $classNumber) {
            $enrollment = $this->enrollment($classNumber);
            $progress = $this->progress($enrollment);
            $map = [
                'recovery' => ['highlight' => 'lowest', 'needle' => '%'],
                'consolidation' => ['highlight' => 'largest_rise', 'needle' => 'p.p.'],
                'improvement' => ['highlight' => 'highest', 'needle' => '%'],
            ];

            $this->visit($enrollment)->assertInertia(function ($page) use ($progress, $map, &$sawMissingHighlight) {
                $page->where('aiPurposeSuggestions', function ($suggestions) use ($progress, $map, &$sawMissingHighlight) {
                    foreach ($map as $purpose => $expectation) {
                        $highlight = $progress['domains']['highlights'][$expectation['highlight']];
                        $proposal = $suggestions[$purpose];

                        if ($highlight === null) {
                            $sawMissingHighlight = true;
                            $this->assertNull($proposal);

                            continue;
                        }

                        $this->assertSame($highlight['domain_id'], $proposal['domain_id']);
                        $this->assertSame($highlight['name'], $proposal['domain']);
                        $this->assertSame($highlight['value'], $proposal['value']);
                        $this->assertStringContainsString($expectation['needle'], $proposal['justification']);
                        // Rounded to one decimal, exactly as Phrase::number()
                        // rounds it — never the raw canonical precision
                        // (§1 of the panel review, round 2).
                        $this->assertStringContainsString(
                            (string) Phrase::number(ltrim((string) $highlight['value'], '+-')),
                            $proposal['justification'],
                        );
                    }

                    return true;
                });
            });
        }

        $this->assertTrue($sawMissingHighlight, 'O cenário demo deve cobrir pelo menos um destaque nulo por empate ou falta de dados.');
    }

    // ------------------------------------------- §11: recuperação/consolidação/melhoria

    #[Test]
    public function purpose_is_stored_and_read_correctly_and_null_is_handled_gracefully(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $this->actingAs($this->teacher)->post("/classes/{$class->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->id],
            'intervention_type' => 'learning_reinforcement',
            'domain_relation' => 'none',
            'started_on' => '2026-11-10',
            'purpose' => 'recovery',
            'frequency' => '2x por semana',
            'tracking_indicator' => 'n.º de leituras concluídas',
        ])->assertSessionHasNoErrors();

        $withPurpose = $this->asTenant(fn (): Intervention => Intervention::latest('id')->firstOrFail());
        $this->assertSame('recovery', $withPurpose->purpose->value);
        $this->assertSame('2x por semana', $withPurpose->frequency);

        // A second intervention, with no purpose named at all.
        $this->actingAs($this->teacher)->post("/classes/{$class->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->id],
            'intervention_type' => 'learning_reinforcement',
            'domain_relation' => 'none',
            'started_on' => '2026-11-11',
        ])->assertSessionHasNoErrors();

        $withoutPurpose = $this->asTenant(fn (): Intervention => Intervention::latest('id')->firstOrFail());
        $this->assertNull($withoutPurpose->purpose);

        $this->visit($enrollment)->assertInertia(function ($page) {
            $page->where('interventions.rows', function ($rows) {
                $byPurpose = collect($rows)->pluck('purpose_label', 'purpose')->all();

                $this->assertSame('Recuperação', $byPurpose['recovery'] ?? null);
                // Null is never defaulted into one of the three.
                $this->assertContains(null, collect($rows)->pluck('purpose')->all());

                return true;
            });
        });
    }

    // --------------------------------------------------- §12: IA nunca escreve

    #[Test]
    public function the_ai_suggestion_path_never_creates_an_intervention_even_when_unavailable(): void
    {
        $enrollment = $this->enrollment(1);
        $class = $this->schoolClass();
        $before = Intervention::withoutGlobalScope('organization')->count();
        $domain = $this->firstDomain($enrollment);
        $payload = ['domain_id' => $domain['domain_id'], 'purpose' => 'recovery'];

        // Base: unavailable by plan.
        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia", $payload)
            ->assertRedirect();

        $this->assertSame($before, Intervention::withoutGlobalScope('organization')->count());
        $this->assertIsArray(session('aiSuggestionError'));

        // Pro, no engine configured: unavailable by provider.
        $this->givePlan('pro');
        config(['lapis.ai.driver' => null]);

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia", $payload)
            ->assertRedirect();

        $this->assertSame($before, Intervention::withoutGlobalScope('organization')->count());

        // Pro, engine configured and answering: a suggestion is produced,
        // and STILL nothing is written.
        config(['lapis.ai.driver' => 'fake']);
        $engine = new FakeAiTextProvider('modelo-de-teste');
        $engine->willReturn(
            "NOME: Leitura orientada\nOBJETIVO: melhorar a leitura.\nAPLICACAO: leitura orientada.\nFREQUENCIA: 2x por semana\nDURACAO: 4 semanas\nINDICADOR: número de textos lidos\nREVISAO: daqui a 4 semanas",
        );
        $this->app->instance(FakeAiTextProvider::class, $engine);

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia", $payload)
            ->assertRedirect();

        $this->assertSame($before, Intervention::withoutGlobalScope('organization')->count());
        $this->assertIsArray(session('aiSuggestion'));
        $this->assertCount(1, session('aiSuggestion')['suggestions']);
        $this->assertSame('recovery', session('aiSuggestion')['suggestions'][0]['purpose']);
    }

    // ------------------------------------------------- §13: prefill do relatório

    #[Test]
    public function gerar_relatorio_link_carries_the_right_aluno_turma_e_periodo(): void
    {
        $enrollment = $this->enrollment(1);
        $class = $this->schoolClass();

        $this->visit($enrollment)->assertInertia(function ($page) use ($class, $enrollment) {
            $page->where('links.reports', function (string $url) use ($class, $enrollment) {
                $this->assertStringContainsString('type=student', $url);
                $this->assertStringContainsString("class={$class->ulid}", $url);
                $this->assertStringContainsString("enrollment={$enrollment->ulid}", $url);

                return true;
            });
        });

        $period = $this->period(2);

        $this->actingAs($this->teacher)
            ->get("/reports/novo?type=student&class={$class->ulid}&enrollment={$enrollment->ulid}&period={$period->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('preselected.class_id', $class->id)
                ->where('preselected.enrollment_id', $enrollment->id)
                ->where('preselected.academic_period_id', $period->id));
    }

    // -------------------------------------------- §14: nada escreve nas fontes

    #[Test]
    public function nothing_here_writes_to_an_aggregated_source_table(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.driver' => 'fake']);
        $engine = new FakeAiTextProvider('modelo-de-teste');
        $this->app->instance(FakeAiTextProvider::class, $engine);

        $scoreCount = StudentItemScore::withoutGlobalScope('organization')->count();
        $classificationCount = Classification::withoutGlobalScope('organization')->count();
        $recordCount = EvidenceRecord::withoutGlobalScope('organization')->count();
        $selfAssessmentCount = SelfAssessment::withoutGlobalScope('organization')->count();

        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);
        $domain = $this->firstDomain($enrollment);

        $this->visit($enrollment)->assertOk();
        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia", [
                'domain_id' => $domain['domain_id'],
                'purpose' => 'improvement',
            ]);

        $this->assertSame($scoreCount, StudentItemScore::withoutGlobalScope('organization')->count());
        $this->assertSame($classificationCount, Classification::withoutGlobalScope('organization')->count());
        $this->assertSame($recordCount, EvidenceRecord::withoutGlobalScope('organization')->count());
        $this->assertSame($selfAssessmentCount, SelfAssessment::withoutGlobalScope('organization')->count());
    }

    // ------------------------------------ §15: revisão do painel (ago. 2026)

    /**
     * Not a single string this scenario happens to produce — every sentence
     * a teacher actually reads (Base and Pro alike), across all three
     * readings (the default, "leitura=continua" and "leitura=periodo"), is
     * scanned for the signature of a raw, unrounded DB value: a DOT or a
     * COMMA followed by two or more digits. pt-PT prose never produces
     * either — the comma is the decimal separator here, and
     * Phrase::number()/percentage() always round to one decimal — so a
     * dot-decimal («59.433584», a bare (string) $float) and a comma-decimal
     * with more than one fraction digit («59,433584%», a value merely
     * relocalised without ever being rounded — the actual bug found during
     * this round of visual validation, traced to `Phrase::number()` itself
     * never rounding) are BOTH the same bug class, and BOTH must never
     * appear here again. Generic on purpose: it scans every sentence-bearing
     * field this page has, not only the one string a human happened to spot.
     */
    #[Test]
    public function no_sentence_on_the_page_ever_shows_raw_database_precision(): void
    {
        $this->givePlan('pro');
        $pattern = '/\d+[.,]\d{2,}/';

        foreach ([1, 2, 3, 4, 5, 6] as $classNumber) {
            $enrollment = $this->enrollment($classNumber);

            foreach ([null, 'continua', 'periodo'] as $leitura) {
                $this->visit($enrollment, $leitura)->assertInertia(function ($page) use ($pattern) {
                    $page->where('pro', function ($pro) use ($pattern) {
                        $pro = collect($pro);

                        $texts = collect()
                            ->merge(collect($pro->get('analyticalAlerts'))->pluck('sentence'))
                            ->merge(collect($pro->get('positiveSignals'))->pluck('sentence'))
                            ->merge(collect(data_get($pro, 'whatChanged.items'))->pluck('sentence'))
                            ->merge(collect(data_get($pro, 'estado360.dimensions'))->pluck('detail'))
                            ->merge(collect(data_get($pro, 'potentialities.strengths'))->pluck('detail'))
                            ->merge(collect(data_get($pro, 'potentialities.progressing'))->pluck('detail'))
                            ->push(data_get($pro, 'potentialities.narrative'))
                            ->push(data_get($pro, 'potentialities.next_step'))
                            ->filter();

                        foreach ($texts as $text) {
                            $this->assertDoesNotMatchRegularExpression($pattern, $text, "Precisão de base de dados a nu em: {$text}");
                        }

                        return true;
                    });
                    $page->where('narrative', function ($narrative) use ($pattern) {
                        if ($narrative !== null) {
                            $this->assertDoesNotMatchRegularExpression($pattern, $narrative);
                        }

                        return true;
                    });
                    $page->where('factualAlerts', function ($alerts) use ($pattern) {
                        foreach (collect($alerts)->pluck('sentence') as $sentence) {
                            $this->assertDoesNotMatchRegularExpression($pattern, $sentence);
                        }

                        return true;
                    });
                    $page->where('strengths', function ($rows) use ($pattern) {
                        foreach (collect($rows)->pluck('sentence') as $sentence) {
                            $this->assertDoesNotMatchRegularExpression($pattern, $sentence);
                        }

                        return true;
                    });
                    // The AI "initial combination" justification («Resultado
                    // atual mais baixo: 37,5%.») — a different code path
                    // (StudentProgressController::aiPurposeSuggestions()),
                    // never audited by the fields above.
                    $page->where('aiPurposeSuggestions', function ($suggestions) use ($pattern) {
                        foreach (collect($suggestions)->filter()->pluck('justification') as $justification) {
                            $this->assertDoesNotMatchRegularExpression($pattern, $justification);
                        }

                        return true;
                    });
                });
            }
        }
    }

    /**
     * §7 of the panel review: "O que mudou" names the metric actually shown
     * on this page — Média Ponderada / Média Ponderada Acumulada — and
     * never the generic word "resultado" when a specific reading applies.
     */
    #[Test]
    public function what_changed_names_the_real_reading_never_a_bare_resultado(): void
    {
        $this->givePlan('pro');
        $enrollment = $this->enrollment(1);
        $progress = $this->progress($enrollment);
        $label = mb_strtolower((string) $progress['reading']['label']);

        $this->visit($enrollment)->assertInertia(function ($page) use ($label) {
            $page->where('pro.whatChanged.items', function ($items) use ($label) {
                $result = collect($items)->firstWhere('key', 'result');
                $this->assertNotNull($result, 'Esperava-se um item "result" em O que mudou para este aluno.');

                $sentence = mb_strtolower($result['sentence']);
                $this->assertStringContainsString($label, $sentence);
                $this->assertStringNotContainsString('o resultado passou', $sentence);

                return true;
            });
        });
    }

    /**
     * §4 of the panel review: the positive signal names the category that
     * actually improved, with its real before/after counts, rather than a
     * sum across categories that could hide one getting worse behind
     * another improving more.
     */
    #[Test]
    public function positive_signals_name_the_specific_category_that_improved_with_real_counts(): void
    {
        $this->givePlan('pro');
        $enrollment = $this->enrollment(2);
        $period1 = $this->period(1);
        $period2 = $this->period(2);

        // Two disciplinary incidents in the earlier period, none in the one
        // being read now — a real, single-category recovery.
        $this->record($enrollment, EvidenceKind::Incident, $period1->starts_on->addDays(2)->toDateString(), [
            'disciplinary_severity' => 'g3',
        ]);
        $this->record($enrollment, EvidenceKind::Incident, $period1->starts_on->addDays(3)->toDateString(), [
            'disciplinary_severity' => 'g3',
        ]);

        $this->visit($enrollment)->assertInertia(function ($page) use ($period1, $period2) {
            $page->where('pro.positiveSignals', function ($signals) use ($period1, $period2) {
                $recovery = collect($signals)->firstWhere('key', 'recovery');
                $this->assertNotNull($recovery, 'Esperava-se um sinal positivo "recovery" para esta redução real.');

                $this->assertSame(
                    "No {$period1->label} existiam 2 registos que requeriam atenção: Ocorrências disciplinares. No {$period2->label} não existem registos dessa natureza.",
                    $recovery['sentence'],
                );

                return true;
            });
        });
    }

    /**
     * §8 of the panel review: a domain with visible results elsewhere on
     * this same page is never told it "não tem registos" without qualifying
     * that this is specifically about the acompanhamento logbook — a
     * different, unrelated data source from the assessment results.
     */
    #[Test]
    public function domains_without_evidence_names_the_acompanhamento_log_explicitly(): void
    {
        // «Atenção automática» and «Pontos fortes identificados
        // automaticamente» are Pro and Institucional (Matriz §4, §5); this
        // test is about WHAT they say, so it runs on the plan that produces
        // them. That Base receives neither is asserted in
        // tests/Feature/Entitlements/BaseProBoundaryTest.php.
        $this->givePlan('pro');

        $enrollment = $this->enrollment(1);
        $period = $this->period(2);
        $domains = $this->progress($enrollment)['domains']['rows'];
        $this->assertGreaterThan(1, count($domains), 'O cenário de demonstração precisa de mais do que um domínio.');

        $withEvidence = $domains[0];

        $this->record($enrollment, EvidenceKind::Note, $period->starts_on->addDays(1)->toDateString(), [
            'domain_id' => $withEvidence['domain_id'],
        ]);

        $this->visit($enrollment)->assertInertia(function ($page) use ($withEvidence) {
            $page->where('factualAlerts', function ($alerts) use ($withEvidence) {
                $row = collect($alerts)->firstWhere('key', 'domains_without_evidence');
                $this->assertNotNull($row, 'Esperava-se o alerta domains_without_evidence com pelo menos um domínio sem registos.');

                $this->assertStringContainsString('registo', $row['sentence']);
                $this->assertStringContainsString('acompanhamento', $row['sentence']);
                $this->assertStringNotContainsString('«'.$withEvidence['name'].'»', $row['sentence']);
                // Never the bare, unqualified claim that the domain "não tem
                // registos" — that domain visibly has results elsewhere on
                // this same page.
                $this->assertDoesNotMatchRegularExpression('/» não t[eê]m? registos\./u', $row['sentence']);

                return true;
            });
        });
    }
}
