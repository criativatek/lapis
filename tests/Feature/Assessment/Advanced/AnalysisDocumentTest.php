<?php

namespace Tests\Feature\Assessment\Advanced;

use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
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
use App\Services\Assessment\Advanced\AdvancedAnalyticsUnavailable;
use App\Services\Assessment\Advanced\BuildAssessmentComparison;
use App\Services\Assessment\Advanced\BuildAssessmentTimeline;
use App\Services\Assessment\Advanced\BuildClassStrengths;
use App\Services\Assessment\Advanced\BuildClassTrends;
use App\Services\Assessment\Advanced\Document\AnalysisDocumentOptions;
use App\Services\Assessment\Advanced\Document\BuildAnalysisDocument;
use App\Services\Assessment\Advanced\Document\DocumentFormat;
use App\Services\Assessment\Advanced\TimelineCriteria;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Entitlements\Entitlements;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * D5 — O documento Pro da Análise da Avaliação: a estrutura, a pré-visualização e o PDF.
 *
 * O QUE ESTES TESTES DEFENDEM: que o documento é a análise do ecrã (paridade, célula a célula, com os
 * três payloads da página) e que o PDF diz o que a pré-visualização diz; que as contagens, os
 * denominadores e os avisos viajam; que os nomes só saem quando se pedem; que é Pro até ao serviço, que
 * nenhuma organização vê a de outra e que o rasto de auditoria só tem contagens; e que o custo não cresce
 * com a turma.
 */
class AnalysisDocumentTest extends TestCase
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

    /** @return array<string, mixed> */
    private function timeline(): array
    {
        return $this->asTenant(fn (): array => app(BuildAssessmentTimeline::class)->for($this->class()));
    }

    /**
     * @param  array<string, mixed>|null  $timeline
     * @return array<string, mixed>
     */
    private function document(?AnalysisDocumentOptions $options = null, ?string $from = null, ?string $to = null, ?string $moment = null, ?array $timeline = null): array
    {
        return $this->asTenant(fn (): array => app(BuildAnalysisDocument::class)->build($this->class(), $timeline ?? $this->timeline(), $from, $to, $moment, $options ?? new AnalysisDocumentOptions));
    }

    /** @return array{string, string} */
    private function periodKeys(): array
    {
        return $this->asTenant(function (): array {
            $periods = $this->class()->academicYear->periods()->orderBy('sequence')->get();

            return ['period:'.$periods[0]->ulid, 'period:'.$periods[1]->ulid];
        });
    }

    private function keep(string $label): string
    {
        [$classUlid, $periodUlid] = $this->asTenant(fn (): array => [$this->class()->ulid, $this->class()->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid]);

        $this->actingAs($this->teacher)->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
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

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function blocks(array $document, string $section): array
    {
        foreach ($document['sections'] as $candidate) {
            if ($candidate['key'] === $section) {
                return $candidate['blocks'];
            }
        }

        return [];
    }

    /**
     * The first table after a heading.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function tableAfter(array $blocks, string $heading): array
    {
        $seen = false;

        foreach ($blocks as $block) {
            if ($block['type'] === 'heading' && $block['text'] === $heading) {
                $seen = true;

                continue;
            }

            if ($seen && $block['type'] === 'table') {
                return $block;
            }
        }

        $this->fail("No table after «{$heading}».");
    }

    /**
     * Every text of the structure, in order — what a renderer must put on the page.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function texts(array $document): array
    {
        $texts = [$document['title'], $document['subtitle'], $document['issued_label'] ?? ''];

        foreach ($document['identification'] as $row) {
            array_push($texts, $row['label'], $row['value']);
        }

        array_push($texts, ...$document['warnings']);

        foreach ($document['sections'] as $section) {
            $texts[] = $section['title'];

            foreach ($section['blocks'] as $block) {
                match ($block['type']) {
                    'heading', 'paragraph' => $texts[] = $block['text'],
                    'keyvalues' => array_push($texts, ...array_merge(...array_map(fn (array $item): array => [$item['label'], $item['value']], $block['items']))),
                    'list' => array_push($texts, ...($block['title'] !== null ? [$block['title']] : []), ...$block['items']),
                    'table' => array_push($texts, ...array_column($block['columns'], 'label'), ...array_merge(...$block['rows'])),
                };
            }
        }

        $texts[] = $document['notice'];

        return array_values(array_filter($texts, fn (string $text): bool => $text !== ''));
    }

    // ================================================================= a estrutura

    #[Test]
    public function the_document_identifies_the_class_the_year_the_scale_and_the_moments_analysed(): void
    {
        [$first, $second] = $this->periodKeys();
        $document = $this->document();
        $identification = array_column($document['identification'], 'value', 'label');

        $this->assertSame('Análise da Avaliação', $document['title']);
        $this->assertSame('7.º A', $identification['Turma']);
        $this->assertNotSame('', $identification['Disciplina']);
        $this->assertNotSame('', $identification['Ano letivo']);
        $this->assertNotSame('', $identification['Escala de avaliação']);
        $this->assertSame('1.º Semestre → 2.º Semestre', $identification['Momentos comparados']);
        $this->assertSame('2.º Semestre — Calculado com os dados de hoje', $identification['Momento analisado']);
        $this->assertSame('1.º Semestre → 2.º Semestre', $identification['Linha de tendências']);
        $this->assertStringContainsString('7.º A', $document['subtitle']);
        $this->assertSame(['comparacao', 'pontos-fortes', 'tendencias'], array_column($document['sections'], 'key'));
        $this->assertFalse($document['is_empty']);

        // An explicit choice of moments is the document's own.
        $chosen = $this->document(from: $second, to: $first, moment: $first);
        $this->assertSame('2.º Semestre', array_column($chosen['identification'], 'value', 'label')['Momentos comparados'] === '1.º Semestre → 2.º Semestre' ? '2.º Semestre' : '?');
        $this->assertSame('1.º Semestre — Calculado com os dados de hoje', array_column($chosen['identification'], 'value', 'label')['Momento analisado']);
    }

    #[Test]
    public function the_sections_are_a_whitelist_and_none_means_all(): void
    {
        $this->assertSame(['tendencias'], array_column($this->document(AnalysisDocumentOptions::fromQuery(['secoes' => 'tendencias']))['sections'], 'key'));
        $this->assertSame(['comparacao', 'tendencias'], array_column($this->document(AnalysisDocumentOptions::fromQuery(['secoes' => 'tendencias,comparacao,lixo']))['sections'], 'key'), 'in the order they are laid out, and what is not a section is ignored');
        $this->assertSame(['comparacao', 'pontos-fortes', 'tendencias'], array_column($this->document(AnalysisDocumentOptions::fromQuery(['secoes' => 'lixo']))['sections'], 'key'));
        $this->assertSame(['comparacao', 'pontos-fortes', 'tendencias'], array_column($this->document(AnalysisDocumentOptions::fromQuery([]))['sections'], 'key'));

        // A document of one section does not speak of the others.
        $only = $this->document(AnalysisDocumentOptions::fromQuery(['secoes' => 'tendencias']));
        $labels = array_column($only['identification'], 'label');
        $this->assertNotContains('Momentos comparados', $labels);
        $this->assertNotContains('Momento analisado', $labels);
        $this->assertContains('Linha de tendências', $labels);
    }

    #[Test]
    public function the_options_round_trip_through_the_query(): void
    {
        $options = AnalysisDocumentOptions::fromQuery(['secoes' => 'pontos-fortes,tendencias', 'alunos' => '1']);

        $this->assertSame(['secoes' => 'pontos-fortes,tendencias', 'alunos' => '1'], $options->toQuery());
        $this->assertEquals($options, AnalysisDocumentOptions::fromQuery($options->toQuery()));
        $this->assertFalse(AnalysisDocumentOptions::fromQuery(['alunos' => '0'])->students);
        $this->assertFalse(AnalysisDocumentOptions::fromQuery([])->students, 'names are opt-in');
        $this->assertTrue(AnalysisDocumentOptions::fromQuery(['alunos' => 'true'])->students);
    }

    // ================================================================= paridade com o ecrã

    #[Test]
    public function the_comparison_section_is_the_comparison_the_page_shows_cell_by_cell(): void
    {
        $timeline = $this->timeline();
        $screen = $this->asTenant(fn (): array => app(BuildAssessmentComparison::class)->between($timeline));
        $blocks = $this->blocks($this->document(timeline: $timeline), 'comparacao');

        $domains = $this->tableAfter($blocks, 'Por domínio');
        $this->assertCount(count($screen['domains']), $domains['rows']);

        foreach ($screen['domains'] as $index => $domain) {
            $row = $domains['rows'][$index];

            $this->assertSame($domain['name'], $row[0]);
            $this->assertSame(DocumentFormat::percent($domain['paired']['from_mean']), $row[1]);
            $this->assertSame(DocumentFormat::percent($domain['paired']['to_mean']), $row[2]);
            $this->assertSame($domain['paired']['change'] !== null ? DocumentFormat::points($domain['paired']['change']['points']) : '—', $row[3]);
            $this->assertSame($domain['counts'] !== null ? "{$domain['counts']['up']} / {$domain['counts']['flat']} / {$domain['counts']['down']}" : '—', $row[4]);
            $this->assertSame((string) $domain['paired']['students'], $row[5]);
        }

        // The class: the evolution of the same students, and the counts with their denominator.
        $text = json_encode($blocks, JSON_UNESCAPED_UNICODE);
        $paired = $screen['class']['paired'];
        $this->assertStringContainsString(DocumentFormat::percent($paired['from_mean']).' → '.DocumentFormat::percent($paired['to_mean']), (string) $text);
        $this->assertStringContainsString(DocumentFormat::points($paired['change']['points']), (string) $text);
        $this->assertStringContainsString("{$screen['class']['counts']['up']} de ".DocumentFormat::students($screen['class']['counts']['compared']).' comparados', (string) $text);
    }

    #[Test]
    public function the_strengths_section_is_the_strengths_the_page_shows_cell_by_cell(): void
    {
        $timeline = $this->timeline();
        $screen = $this->asTenant(fn (): array => app(BuildClassStrengths::class)->for($timeline));
        $blocks = $this->blocks($this->document(timeline: $timeline), 'pontos-fortes');

        $table = $this->tableAfter($blocks, 'Por domínio');
        $this->assertCount(count($screen['domains']), $table['rows']);

        foreach ($screen['domains'] as $index => $domain) {
            $row = $table['rows'][$index];

            $this->assertStringStartsWith($domain['name'], $row[0]);
            $this->assertSame(DocumentFormat::percent($domain['mean']), $row[1]);
            $this->assertSame(DocumentFormat::countOf($domain['students']['with_value'], $domain['students']['of']), $row[2]);
            $this->assertSame($domain['below_positive'] !== null ? DocumentFormat::countOf($domain['below_positive']['count'], $domain['below_positive']['of']).' ('.DocumentFormat::percent($domain['below_positive']['share']).')' : '—', $row[3]);
        }

        // Every highlight the page shows is in the document, word for word.
        $texts = json_encode($blocks, JSON_UNESCAPED_UNICODE);
        foreach ([...$screen['highlights']['strengths'], ...$screen['highlights']['attention']] as $highlight) {
            $this->assertStringContainsString(substr(json_encode($highlight['text'], JSON_UNESCAPED_UNICODE), 1, -1), (string) $texts);
        }
    }

    #[Test]
    public function the_trends_section_is_the_trends_the_page_shows_cell_by_cell(): void
    {
        $this->keep('Momento A');
        $this->keep('Momento B');
        $timeline = $this->timeline();
        $screen = $this->asTenant(fn (): array => app(BuildClassTrends::class)->for($timeline));
        $blocks = $this->blocks($this->document(timeline: $timeline), 'tendencias');

        $table = $this->tableAfter($blocks, 'A turma e cada domínio');
        $this->assertCount(1 + count($screen['class']['domains']), $table['rows']);

        $global = $table['rows'][0];
        $this->assertSame('Resultado global', $global[0]);
        $this->assertStringStartsWith(DocumentFormat::trendWord($screen['class']['global']['trend']['state']), $global[1]);
        $this->assertSame(DocumentFormat::values($screen['class']['global']['means']), $global[2]);

        foreach ($screen['class']['domains'] as $index => $domain) {
            $this->assertSame($domain['name'], $table['rows'][$index + 1][0]);
            $this->assertStringStartsWith(DocumentFormat::trendWord($domain['trend']['state']), $table['rows'][$index + 1][1]);
            $this->assertSame(DocumentFormat::values($domain['means']), $table['rows'][$index + 1][2]);
        }

        // The line of moments is the page's.
        $line = collect($blocks)->first(fn (array $block): bool => $block['type'] === 'paragraph' && str_starts_with($block['text'], 'Momentos da linha:'));
        $this->assertNotNull($line);
        foreach ($screen['points'] as $point) {
            $this->assertStringContainsString($point['label'], $line['text']);
        }
        $this->assertStringContainsString($screen['availability']['comparable_points'].' comparáveis, ancorados em «'.$screen['reference']['label'].'»', $line['text']);
    }

    #[Test]
    public function the_students_tables_carry_the_screens_figures_when_asked_for(): void
    {
        $this->keep('Momento A');
        $this->keep('Momento B');
        $timeline = $this->timeline();
        $screen = $this->asTenant(fn (): array => app(BuildClassTrends::class)->for($timeline));
        $document = $this->document(new AnalysisDocumentOptions(students: true), timeline: $timeline);

        $table = $this->tableAfter($this->blocks($document, 'tendencias'), 'Cada aluno');
        $this->assertCount(count($screen['students']), $table['rows']);

        foreach ($screen['students'] as $index => $student) {
            $row = $table['rows'][$index];

            $this->assertStringContainsString($student['name'], $row[0]);
            $this->assertSame(DocumentFormat::values($student['global']['values']), $row[1]);
            $this->assertStringStartsWith(DocumentFormat::trendWord($student['global']['trend']['state']), $row[2]);
        }

        $comparison = $this->tableAfter($this->blocks($document, 'comparacao'), 'Cada aluno');
        $this->assertCount(6, $comparison['rows']);
    }

    // ================================================================= os mesmos números, ditos da mesma maneira (PHP ↔ ecrã)

    #[Test]
    public function the_figures_are_said_the_way_the_screen_says_them(): void
    {
        // The same sentences `assessmentComparison.test.ts` and `trajectories.test.ts` hold the screen to.
        $this->assertSame('62,0%', DocumentFormat::percent('62.04'));
        $this->assertSame('—', DocumentFormat::percent(null));
        $this->assertSame('+8,5 p.p.', DocumentFormat::points('8.5'));
        $this->assertSame('-3,2 p.p.', DocumentFormat::points('-3.2'));
        $this->assertSame('0,0 p.p.', DocumentFormat::points('0.0'));
        $this->assertSame('1 aluno', DocumentFormat::students(1));
        $this->assertSame('6 alunos', DocumentFormat::students(6));
        $this->assertSame('8 de 27 alunos', DocumentFormat::countOf(8, 27));
        $this->assertSame('0 de 1 aluno', DocumentFormat::countOf(0, 1));

        $level = fn (string $label, bool $negative): array => ['code' => $label, 'label' => $label, 'sequence' => 2, 'is_negative' => $negative];
        $this->assertSame('Insuficiente → Suficiente — passou a nível positivo', DocumentFormat::levelChange(['state' => 'up', 'from' => $level('Insuficiente', true), 'to' => $level('Suficiente', false), 'crossed_line' => 'to_positive', 'reason' => null]));
        $this->assertSame('Manteve «Bom»', DocumentFormat::levelChange(['state' => 'same', 'from' => $level('Bom', false), 'to' => $level('Bom', false), 'crossed_line' => null, 'reason' => null]));
        $this->assertSame('Sem nível num dos momentos.', DocumentFormat::levelChange(['state' => 'unavailable', 'reason' => 'missing']));

        $trend = ['state' => 'consistent_up', 'with_value' => 4, 'counts' => ['up' => 3, 'flat' => 0, 'down' => 0], 'steps' => [1, 2, 3], 'gaps' => []];
        $this->assertSame('Subiu em 3 de 3 passos', DocumentFormat::trendSteps($trend));
        $this->assertSame('Subiu em 1, ficou igual em 1, desceu em 1 de 3 passos', DocumentFormat::trendSteps([...$trend, 'counts' => ['up' => 1, 'flat' => 1, 'down' => 1]]));
        $this->assertSame('2 momentos com valor: uma tendência precisa de 3', DocumentFormat::trendSteps(['with_value' => 2, 'steps' => [], 'counts' => ['up' => 0, 'flat' => 0, 'down' => 0]]));
        $this->assertSame('Sem valores', DocumentFormat::trendSteps(['with_value' => 0, 'steps' => [], 'counts' => ['up' => 0, 'flat' => 0, 'down' => 0]]));

        $this->assertSame('Abaixo do nível positivo em 2 momentos seguidos, incluindo o mais recente.', DocumentFormat::persistent(['state' => 'yes', 'run' => 2]));
        $this->assertNull(DocumentFormat::persistent(['state' => 'no', 'run' => 0]));
        $this->assertNull(DocumentFormat::persistent(['state' => 'no_value', 'run' => 0]));
        $this->assertStringContainsString('não se pode dizer que continua', (string) DocumentFormat::persistent(['state' => 'no_recent', 'run' => 0]));
        $this->assertSame('60,0% → — → 70,0%', DocumentFormat::values(['60.0', null, '70.0']));
    }

    // ================================================================= a lista dos alunos abaixo do nível positivo em mais de um domínio

    /**
     * A one-moment timeline written by hand, with students below the line in several domains.
     *
     * @return array<string, mixed>
     */
    private function synthetic(): array
    {
        $scale = ['name' => 'Escala de teste', 'kind' => 'levels', 'levels' => [
            ['code' => '1', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true],
            ['code' => '2', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false],
        ]];
        $figure = fn (string $value): array => ['value' => $value, 'band' => (float) $value < 50
            ? ['code' => '1', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true]
            : ['code' => '2', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false], 'coverage_warning' => false];

        $students = [];
        foreach ([['Aluno Um', ['40', '45']], ['Aluno Dois', ['80', '40']], ['Aluno Três', ['70', '75']]] as $index => [$name, $values]) {
            $students[] = ['enrollment_ulid' => "enr-{$index}", 'name' => $name, 'class_number' => $index + 1, 'cells' => ['period:p1' => [
                'result' => $figure('60'), 'domains' => ['dom-0' => $figure($values[0]), 'dom-1' => $figure($values[1])], 'classification' => null, 'participation' => null,
            ]]];
        }

        return [
            'class_ulid' => 'cls', 'scale' => $scale,
            'domains' => [['ulid' => 'dom-0', 'name' => 'Escrita', 'weight_percent' => '50.0000'], ['ulid' => 'dom-1', 'name' => 'Leitura', 'weight_percent' => '50.0000']],
            'points' => [[
                'key' => 'period:p1', 'kind' => 'period', 'label' => 'Momento 1', 'period_ulid' => 'p1', 'closes_the_year' => false,
                'basis' => ['kind' => 'live', 'label' => 'Calculado com os dados de hoje', 'as_of' => null],
                'level_signature' => TimelineCriteria::levels($scale), 'percentage_signature' => 'P', 'notices' => [], 'ordinal' => 1,
                'students_total' => 3, 'students_with_result' => 3, 'partial_coverage' => 0, 'has_results' => true, 'few_students' => true,
            ]],
            'students' => $students,
            'availability' => ['points_with_results' => 1, 'comparable_points' => 1, 'comparison' => false, 'trends' => false, 'reason' => 'x'],
            'notices' => [], 'context' => [],
        ];
    }

    #[Test]
    public function the_students_below_the_line_in_two_domains_are_listed_only_when_names_are_asked_for(): void
    {
        $timeline = $this->synthetic();
        $options = fn (bool $students): AnalysisDocumentOptions => new AnalysisDocumentOptions(['pontos-fortes'], $students);

        $without = $this->document($options(false), timeline: $timeline);
        $with = $this->document($options(true), timeline: $timeline);

        // The count is always there, with its denominator.
        $this->assertStringContainsString('1 em dois ou mais', (string) json_encode($without, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Aluno Um', (string) json_encode($without, JSON_UNESCAPED_UNICODE));

        $list = collect($this->blocks($with, 'pontos-fortes'))->first(fn (array $block): bool => $block['type'] === 'list' && count($block['items']) === 1 && str_contains($block['items'][0], 'Aluno Um'));
        $this->assertNotNull($list, 'the nominal list of who is below the line in two domains');
        $this->assertSame('N.º 1 · Aluno Um — 2 de 2 domínios com valor: Escrita 40,0% · Insuficiente; Leitura 45,0% · Insuficiente', $list['items'][0]);
        $this->assertStringNotContainsString('Aluno Dois', $list['items'][0], 'one domain below the line is not two');
    }

    // ================================================================= suporte, avisos, comparabilidade

    #[Test]
    public function the_warnings_gather_coverage_comparability_and_missing_data_once(): void
    {
        $document = $this->document();

        // The demo has few students in the first period and partial coverage: both are warnings.
        $this->assertNotEmpty($document['warnings']);
        $this->assertSame($document['warnings'], array_values(array_unique($document['warnings'])));

        $screen = $this->asTenant(fn (): array => app(BuildAssessmentComparison::class)->between($this->timeline()));
        foreach ($screen['cautions'] as $caution) {
            $this->assertContains($caution['text'], $document['warnings']);
        }
    }

    #[Test]
    public function a_comparison_that_cannot_be_made_is_said_with_its_reason_and_nothing_is_subtracted(): void
    {
        [, $second] = $this->periodKeys();
        $moment = $this->keep('Outros pesos');
        $this->rewrite($moment, function (array $payload): array {
            $payload['domains'][0]['weight_percent'] = '99.0000';

            return $payload;
        });

        $document = $this->document(AnalysisDocumentOptions::fromQuery(['secoes' => 'comparacao']), 'moment:'.$moment, $second);
        $blocks = $this->blocks($document, 'comparacao');
        $text = (string) json_encode($blocks, JSON_UNESCAPED_UNICODE);

        $this->assertContains('Os domínios ou os pesos de avaliação mudaram entre os dois momentos: as percentagens não são diretamente comparáveis.', $document['warnings']);
        $this->assertStringContainsString('Estes dois momentos não são totalmente comparáveis', $text);
        $this->assertStringContainsString('Sem comparação: os critérios mudaram entre os dois momentos', $text);

        foreach ($this->tableAfter($blocks, 'Por domínio')['rows'] as $row) {
            $this->assertSame('—', $row[3], 'no variation across other criteria');
        }
        $this->assertStringNotContainsString('Progrediram', $text);
    }

    #[Test]
    public function moments_left_out_of_the_trend_line_and_too_few_moments_are_warned(): void
    {
        $this->keep('Antes');
        $other = $this->keep('Outros pesos');
        $this->rewrite($other, function (array $payload): array {
            $payload['domains'][0]['weight_percent'] = '99.0000';

            return $payload;
        });

        $document = $this->document(AnalysisDocumentOptions::fromQuery(['secoes' => 'tendencias']));
        $joined = implode("\n", $document['warnings']);

        $this->assertStringContainsString('Outros pesos: Os domínios ou os pesos', $joined);
        $this->assertStringContainsString('(Outros pesos)', array_column($document['identification'], 'value', 'label')['Linha de tendências']);

        $list = collect($this->blocks($document, 'tendencias'))->first(fn (array $block): bool => $block['type'] === 'list' && $block['title'] === 'Momentos fora da linha');
        $this->assertNotNull($list);

        // With two comparable moments, a trend cannot be read, and the document says so.
        $two = $this->document(AnalysisDocumentOptions::fromQuery(['secoes' => 'tendencias']), timeline: $this->timeline());
        $this->assertNotEmpty($two['warnings']);
    }

    #[Test]
    public function without_a_line_between_positive_and_negative_the_document_counts_nobody_below_it(): void
    {
        $moment = $this->keep('Antes da escala viajar');
        $this->rewrite($moment, function (array $payload): array {
            unset($payload['scale']);
            $payload['version'] = 2;

            return $payload;
        });

        $document = $this->document(AnalysisDocumentOptions::fromQuery(['secoes' => 'pontos-fortes']), moment: 'moment:'.$moment);
        $text = (string) json_encode($document, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('não se contam alunos abaixo do nível positivo', $text);
        $this->assertStringContainsString('Sem linha entre positivo e negativo neste momento', $text);
        $this->assertStringNotContainsString('Nenhum aluno abaixo do nível positivo em «', $text);
        $this->assertNotEmpty(array_filter($document['warnings'], fn (string $warning): bool => str_contains($warning, 'escala viajar')));
    }

    // ================================================================= privacidade

    #[Test]
    public function names_only_travel_when_the_teacher_asks_for_the_nominal_lists(): void
    {
        $without = (string) json_encode($this->document(), JSON_UNESCAPED_UNICODE);
        $with = (string) json_encode($this->document(new AnalysisDocumentOptions(students: true)), JSON_UNESCAPED_UNICODE);

        foreach (['Ana Marques', 'Bruno Teixeira', 'Carolina Nunes', 'Diogo Ferreira', 'Eva Salgado', 'Filipe Andrade'] as $name) {
            $this->assertStringNotContainsString($name, $without, "{$name} must not be named unless asked");
            $this->assertStringContainsString($name, $with);
        }
    }

    #[Test]
    public function nothing_private_travels_in_the_document(): void
    {
        $this->asTenant(fn () => Classification::query()->update(['override_reason' => 'MOTIVO-LIVRE-RESERVADO']));
        $this->keep('Momento');

        $json = (string) json_encode($this->document(new AnalysisDocumentOptions(students: true)), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('MOTIVO-LIVRE-RESERVADO', $json);
        foreach (['enrollment_id', 'domain_id', 'period_id', 'scale_level_id', 'scale_id', 'student_id', 'override_reason', 'self_assessment', 'enrollment_ulid'] as $forbidden) {
            $this->assertStringNotContainsString("\"{$forbidden}\"", $json, "«{$forbidden}» must not travel");
        }
    }

    #[Test]
    public function the_words_never_diagnose_forecast_or_recommend(): void
    {
        $document = $this->document(new AnalysisDocumentOptions(students: true));
        $all = mb_strtolower(implode("\n", $this->texts($document)));

        $this->assertStringContainsString('não é um diagnóstico', $all);
        $this->assertStringContainsString('não faz previsões', $all);

        $rest = str_replace(['não é um diagnóstico de dificuldades de aprendizagem', 'não é um diagnóstico', 'não faz previsões'], '', $all);
        foreach (['dislexia', 'dificuldade de aprendizagem', 'recomend', 'deveria', 'sugerimos', 'provavelmente', 'vai piorar', 'risco'] as $word) {
            $this->assertStringNotContainsString($word, $rest, "«{$word}» must not be said");
        }
    }

    // ================================================================= Pro até ao serviço

    #[Test]
    public function the_service_refuses_what_the_plan_does_not_include(): void
    {
        $timeline = $this->timeline();
        $this->subscribe('base');

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        $this->asTenant(fn (): array => app(BuildAnalysisDocument::class)->build($this->class(), $timeline, null, null, null, new AnalysisDocumentOptions));
    }

    #[Test]
    public function a_suspended_subscription_does_not_get_a_new_document_either(): void
    {
        $timeline = $this->timeline();
        $this->subscribe('pro', SubscriptionStatus::Suspended);

        $this->expectException(AdvancedAnalyticsUnavailable::class);
        $this->asTenant(fn (): array => app(BuildAnalysisDocument::class)->build($this->class(), $timeline, null, null, null, new AnalysisDocumentOptions));
    }

    // ================================================================= a rota: pré-visualização e PDF

    private function url(string $suffix = '', string $query = ''): string
    {
        $ulid = $this->asTenant(fn (): string => $this->class()->ulid);

        return "/classes/{$ulid}/analise-avaliacao/documento{$suffix}{$query}";
    }

    private function preview(string $query = ''): TestResponse
    {
        return $this->actingAs($this->teacher)->get($this->url('', $query));
    }

    private function pdf(string $query = ''): TestResponse
    {
        return $this->actingAs($this->teacher)->get($this->url('/pdf', $query));
    }

    #[Test]
    public function a_pro_teacher_previews_the_document(): void
    {
        [$first, $second] = $this->periodKeys();

        $this->preview()->assertOk()->assertInertia(fn ($page) => $page
            ->component('assessment-analysis/Document')
            ->where('schoolClass.label', '7.º A')
            ->where('document.title', 'Análise da Avaliação')
            ->has('document.sections', 3)
            ->has('document.warnings')
            ->has('document.identity.name')
            ->where('controls.options.alunos', '0')
            ->where('controls.options.secoes', 'comparacao,pontos-fortes,tendencias')
            ->where('controls.base_url', fn ($url) => str_ends_with((string) $url, '/analise-avaliacao/documento')));

        $this->preview("?de={$second}&para={$first}&momento={$first}&secoes=tendencias&alunos=1")->assertOk()->assertInertia(fn ($page) => $page
            ->has('document.sections', 1)
            ->where('controls.moments.momento', $first)
            ->where('controls.options.alunos', '1')
            ->where('controls.back.href', fn ($href) => str_contains((string) $href, 'momento=')));
    }

    #[Test]
    public function the_preview_is_exactly_the_structure_the_service_builds(): void
    {
        $expected = $this->document(AnalysisDocumentOptions::fromQuery(['secoes' => 'comparacao,tendencias', 'alunos' => '1']));

        $this->preview('?secoes=comparacao,tendencias&alunos=1')->assertInertia(function ($page) use ($expected): void {
            $actual = $page->toArray()['props']['document'];

            $this->assertSame($expected['sections'], $actual['sections']);
            $this->assertSame($expected['identification'], $actual['identification']);
            $this->assertSame($expected['warnings'], $actual['warnings']);
            $this->assertSame($expected['notice'], $actual['notice']);
        });
    }

    #[Test]
    public function the_pdf_is_a_pdf_never_cached_and_named_without_a_student(): void
    {
        $response = $this->pdf('?alunos=1')->assertOk();
        $content = $response->getContent();

        $this->assertStringStartsWith('%PDF', (string) $content);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="analise-avaliacao-7o-a-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        foreach (['Ana', 'Carolina', 'Eva'] as $name) {
            $this->assertStringNotContainsString($name, (string) $response->headers->get('Content-Disposition'));
        }
    }

    #[Test]
    public function the_pdf_says_what_the_preview_says(): void
    {
        $this->keep('Momento A');
        $this->keep('Momento B');
        $query = 'alunos=1';

        $expected = $this->document(new AnalysisDocumentOptions(students: true));
        $text = (new Parser)->parseContent((string) $this->pdf("?{$query}")->assertOk()->getContent())->getText();
        $flat = fn (string $value): string => preg_replace('/\s+/u', '', $value) ?? $value;

        $missing = [];
        foreach ($this->texts($expected) as $sentence) {
            if (str_starts_with($sentence, 'Emitido a ')) {
                continue; // the minute of issue differs between two requests
            }

            if (! str_contains($flat($text), $flat($sentence))) {
                $missing[] = $sentence;
            }
        }

        $this->assertSame([], $missing, 'Everything the preview says is on the paper, word for word.');
    }

    #[Test]
    public function the_blade_draws_every_text_of_the_structure_and_escapes_it(): void
    {
        $document = $this->document(new AnalysisDocumentOptions(students: true));
        $document['identity'] = ['name' => 'Escola de Teste', 'header_lines' => [], 'footer_note' => null, 'is_configured' => true, 'logo_url' => null, 'logo' => null];
        $document['issued_label'] = 'Emitido a 15/12/2026, 10:00';
        $document['sections'][0]['blocks'][] = ['type' => 'paragraph', 'text' => '<script>alert(1)</script> & «aspas»', 'tone' => 'plain'];

        $html = View::make('assessment-analysis.document', ['document' => $document])->render();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; «aspas»', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES);
        $flat = fn (string $value): string => preg_replace('/\s+/u', '', $value) ?? $value;
        foreach ($this->texts($document) as $sentence) {
            $this->assertStringContainsString($flat($sentence), $flat($plain), "«{$sentence}» is not drawn");
        }
    }

    #[Test]
    public function a_malformed_request_is_a_validation_error(): void
    {
        $this->preview('?de=nao-e-um-momento')->assertSessionHasErrors('de');
        $this->preview('?momento=lixo')->assertSessionHasErrors('momento');
        $this->preview('?secoes=COM%20ESPACOS')->assertSessionHasErrors('secoes');
        $this->preview('?alunos=2')->assertSessionHasErrors('alunos');
        $this->pdf('?alunos=talvez')->assertSessionHasErrors('alunos');
    }

    // ================================================================= autorização e isolamento

    #[Test]
    public function a_base_organization_is_refused_by_the_server_and_receives_nothing(): void
    {
        $this->subscribe('base');

        $preview = $this->preview()->assertForbidden();
        $preview->assertInertia(fn ($page) => $page->component('ModuleUnavailable'));
        $this->assertStringNotContainsString('Carolina', (string) $preview->getContent());

        $pdf = $this->pdf('?alunos=1')->assertForbidden();
        $this->assertStringNotContainsString('%PDF', (string) $pdf->getContent());
        $this->assertSame(0, AuditEvent::where('event', 'assessment_analysis.document.exported')->count());
    }

    #[Test]
    public function a_suspended_or_overridden_organization_is_refused_too(): void
    {
        $this->subscribe('pro', SubscriptionStatus::Suspended);
        $this->preview()->assertForbidden();
        $this->pdf()->assertForbidden();

        $this->subscribe('pro');
        $this->preview()->assertOk();
        $this->pdf()->assertOk();

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'module_id' => Module::where('key', 'advanced_analytics')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();

        $this->preview()->assertForbidden();
        $this->pdf()->assertForbidden();
    }

    #[Test]
    public function another_organizations_teacher_cannot_open_the_class_document(): void
    {
        $url = $this->url();
        $pdf = $this->url('/pdf');
        $stranger = User::factory()->create();
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $stranger->personalOrganization()->id],
            ['plan_id' => Plan::where('key', 'pro')->firstOrFail()->id, 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::parse('2026-01-01 00:00:00'), 'ends_at' => null],
        );
        app(Entitlements::class)->flush();

        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->actingAs($stranger)->get($pdf)->assertNotFound();
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_is_forbidden_and_a_guest_is_sent_to_login(): void
    {
        $other = $this->asTenant(function (): SchoolClass {
            $demo = $this->class();

            return SchoolClass::create([
                'academic_year_id' => $demo->academic_year_id, 'subject_id' => $demo->subject_id,
                'label' => '7.º B', 'grade_level' => '7.º', 'status' => 'active',
            ]);
        });

        $this->actingAs($this->teacher)->get("/classes/{$other->ulid}/analise-avaliacao/documento")->assertForbidden();
        $this->actingAs($this->teacher)->get("/classes/{$other->ulid}/analise-avaliacao/documento/pdf")->assertForbidden();

        auth()->logout();
        $this->get($this->url())->assertRedirect('/login');
        $this->get($this->url('/pdf'))->assertRedirect('/login');
    }

    // ================================================================= auditoria, efeitos, desempenho

    #[Test]
    public function exporting_leaves_a_trail_of_counts_and_options_and_never_a_name_or_a_mark(): void
    {
        $this->keep('Momento A');
        $this->asTenant(fn () => Classification::query()->update(['override_reason' => 'MOTIVO-LIVRE-RESERVADO']));
        [$first, $second] = $this->periodKeys();

        $this->pdf("?de={$first}&para={$second}&alunos=1&secoes=comparacao,tendencias")->assertOk();

        $event = AuditEvent::where('event', 'assessment_analysis.document.exported')->latest('id')->firstOrFail();
        $properties = $event->properties;

        $this->assertSame('pdf', $properties['format']);
        $this->assertSame(['comparacao', 'tendencias'], $properties['sections']);
        $this->assertTrue($properties['include_students']);
        $this->assertSame(6, $properties['students']);
        $this->assertSame($first, $properties['comparison_from']);
        $this->assertSame('ready', $properties['comparison']);
        $this->assertArrayHasKey('comparable_points', $properties);

        $json = (string) json_encode([$properties, $event->summary], JSON_UNESCAPED_UNICODE);
        foreach (['Ana Marques', 'Carolina', 'Eva Salgado', 'MOTIVO-LIVRE-RESERVADO', '80,1', '72,5'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    #[Test]
    public function only_the_pdf_leaves_a_trail_and_neither_writes_anything_of_the_assessment(): void
    {
        $tables = ['student_item_scores', 'classifications', 'calculation_snapshots', 'evaluation_sheet_exports', 'domain_appreciation_decisions', 'reports'];
        $count = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), $tables);

        $before = $count();
        $this->preview()->assertOk();
        $this->assertSame(0, AuditEvent::where('event', 'assessment_analysis.document.exported')->count(), 'the preview is the screen, not an export');
        $this->pdf()->assertOk();

        $this->assertSame($before, $count());
        $this->assertSame(1, AuditEvent::where('event', 'assessment_analysis.document.exported')->count());
    }

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

    private function queriesOf(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->teacher)->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function the_cost_of_the_preview_and_of_the_pdf_does_not_grow_with_the_class(): void
    {
        $this->keep('Momento');
        $preview = $this->url('', '?alunos=1');
        $pdf = $this->url('/pdf', '?alunos=1');

        $this->queriesOf($preview);
        $this->queriesOf($pdf); // warm every one-off

        $smallPreview = $this->queriesOf($preview);
        $smallPdf = $this->queriesOf($pdf);
        $this->enrolUpTo(30);
        $largePreview = $this->queriesOf($preview);
        $largePdf = $this->queriesOf($pdf);

        $this->assertSame($smallPreview, $largePreview, "Six students cost {$smallPreview} queries and thirty cost {$largePreview} on the preview.");
        $this->assertSame($smallPdf, $largePdf, "Six students cost {$smallPdf} queries and thirty cost {$largePdf} on the PDF.");
    }
}
