<?php

namespace Tests\Feature\Reporting;

use App\Http\Controllers\EvaluationSheetGridDocumentController;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
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
use App\Models\SheetMomentKind;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGrid;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGridOptions;
use App\Support\Entitlements\Entitlements;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The preview of the printable grid of a class (C3): the two routes, who may open
 * them, what they hand to the page, and what they refuse.
 *
 * The structure itself is C2's and is tested there; what this holds is that the
 * page receives THAT structure untouched, that a kept moment is its payload and
 * never today, and that the routes are as closed as the Pauta they hang off.
 */
class EvaluationSheetGridDocumentTest extends TestCase
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

        // Inside the 1.º Semestre of the demo (14/09/2026 to 29/01/2027). Frozen, so the
        // page's «Emitido a …» can be compared to the byte.
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
        return "/classes/{$this->classUlid()}/pauta-avaliacao/impressao/".($period ?? $this->periodUlid()).$query;
    }

    private function snapshotUrl(string $exportUlid, string $query = '', ?string $class = null): string
    {
        return '/classes/'.($class ?? $this->classUlid())."/pauta-avaliacao/historico/{$exportUlid}/impressao{$query}";
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
     * @return array<string, mixed> the Inertia props of the page at `$url`
     */
    private function props(string $url, ?User $as = null): array
    {
        $props = null;

        $this->actingAs($as ?? $this->teacher)->get($url)->assertOk()->assertInertia(function ($page) use (&$props): void {
            $page->component('evaluation-sheets/GridDocument');
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    /**
     * What the page added to the structure (the school's letterhead, the moment of issue), taken off.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function withoutPrinting(array $document): array
    {
        unset($document['identity'], $document['issued_at'], $document['issued_label']);

        return $document;
    }

    /**
     * The grid the live page must hand over, made the way the controller makes it.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function expectedLive(array $query = [], SheetMomentKind $moment = SheetMomentKind::Final): array
    {
        return $this->asTenant(function () use ($query, $moment): array {
            $class = SchoolClass::where('label', '7.º A')->with(['subject', 'academicYear'])->firstOrFail();
            $period = $this->period($class);
            $sheet = app(BuildEvaluationSheet::class)->for($class, $period, ClassificationScope::Period, false);

            return json_decode((string) json_encode((new EvaluationSheetGrid)->build(
                EvaluationSheetDocument::fromLiveSheet($class, $period, $sheet, ClassificationScope::Period, $moment),
                EvaluationSheetGridOptions::fromQuery($query),
            )), true);
        });
    }

    // ================================================================= a pauta de hoje

    #[Test]
    public function the_page_receives_exactly_the_structure_the_grid_makes_from_the_live_sheet(): void
    {
        $this->decide('Carolina Nunes', '4');

        $props = $this->props($this->liveUrl());

        $this->assertSame($this->expectedLive(), $this->withoutPrinting($props['document']));
        $this->assertSame('Emitido a 15/12/2026, 10:00', $props['document']['issued_label']);
        $this->assertSame(['ulid', 'label'], array_keys($props['schoolClass']));
    }

    #[Test]
    public function the_live_sheet_says_where_it_comes_from_and_how_to_go_back(): void
    {
        $props = $this->props($this->liveUrl());

        $this->assertSame('live', $props['source']['kind']);
        $this->assertSame('final', $props['source']['moment']);
        $this->assertSame("/classes/{$this->classUlid()}/pauta-avaliacao/{$this->periodUlid()}", $props['source']['back']['href']);
        $this->assertSame("/classes/{$this->classUlid()}/pauta-avaliacao/impressao/{$this->periodUlid()}", $props['source']['base_url']);
        $this->assertSame([], $props['source']['fixed_query']);
        $this->assertFalse($props['document']['source']['is_historical']);
        $this->assertSame('Estado à data de emissão', $props['document']['source']['label']);
    }

    #[Test]
    public function the_interim_moment_titles_the_document_and_travels_with_every_option(): void
    {
        $props = $this->props($this->liveUrl('?momento=interim&orientacao=vertical'));

        $this->assertStringStartsWith('Momento intercalar do ', $props['document']['title']);
        $this->assertSame('interim', $props['source']['moment']);
        $this->assertSame(['momento' => 'interim'], $props['source']['fixed_query']);
        $this->assertStringEndsWith('?momento=interim', $props['source']['back']['href']);
        $this->assertSame($this->expectedLive(['orientacao' => 'vertical'], SheetMomentKind::Interim), $this->withoutPrinting($props['document']));

        // The two moments read the same sheet: only the title differs.
        $final = $this->props($this->liveUrl('?orientacao=vertical'))['document'];
        $this->assertNotSame($final['title'], $props['document']['title']);
        $this->assertSame($final['blocks'], $props['document']['blocks']);
        $this->assertSame($final['summary'], $props['document']['summary']);

        // A word nobody knows is the final moment, the one an old link opens.
        $unknown = $this->props($this->liveUrl('?momento=banana'));
        $this->assertSame('final', $unknown['source']['moment']);
        $this->assertSame($final['title'], $unknown['document']['title']);
    }

    #[Test]
    public function the_options_in_the_query_reach_the_grid_and_come_back_in_the_document(): void
    {
        $default = $this->props($this->liveUrl())['document'];

        $this->assertSame(['self_assessment' => false, 'orientation' => 'landscape'], $default['options']);
        $this->assertSame('landscape', $default['orientation']);

        $vertical = $this->props($this->liveUrl('?orientacao=vertical&autoavaliacao=1'))['document'];

        $this->assertSame('portrait', $vertical['orientation']);
        $this->assertSame('portrait', $vertical['options']['orientation']);
        $this->assertGreaterThanOrEqual(count($default['blocks']), count($vertical['blocks']), 'a Pauta is wide: vertical never takes fewer blocks');
        $this->assertSame($this->expectedLive(['orientacao' => 'vertical', 'autoavaliacao' => '1']), $this->withoutPrinting($vertical));
    }

    #[Test]
    public function forged_options_are_the_default_and_never_an_error(): void
    {
        $document = $this->props($this->liveUrl('?autoavaliacao[]=1&orientacao=diagonal&inventada=1'))['document'];

        $this->assertSame(['self_assessment' => false, 'orientation' => 'landscape'], $document['options']);
    }

    #[Test]
    public function the_live_sheet_is_read_without_the_proposal_basis(): void
    {
        // The grid does not read «a proposta de hoje» nor «desatualizada», and in the last
        // unit of the year those cost the results of every other unit.
        $sheet = $this->asTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return app(BuildEvaluationSheet::class)->for($class, $this->period($class), ClassificationScope::Period, false);
        });

        $calls = [];
        $this->partialMock(BuildEvaluationSheet::class, function ($mock) use ($sheet, &$calls): void {
            $mock->shouldReceive('for')->andReturnUsing(function (...$arguments) use ($sheet, &$calls): array {
                $calls[] = $arguments;

                return $sheet;
            });
        });

        $this->props($this->liveUrl());

        $this->assertCount(1, $calls);
        $this->assertFalse($calls[0][3] ?? true, 'withProposalBasis must be false');
    }

    #[Test]
    public function a_period_that_is_not_of_this_class_does_not_resolve(): void
    {
        $this->actingAs($this->teacher)->get($this->liveUrl(period: '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
        $this->actingAs($this->teacher)->get($this->liveUrl(period: 'não-é-um-ulid'))->assertNotFound();

        // A period of another organization is not a period at all.
        $stranger = User::factory()->create();
        $foreign = $this->asTenant(function (): string {
            $year = AcademicYear::create([
                'label' => '2026/2027', 'starts_on' => '2026-09-14', 'ends_on' => '2027-06-30', 'status' => 'active', 'country_code' => 'PT',
            ]);

            return $year->periods()->create([
                'label' => '1.º Período', 'kind' => 'term', 'sequence' => 1, 'starts_on' => '2026-09-14', 'ends_on' => '2026-12-19', 'status' => 'open',
            ])->ulid;
        }, $stranger->personalOrganization());

        $this->actingAs($this->teacher)->get($this->liveUrl(period: $foreign))->assertNotFound();
    }

    // ================================================================= um momento guardado

    #[Test]
    public function a_kept_moment_prints_its_payload_and_never_the_present(): void
    {
        $this->decide('Carolina Nunes', '3');
        $exportUlid = $this->keep('Momento de novembro');

        $before = $this->props($this->snapshotUrl($exportUlid));

        $this->assertSame('snapshot', $before['source']['kind']);
        $this->assertNull($before['source']['moment']);
        $this->assertTrue($before['document']['source']['is_historical']);
        $this->assertSame('Momento de novembro', $before['document']['title']);
        $this->assertSame('15/12/2026', $before['document']['meta']['reference_date']);
        $this->assertSame("/classes/{$this->classUlid()}/pauta-avaliacao/historico/{$exportUlid}", $before['source']['back']['href']);
        $this->assertSame("/classes/{$this->classUlid()}/pauta-avaliacao/historico/{$exportUlid}/impressao", $before['source']['base_url']);

        $expected = $this->asTenant(fn (): array => json_decode((string) json_encode((new EvaluationSheetGrid)->build(
            EvaluationSheetDocument::fromSnapshot(EvaluationSheetExport::where('ulid', $exportUlid)->firstOrFail()),
            new EvaluationSheetGridOptions,
        )), true));
        $this->assertSame($expected, $this->withoutPrinting($before['document']));

        // The teacher changes their mind: the live page follows, the kept one does not.
        $this->decide('Carolina Nunes', '4');

        $after = $this->props($this->snapshotUrl($exportUlid));
        $this->assertSame($before['document']['blocks'], $after['document']['blocks']);
        $this->assertSame($before, $after);

        // The «Nível atribuído» of one student, wherever the planner put that column.
        $assigned = function (array $document): ?string {
            foreach ($document['blocks'] as $block) {
                $position = array_search('assigned', array_column($block['columns'], 'kind'), true);

                foreach ($block['rows'] as $row) {
                    if ($position !== false && $row['name'] === 'Carolina Nunes') {
                        return $row['cells'][$position]['text'];
                    }
                }
            }

            return null;
        };

        $this->assertSame('3', $assigned($after['document']));
        $this->assertSame('4', $assigned($this->props($this->liveUrl())['document']));
    }

    #[Test]
    public function the_kept_moment_action_has_no_way_of_reaching_the_present(): void
    {
        // STRUCTURAL, NOT A HABIT: the sheet builder is a parameter of `live` alone.
        $live = array_map(fn ($parameter): string => (string) $parameter->getType(), (new ReflectionMethod(EvaluationSheetGridDocumentController::class, 'live'))->getParameters());
        $snapshot = array_map(fn ($parameter): string => (string) $parameter->getType(), (new ReflectionMethod(EvaluationSheetGridDocumentController::class, 'snapshot'))->getParameters());

        $this->assertContains(BuildEvaluationSheet::class, $live);
        $this->assertNotContains(BuildEvaluationSheet::class, $snapshot);

        $constructor = (new ReflectionMethod(EvaluationSheetGridDocumentController::class, '__construct'))->getParameters();
        $this->assertNotContains(BuildEvaluationSheet::class, array_map(fn ($parameter): string => (string) $parameter->getType(), $constructor));
    }

    #[Test]
    public function a_kept_moment_from_before_the_newer_keys_still_opens(): void
    {
        $exportUlid = $this->keep('Momento antigo');

        // A payload like the ones already in production: no self-assessment, no level codes,
        // no colours, no decision keys per domain.
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

        $document = $this->props($this->snapshotUrl($exportUlid, '?autoavaliacao=1'))['document'];

        $this->assertSame('Momento antigo', $document['title']);
        $this->assertFalse($document['self_assessment_available']);
        $this->assertFalse($document['options']['self_assessment'], 'asking for what the sheet does not have adds nothing');
        $this->assertStringContainsString('Carolina Nunes', (string) json_encode($document, JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThan(0, $document['summary']['students']);
    }

    #[Test]
    public function a_tampered_snapshot_is_refused_rather_than_printed(): void
    {
        $exportUlid = $this->keep('Momento intercalar');
        // The marker is built here, so the failure page (which prints this very test) cannot contain it.
        $marker = bin2hex(random_bytes(6));

        $this->asTenant(function () use ($marker): void {
            $export = EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail();
            $payload = $export->payload;
            $payload['students'][0]['name'] = 'Trocado-'.$marker;

            // The seal is NOT recomputed: this is exactly what the integrity check exists to catch.
            DB::table('evaluation_sheet_exports')->where('id', $export->id)->update(['payload' => json_encode($payload)]);
        });

        // The way production answers: the debug page prints variables and would show anything.
        config(['app.debug' => false]);

        $response = $this->actingAs($this->teacher)->get($this->snapshotUrl($exportUlid));

        $response->assertStatus(409);
        $this->assertStringNotContainsString($marker, (string) $response->getContent());
    }

    // ================================================================= quem pode abrir

    #[Test]
    public function a_guest_is_sent_to_login(): void
    {
        $snapshot = $this->snapshotUrl($this->keep('Momento'));
        $live = $this->liveUrl();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get($live)->assertRedirect('/login');
        $this->get($snapshot)->assertRedirect('/login');
    }

    #[Test]
    public function another_organization_cannot_resolve_the_class_the_period_or_the_record(): void
    {
        $exportUlid = $this->keep('Momento');
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get($this->liveUrl())->assertNotFound();
        $this->actingAs($stranger)->get($this->snapshotUrl($exportUlid))->assertNotFound();
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_gets_403_and_no_data(): void
    {
        $exportUlid = $this->keep('Momento');
        $colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$colleague->id => ['joined_at' => now()]]);

        $this->withSession(['organization_id' => $this->organization->id]);

        foreach ([$this->liveUrl(), $this->snapshotUrl($exportUlid)] as $url) {
            $response = $this->actingAs($colleague)->get($url);

            $response->assertForbidden();
            $this->assertStringNotContainsString('Carolina Nunes', (string) $response->getContent());
        }
    }

    #[Test]
    public function a_record_is_not_a_key_to_another_class_of_the_same_organization(): void
    {
        $exportUlid = $this->keep('Momento');

        // The teacher teaches the other class too, so the class gate lets them in
        // and only the record's own class can refuse.
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

        $response = $this->actingAs($this->teacher)->get($this->snapshotUrl($exportUlid, class: $otherClassUlid));

        $response->assertNotFound();
        $this->assertStringNotContainsString('Carolina Nunes', (string) $response->getContent());
    }

    #[Test]
    public function the_base_plan_may_open_them_and_a_blocked_module_may_not(): void
    {
        $exportUlid = $this->keep('Momento');

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
        $this->actingAs($this->teacher)->get($this->snapshotUrl($exportUlid))->assertOk();

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->getKey(),
            'module_id' => Module::where('key', 'results')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($this->teacher)->get($this->liveUrl())->assertForbidden();
        $this->actingAs($this->teacher)->get($this->snapshotUrl($exportUlid))->assertForbidden();
    }

    #[Test]
    public function they_still_open_while_the_account_is_read_only(): void
    {
        $exportUlid = $this->keep('Momento');

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'pro')->firstOrFail()->id,
                'status' => SubscriptionStatus::Suspended,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            ],
        );
        app(Entitlements::class)->flush();

        // GETs: a read-only account may still read and print what it already has.
        $this->actingAs($this->teacher)->get($this->liveUrl())->assertOk();
        $this->actingAs($this->teacher)->get($this->snapshotUrl($exportUlid))->assertOk();
    }

    // ================================================================= o que nunca faz

    #[Test]
    public function opening_them_writes_nothing_of_the_assessment_and_nothing_to_the_trail(): void
    {
        $exportUlid = $this->keep('Momento');
        $tables = ['student_item_scores', 'classifications', 'calculation_snapshots', 'evaluation_sheet_exports', 'domain_appreciation_decisions', 'audit_events', 'reports'];
        $count = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), $tables);

        $before = $count();
        $this->props($this->liveUrl());
        $this->props($this->liveUrl('?momento=interim&orientacao=vertical&autoavaliacao=1'));
        $this->props($this->snapshotUrl($exportUlid));

        $this->assertSame($before, $count());
    }

    #[Test]
    public function the_free_text_of_a_decision_and_every_identifier_stay_out_of_the_page(): void
    {
        $this->decide('Carolina Nunes', '4');

        $this->asTenant(function (): void {
            Classification::query()
                ->where('enrollment_id', Enrollment::query()->where('class_id', SchoolClass::where('label', '7.º A')->value('id'))->first()->id)
                ->update(['override_reason' => 'MOTIVO-LIVRE-RESERVADO']);
        });
        $exportUlid = $this->keep('Momento privado');

        foreach ([$this->liveUrl('?autoavaliacao=1'), $this->snapshotUrl($exportUlid, '?autoavaliacao=1')] as $url) {
            $response = $this->actingAs($this->teacher)->get($url);
            $response->assertOk();
            $html = (string) $response->getContent();

            $this->assertStringNotContainsString('MOTIVO-LIVRE-RESERVADO', $html);
            $this->assertStringNotContainsString('override_reason', $html);
            $this->assertStringNotContainsString('enrollment_id', $html);
        }
    }

    #[Test]
    public function the_pauta_and_the_history_point_to_the_grid(): void
    {
        $exportUlid = $this->keep('Momento');

        // The Pauta page keeps its own «Imprimir»; the link to the grid is a Vue concern (tested there).
        // Here: the routes the links point to exist and answer for the period the Pauta shows.
        $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/{$this->periodUlid()}")->assertOk();
        $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/historico")->assertOk();
        $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/historico/{$exportUlid}")->assertOk();
        $this->actingAs($this->teacher)->get($this->liveUrl())->assertOk();
        $this->actingAs($this->teacher)->get($this->snapshotUrl($exportUlid))->assertOk();
    }
}
