<?php

namespace Tests\Feature\Usage;

use App\Models\ClassNotebookEntry;
use App\Models\InterventionType;
use App\Models\UsageEvent;
use App\Services\Usage\AggregateUsage;
use App\Support\Usage\UsageEventKind;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFailureCode;
use App\Support\Usage\UsageFeature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * A promessa central de docs/usage-insights.md, posta à prova pelos ganchos
 * reais: gravar conteúdo pedagógico nunca deixa conteúdo nas tabelas de utilização.
 */
class UsageHooksPrivacyTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase, SetsUsageCoverage;

    private const SENTINEL = 'SENTINELA-PRIVADA-7f3a';

    private const STUDENT = 'Anastácia Ficcional Zacarias';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $this->bootLessonFixtures();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @return list<string> */
    private function serialisedRows(string $table): array
    {
        return DB::table($table)->get()
            ->map(fn (object $row): string => (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            ->all();
    }

    #[Test]
    public function nothing_a_teacher_writes_ever_reaches_the_usage_tables(): void
    {
        $enrollment = $this->enroll(self::STUDENT);
        $lesson = $this->makeLesson();

        // Um sumário com texto, notas privadas, recursos e trabalho de casa.
        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'summary_version' => $this->summaryVersion($lesson),
            'content' => 'Sumário '.self::SENTINEL.' sobre '.self::STUDENT,
            'private_notes' => 'Notas '.self::SENTINEL,
            'resources' => 'https://exemplo.test/'.self::SENTINEL,
            'homework' => 'TPC '.self::SENTINEL,
        ])->assertRedirect();

        // Uma entrada do caderno, criada e editada.
        $notebook = "/classes/{$this->schoolClass->ulid}/notebook";
        $this->asTeacher()->post($notebook, ['title' => self::SENTINEL, 'body' => self::STUDENT.' '.self::SENTINEL])->assertRedirect();
        $entryUlid = $this->inTenant($this->organization, fn () => ClassNotebookEntry::query()->firstOrFail()->ulid);
        $this->asTeacher()->put("{$notebook}/{$entryUlid}", ['title' => self::SENTINEL, 'body' => 'Alterado '.self::SENTINEL, 'lock_version' => 0])->assertRedirect();

        // Uma intervenção com dois tipos e descrição com o nome do aluno.
        $this->asTeacher()->postJson("/classes/{$this->schoolClass->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->id],
            'intervention_types' => [InterventionType::PedagogicalDifferentiation->value, InterventionType::PsychopedagogicalSupport->value],
            'domain_relation' => 'none',
            'description' => self::SENTINEL.' '.self::STUDENT,
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
        ])->assertRedirect();

        // Um registo com descrição.
        $this->asTeacher()->postJson("/classes/{$this->schoolClass->ulid}/records", [
            'kind' => 'participation', 'disciplinary_severity' => null, 'homework_status' => null,
            'participation_level' => 'positive', 'activity_evaluation' => null, 'activity_include_in_report' => null,
            'description' => self::SENTINEL, 'occurred_at' => '2026-10-12',
            'enrollment_ids' => [$enrollment->id], 'domain_id' => null,
        ])->assertRedirect();

        // Houve mesmo ações registadas — senão o teste não provava nada.
        $this->assertGreaterThanOrEqual(5, UsageEvent::query()->where('kind', UsageEventKind::Action->value)->count());

        app(AggregateUsage::class)->run(CarbonImmutable::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));
        $this->assertGreaterThan(0, DB::table('usage_daily_rollups')->count());

        foreach (['usage_events', 'usage_daily_rollups', 'usage_user_states', 'usage_collection_periods', 'usage_aggregation_state'] as $table) {
            foreach ($this->serialisedRows($table) as $row) {
                $this->assertStringNotContainsString('SENTINELA', $row, "{$table} guardou conteúdo pedagógico");
                $this->assertStringNotContainsString('Anastácia', $row, "{$table} guardou o nome de um aluno");
                $this->assertStringNotContainsString('Zacarias', $row, "{$table} guardou o nome de um aluno");
            }
        }
    }

    #[Test]
    public function the_only_text_columns_of_usage_events_hold_catalogue_values(): void
    {
        $lesson = $this->makeLesson();
        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'summary_version' => $this->summaryVersion($lesson),
            'content' => self::SENTINEL,
        ])->assertRedirect();
        $this->asTeacher()->post("/classes/{$this->schoolClass->ulid}/notebook", ['title' => null, 'body' => self::SENTINEL])->assertRedirect();

        $rows = DB::table('usage_events')->get();
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertNotNull(UsageFeature::tryFrom($row->feature));
            $this->assertNotNull(UsageEventType::tryFrom($row->event));
            $this->assertNotNull(UsageEventKind::tryFrom($row->kind));
            $this->assertTrue($row->failure_code === null || UsageFailureCode::tryFrom($row->failure_code) !== null);
            // O resumo HMAC não é reversível: 64 caracteres hexadecimais, nada mais.
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row->dedupe_key);
        }
    }
}
