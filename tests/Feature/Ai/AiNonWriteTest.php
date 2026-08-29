<?php

namespace Tests\Feature\Ai;

use App\Domain\Reporting\SectionKey;
use App\Models\Enrollment;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportSection;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Services\Reporting\CreateReport;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A IA SUGERE. O PROFESSOR DECIDE (§15).
 *
 * The principle, tested as a property of the database rather than as a promise
 * in a docblock: run every AI experience the product has, and assert that not
 * one row of pedagogical data moved.
 *
 * WHY A SNAPSHOT AND NOT A LIST OF ASSERTIONS. «The grade did not change» is
 * the obvious test and it is the weak one — it only catches the write somebody
 * thought of. A checksum over every pedagogical table catches the write nobody
 * thought of: a `touch()` on the class, a status flipped by a listener, an
 * intervention created by a helper somebody reused. If a single byte of any of
 * these tables differs after an AI reading, this fails and names the table.
 *
 * WHAT COUNTS AS PEDAGOGICAL DATA HERE is listed in `TABLES` below and is
 * deliberately wide: results, scores, classifications, records, interventions,
 * enrolments, instruments, profile versions. If any of these can change because
 * somebody asked a model a question, the product has lost the property it sells.
 *
 * WHAT IS ALLOWED TO CHANGE, AND IS EXCLUDED ON PURPOSE: `ai_usage_events` (the
 * meter — a call happened, and it must be recorded) and `audit_events` (the
 * trail — a person asked for something, and that is exactly what an audit log
 * is for). Both are records ABOUT the request, neither is a pedagogical fact,
 * and a test that forbade them would be forbidding the accountability the rest
 * of this module is built on.
 */
class AiNonWriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every table that holds a pedagogical fact. Not one of them may differ
     * after an AI reading.
     */
    private const TABLES = [
        'student_item_scores',
        'classifications',
        'evidence_records',
        'interventions',
        'intervention_reviews',
        'enrollments',
        'instruments',
        'instrument_items',
        'assessment_profile_versions',
        'self_assessments',
        'classes',
        'reports',
        'report_sections',
    ];

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();

        $this->seed(EntitlementsSeeder::class);
        $this->givePlan('institutional');

        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'modelo-de-teste']);
    }

    // ------------------------------------------------------------------ the tests

    #[Test]
    public function an_assessment_reading_changes_no_pedagogical_data(): void
    {
        $this->engine($this->sixBlockAnswer());
        $class = $this->schoolClass();

        $this->assertChangesNothing(function () use ($class): void {
            $this->actingAs($this->teacher)
                ->from("/classes/{$class->ulid}/results")
                ->post("/classes/{$class->ulid}/results/analise-ia")
                ->assertRedirect();
        });
    }

    #[Test]
    public function a_class_statistics_reading_changes_no_pedagogical_data(): void
    {
        $this->engine(implode("\n", [
            'SINTESE: A turma apresenta resultados globalmente positivos.',
            'PADROES: - Os resultados concentram-se nos níveis intermédios.',
            'ATENCAO: - Nada a assinalar nos dados fornecidos.',
            'SUGESTOES: - Pode ser útil considerar tarefas de escrita mais frequentes.',
        ]));

        $class = $this->schoolClass();

        $this->assertChangesNothing(function () use ($class): void {
            $this->actingAs($this->teacher)
                ->from("/classes/{$class->ulid}/results/estatistica")
                ->post("/classes/{$class->ulid}/results/estatistica/analise-ia")
                ->assertRedirect();
        });
    }

    #[Test]
    public function a_followup_synthesis_changes_no_pedagogical_data(): void
    {
        $this->engine($this->sixBlockAnswer());

        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->assertChangesNothing(function () use ($class, $enrollment): void {
            $this->actingAs($this->teacher)
                ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
                ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sintese-ia")
                ->assertRedirect();
        });
    }

    /**
     * THE ONE MOST WORTH TESTING, because it is the one whose answer LOOKS like
     * something to save. A strategy suggestion arrives with a name, an
     * objective, a frequency and a review date — the exact shape of an
     * Intervention — and the temptation to persist it is the whole reason §9
     * says the writing must be a second, human action.
     */
    #[Test]
    public function a_strategy_suggestion_creates_no_intervention(): void
    {
        $this->engine(implode("\n", [
            'NOME: Leitura orientada',
            'OBJETIVO: melhorar a leitura em voz alta.',
            'APLICACAO: leitura orientada diária.',
            'FREQUENCIA: diária',
            'DURACAO: 4 semanas',
            'INDICADOR: fluência nas leituras seguintes',
            'REVISAO: após 3 evidências comparáveis',
        ]));

        $class = $this->schoolClass();
        $enrollment = $this->enrollment();
        $domainId = $this->firstDomainId($enrollment);

        $this->assertChangesNothing(function () use ($class, $enrollment, $domainId): void {
            $this->actingAs($this->teacher)
                ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
                ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia", [
                    'domain_id' => $domainId,
                    'purpose' => 'improvement',
                ])
                ->assertRedirect();
        });

        // Said again explicitly, because this is the assertion somebody will
        // look for by name when they are worried about it.
        $this->assertSame(0, $this->asTenant(fn (): int => Intervention::query()->count()));
    }

    /**
     * A REPORT REWRITE PROPOSES; ACCEPTING IS A SEPARATE REQUEST.
     *
     * The section's own `body` is asserted unchanged after the suggestion, and
     * the suggestion itself lives in the session — the writing happens through
     * the section editor, with the teacher's own submit, which
     * `WritingAssistantTest::a_suggestion_does_not_replace_anything_until_it_is_accepted`
     * already covers end to end. This is the database-wide version of the same
     * claim.
     */
    #[Test]
    public function a_report_rewrite_changes_nothing_until_a_human_accepts_it(): void
    {
        $this->engine('A turma manteve o desempenho ao longo do intervalo analisado, com estabilidade nos resultados.');

        // Built through the real creation service, exactly as
        // `WritingAssistantTest` does: a report assembled any other way would
        // not have the sections the rewrite endpoint expects to find.
        $report = $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->asTenant(fn () => $this->schoolClass()->academicYear->periods()->orderBy('sequence')->firstOrFail()),
        ));

        $section = $this->asTenant(function () use ($report): ReportSection {
            $section = $report->sections()->where('key', SectionKey::OverallAssessment->value)->firstOrFail();
            $section->update(['body' => 'A turma manteve o desempenho ao longo do intervalo analisado.']);

            return $section->fresh();
        });

        $this->assertChangesNothing(function () use ($report, $section): void {
            $this->actingAs($this->teacher)
                ->from("/reports/{$report->ulid}")
                ->post("/reports/{$report->ulid}/seccoes/{$section->ulid}/aperfeicoar", ['mode' => 'clearer'])
                ->assertRedirect();
        });
    }

    // ---------------------------------------------------------------- machinery

    /**
     * Run something, and fail if any pedagogical table changed.
     *
     * A CHECKSUM PER TABLE, not a row count: a row count would miss an UPDATE,
     * which is the write that actually matters here — nobody fears an AI
     * INSERTing a grade; they fear it changing one.
     */
    private function assertChangesNothing(callable $action): void
    {
        $before = $this->snapshot();

        $action();

        $after = $this->snapshot();

        foreach (self::TABLES as $table) {
            $this->assertSame(
                $before[$table],
                $after[$table],
                "An AI request changed `{$table}`. A IA sugere; o professor decide (§15) — nothing on an AI path may write pedagogical data.",
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach (self::TABLES as $table) {
            // Ordered by primary key so the digest is stable, and serialised
            // whole so an UPDATE to any column shows up.
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row): string => json_encode($row) ?: '')->all();

            $snapshot[$table] = hash('sha256', implode('|', $rows));
        }

        return $snapshot;
    }

    private function engine(string $answer): FakeAiTextProvider
    {
        $engine = new FakeAiTextProvider('modelo-de-teste');
        $engine->willReturn($answer);
        $this->app->instance(FakeAiTextProvider::class, $engine);

        return $engine;
    }

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

    private function firstDomainId(Enrollment $enrollment): int
    {
        return $this->asTenant(function () use ($enrollment): int {
            $progress = app(BuildStudentProgress::class)
                ->for($this->schoolClass(), $enrollment);

            return (int) $progress['domains']['rows'][0]['domain_id'];
        });
    }
}
