<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\Classification;
use App\Models\Domain;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guardar uma pauta e voltar a abri-la (Fatia 3).
 *
 * A regra que todos estes testes protegem é uma só: uma pauta guardada é uma
 * FOTOGRAFIA, e uma fotografia não muda quando o mundo muda. Nem quando as
 * notas mudam, nem quando o período muda de nome, nem quando um domínio é
 * recolorido, nem quando alguém guarda outra pauta do mesmo momento.
 */
class EvaluationSheetHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        // O cenário de demonstração corre em 2026/2027 e o «1.º Semestre» vai
        // de 14/09/2026 a 29/01/2027. Guardar uma pauta numa data futura é
        // recusado de propósito — uma fotografia de um momento que ainda não
        // chegou seria a fotografia de nada — por isso o relógio do teste tem
        // de estar DENTRO do período que está a ser fotografado.
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback, ?User $user = null): mixed
    {
        return app(CurrentOrganization::class)->runFor(($user ?? $this->teacher)->personalOrganization(), $callback);
    }

    /** @return array{SchoolClass, AcademicPeriod} */
    private function context(): array
    {
        return $this->asTenant(function (): array {
            $schoolClass = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$schoolClass, $schoolClass->academicYear->periods()->where('sequence', 1)->firstOrFail()];
        });
    }

    private function save(string $label = 'Semestre — 1.º Semestre', ?string $effectiveAt = null): TestResponse
    {
        [$class, $period] = $this->context();

        return $this->actingAs($this->teacher)->post(
            "/classes/{$class->ulid}/pauta-avaliacao/{$period->ulid}/guardar",
            [
                'moment_label' => $label,
                'effective_at' => $effectiveAt ?? $period->starts_on->copy()->addDays(30)->toDateString(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page['props'];
    }

    /**
     * @return array<string, mixed>
     */
    private function openHistory(): array
    {
        [$class] = $this->context();

        return $this->props($this->actingAs($this->teacher)->get("/classes/{$class->ulid}/pauta-avaliacao/historico"));
    }

    /**
     * @return array<string, mixed>
     */
    private function openSnapshot(string $exportUlid): array
    {
        [$class] = $this->context();

        return $this->props(
            $this->actingAs($this->teacher)->get("/classes/{$class->ulid}/pauta-avaliacao/historico/{$exportUlid}"),
        );
    }

    /** Confirma um nível para a Carolina — uma alteração real, posterior. */
    private function decideCarolinasClassification(): void
    {
        [$class, $period] = $this->context();

        $this->asTenant(function () use ($class, $period): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);

            $carolina = Classification::query()
                ->where('academic_period_id', $period->id)
                ->get()
                ->first(fn (Classification $classification): bool => $classification->enrollment->student->identity->display_name === 'Carolina Nunes');

            $levelFour = $class->profileVersion->scale->levels()->where('code', '4')->firstOrFail();

            app(ConfirmClassification::class)->confirm($carolina, $this->teacher, $levelFour->id);
        });
    }

    // -------------------------------------------------------------------- A

    #[Test]
    public function a_saved_sheet_never_moves_when_the_live_results_do(): void
    {
        $this->save()->assertRedirect();

        $export = $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());
        $before = $export->payload['students'];

        // Nada estava decidido quando a fotografia foi tirada.
        $carolinaBefore = collect($before)->firstWhere('name', 'Carolina Nunes');
        $this->assertNotNull($carolinaBefore);
        $this->assertNull($carolinaBefore['classification']);

        $this->decideCarolinasClassification();

        // A pauta VIVA mudou — se não tivesse mudado, este teste não estaria a
        // provar nada sobre a fotografia.
        [$class] = $this->context();
        $live = $this->props($this->actingAs($this->teacher)->get("/classes/{$class->ulid}/pauta-avaliacao"));
        $carolinaLive = collect($live['sheet']['students'])->firstWhere('name', 'Carolina Nunes');
        $this->assertSame('Bom', $carolinaLive['classification']['final_scale_level_label']);

        // A fotografia não.
        $snapshot = $this->openSnapshot($export->ulid)['snapshot'];
        $this->assertSame($before, $snapshot['students']);
        $this->assertNull(collect($snapshot['students'])->firstWhere('name', 'Carolina Nunes')['classification']);
    }

    // -------------------------------------------------------------------- B

    #[Test]
    public function several_snapshots_of_the_same_moment_all_survive_and_only_the_first_is_the_most_recent(): void
    {
        [, $period] = $this->context();
        $sameDate = $period->starts_on->copy()->addDays(30)->toDateString();

        $this->save('Semestre — 1.º Semestre', $sameDate)->assertRedirect();
        $this->decideCarolinasClassification();
        $this->save('Semestre — 1.º Semestre', $sameDate)->assertRedirect();
        $this->save('Semestre — 1.º Semestre', $sameDate)->assertRedirect();

        $ulids = $this->asTenant(
            fn (): array => EvaluationSheetExport::query()->orderBy('id')->pluck('ulid')->all(),
        );
        $this->assertCount(3, $ulids, 'Nenhuma pauta guardada pode ser substituída por outra.');

        $entries = $this->openHistory()['entries'];
        $this->assertCount(3, $entries);

        // Mais recente primeiro — e «mais recente» é uma posição na lista, não
        // uma coluna: nada no registo diz que o é.
        $this->assertSame(array_reverse($ulids), array_column($entries, 'ulid'));

        $this->asTenant(function (): void {
            foreach (EvaluationSheetExport::query()->get() as $export) {
                $this->assertArrayNotHasKey('is_latest', $export->getAttributes());
            }
        });

        // A primeira fotografia continua sem classificação; as posteriores já a
        // têm. Três registos do mesmo momento, três conteúdos diferentes.
        $first = $this->openSnapshot($ulids[0])['snapshot'];
        $last = $this->openSnapshot($ulids[2])['snapshot'];
        $this->assertNull(collect($first['students'])->firstWhere('name', 'Carolina Nunes')['classification']);
        $this->assertSame(
            'Bom',
            collect($last['students'])->firstWhere('name', 'Carolina Nunes')['classification']['final_scale_level_label'],
        );
    }

    // -------------------------------------------------------------------- C

    #[Test]
    public function another_organization_cannot_reach_a_snapshot(): void
    {
        $this->save()->assertRedirect();

        [$class] = $this->context();
        $export = $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/classes/{$class->ulid}/pauta-avaliacao/historico/{$export->ulid}")
            ->assertNotFound();

        $this->actingAs($stranger)
            ->get("/classes/{$class->ulid}/pauta-avaliacao/historico")
            ->assertNotFound();
    }

    #[Test]
    public function a_snapshot_cannot_be_opened_through_another_class_of_the_same_organization(): void
    {
        $this->save()->assertRedirect();

        $export = $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());

        // Outra turma DA MESMA ORGANIZAÇÃO, do mesmo professor: o escopo de
        // tenant não a distingue, só a verificação explícita class_id === class
        // o faz. Sem ela, o ulid no URL é um IDOR entre turmas.
        $otherClass = $this->asTenant(function (): SchoolClass {
            $original = SchoolClass::where('label', '7.º A')->firstOrFail();

            $class = SchoolClass::create([
                'academic_year_id' => $original->academic_year_id,
                'subject_id' => $original->subject_id,
                'grade_level' => $original->grade_level,
                'label' => '7.º B',
                'status' => 'preparation',
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });

        $this->actingAs($this->teacher)
            ->get("/classes/{$otherClass->ulid}/pauta-avaliacao/historico/{$export->ulid}")
            ->assertNotFound();
    }

    // -------------------------------------------------------------------- D

    #[Test]
    public function renaming_the_period_afterwards_never_rewrites_what_the_history_says(): void
    {
        $this->save()->assertRedirect();

        $export = $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());

        [, $period] = $this->context();
        $this->asTenant(function () use ($period): void {
            AcademicPeriod::query()->whereKey($period->id)->update([
                'label' => '1.º Trimestre renomeado',
                'kind' => AcademicPeriodKind::Trimester->value,
            ]);
        });

        // A configuração atual mudou mesmo.
        $this->assertSame(
            '1.º Trimestre renomeado',
            $this->asTenant(fn (): string => AcademicPeriod::query()->whereKey($period->id)->firstOrFail()->label),
        );

        $entry = collect($this->openHistory()['entries'])->firstWhere('ulid', $export->ulid);
        $this->assertSame('1.º Semestre', $entry['period_label']);
        $this->assertSame('Semestre', $entry['period_kind_label']);

        $snapshot = $this->openSnapshot($export->ulid)['snapshot'];
        $this->assertSame('1.º Semestre', $snapshot['period']['label']);
        $this->assertSame('Semestre', $snapshot['period']['kind_label']);
    }

    // -------------------------------------------------------------------- E

    #[Test]
    public function a_snapshot_keeps_the_domain_names_and_colours_it_was_taken_with(): void
    {
        $this->save()->assertRedirect();

        $export = $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());
        $originalDomains = $export->payload['domains'];

        $reading = collect($originalDomains)->firstWhere('name', 'Leitura');
        $this->assertNotNull($reading);
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $reading['color']);

        $this->asTenant(function () use ($reading): void {
            Domain::query()->whereKey($reading['domain_id'])->update([
                'name' => 'Leitura (renomeado)',
                'color' => '#123456',
            ]);
        });

        // A pauta viva já mostra o novo nome e a nova cor.
        [$class] = $this->context();
        $live = $this->props($this->actingAs($this->teacher)->get("/classes/{$class->ulid}/pauta-avaliacao"));
        $liveReading = collect($live['sheet']['domains'])->firstWhere('domain_id', $reading['domain_id']);
        $this->assertSame('Leitura (renomeado)', $liveReading['name']);
        $this->assertSame('#123456', $liveReading['color']);

        // A fotografia mostra o que mostrava.
        $snapshot = $this->openSnapshot($export->ulid)['snapshot'];
        $this->assertSame($originalDomains, $snapshot['domains']);

        $snapshotReading = collect($snapshot['domains'])->firstWhere('domain_id', $reading['domain_id']);
        $this->assertSame('Leitura', $snapshotReading['name']);
        $this->assertSame($reading['color'], $snapshotReading['color']);

        // E as apreciações por domínio de cada aluno também não se mexeram.
        $carolina = collect($snapshot['students'])->firstWhere('name', 'Carolina Nunes');
        $carolinaReading = collect($carolina['domains'])->firstWhere('domain_id', $reading['domain_id']);
        $this->assertSame('Leitura', $carolinaReading['name']);
    }

    // -------------------------------------------------------------------- F

    #[Test]
    public function the_author_the_moment_and_the_two_dates_are_recorded(): void
    {
        [, $period] = $this->context();
        $effectiveAt = $period->starts_on->copy()->addDays(45)->toDateString();

        $this->save('Conselho de Turma — dezembro', $effectiveAt)->assertRedirect();

        $export = $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());

        $this->assertSame('Conselho de Turma — dezembro', $export->moment_label);
        $this->assertSame($effectiveAt, $export->effective_at->toDateString());
        $this->assertSame($this->teacher->id, $export->exported_by);
        $this->assertSame('snapshot', $export->adapter);
        $this->assertTrue($export->isIntact());

        $this->assertSame($this->teacher->name, $export->payload['author']['name']);
        $this->assertSame('Conselho de Turma — dezembro', $export->payload['moment']['label']);
        $this->assertSame($effectiveAt, $export->payload['moment']['effective_at']);
        $this->assertSame(1, $export->payload['version']);
        $this->assertSame('period', $export->payload['scope']);
        $this->assertSame('7.º A', $export->payload['class']['label']);

        $entry = collect($this->openHistory()['entries'])->firstWhere('ulid', $export->ulid);
        $this->assertSame('Conselho de Turma — dezembro', $entry['moment_label']);
        $this->assertSame($effectiveAt, $entry['effective_at']);
        $this->assertSame($this->teacher->name, $entry['author']);
    }

    #[Test]
    public function the_reference_date_is_checked_against_the_periods_own_dates(): void
    {
        [, $period] = $this->context();

        $this->save('Fora do período', $period->starts_on->copy()->subDay()->toDateString())
            ->assertSessionHasErrors('effective_at');

        $this->assertSame(0, $this->asTenant(fn (): int => EvaluationSheetExport::query()->count()));
    }

    // -------------------------------------------------------------------- G

    #[Test]
    public function a_sheet_kept_without_an_excel_file_is_a_first_class_record(): void
    {
        $this->save()->assertRedirect();

        $export = $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());

        $this->assertNull($export->file_path);
        $this->assertNull($export->file_checksum);
        $this->assertNull($export->original_extension);
        $this->assertSame('Guardado', $export->statusLabel());

        $entry = collect($this->openHistory()['entries'])->firstWhere('ulid', $export->ulid);
        $this->assertFalse($entry['has_file']);
        $this->assertSame('Guardado', $entry['status_label']);

        $props = $this->openSnapshot($export->ulid);
        $this->assertNull($props['integrityFailure']);
        $this->assertNotEmpty($props['snapshot']['students']);
    }

    #[Test]
    public function warnings_are_sentences_a_teacher_can_read(): void
    {
        $this->save()->assertRedirect();

        $export = $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());

        $this->assertGreaterThan(0, $export->warning_count);
        $this->assertTrue($export->exported_with_warnings);
        $this->assertSame($export->warning_count, count($export->payload['warnings']));

        foreach ($export->payload['warnings'] as $warning) {
            $this->assertIsString($warning);
            $this->assertStringEndsWith('.', $warning);
        }

        $this->assertContains('Diogo Ferreira: sem classificação registada.', $export->payload['warnings']);
    }

    #[Test]
    public function a_tampered_snapshot_is_refused_rather_than_shown(): void
    {
        $this->save()->assertRedirect();

        $export = $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());

        // O modelo recusa update(); a adulteração que interessa é a que não
        // passa pelo modelo — uma escrita direta na base de dados.
        $this->asTenant(function () use ($export): void {
            EvaluationSheetExport::query()->whereKey($export->id)->toBase()->update([
                'payload' => json_encode(['version' => 1, 'students' => ['adulterado']]),
            ]);
        });

        $props = $this->openSnapshot($export->ulid);

        $this->assertNull($props['snapshot']);
        $this->assertNotNull($props['integrityFailure']);
    }
}
