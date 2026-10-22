<?php

namespace Tests\Feature\Reporting;

use App\Models\AcademicPeriod;
use App\Models\AuditEvent;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGrid;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGridOptions;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * The charts of the grid of a class (C5), over the REAL Pauta: the live sheet, a kept
 * moment and the statistics.
 *
 * The unit test holds the charts against hand-made documents. This one holds what
 * they are for: that the means and the counts are the class statistics' own wherever
 * both count the same students; that a kept moment draws its payload and never
 * today; that the page and the PDF draw the same bars; and that nothing the
 * statistics would add (movement, comparison, trend) is in them.
 */
class EvaluationSheetGridChartsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        // Inside the 1.º Semestre of the demo (14/09/2026 to 29/01/2027).
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $this->period($class));
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

    private function period(SchoolClass $class): AcademicPeriod
    {
        return $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
    }

    private function classUlid(): string
    {
        return $this->asTenant(fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()->ulid);
    }

    private function periodUlid(): string
    {
        return $this->asTenant(fn (): string => $this->period(SchoolClass::where('label', '7.º A')->firstOrFail())->ulid);
    }

    private function decide(string $name, string $code): void
    {
        $levelId = $this->asTenant(fn (): int => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);
        $enrollment = $this->asTenant(fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->enrollments()->with('student.identity')->get()
            ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === $name)->ulid);

        $this->actingAs($this->teacher)->post(
            "/classes/{$this->classUlid()}/classifications/{$this->periodUlid()}/{$enrollment}/decide",
            ['final_scale_level_id' => $levelId],
        )->assertSessionHasNoErrors()->assertRedirect();
    }

    private function keep(string $label): string
    {
        $this->actingAs($this->teacher)->post("/classes/{$this->classUlid()}/pauta-avaliacao/{$this->periodUlid()}/guardar", [
            'moment_label' => $label,
            'effective_at' => '2026-12-15',
            'scope' => ClassificationScope::Period->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return $this->asTenant(fn (): string => EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail()->ulid);
    }

    /**
     * The grid of the live sheet, as the controller makes it.
     *
     * @return array<string, mixed>
     */
    private function liveGrid(string $charts = '1'): array
    {
        return $this->asTenant(function () use ($charts): array {
            $class = SchoolClass::where('label', '7.º A')->with(['subject', 'academicYear'])->firstOrFail();
            $period = $this->period($class);
            $sheet = app(BuildEvaluationSheet::class)->for($class, $period, ClassificationScope::Period, false);

            return (new EvaluationSheetGrid)->build(
                EvaluationSheetDocument::fromLiveSheet($class, $period, $sheet, ClassificationScope::Period),
                EvaluationSheetGridOptions::fromQuery(['graficos' => $charts]),
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotGrid(string $exportUlid): array
    {
        return $this->asTenant(fn (): array => (new EvaluationSheetGrid)->build(
            EvaluationSheetDocument::fromSnapshot(EvaluationSheetExport::where('ulid', $exportUlid)->firstOrFail()),
            EvaluationSheetGridOptions::fromQuery(['graficos' => '1']),
        ));
    }

    /**
     * @return array<string, mixed> the class statistics of the same period, standalone, for the students who attend
     */
    private function statistics(): array
    {
        return $this->asTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return app(BuildClassStatistics::class)->for($class, $this->period($class));
        });
    }

    /** «72,4 %» → «72.4», the statistics' own form. */
    private function number(string $valueText): string
    {
        return str_replace(',', '.', trim(str_replace('%', '', $valueText)));
    }

    // ================================================================= paridade com a Estatística

    #[Test]
    public function the_class_means_are_the_statistics_own_means_with_the_same_number_of_students(): void
    {
        $means = $this->liveGrid()['charts']['means'];
        $statistics = $this->statistics();

        $bars = collect($means['bars'])->keyBy('label');

        // The global mean: the mean of the students' own results, each counting once.
        $this->assertSame($statistics['summary']['class_average'], $this->number($bars['Global']['value_text']));
        $this->assertSame(
            'n = '.$statistics['summary']['students_with_result'].' alunos com resultado',
            $bars['Global']['detail'],
        );

        // And each domain's.
        foreach ($statistics['domain_statistics'] as $domain) {
            $this->assertArrayHasKey($domain['label'], $bars->all(), $domain['label']);
            // A domain nobody has a result in has no mean: the statistics say null, the chart a dash — never a zero.
            $this->assertSame($domain['period_average'] ?? '—', $domain['period_average'] === null ? $bars[$domain['label']]['value_text'] : $this->number($bars[$domain['label']]['value_text']), $domain['label']);
            $this->assertSame(
                $domain['students_with_result'] === 0 ? 'sem resultados' : 'n = '.$domain['students_with_result'].' '.($domain['students_with_result'] === 1 ? 'aluno' : 'alunos').' com resultado',
                $bars[$domain['label']]['detail'],
                $domain['label'],
            );
        }

        // Nothing the statistics add: no movement, no comparison, no trend in the charts.
        $json = (string) json_encode($this->liveGrid()['charts'], JSON_UNESCAPED_UNICODE);

        foreach (['evolu', 'tendência', 'subiu', 'desceu', 'anterior', 'progress'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($json));
        }
    }

    #[Test]
    public function the_assigned_levels_are_the_statistics_assigned_distribution(): void
    {
        $this->decide('Carolina Nunes', '4');
        $this->decide('Ana Marques', '5');
        $this->decide('Bruno Teixeira', '3');
        $this->decide('Eva Salgado', '3');

        $chart = $this->liveGrid()['charts']['assigned'];
        $statistics = $this->statistics()['assigned_distribution'];

        $this->assertNotNull($chart);
        $this->assertSame('levels', $statistics['mode']);

        // Every band of the scale gets a bar, in the scale's own order, with the statistics' own count and percentage.
        $this->assertCount(count($statistics['bands']), $chart['bars']);

        foreach ($statistics['bands'] as $index => $band) {
            $bar = $chart['bars'][$index];

            $this->assertStringEndsWith((string) $band['label'], $bar['label'], "band {$index}");
            $this->assertSame(
                $band['count'].' · '.str_replace('.', ',', (string) ($band['percentage'] ?? '0.0')).' %',
                $bar['value_text'],
                "band {$index}",
            );
            $this->assertSame($band['is_negative'], $bar['negative'], "band {$index}");
        }

        $this->assertSame($statistics['classified'], $chart['n']);
        $this->assertSame('n = 4 de 6 alunos com nível atribuído', $chart['n_label']);
        $this->assertSame('Sem nível atribuído: 2', $chart['missing_note']);
        $this->assertSame($statistics['without_classification'], 2, 'the statistics count the same two aside');
        $this->assertSame('Poucos alunos (n = 4): leia com cautela.', $chart['caution']);
    }

    #[Test]
    public function the_proposed_levels_are_the_levels_the_students_were_proposed(): void
    {
        $chart = $this->liveGrid()['charts']['proposals'];
        $sheet = $this->asTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return app(BuildEvaluationSheet::class)->for($class, $this->period($class), ClassificationScope::Period, false);
        });

        // Counted straight from the sheet's own proposals, level by level.
        $expected = [];

        foreach ($sheet['students'] as $student) {
            $code = $student['classification']['proposed_scale_level_code'] ?? null;

            if ($code !== null) {
                $expected[$code] = ($expected[$code] ?? 0) + 1;
            }
        }

        $this->assertSame(array_sum($expected), $chart['n']);
        $this->assertSame(count($sheet['scale']['levels']), count($chart['bars']), 'every band of the scale');

        foreach ($sheet['scale']['levels'] as $index => $level) {
            $this->assertStringStartsWith(($expected[$level['code']] ?? 0).' · ', $chart['bars'][$index]['value_text'], $level['code']);
        }

        // Where the statistics count the same students, the proposals and the bands of the means agree:
        // the proposal was made from that very mean.
        $distribution = $this->statistics()['distribution'];

        foreach ($distribution as $index => $band) {
            $this->assertSame($band['count'], (int) explode(' · ', $chart['bars'][$index]['value_text'])[0], "band {$index}");
        }
    }

    // ================================================================= um momento guardado

    #[Test]
    public function a_kept_moment_draws_its_own_charts_from_its_payload_and_never_the_present(): void
    {
        $this->decide('Carolina Nunes', '3');
        $exportUlid = $this->keep('Momento de novembro');

        $kept = $this->snapshotGrid($exportUlid)['charts'];

        $this->assertSame($this->liveGrid()['charts'], $kept, 'at the moment it was kept, its charts ARE the live ones');

        // The teacher changes their mind and another student is given a level.
        $this->decide('Carolina Nunes', '5');
        $this->decide('Ana Marques', '5');

        $this->assertSame($kept, $this->snapshotGrid($exportUlid)['charts'], 'not one bar of a kept moment moves');
        $this->assertNotSame($kept['assigned'], $this->liveGrid()['charts']['assigned'], 'while the live one follows');
    }

    #[Test]
    public function the_scale_travels_with_a_new_photograph_and_the_level_charts_read_it(): void
    {
        $exportUlid = $this->keep('Momento de novembro');

        $payload = $this->asTenant(fn (): array => EvaluationSheetExport::where('ulid', $exportUlid)->firstOrFail()->payload);

        $this->assertSame(3, $payload['version']);
        $this->assertSame('level', $payload['scale']['kind']);
        $this->assertSame(['code', 'label', 'sequence', 'is_negative'], array_keys($payload['scale']['levels'][0]));
        $this->assertSame([1, 2, 3, 4, 5], array_column($payload['scale']['levels'], 'sequence'));
        // No identifier of any kind in what was frozen about the scale.
        $this->assertStringNotContainsString('"id"', (string) json_encode($payload['scale']));

        $charts = $this->snapshotGrid($exportUlid)['charts'];

        $this->assertTrue($charts['proposals']['available']);
        $this->assertCount(5, $charts['proposals']['bars']);
    }

    #[Test]
    public function a_photograph_from_before_the_scale_travelled_says_the_level_charts_are_not_available_and_still_draws_the_means(): void
    {
        $this->decide('Carolina Nunes', '4');
        $exportUlid = $this->keep('Momento antigo');

        $this->asTenant(function (): void {
            $export = EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail();
            $payload = $export->payload;
            unset($payload['scale']);
            $payload['version'] = 2;

            DB::table('evaluation_sheet_exports')->where('id', $export->id)->update([
                'payload' => json_encode($payload),
                'payload_hash' => CanonicalPayload::hash($payload),
            ]);
        });

        $charts = $this->snapshotGrid($exportUlid)['charts'];

        foreach (['proposals', 'assigned'] as $key) {
            $this->assertFalse($charts[$key]['available'], $key);
            $this->assertSame([], $charts[$key]['bars'], $key);
            $this->assertStringContainsString('guardada antes de a escala viajar com ela', (string) $charts[$key]['unavailable_message']);
        }

        // The means need no scale: they are there, and they are the live ones of that day.
        $this->assertNotSame([], $charts['means']['bars']);
        $this->assertSame($this->liveGrid()['charts']['means'], $charts['means']);
    }

    // ================================================================= a página e o PDF

    /**
     * The text of each page of a PDF, in page order.
     *
     * @return list<string>
     */
    private function pages(string $bytes): array
    {
        return array_values(array_map(
            fn ($page): string => preg_replace('/\s+/u', ' ', $page->getText()) ?? '',
            (new Parser)->parseContent($bytes)->getPages(),
        ));
    }

    #[Test]
    public function the_charts_are_off_by_default_on_the_page_and_in_the_pdf(): void
    {
        $page = null;
        $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/impressao/{$this->periodUlid()}")
            ->assertOk()->assertInertia(function ($inertia) use (&$page): void {
                $page = $inertia->toArray()['props']['document'];
            });

        $this->assertFalse($page['options']['charts']);
        $this->assertNull($page['charts']);

        $pdf = $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/impressao/{$this->periodUlid()}/pdf")->assertOk()->getContent();
        $this->assertStringNotContainsString('Distribuição dos níveis', implode(' ', $this->pages((string) $pdf)));
        $this->assertStringNotContainsString('Média da turma', implode(' ', $this->pages((string) $pdf)));
    }

    #[Test]
    public function the_page_and_the_pdf_draw_the_same_charts(): void
    {
        $this->decide('Carolina Nunes', '4');
        $this->decide('Ana Marques', '5');
        $exportUlid = $this->keep('Momento de teste');

        foreach ([
            ["/classes/{$this->classUlid()}/pauta-avaliacao/impressao/{$this->periodUlid()}", $this->liveGrid()['charts']],
            ["/classes/{$this->classUlid()}/pauta-avaliacao/historico/{$exportUlid}/impressao", $this->snapshotGrid($exportUlid)['charts']],
        ] as [$base, $expected]) {
            $page = null;
            $this->actingAs($this->teacher)->get($base.'?graficos=1')->assertOk()->assertInertia(function ($inertia) use (&$page): void {
                $page = $inertia->toArray()['props']['document'];
            });

            // The page receives exactly the charts the grid composes.
            $this->assertTrue($page['options']['charts']);
            $this->assertSame(json_decode((string) json_encode($expected), true), $page['charts']);

            // And the PDF draws the same titles, bars, values, notes and cautions.
            $text = implode(' ', $this->pages((string) $this->actingAs($this->teacher)->get($base.'/pdf?graficos=1')->assertOk()->getContent()));

            foreach (array_filter([$expected['proposals'], $expected['assigned'], $expected['means']]) as $chart) {
                $this->assertStringContainsString($chart['title'], $text);
                $this->assertStringContainsString($chart['basis'], $text);

                foreach ($chart['bars'] as $bar) {
                    $this->assertStringContainsString($bar['label'], $text);
                    $this->assertStringContainsString($bar['value_text'], $text);
                }

                foreach (['n_label', 'missing_note', 'unplaced_note', 'caution', 'axis'] as $key) {
                    if (! empty($chart[$key])) {
                        $this->assertStringContainsString($chart[$key], $text, $key);
                    }
                }
            }
        }
    }

    #[Test]
    public function the_assigned_chart_appears_only_when_somebody_was_given_a_level(): void
    {
        $this->assertNull($this->liveGrid()['charts']['assigned']);

        $page = implode(' ', $this->pages((string) $this->actingAs($this->teacher)
            ->get("/classes/{$this->classUlid()}/pauta-avaliacao/impressao/{$this->periodUlid()}/pdf?graficos=1")->assertOk()->getContent()));
        $this->assertStringNotContainsString('Distribuição dos níveis atribuídos', $page);
        $this->assertStringContainsString('Distribuição dos níveis propostos', $page);

        $this->decide('Carolina Nunes', '4');

        $this->assertNotNull($this->liveGrid()['charts']['assigned']);
    }

    #[Test]
    public function the_audit_of_the_export_says_the_charts_were_asked_for_and_still_has_no_names(): void
    {
        $this->decide('Carolina Nunes', '4');

        $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/impressao/{$this->periodUlid()}/pdf?graficos=1")->assertOk();

        $event = AuditEvent::where('event', 'evaluation_sheets.grid_document.exported')->latest('id')->firstOrFail();

        $this->assertTrue($event->properties['options']['charts']);
        $this->assertStringNotContainsString('Carolina', (string) json_encode($event->properties, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function opening_the_charts_writes_nothing_of_the_assessment(): void
    {
        $exportUlid = $this->keep('Momento');
        $tables = ['student_item_scores', 'classifications', 'calculation_snapshots', 'evaluation_sheet_exports', 'domain_appreciation_decisions', 'reports'];
        $count = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), $tables);

        $before = $count();
        $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/impressao/{$this->periodUlid()}?graficos=1")->assertOk();
        $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/historico/{$exportUlid}/impressao?graficos=1")->assertOk();

        $this->assertSame($before, $count());
    }
}
