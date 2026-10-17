<?php

namespace Tests\Feature\Timetable;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\DataExport;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\TimetableActivity;
use App\Models\TimetableActivitySession;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * O histórico das «outras atividades» (0.170.0) e as duas exportações: a folha
 * «Outras atividades» de «Exportar os meus dados» e o Excel do filtro do
 * histórico. Só do dono; o texto nunca é uma fórmula.
 */
class ActivitySessionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'Europe/Lisbon';

    private User $teacher;

    private Organization $organization;

    private AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', self::TIMEZONE));

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        $this->academicYear = $this->inTenant($this->organization, fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create(['label' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']));
    }

    #[Test]
    public function the_history_lists_only_sessions_with_records_newest_first_with_the_whole_text(): void
    {
        $activity = $this->activity(['title' => 'Apoio 5.º']);
        $long = str_repeat('Texto longo com url https://exemplo.test/'.str_repeat('a', 200).' ', 8);
        $this->makeSession($activity, '2026-10-13', $long, 'done');
        $this->makeSession($activity, '2026-10-20', null, 'not_held');
        $this->makeSession($activity, '2026-10-27', null, 'planned');

        $this->history()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 2)
            ->where('sessions.0.occurs_on', '2026-10-20')
            ->where('sessions.0.presentation', 'not_held')
            ->where('sessions.1.occurs_on', '2026-10-13')
            ->where('sessions.1.summary', $long)
            ->where('filters.from', '2026-09-01')
            ->where('filters.to', '2027-06-30')
            ->where('pagination.total', 2)
            ->etc());
    }

    #[Test]
    public function the_history_filters_by_activity_including_closed_blocks_and_orphans_by_their_copy(): void
    {
        $open = $this->activity(['title' => 'Apoio']);
        $closed = $this->activity(['title' => 'Clube', 'kind' => 'club', 'day_of_week' => 4, 'ends_on' => '2026-09-30']);
        $gone = $this->activity(['title' => 'Antiga', 'day_of_week' => 5]);
        $this->makeSession($open, '2026-10-13', 'Do apoio.', 'done');
        $this->makeSession($closed, '2026-09-24', 'Do clube.', 'done');
        $this->makeSession($gone, '2026-10-02', 'Da antiga.', 'done');
        $this->inTenant($this->organization, fn () => TimetableActivity::query()->whereKey($gone->id)->delete());

        $this->history(['activity' => $closed->ulid])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 1)->where('sessions.0.summary', 'Do clube.')->etc());

        $this->history(['activity' => 'title:Antiga'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 1)->where('sessions.0.summary', 'Da antiga.')->where('sessions.0.activity_ulid', null)->etc());

        $this->history()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 3)
            ->has('activityOptions', 3)
            ->etc());
    }

    #[Test]
    public function the_history_filters_by_period_and_an_empty_period_means_everything(): void
    {
        $activity = $this->activity();
        $this->makeSession($activity, '2026-10-13', 'Outubro.', 'done');
        $this->makeSession($activity, '2026-11-03', 'Novembro.', 'done');
        $this->makeSession($activity, '2026-08-25', 'Antes do ano letivo.', 'done');

        $this->history(['from' => '2026-11-01', 'to' => '2026-11-30'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 1)->where('sessions.0.summary', 'Novembro.')->etc());

        // O fim do período é inclusivo.
        $this->history(['from' => '2026-10-13', 'to' => '2026-10-13'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 1)->where('sessions.0.summary', 'Outubro.')->etc());

        $this->history(['from' => '', 'to' => ''])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 3)->etc());

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/activity-sessions?from=2026-11-30&to=2026-11-01')->assertSessionHasErrors('to');
    }

    #[Test]
    public function a_filter_naming_another_teachers_activity_finds_nothing_and_confirms_nothing(): void
    {
        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $theirs = $this->activity(['title' => 'Do colega'], $colleague);
        $this->makeSession($theirs, '2026-10-13', 'Texto do colega.', 'done', $colleague);
        $mine = $this->activity(['title' => 'Minha', 'day_of_week' => 4]);
        $this->makeSession($mine, '2026-10-15', 'Meu texto.', 'done');

        $this->history(['activity' => $theirs->ulid])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 0)->etc());
        $this->history()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 1)->where('sessions.0.summary', 'Meu texto.')->etc());
    }

    // ------------------------------------------------------------- exportação

    #[Test]
    public function the_history_excel_is_the_current_filter_of_the_owner_only_and_audited_with_counts(): void
    {
        $activity = $this->activity(['title' => 'Apoio 5.º', 'kind' => 'student_support', 'numbering_enabled' => true]);
        $this->makeSession($activity, '2026-10-13', '=HYPERLINK("http://x.test","clica")', 'done', null, 1);
        $this->makeSession($activity, '2026-10-20', 'Segundo.', 'planned');
        $this->makeSession($activity, '2026-10-27', 'Fora do filtro.', 'done');

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $theirs = $this->activity(['title' => 'Do colega', 'day_of_week' => 4], $colleague);
        $this->makeSession($theirs, '2026-10-15', 'Texto do colega.', 'done', $colleague);

        $response = $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/activity-sessions/export?from=2026-10-01&to=2026-10-26');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename="outras-atividades-\d{8}\.xlsx"$/', (string) $response->headers->get('Content-Disposition'));

        $sheet = $this->workbook((string) $response->getContent())->getSheetByName('Outras atividades');
        $this->assertNotNull($sheet);
        $this->assertSame(['Data', 'Início', 'Fim', 'Atividade', 'Tipo', 'Turma', 'N.º', 'Estado', 'Sumário'], array_map(fn (string $column): string => (string) $sheet->getCell($column.'1')->getValue(), ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I']));

        // Mais recente primeiro: 20/10 (preparada), depois 13/10 (realizada, n.º 1).
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame('Segundo.', $sheet->getCell('I2')->getValue());
        $this->assertSame('Preparada', $sheet->getCell('H2')->getValue());
        $this->assertSame('Apoio 5.º', $sheet->getCell('D3')->getValue());
        $this->assertSame('Apoio a alunos', $sheet->getCell('E3')->getValue());
        $this->assertSame(1, (int) $sheet->getCell('G3')->getValue());
        $this->assertSame('Realizada', $sheet->getCell('H3')->getValue());
        $this->assertSame('10:30', $sheet->getCell('B3')->getValue());

        // Texto, nunca fórmula.
        $this->assertSame('=HYPERLINK("http://x.test","clica")', $sheet->getCell('I3')->getValue());
        $this->assertSame('s', $sheet->getCell('I3')->getDataType());
        $this->assertStringNotContainsString('Texto do colega.', (string) $response->getContent());

        $event = AuditEvent::query()->where('event', 'activity-sessions.exported')->latest('id')->firstOrFail();
        $this->assertSame(['format' => 'xlsx', 'sessions' => 2], $event->properties);
        $this->assertStringNotContainsString('clica', (string) json_encode($event->toArray()));
    }

    #[Test]
    public function the_export_of_another_teacher_never_contains_my_sessions(): void
    {
        $activity = $this->activity();
        $this->makeSession($activity, '2026-10-13', 'Meu texto.', 'done');

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);

        $response = $this->actingAs($colleague)->withSession($this->tenantSession())->get('/activity-sessions/export');

        $response->assertOk();
        $sheet = $this->workbook((string) $response->getContent())->getSheetByName('Outras atividades');
        $this->assertSame(1, $sheet->getHighestRow());
    }

    #[Test]
    public function the_data_export_has_an_outras_atividades_sheet_with_the_owners_sessions_only(): void
    {
        Storage::fake('local');

        $activity = $this->activity(['title' => 'Clube de Xadrez', 'kind' => 'club']);
        $this->makeSession($activity, '2026-10-13', "Aberturas.\nFinais.", 'done');
        $this->makeSession($activity, '2026-10-20', null, 'planned');

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $theirs = $this->activity(['title' => 'Do colega', 'day_of_week' => 4], $colleague);
        $this->makeSession($theirs, '2026-10-15', 'Texto do colega.', 'done', $colleague);

        $this->actingAs($this->teacher)->withSession($this->tenantSession())->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->teacher->id)->latest('id')->firstOrFail();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->disk_path)) === true);
        $workbook = $this->workbook((string) $zip->getFromName('Exportacao-Lapispro.xlsx'));
        $sheet = $workbook->getSheetByName('Outras atividades');

        $this->assertNotNull($sheet);
        $this->assertSame(2, $sheet->getHighestRow());
        $this->assertSame('Clube de Xadrez', $sheet->getCell('D2')->getValue());
        $this->assertSame('Clube', $sheet->getCell('E2')->getValue());
        $this->assertSame("Aberturas.\nFinais.", $sheet->getCell('I2')->getValue());
        $this->assertStringNotContainsString('Texto do colega.', (string) $zip->getFromName('Exportacao-Lapispro.xlsx'));

        $resumo = $workbook->getSheetByName('Resumo');
        $found = false;

        foreach ($resumo->toArray() as $line) {
            if (($line[0] ?? null) === 'Nº de sessões de outras atividades com registos') {
                $this->assertSame(1, (int) $line[1]);
                $found = true;
            }
        }

        $this->assertTrue($found);
    }

    // ----------------------------------------------------- ciclo de vida

    #[Test]
    public function deleting_a_draft_academic_year_keeps_the_sessions_without_a_year(): void
    {
        $activity = $this->activity();
        $session = $this->makeSession($activity, '2026-10-13', 'Texto.', 'done');
        $this->assertSame($this->academicYear->id, $session->academic_year_id);

        DB::table('academic_years')->where('id', $this->academicYear->id)->delete();

        $session->refresh();
        $this->assertNull($session->academic_year_id);
        $this->assertSame('Texto.', $session->summary);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, string>  $query
     */
    private function history(array $query = []): TestResponse
    {
        return $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/activity-sessions'.($query === [] ? '' : '?'.http_build_query($query)))
            ->assertOk();
    }

    private function workbook(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis_activity_sessions_test_');
        file_put_contents($path, $bytes);

        try {
            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activity(array $attributes = [], ?User $owner = null): TimetableActivity
    {
        return $this->inTenant($this->organization, fn (): TimetableActivity => TimetableActivity::create([
            'user_id' => ($owner ?? $this->teacher)->id,
            'kind' => 'other',
            'title' => 'RTC',
            'day_of_week' => 2,
            'starts_at' => '10:30',
            'ends_at' => '11:20',
            ...$attributes,
        ]));
    }

    private function makeSession(TimetableActivity $activity, string $date, ?string $summary, string $status, ?User $owner = null, ?int $number = null): TimetableActivitySession
    {
        return $this->inTenant($this->organization, function () use ($activity, $date, $summary, $status, $owner, $number): TimetableActivitySession {
            $session = new TimetableActivitySession;
            $session->forceFill([
                'user_id' => ($owner ?? $this->teacher)->id,
                'timetable_activity_id' => $activity->id,
                'activity_kind' => $activity->kind->value,
                'activity_title' => $activity->title,
                'class_id' => $activity->class_id,
                'academic_year_id' => $this->academicYear->id,
                'occurs_on' => $date,
                'starts_at' => $activity->starts_at,
                'ends_at' => $activity->ends_at,
                'summary' => $summary,
                'status' => $status,
                'session_number' => $number,
            ])->save();

            return $session;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantSession(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'academic_year_id' => $this->academicYear->id,
        ];
    }

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
