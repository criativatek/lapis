<?php

namespace Tests\Feature\Assessment\Advanced;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EvaluationSheetExport;
use App\Models\Module;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\Advanced\BuildAssessmentComparison;
use App\Services\Assessment\Advanced\BuildAssessmentTimeline;
use App\Services\Assessment\Advanced\BuildClassStrengths;
use App\Services\Assessment\Advanced\BuildClassTrends;
use App\Services\Assessment\Advanced\BuildStudentYear;
use App\Services\Assessment\Advanced\Document\AnalysisDocumentOptions;
use App\Services\Assessment\Advanced\Document\BuildAnalysisDocument;
use App\Services\Assessment\Advanced\Document\DocumentFormat;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\Export\EvaluationSheetCellText;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Entitlements\Entitlements;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fatia D — validação final (D6): as funcionalidades Pro das etapas D0 a D5, vistas de fora e todas ao mesmo tempo.
 *
 * Cada etapa tem os seus testes. O que se afirma aqui é o que só se vê com o conjunto:
 *
 *  - PARIDADE ENTRE SUPERFÍCIES: o mesmo número é o mesmo no ecrã, no documento, na Estatística e nas
 *    exportações da Pauta (CSV de hoje e de um momento guardado);
 *  - ACESSO: uma matriz — todas as superfícies Pro × todas as situações em que alguém as pede — porque o
 *    Pro tem de falhar fechado em todas, e não só onde se lembraram de testar;
 *  - O BASE NÃO SE MEXE: abrir tudo o que é Pro não escreve um byte nos dados, e o Base continua a
 *    abrir, imprimir e exportar o que sempre abriu, sem rasto da camada Pro;
 *  - DADOS HISTÓRICOS: cada leitura aguenta o que a produção tem — fotografias antigas, turmas sem perfil,
 *    sem períodos, com alunos que saíram — e diz o que não pode dizer, em vez de falhar;
 *  - DESEMPENHO: o custo de cada superfície não cresce com a turma.
 */
class FatiaDValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));
        $this->subscribe('pro');

        $this->asTenant(function (): void {
            $class = $this->class();
            foreach ($class->academicYear->periods()->orderBy('sequence')->get() as $period) {
                app(ProposeClassifications::class)->forPeriod($class, $period);
            }
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function subscribe(string $plan, SubscriptionStatus $status = SubscriptionStatus::Active): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->teacher->personalOrganization()->id],
            ['plan_id' => Plan::where('key', $plan)->firstOrFail()->id, 'status' => $status, 'starts_at' => Carbon::parse('2026-01-01 00:00:00'), 'ends_at' => null],
        );
        app(Entitlements::class)->flush();
    }

    private function class(): SchoolClass
    {
        return SchoolClass::where('label', '7.º A')->with('profileVersion')->firstOrFail();
    }

    private function classUlid(): string
    {
        return $this->asTenant(fn (): string => $this->class()->ulid);
    }

    private function enrollment(string $name): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => $this->class()->enrollments()->with('student.identity')->get()
            ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === $name));
    }

    /** @return array{string, string} */
    private function periodUlids(): array
    {
        return $this->asTenant(function (): array {
            $periods = $this->class()->academicYear->periods()->orderBy('sequence')->get();

            return [$periods[0]->ulid, $periods[1]->ulid];
        });
    }

    /** @return array<string, mixed> */
    private function timeline(): array
    {
        return $this->asTenant(fn (): array => app(BuildAssessmentTimeline::class)->for($this->class()));
    }

    private function keep(string $label, int $sequence = 1): string
    {
        $period = $this->asTenant(fn (): string => $this->class()->academicYear->periods()->where('sequence', $sequence)->firstOrFail()->ulid);

        $this->actingAs($this->teacher)->post("/classes/{$this->classUlid()}/pauta-avaliacao/{$period}/guardar", [
            'moment_label' => $label, 'effective_at' => '2026-12-15', 'scope' => ClassificationScope::Period->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return $this->asTenant(fn (): string => EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail()->ulid);
    }

    private function rewrite(string $exportUlid, callable $change): void
    {
        $this->asTenant(function () use ($exportUlid, $change): void {
            $export = EvaluationSheetExport::where('ulid', $exportUlid)->firstOrFail();
            $payload = $change($export->payload);

            DB::table('evaluation_sheet_exports')->where('id', $export->id)->update([
                'payload' => json_encode($payload),
                'payload_hash' => CanonicalPayload::hash($payload),
            ]);
        });
    }

    // ================================================================= 1. paridade entre superfícies

    #[Test]
    public function the_same_class_mean_is_the_same_in_the_analysis_the_document_and_estatistica(): void
    {
        $timeline = $this->timeline();
        [$first, $second] = array_column(array_filter($timeline['points'], fn (array $point): bool => $point['kind'] === 'period'), 'key');

        $comparison = $this->asTenant(fn (): array => app(BuildAssessmentComparison::class)->between($timeline, $first, $second));
        $strengths = $this->asTenant(fn (): array => app(BuildClassStrengths::class)->for($timeline, $second));
        $trends = $this->asTenant(fn (): array => app(BuildClassTrends::class)->for($timeline));
        $document = $this->asTenant(fn (): array => app(BuildAnalysisDocument::class)->build($this->class(), $timeline, $first, $second, $second, new AnalysisDocumentOptions));

        $estatistica = $this->asTenant(function (): string {
            $class = $this->class();

            return (string) app(BuildClassStatistics::class)->for($class, $class->academicYear->periods()->where('sequence', 2)->firstOrFail())['summary']['class_average'];
        });

        // One figure, four readings of it.
        $this->assertSame($estatistica, $comparison['class']['moments']['to']['mean']);
        $this->assertSame($estatistica, $strengths['result']['mean']);
        $this->assertSame($estatistica, last(array_column($trends['class']['global']['everyone'], 'mean')));

        $text = json_encode($document['sections'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Média em «2.º Semestre»', (string) $text);
        $this->assertStringContainsString(DocumentFormat::percent($estatistica).' · ', (string) $text);
    }

    #[Test]
    public function each_students_figure_is_the_paulas_csv_and_the_documents_and_the_screens(): void
    {
        $timeline = $this->timeline();
        $periodKey = 'period:'.$this->periodUlids()[1];
        $csv = $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/csv/{$this->periodUlids()[1]}")->assertOk()->getContent();
        $document = $this->asTenant(fn (): array => app(BuildAnalysisDocument::class)->build($this->class(), $timeline, null, null, null, new AnalysisDocumentOptions(students: true)));
        $documentText = (string) json_encode($document['sections'], JSON_UNESCAPED_UNICODE);

        $checked = 0;
        foreach ($timeline['students'] as $student) {
            $value = $student['cells'][$periodKey]['result']['value'] ?? null;

            if ($value === null) {
                continue;
            }

            $line = collect(preg_split('/\R/', (string) $csv))->first(fn (string $row): bool => str_contains($row, $student['name']));

            $this->assertNotNull($line, "{$student['name']} is in the Pauta's CSV");
            // The CSV writes one decimal with a point; the document, one decimal with a comma.
            $this->assertStringContainsString(EvaluationSheetCellText::percentage($value), (string) $line, "{$student['name']}: the CSV");
            $this->assertStringContainsString(DocumentFormat::percent($value), $documentText, "{$student['name']}: the document");
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'the check is not vacuous');
    }

    #[Test]
    public function a_kept_moment_says_in_the_analysis_what_its_own_csv_says(): void
    {
        $exportUlid = $this->keep('Fotografia');
        $csv = $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/historico/{$exportUlid}/csv")->assertOk()->getContent();
        $timeline = $this->timeline();

        $checked = 0;
        foreach ($timeline['students'] as $student) {
            $value = $student['cells']['moment:'.$exportUlid]['result']['value'] ?? null;

            if ($value === null) {
                continue;
            }

            $line = collect(preg_split('/\R/', (string) $csv))->first(fn (string $row): bool => str_contains($row, $student['name']));
            $this->assertStringContainsString(EvaluationSheetCellText::percentage($value), (string) $line, $student['name']);
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }

    #[Test]
    public function the_student_page_the_class_comparison_and_the_trends_agree_about_the_same_student(): void
    {
        $this->keep('Momento A');
        $this->keep('Momento B');
        $timeline = $this->timeline();
        $comparison = $this->asTenant(fn (): array => app(BuildAssessmentComparison::class)->between($timeline));
        $trends = $this->asTenant(fn (): array => app(BuildClassTrends::class)->for($timeline));

        foreach (['Carolina Nunes', 'Ana Marques', 'Diogo Ferreira'] as $name) {
            $enrollment = $this->enrollment($name);
            $year = $this->asTenant(fn (): array => app(BuildStudentYear::class)->for($timeline, $enrollment->ulid));
            $row = collect($comparison['students'])->firstWhere('name', $name);
            $classRow = collect($trends['students'])->firstWhere('name', $name);

            if ($year['state'] === 'ready') {
                $this->assertSame($row['movement'], $year['result']['movement'], "{$name}: movement");
                $this->assertSame($row['assigned'], $year['assigned'], "{$name}: assigned level");
            }

            $this->assertSame($classRow['global'], $year['trends']['result'], "{$name}: trajectory");
        }
    }

    // ================================================================= 2. controlo de acesso: todas as superfícies × todas as situações

    /**
     * @return array<string, string> label → URL of every Pro surface of the Fatia D
     */
    private function proSurfaces(): array
    {
        $class = $this->classUlid();
        $student = $this->enrollment('Carolina Nunes')->ulid;

        return [
            'análise' => "/classes/{$class}/analise-avaliacao",
            'documento' => "/classes/{$class}/analise-avaliacao/documento",
            'pdf' => "/classes/{$class}/analise-avaliacao/documento/pdf",
            'aluno' => "/classes/{$class}/evolucao/{$student}",
        ];
    }

    /**
     * What a surface sends: for the student page, whether the Pro keys exist in the props.
     */
    private function proKeysOnTheStudentPage(TestResponse $response): bool
    {
        $present = false;

        $response->assertInertia(function ($page) use (&$present): void {
            $props = $page->toArray()['props'];
            $present = array_key_exists('yearReading', $props) || array_key_exists('pro', $props);
        });

        return $present;
    }

    #[Test]
    public function every_pro_surface_opens_for_pro_and_fails_closed_for_everyone_else(): void
    {
        $surfaces = $this->proSurfaces();

        // PRO, active: every surface opens.
        foreach ($surfaces as $label => $url) {
            $response = $this->actingAs($this->teacher)->get($url)->assertOk();
            $label === 'aluno' ? $this->assertTrue($this->proKeysOnTheStudentPage($response)) : null;
        }

        // BASE, SUSPENDED, MODULE OFF: the three Pro pages are refused with no data; the student page stays
        // open (it is Base) and simply has no Pro keys.
        $deny = function (string $situation) use ($surfaces): void {
            foreach (['análise', 'documento', 'pdf'] as $label) {
                $response = $this->actingAs($this->teacher)->get($surfaces[$label])->assertForbidden();

                $this->assertStringNotContainsString('Carolina', (string) $response->getContent(), "{$situation}: {$label} must not carry the class");
                $this->assertStringNotContainsString('%PDF', (string) $response->getContent(), "{$situation}: {$label}");
            }

            $student = $this->actingAs($this->teacher)->get($surfaces['aluno'])->assertOk();
            $this->assertFalse($this->proKeysOnTheStudentPage($student), "{$situation}: the student page carries no Pro key");
        };

        $this->subscribe('base');
        $deny('base');

        $this->subscribe('pro', SubscriptionStatus::Suspended);
        $deny('suspensa');

        $this->subscribe('pro');
        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'module_id' => Module::where('key', 'advanced_analytics')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();
        $deny('módulo desligado');

        // None of the refusals exported anything: the only export is the one Pro made at the start.
        $this->assertSame(1, AuditEvent::where('event', 'assessment_analysis.document.exported')->count());
    }

    #[Test]
    public function another_organization_and_a_colleague_who_does_not_teach_the_class_see_none_of_it(): void
    {
        $surfaces = $this->proSurfaces();

        $stranger = User::factory()->create();
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $stranger->personalOrganization()->id],
            ['plan_id' => Plan::where('key', 'pro')->firstOrFail()->id, 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::parse('2026-01-01 00:00:00'), 'ends_at' => null],
        );
        app(Entitlements::class)->flush();

        // A class — or a student — of another organization does not resolve at all.
        foreach ($surfaces as $label => $url) {
            $this->actingAs($stranger)->get($url)->assertNotFound();
        }

        // A colleague of the same organization who does not teach it.
        $other = $this->asTenant(function (): SchoolClass {
            $demo = $this->class();

            return SchoolClass::create([
                'academic_year_id' => $demo->academic_year_id, 'subject_id' => $demo->subject_id,
                'label' => '7.º B', 'grade_level' => '7.º', 'status' => 'active',
            ]);
        });
        foreach (['analise-avaliacao', 'analise-avaliacao/documento', 'analise-avaliacao/documento/pdf'] as $path) {
            $this->actingAs($this->teacher)->get("/classes/{$other->ulid}/{$path}")->assertForbidden();
        }

        // And a guest is sent to login, on every surface.
        auth()->logout();
        foreach ($surfaces as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    // ================================================================= 3. o Base não se mexe

    /**
     * The state of the data, table by table: what a Pro reading must leave exactly as it found it.
     *
     * Audit and usage are the trail of having been used, and sessions and caches are the machinery of a
     * request: they move, and they are not data of the assessment.
     *
     * @return array<string, string>
     */
    private function dataFingerprint(): array
    {
        $machinery = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'audit_events', 'migrations'];
        $fingerprint = [];

        foreach (Schema::getTables() as $table) {
            $name = (string) $table['name'];

            if (in_array($name, $machinery, true) || str_starts_with($name, 'usage_')) {
                continue;
            }

            $query = DB::table($name);

            if (Schema::hasColumn($name, 'id')) {
                $query->orderBy('id');
            }

            $fingerprint[$name] = md5((string) json_encode($query->get()->map(fn ($row): array => (array) $row)->all()));
        }

        return $fingerprint;
    }

    #[Test]
    public function opening_every_pro_surface_writes_nothing_to_the_data(): void
    {
        $this->keep('Momento A');
        $surfaces = $this->proSurfaces();
        $before = $this->dataFingerprint();

        foreach ($surfaces as $url) {
            $this->actingAs($this->teacher)->get($url)->assertOk();
        }
        $this->actingAs($this->teacher)->get($surfaces['documento'].'?alunos=1&secoes=tendencias')->assertOk();
        $this->actingAs($this->teacher)->get($surfaces['pdf'].'?alunos=1')->assertOk();

        $this->assertSame($before, $this->dataFingerprint(), 'a Pro reading is a reading: not a row moved');
    }

    #[Test]
    public function the_base_plan_keeps_opening_printing_and_exporting_what_it_always_did_with_no_trace_of_the_pro_layer(): void
    {
        $period = $this->periodUlids()[1];
        $class = $this->classUlid();
        $student = $this->enrollment('Carolina Nunes')->ulid;
        $exportUlid = $this->keep('Momento');

        $this->subscribe('base');

        $pages = [
            'resultados' => "/classes/{$class}/results/{$period}",
            'estatística' => "/classes/{$class}/results/estatistica/{$period}",
            'quadro síntese' => "/classes/{$class}/results/quadro-sintese",
            'pauta' => "/classes/{$class}/pauta-avaliacao/{$period}",
            'histórico' => "/classes/{$class}/pauta-avaliacao/historico",
            'pauta guardada' => "/classes/{$class}/pauta-avaliacao/historico/{$exportUlid}",
            'aluno' => "/classes/{$class}/evolucao/{$student}",
        ];

        // The student page already has its own «strengths» (empty on Base): only the Fatia D's markers are looked for there.
        $proMarkers = ['yearReading', 'students_below', 'persistent', 'analise-avaliacao'];
        $classMarkers = ['"strengths"', '"trends"'];

        foreach ($pages as $label => $url) {
            $content = (string) $this->actingAs($this->teacher)->get($url)->assertOk()->getContent();

            foreach ($label === 'aluno' ? $proMarkers : [...$proMarkers, ...$classMarkers] as $marker) {
                $this->assertStringNotContainsString($marker, $content, "Base: «{$label}» carries «{$marker}»");
            }
        }

        // The documents and the files Base always had.
        foreach ([
            "/classes/{$class}/pauta-avaliacao/impressao/{$period}",
            "/classes/{$class}/pauta-avaliacao/impressao/{$period}/pdf",
            "/classes/{$class}/pauta-avaliacao/historico/{$exportUlid}/impressao",
            "/classes/{$class}/pauta-avaliacao/historico/{$exportUlid}/impressao/pdf",
            "/classes/{$class}/pauta-avaliacao/csv/{$period}",
            "/classes/{$class}/pauta-avaliacao/xlsx/{$period}",
            "/classes/{$class}/pauta-avaliacao/historico/{$exportUlid}/csv",
            "/classes/{$class}/pauta-avaliacao/historico/{$exportUlid}/xlsx",
        ] as $url) {
            $this->actingAs($this->teacher)->get($url)->assertOk();
        }
    }

    // ================================================================= 4. dados históricos

    /**
     * Every reading of the Fatia D, over one timeline. A scenario passes when none of them fails and each
     * says what it cannot say.
     *
     * @param  array<string, mixed>  $timeline
     * @return array<string, mixed>
     */
    private function everyReading(array $timeline, ?string $studentUlid = null): array
    {
        return $this->asTenant(function () use ($timeline, $studentUlid): array {
            $class = $this->class();

            return [
                'comparison' => app(BuildAssessmentComparison::class)->between($timeline),
                'strengths' => app(BuildClassStrengths::class)->for($timeline),
                'trends' => app(BuildClassTrends::class)->for($timeline),
                'year' => $studentUlid === null ? null : app(BuildStudentYear::class)->for($timeline, $studentUlid),
                'document' => app(BuildAnalysisDocument::class)->build($class, $timeline, null, null, null, new AnalysisDocumentOptions(students: true)),
            ];
        });
    }

    #[Test]
    public function photographs_from_before_the_scale_travelled_and_without_weights_are_read_not_trusted(): void
    {
        $noScale = $this->keep('Sem escala');
        $this->rewrite($noScale, function (array $payload): array {
            unset($payload['scale']);
            $payload['version'] = 2;

            return $payload;
        });

        $noWeights = $this->keep('Sem pesos');
        $this->rewrite($noWeights, function (array $payload): array {
            $payload['version'] = 1;
            foreach ($payload['domains'] as $index => $domain) {
                unset($payload['domains'][$index]['weight_percent']);
            }
            unset($payload['scale']);

            return $payload;
        });

        $readings = $this->everyReading($this->timeline(), $this->enrollment('Carolina Nunes')->ulid);

        $this->assertSame('ready', $readings['comparison']['state']);
        $this->assertSame('ready', $readings['trends']['state']);
        $this->assertSame('ready', $readings['year']['state']);

        // The old photographs are in the timeline, said to be of unknown criteria or scale — and out of the trend line.
        $this->assertSame(['Sem escala', 'Sem pesos'], array_column($readings['trends']['left_out'], 'label'));
        $this->assertSame(2, $readings['trends']['availability']['comparable_points']);

        // The document says it, among the warnings; nothing was trusted and nothing failed.
        $warnings = implode("\n", $readings['document']['warnings']);
        $this->assertStringContainsString('Sem escala:', $warnings);
        $this->assertStringContainsString('Sem pesos:', $warnings);

        // A moment without a scale has no line between positive and negative.
        $moment = app(BuildClassStrengths::class);
        $strengths = $this->asTenant(fn (): array => $moment->for($this->timeline(), 'moment:'.$noScale));
        $this->assertFalse($strengths['negative_line']['known']);
        $this->assertSame('scale_unknown', $strengths['negative_line']['reason']);
    }

    #[Test]
    public function a_kept_moment_of_accumulated_scope_or_with_a_broken_seal_is_left_out_and_said(): void
    {
        $accumulated = $this->keep('Acumulado');
        $tampered = $this->keep('Adulterado');

        $this->asTenant(function () use ($accumulated, $tampered): void {
            DB::table('evaluation_sheet_exports')->where('ulid', $accumulated)->update(['scope' => ClassificationScope::Accumulated->value]);

            $export = EvaluationSheetExport::where('ulid', $tampered)->firstOrFail();
            $payload = $export->payload;
            $payload['students'][0]['overall']['normalized_value'] = '1.000000';
            DB::table('evaluation_sheet_exports')->where('id', $export->id)->update(['payload' => json_encode($payload)]);
        });

        $timeline = $this->timeline();
        $readings = $this->everyReading($timeline);

        $this->assertSame([], array_values(array_filter($timeline['points'], fn (array $point): bool => $point['kind'] === 'kept_moment')));
        $this->assertEqualsCanonicalizing(['moment_integrity', 'moment_not_standalone'], array_column($timeline['notices'], 'code'));
        $this->assertSame('ready', $readings['comparison']['state'], 'the two periods are still compared');
    }

    #[Test]
    public function a_class_with_no_profile_no_periods_or_no_students_is_read_and_says_why_it_cannot(): void
    {
        $demo = $this->asTenant(fn (): SchoolClass => $this->class());

        $classes = $this->asTenant(function () use ($demo): array {
            $make = fn (string $label, $year) => SchoolClass::create(['academic_year_id' => $year, 'subject_id' => $demo->subject_id, 'label' => $label, 'grade_level' => '7.º', 'status' => 'active']);
            $emptyYear = AcademicYear::factory()->recycle($this->teacher->personalOrganization())->create(['starts_on' => '2027-09-14', 'ends_on' => '2028-06-30']);

            return [
                'sem perfil' => $make('7.º Sem Perfil', $demo->academic_year_id)->id,
                'sem períodos' => $make('7.º Sem Períodos', $emptyYear->id)->id,
            ];
        });

        foreach ($classes as $label => $id) {
            $readings = $this->asTenant(function () use ($id): array {
                $class = SchoolClass::findOrFail($id);
                $timeline = app(BuildAssessmentTimeline::class)->for($class);

                return [
                    'timeline' => $timeline,
                    'comparison' => app(BuildAssessmentComparison::class)->between($timeline),
                    'strengths' => app(BuildClassStrengths::class)->for($timeline),
                    'trends' => app(BuildClassTrends::class)->for($timeline),
                    'document' => app(BuildAnalysisDocument::class)->build($class, $timeline, null, null, null, new AnalysisDocumentOptions(students: true)),
                ];
            });

            $this->assertSame([], $readings['timeline']['points'], $label);
            foreach (['comparison', 'strengths', 'trends'] as $reading) {
                $this->assertSame('unavailable', $readings[$reading]['state'], "{$label}: {$reading}");
            }
            $this->assertNotEmpty($readings['document']['warnings'], "{$label}: the document says why there is nothing to read");
            $this->assertFalse($readings['document']['is_empty'], 'the sections are there, each with its reason');
        }
    }

    #[Test]
    public function a_student_who_left_the_class_and_one_who_never_had_a_result_do_not_break_any_reading(): void
    {
        $this->asTenant(function (): void {
            $class = $this->class();
            $organization = $this->teacher->personalOrganization();

            foreach ([['Aluno Que Saiu', EnrollmentStatus::Left, 7], ['Aluno Sem Resultados', EnrollmentStatus::Active, 8]] as [$name, $status, $number]) {
                $student = Student::factory()->recycle($organization)->create();
                StudentIdentity::create(['student_id' => $student->getKey(), 'organization_id' => $organization->getKey(), 'display_name' => $name]);
                Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->getKey(), 'student_id' => $student->getKey(), 'class_number' => $number,
                    'enrolled_on' => '2026-09-14', 'status' => $status,
                    'left_on' => $status === EnrollmentStatus::Left ? '2026-10-20' : null,
                ]);
            }
        });

        $timeline = $this->timeline();

        foreach (['Aluno Que Saiu', 'Aluno Sem Resultados'] as $name) {
            $enrollment = $this->enrollment($name);
            $readings = $this->everyReading($timeline, $enrollment->ulid);

            $this->assertContains($readings['year']['state'], ['ready', 'unavailable'], $name);
            $this->assertSame('ready', $readings['comparison']['state'], "{$name}: the class reading is not affected");
        }

        // Someone with no result at all is told so, and is not made a zero.
        $year = $this->everyReading($timeline, $this->enrollment('Aluno Sem Resultados')->ulid)['year'];
        $this->assertSame('unavailable', $year['state']);
        $this->assertSame('Este aluno ainda não tem resultado em nenhum momento: não há nada para comparar.', $year['reason']);

        $response = $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/evolucao/{$this->enrollment('Aluno Sem Resultados')->ulid}")->assertOk();
        $this->assertTrue($this->proKeysOnTheStudentPage($response));
    }

    #[Test]
    public function with_data_in_only_the_first_period_every_reading_says_how_much_it_can_say(): void
    {
        $timeline = $this->timeline();
        $timeline['points'][1]['has_results'] = false;

        $readings = $this->everyReading($timeline);

        $this->assertSame('unavailable', $readings['comparison']['state']);
        $this->assertSame('ready', $readings['strengths']['state'], 'one moment is enough to describe a moment');
        $this->assertSame('ready', $readings['trends']['state']);
        $this->assertFalse($readings['trends']['availability']['persistence']);
        $this->assertNotEmpty($readings['document']['warnings']);
    }

    // ================================================================= 5. desempenho: o custo não cresce com a turma

    private function enrolUpTo(int $total): void
    {
        $this->asTenant(function () use ($total): void {
            $class = $this->class();
            $organization = $this->teacher->personalOrganization();
            $existing = Enrollment::query()->where('class_id', $class->getKey())->count();

            for ($number = $existing + 1; $number <= $total; $number++) {
                $student = Student::factory()->recycle($organization)->create();
                StudentIdentity::create(['student_id' => $student->getKey(), 'organization_id' => $organization->getKey(), 'display_name' => "Aluno de Teste {$number}"]);
                Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->getKey(), 'student_id' => $student->getKey(), 'class_number' => $number, 'enrolled_on' => '2026-09-14',
                ]);
            }
        });
    }

    /**
     * @return array<string, int>
     */
    private function queryCosts(): array
    {
        $costs = [];

        foreach ($this->proSurfaces() as $label => $url) {
            $url .= $label === 'documento' || $label === 'pdf' ? '?alunos=1' : '';

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->teacher)->get($url)->assertOk();
            $costs[$label] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $costs;
    }

    #[Test]
    public function the_cost_of_every_pro_surface_is_the_same_with_six_thirty_and_sixty_students(): void
    {
        $this->keep('Momento');
        $this->queryCosts(); // warm every one-off

        $six = $this->queryCosts();
        $this->enrolUpTo(30);
        $thirty = $this->queryCosts();
        $this->enrolUpTo(60);
        $sixty = $this->queryCosts();

        $this->assertSame($six, $thirty, 'six students against thirty: '.json_encode([$six, $thirty]));
        $this->assertSame($six, $sixty, 'six students against sixty: '.json_encode([$six, $sixty]));
    }

    #[Test]
    public function a_class_of_sixty_is_read_and_printed_end_to_end(): void
    {
        $this->keep('Momento A');
        $this->keep('Momento B');
        $this->enrolUpTo(60);

        $started = microtime(true);
        $document = $this->actingAs($this->teacher)->get($this->proSurfaces()['pdf'].'?alunos=1')->assertOk();
        $elapsed = microtime(true) - $started;

        $this->assertStringStartsWith('%PDF', (string) $document->getContent());
        // Not a benchmark: a ceiling far above what it costs, so that a change that makes it quadratic is seen.
        $this->assertLessThan(60, $elapsed, "A class of 60 took {$elapsed}s to print.");
    }
}
