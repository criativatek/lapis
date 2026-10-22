<?php

namespace Tests\Feature\Reporting;

use App\Http\Controllers\EvaluationSheetGridDocumentController;
use App\Models\AcademicPeriod;
use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Documents\Pdf\DompdfPrinter;
use App\Services\Documents\Pdf\LetterheadLayout;
use App\Services\Documents\Pdf\PaperOrientation;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGrid;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGridOptions;
use App\Services\Reporting\ClassDocuments\InstrumentGridLayout;
use App\Support\Entitlements\Entitlements;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Smalot\PdfParser\Parser;
use Tests\Support\EvaluationSheetSample;
use Tests\TestCase;

/**
 * The PDF of the grid of a class (C4): the two routes, who may open them, what is
 * in the file, and what is not.
 *
 * Real PDFs, parsed back to text: the structure is C2's and the page is C3's;
 * what this holds is that the file draws THAT structure — every student, every
 * domain, every figure, in every block — that a kept moment is its payload and
 * never today, that the file says it is provisional on every page, that the trail
 * has counts and no names, and that nothing private is in the bytes.
 */
class EvaluationSheetGridPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();

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
    private function asTenant(callable $callback, ?Organization $organization = null): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization ?? $this->organization, $callback);
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

    private function liveUrl(string $query = '', ?string $period = null): string
    {
        return "/classes/{$this->classUlid()}/pauta-avaliacao/impressao/".($period ?? $this->periodUlid()).'/pdf'.$query;
    }

    private function snapshotUrl(string $exportUlid, string $query = '', ?string $class = null): string
    {
        return '/classes/'.($class ?? $this->classUlid())."/pauta-avaliacao/historico/{$exportUlid}/impressao/pdf{$query}";
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

    /** The PDF the teacher would download, as bytes. */
    private function pdf(string $url, ?User $as = null): string
    {
        $response = $this->actingAs($as ?? $this->teacher)->get($url);
        $response->assertOk();

        return (string) $response->getContent();
    }

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

    private function isLandscape(string $bytes): bool
    {
        return (bool) preg_match('/MediaBox\s*\[[\d.]+\s+[\d.]+\s+841\.89\d*\s+595\.28\d*\]/', $bytes);
    }

    /**
     * The structure the PDF must draw, made the way the controller makes it from the live sheet.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function expectedLive(array $query = []): array
    {
        return $this->asTenant(function () use ($query): array {
            $class = SchoolClass::where('label', '7.º A')->with(['subject', 'academicYear'])->firstOrFail();
            $period = $this->period($class);
            $sheet = app(BuildEvaluationSheet::class)->for($class, $period, ClassificationScope::Period, false);

            return (new EvaluationSheetGrid)->build(
                EvaluationSheetDocument::fromLiveSheet($class, $period, $sheet, ClassificationScope::Period),
                EvaluationSheetGridOptions::fromQuery($query),
            );
        });
    }

    // ================================================================= o ficheiro

    #[Test]
    public function the_pdf_is_a_real_attachment_that_is_never_cached_and_never_named_after_a_student(): void
    {
        foreach ([$this->liveUrl(), $this->snapshotUrl($this->keep('Momento de novembro'))] as $url) {
            $response = $this->actingAs($this->teacher)->get($url);

            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', (string) $response->getContent());

            $disposition = (string) $response->headers->get('Content-Disposition');
            $this->assertStringStartsWith('attachment; filename="grelha-pauta-7o-a-', $disposition);
            $this->assertStringNotContainsString('carolina', strtolower($disposition));
            $this->assertStringNotContainsString('aluno', strtolower($disposition));

            $cache = (string) $response->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cache);
            $this->assertStringContainsString('private', $cache);
        }
    }

    #[Test]
    public function the_pdf_is_landscape_by_default_and_portrait_when_asked(): void
    {
        $this->assertTrue($this->isLandscape($this->pdf($this->liveUrl())));
        $this->assertFalse($this->isLandscape($this->pdf($this->liveUrl('?orientacao=vertical'))));
        $this->assertTrue($this->isLandscape($this->pdf($this->liveUrl('?orientacao=horizontal'))));
        // A forged value is the default, never an error.
        $this->assertTrue($this->isLandscape($this->pdf($this->liveUrl('?orientacao=diagonal&autoavaliacao[]=1'))));
    }

    #[Test]
    public function the_pdf_draws_what_the_preview_draws_every_student_and_every_figure_in_every_block(): void
    {
        $this->decide('Carolina Nunes', '4');
        $this->decide('Ana Marques', '5');

        foreach (['', '?orientacao=vertical'] as $query) {
            $structure = $this->expectedLive($query === '' ? [] : ['orientacao' => 'vertical']);
            $pages = $this->pages($this->pdf($this->liveUrl($query)));
            $text = implode(' ', $pages);

            $this->assertGreaterThanOrEqual(count($structure['blocks']), count($pages), 'a block never shares a sheet with the next one');

            foreach ($structure['blocks'] as $index => $block) {
                if ($block['caption'] !== null) {
                    $this->assertStringContainsString($block['caption'], $text);
                }

                foreach ($block['columns'] as $column) {
                    $this->assertStringContainsString($column['label'], $text);
                }

                foreach ($block['group_spans'] as $span) {
                    if ($span['label'] !== null) {
                        $this->assertStringContainsString($span['label'], $text, "block {$index}: group header");
                    }
                }

                foreach ($block['rows'] as $row) {
                    $this->assertStringContainsString($row['name'], $text, "block {$index}: student missing");

                    foreach ($row['cells'] as $cell) {
                        if ($cell['text'] !== '' && $cell['text'] !== '—') {
                            $this->assertStringContainsString($cell['text'], $text, "block {$index}: {$row['name']}: «{$cell['text']}»");
                        }
                    }
                }
            }

            // Every student appears once per block, so none is lost to a page.
            foreach (collect($structure['blocks'][0]['rows'])->pluck('name') as $name) {
                $this->assertSame(count($structure['blocks']), substr_count($text, $name), "{$name} once per block ({$query})");
            }

            foreach ($structure['notes'] as $note) {
                $this->assertStringContainsString($note, $text);
            }
        }
    }

    #[Test]
    public function every_page_carries_its_page_number_the_provisional_stamp_and_the_heading_of_its_columns(): void
    {
        $pages = $this->pages($this->pdf($this->liveUrl()));

        $this->assertGreaterThanOrEqual(2, count($pages));

        foreach ($pages as $index => $text) {
            $this->assertStringContainsString('Página '.($index + 1).' / '.count($pages), $text);
            $this->assertStringContainsString('PROVISÓRIO', $text, 'page '.($index + 1));
            $this->assertStringContainsString('Nome', $text);
            $this->assertStringContainsString('Carolina Nunes', $text);
        }
    }

    #[Test]
    public function the_stamp_goes_when_everybody_has_a_level_assigned(): void
    {
        foreach (['Ana Marques', 'Bruno Teixeira', 'Carolina Nunes', 'Diogo Ferreira', 'Eva Salgado', 'Filipe Andrade'] as $name) {
            $this->decide($name, '3');
        }

        $pages = $this->pages($this->pdf($this->liveUrl()));

        foreach ($pages as $text) {
            $this->assertStringNotContainsString('PROVISÓRIO', $text);
        }

        $this->assertStringContainsString('Classificações tomadas', implode(' ', $pages));
    }

    #[Test]
    public function calculated_proposed_and_decided_stay_in_their_own_columns_in_the_file(): void
    {
        $this->decide('Ana Marques', '5');

        $text = implode(' ', $this->pages($this->pdf($this->liveUrl())));

        foreach (['Resultado', 'Apreciação', 'Proposta do Lapispro', 'Nível atribuído', 'Estado', 'Confirmada', 'Proposta', 'não decidido', 'sem classificação'] as $word) {
            $this->assertStringContainsString($word, $text);
        }

        // The sentence that says how to read it travels with it.
        $this->assertStringContainsString('A proposta é do Lapispro; o nível atribuído é a decisão do professor', $text);
    }

    // ================================================================= um momento guardado

    #[Test]
    public function a_kept_moment_prints_its_payload_and_never_the_present(): void
    {
        $this->decide('Carolina Nunes', '3');
        $exportUlid = $this->keep('Momento de novembro');

        $before = $this->pages($this->pdf($this->snapshotUrl($exportUlid)));
        $beforeText = implode(' ', $before);

        $this->assertStringContainsString('Momento de novembro', $beforeText);
        $this->assertStringContainsString('Momento guardado a', $beforeText);
        $this->assertStringContainsString('15/12/2026', $beforeText);
        $this->assertStringContainsString('não refletem alterações posteriores', $beforeText);

        $this->decide('Carolina Nunes', '4');

        $this->assertSame($before, $this->pages($this->pdf($this->snapshotUrl($exportUlid))), 'not one character of a kept moment moves');

        // The live file follows the new decision.
        $live = $this->expectedLive();
        $assigned = collect($live['blocks'])->flatMap(fn (array $block) => collect($block['rows'])->where('name', 'Carolina Nunes')
            ->map(fn (array $row): ?string => ($position = array_search('assigned', array_column($block['columns'], 'kind'), true)) === false ? null : $row['cells'][$position]['text'])->filter())->first();
        $this->assertSame('4', $assigned);
    }

    #[Test]
    public function a_kept_moment_from_before_the_newer_keys_still_prints(): void
    {
        $exportUlid = $this->keep('Momento antigo');

        $this->asTenant(function (): void {
            $export = EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail();
            $payload = $export->payload;

            foreach ($payload['students'] as $index => $student) {
                unset($payload['students'][$index]['self_assessment'], $payload['students'][$index]['overall']['scale_level_code']);

                foreach ($student['domains'] as $domainIndex => $domain) {
                    unset(
                        $payload['students'][$index]['domains'][$domainIndex]['self_assessment'],
                        $payload['students'][$index]['domains'][$domainIndex]['scale_level_code'],
                        $payload['students'][$index]['domains'][$domainIndex]['decided_scale_level_code'],
                        $payload['students'][$index]['domains'][$domainIndex]['decided_scale_level_label'],
                    );
                }
            }

            foreach ($payload['domains'] as $index => $domain) {
                unset($payload['domains'][$index]['color']);
            }

            DB::table('evaluation_sheet_exports')->where('id', $export->id)->update([
                'payload' => json_encode($payload),
                'payload_hash' => CanonicalPayload::hash($payload),
            ]);
        });

        $pages = $this->pages($this->pdf($this->snapshotUrl($exportUlid, '?autoavaliacao=1')));
        $text = implode(' ', $pages);

        $this->assertStringContainsString('Momento antigo', $text);
        $this->assertStringContainsString('Carolina Nunes', $text);
        $this->assertStringNotContainsString('Autoavaliação', $text, 'asking for what the sheet does not have adds nothing');

        foreach ($pages as $index => $page) {
            $this->assertStringContainsString('Página '.($index + 1).' / '.count($pages), $page);
        }
    }

    #[Test]
    public function a_tampered_snapshot_is_refused_rather_than_printed(): void
    {
        $exportUlid = $this->keep('Momento intercalar');
        $marker = bin2hex(random_bytes(6));

        $this->asTenant(function () use ($marker): void {
            $export = EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail();
            $payload = $export->payload;
            $payload['students'][0]['name'] = 'Trocado-'.$marker;

            // The seal is NOT recomputed: this is exactly what the integrity check exists to catch.
            DB::table('evaluation_sheet_exports')->where('id', $export->id)->update(['payload' => json_encode($payload)]);
        });

        config(['app.debug' => false]);

        $response = $this->actingAs($this->teacher)->get($this->snapshotUrl($exportUlid));

        $response->assertStatus(409);
        $this->assertStringStartsNotWith('%PDF', (string) $response->getContent());
        $this->assertStringNotContainsString($marker, (string) $response->getContent());
    }

    // ================================================================= quem pode abrir

    #[Test]
    public function the_pdf_is_as_closed_as_the_preview(): void
    {
        $snapshot = $this->snapshotUrl($this->keep('Momento'));
        $live = $this->liveUrl();

        // Another organization cannot resolve the class, the period or the record.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get($live)->assertNotFound();
        $this->actingAs($stranger)->get($snapshot)->assertNotFound();

        // A colleague who does not teach the class gets a 403 and not a byte of the file.
        $colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$colleague->id => ['joined_at' => now()]]);
        $this->withSession(['organization_id' => $this->organization->id]);

        foreach ([$live, $snapshot] as $url) {
            $forbidden = $this->actingAs($colleague)->get($url);
            $forbidden->assertForbidden();
            $this->assertStringStartsNotWith('%PDF', (string) $forbidden->getContent());
            $this->assertStringNotContainsString('Carolina Nunes', (string) $forbidden->getContent());
        }

        // A period that is not this class's.
        $this->actingAs($this->teacher)->get($this->liveUrl(period: '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
        $this->actingAs($this->teacher)->get($this->liveUrl(period: 'não-é-um-ulid'))->assertNotFound();

        // A guest goes to the login.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($live)->assertRedirect('/login');
        $this->get($snapshot)->assertRedirect('/login');
    }

    #[Test]
    public function a_record_is_not_a_key_to_another_class_of_the_same_organization(): void
    {
        $snapshot = $this->keep('Momento');

        $otherClassUlid = $this->asTenant(function (): string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $other = SchoolClass::create([
                'academic_year_id' => $class->academic_year_id, 'subject_id' => $class->subject_id,
                'label' => '7.º Z', 'grade_level' => '7.º', 'status' => 'active',
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
            ]);
            $other->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $other->ulid;
        });

        $response = $this->actingAs($this->teacher)->get($this->snapshotUrl($snapshot, class: $otherClassUlid));

        $response->assertNotFound();
        $this->assertStringStartsNotWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_base_plan_may_download_it_and_a_blocked_module_may_not(): void
    {
        $snapshot = $this->snapshotUrl($this->keep('Momento'));

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'base')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );
        app(Entitlements::class)->flush();

        $this->actingAs($this->teacher)->get($this->liveUrl())->assertOk();
        $this->actingAs($this->teacher)->get($snapshot)->assertOk();

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->getKey(),
            'module_id' => Module::where('key', 'results')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($this->teacher)->get($this->liveUrl())->assertForbidden();
        $this->actingAs($this->teacher)->get($snapshot)->assertForbidden();
    }

    #[Test]
    public function the_pdf_still_downloads_while_the_account_is_read_only(): void
    {
        $snapshot = $this->snapshotUrl($this->keep('Momento'));

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'pro')->firstOrFail()->id,
                'status' => SubscriptionStatus::Suspended,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            ],
        );
        app(Entitlements::class)->flush();

        $this->assertStringStartsWith('%PDF-', $this->pdf($this->liveUrl()));
        $this->assertStringStartsWith('%PDF-', $this->pdf($snapshot));
    }

    // ================================================================= auditoria e privacidade

    #[Test]
    public function exporting_leaves_a_trail_with_counts_and_options_and_not_a_single_name(): void
    {
        $this->decide('Carolina Nunes', '4');
        $exportUlid = $this->keep('Momento de novembro');
        $before = AuditEvent::where('event', 'evaluation_sheets.grid_document.exported')->count();

        $this->pdf($this->liveUrl('?momento=interim&orientacao=vertical'));
        $this->pdf($this->snapshotUrl($exportUlid));

        $events = AuditEvent::where('event', 'evaluation_sheets.grid_document.exported')->orderBy('id')->get();
        $this->assertCount($before + 2, $events);

        [$live, $snapshot] = [$events[$before], $events[$before + 1]];

        $this->assertSame('pdf', $live->properties['format']);
        $this->assertSame('live', $live->properties['source']);
        $this->assertSame('interim', $live->properties['moment']);
        $this->assertSame($this->periodUlid(), $live->properties['academic_period_ulid']);
        $this->assertSame('portrait', $live->properties['orientation']);
        $this->assertSame(false, $live->properties['options']['self_assessment']);
        $this->assertTrue($live->properties['provisional']);
        $this->assertSame(6, $live->properties['students']);
        $this->assertSame(5, $live->properties['domains']);
        $this->assertSame(1, $live->properties['decided']);
        $this->assertSame(5, $live->properties['pending']);
        $this->assertGreaterThanOrEqual(1, $live->properties['blocks']);

        $this->assertSame('snapshot', $snapshot->properties['source']);
        $this->assertSame($exportUlid, $snapshot->properties['evaluation_sheet_export_ulid']);
        $this->assertNull($snapshot->properties['moment']);

        foreach ($events->slice($before) as $event) {
            $this->assertStringNotContainsString('Carolina', (string) json_encode($event->properties, JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString('Aluno', (string) json_encode($event->summary, JSON_UNESCAPED_UNICODE));
            $this->assertSame($this->classUlid(), $event->subject_ulid);
        }
    }

    #[Test]
    public function the_free_text_of_a_decision_and_every_identifier_stay_out_of_the_file(): void
    {
        $this->decide('Carolina Nunes', '4');

        $this->asTenant(function (): void {
            Classification::query()
                ->where('enrollment_id', Enrollment::query()->where('class_id', SchoolClass::where('label', '7.º A')->value('id'))->first()->id)
                ->update(['override_reason' => 'MOTIVO-LIVRE-RESERVADO']);
        });
        $exportUlid = $this->keep('Momento privado');

        foreach ([$this->liveUrl('?autoavaliacao=1'), $this->snapshotUrl($exportUlid, '?autoavaliacao=1')] as $url) {
            $text = implode(' ', $this->pages($this->pdf($url)));

            $this->assertStringNotContainsString('MOTIVO-LIVRE-RESERVADO', $text);
            $this->assertStringNotContainsString('override_reason', $text);
            $this->assertStringNotContainsString('enrollment', $text);
        }
    }

    #[Test]
    public function downloading_writes_nothing_of_the_assessment(): void
    {
        $exportUlid = $this->keep('Momento');
        $tables = ['student_item_scores', 'classifications', 'calculation_snapshots', 'evaluation_sheet_exports', 'domain_appreciation_decisions', 'reports'];
        $count = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), $tables);

        $before = $count();
        $this->pdf($this->liveUrl());
        $this->pdf($this->snapshotUrl($exportUlid));

        $this->assertSame($before, $count());
    }

    #[Test]
    public function the_kept_moment_pdf_actions_have_no_way_of_reaching_the_present(): void
    {
        // STRUCTURAL, NOT A HABIT: the sheet builder is a parameter of the live actions alone.
        $controller = EvaluationSheetGridDocumentController::class;
        $types = fn (string $method): array => array_map(
            fn ($parameter): string => (string) $parameter->getType(),
            (new \ReflectionMethod($controller, $method))->getParameters(),
        );

        $this->assertContains(BuildEvaluationSheet::class, $types('livePdf'));
        $this->assertNotContains(BuildEvaluationSheet::class, $types('snapshotPdf'));
        $this->assertNotContains(BuildEvaluationSheet::class, $types('snapshotSource'));
    }

    // ================================================================= grelhas extensas

    /**
     * A sheet of `$domains` domains and `$students` students, rendered through the same Blade and printer as the controller.
     *
     * @return array{string, array<string, mixed>} the file, and the structure it was drawn from
     */
    private function wideBytes(int $domains, int $students, bool $withSelfAssessment, string $orientation): array
    {
        $domainList = [];
        $studentDomains = [];

        foreach (range(1, $domains) as $index) {
            $domainList[] = ['domain_id' => $index, 'name' => 'Domínio '.$index];
            $studentDomains[] = [
                'domain_id' => $index, 'normalized_value' => '60', 'scale_level_code' => 'S', 'scale_level_label' => 'Suficiente',
                'self_assessment' => $withSelfAssessment ? ['display' => 'B', 'code' => 'B'] : null,
            ];
        }

        $rows = [];

        foreach (range(1, $students) as $position) {
            $rows[] = [
                'enrollment_id' => $position, 'class_number' => $position, 'name' => 'Aluno '.str_pad((string) $position, 2, '0', STR_PAD_LEFT),
                'overall' => ['normalized_value' => '60', 'scale_level_code' => 'S', 'scale_level_label' => 'Suficiente'],
                'domains' => $studentDomains,
                'classification' => ['status' => 'proposed', 'proposed_scale_level_code' => 'S', 'proposed_value' => '3', 'final_scale_level_code' => null, 'final_scale_level_label' => null, 'final_value' => null],
                'self_assessment' => $withSelfAssessment ? ['display' => 'S', 'code' => 'S'] : null,
            ];
        }

        $document = EvaluationSheetDocument::fromLiveSheet(
            EvaluationSheetSample::schoolClass(),
            EvaluationSheetSample::period(),
            ['domains' => $domainList, 'students' => $rows],
            ClassificationScope::Period,
        );

        $structure = [
            ...(new EvaluationSheetGrid)->build($document, new EvaluationSheetGridOptions(selfAssessment: $withSelfAssessment, orientation: $orientation)),
            'identity' => ['name' => 'Escola Teste', 'header_lines' => [], 'footer_note' => null, 'is_configured' => true, 'logo_url' => null, 'logo' => null],
            'issued_at' => now()->toIso8601String(),
            'issued_label' => 'Emitido a 15/12/2026, 10:00',
        ];

        $paper = PaperOrientation::from($structure['orientation']);
        $layout = $paper === PaperOrientation::Landscape ? LetterheadLayout::Compact : LetterheadLayout::Standard;

        return [
            app(DompdfPrinter::class)->print(
                View::make('evaluation-sheets.grid-document', ['document' => $structure, 'layout' => $layout])->render(),
                $paper,
                $layout,
            ),
            $structure,
        ];
    }

    #[Test]
    public function a_sheet_with_many_domains_and_many_students_loses_no_student_no_domain_and_no_page_number(): void
    {
        foreach ([InstrumentGridLayout::LANDSCAPE, InstrumentGridLayout::PORTRAIT] as $orientation) {
            foreach ([false, true] as $withSelfAssessment) {
                [$bytes, $structure] = $this->wideBytes(12, 30, $withSelfAssessment, $orientation);
                $pages = $this->pages($bytes);
                $text = implode(' ', $pages);
                $label = "{$orientation}, self-assessment ".json_encode($withSelfAssessment);

                $this->assertSame($orientation === InstrumentGridLayout::LANDSCAPE, $this->isLandscape($bytes), $label);
                $this->assertGreaterThan(2, count($pages), "crosses pages both ways: {$label}");

                // Every domain, in the file.
                foreach (range(1, 12) as $index) {
                    $this->assertStringContainsString('Domínio '.$index.' ', $text.' ', "{$label}: domain {$index}");
                }

                // Every student appears once per block of columns: none is lost to a page.
                $blocks = count($structure['blocks']);
                $this->assertGreaterThan(1, $blocks, $label);

                foreach (range(1, 30) as $position) {
                    $name = 'Aluno '.str_pad((string) $position, 2, '0', STR_PAD_LEFT);
                    $this->assertSame($blocks, substr_count($text, $name), "{$label}: {$name} once per block");
                }

                // Page numbers and the stamp on every page, the column heading on every page.
                foreach ($pages as $index => $page) {
                    $this->assertStringContainsString('Página '.($index + 1).' / '.count($pages), $page, "{$label}: page ".($index + 1));
                    $this->assertStringContainsString('PROVISÓRIO', $page, "{$label}: page ".($index + 1));
                    $this->assertStringContainsString('Nome', $page, "{$label}: heading repeats on page ".($index + 1));
                }

                $this->assertSame($withSelfAssessment, str_contains($text, 'Autoavaliação'), $label);
            }
        }
    }

    #[Test]
    public function an_empty_class_still_makes_a_valid_pdf(): void
    {
        $document = EvaluationSheetDocument::fromLiveSheet(
            EvaluationSheetSample::schoolClass(),
            EvaluationSheetSample::period(),
            ['domains' => [], 'students' => []],
            ClassificationScope::Period,
        );

        $structure = [
            ...(new EvaluationSheetGrid)->build($document, new EvaluationSheetGridOptions),
            'identity' => ['name' => 'Escola Teste', 'header_lines' => [], 'footer_note' => null, 'is_configured' => true, 'logo_url' => null, 'logo' => null],
            'issued_at' => now()->toIso8601String(),
            'issued_label' => 'Emitido a 15/12/2026, 10:00',
        ];

        $bytes = app(DompdfPrinter::class)->print(
            View::make('evaluation-sheets.grid-document', ['document' => $structure, 'layout' => LetterheadLayout::Compact])->render(),
            PaperOrientation::Landscape,
            LetterheadLayout::Compact,
        );

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('Não há alunos nesta turma.', implode(' ', $this->pages($bytes)));
    }
}
