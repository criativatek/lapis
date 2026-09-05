<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A pauta de classificações antiga (`pautas.*`) foi ABSORVIDA pela Pauta de
 * Avaliação, e este ficheiro mudou de sujeito sem mudar de afirmações.
 *
 * O que ele dizia continua a ser verdade e continua a ter de ser provado — «só
 * classificações decididas entram», «uma proposta não é uma nota», «a
 * organização de ao lado não vê nada disto» — só que agora é dito sobre o ecrã
 * novo. O que desapareceu com o ecrã antigo (o toggle dos Registos do
 * professor) tem aqui o teste que DOCUMENTA a remoção: as rotas já não
 * existem, e os dados que elas escreveram continuam na base.
 *
 * Cobre também o que a absorção acrescentou de novo — o CSV e a impressão — e
 * o que ela prometeu: as URLs antigas continuam a responder.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{string, string} class ulid, period ulid */
    private function seedWithOneConfirmedGrade(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = Classification::query()
                ->where('academic_period_id', $period->id)
                ->get()
                ->first(fn ($classification) => $classification->enrollment->student->identity->display_name === 'Carolina Nunes');
            app(ConfirmClassification::class)->confirm($carolina, $teacher);

            return [$class->ulid, $period->ulid];
        });
    }

    private function teacher(): User
    {
        return User::where('email', 'ana.martins@lapis.test')->firstOrFail();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(User $user, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($user->personalOrganization(), $callback);
    }

    // ------------------------------------------------------------------ (A) menu

    #[Test]
    public function the_reports_hub_no_longer_offers_a_second_door_to_the_pauta(): void
    {
        $hub = file_get_contents(resource_path('js/pages/reports/Index.vue'));

        $this->assertNotFalse($hub);
        $this->assertStringNotContainsString('Pautas de classificações', $hub);
        $this->assertStringNotContainsString('/reports/pautas', $hub);
    }

    #[Test]
    public function the_pauta_de_avaliacao_stays_in_the_avaliacao_group_of_the_menu(): void
    {
        /** @var array<int, array{label: string|null, items: array<int, array<string, mixed>>}> $sections */
        $sections = config('navigation.sections');

        $avaliacao = collect($sections)->firstWhere('label', 'Avaliação');
        $this->assertNotNull($avaliacao);

        $item = collect($avaliacao['items'])->firstWhere('key', 'evaluation-sheets');
        $this->assertNotNull($item);
        $this->assertSame('Pautas de Avaliação', $item['label']);
        $this->assertSame('evaluation-sheets.index', $item['route']);
    }

    // -------------------------------------------------------------- (B) redirects

    #[Test]
    public function the_old_pauta_urls_still_answer_and_land_on_the_new_screen(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get('/reports/pautas')
            ->assertRedirect('/avaliacao/pautas');

        // A TURMA É PRESERVADA: um bookmark de uma turma tem de aterrar nessa
        // turma, e não numa lista onde é preciso encontrá-la outra vez.
        $this->actingAs($teacher)->get("/classes/{$classUlid}/report")
            ->assertRedirect("/classes/{$classUlid}/pauta-avaliacao");

        $this->actingAs($teacher)->get("/classes/{$classUlid}/report/export")
            ->assertRedirect("/classes/{$classUlid}/pauta-avaliacao/csv");
    }

    #[Test]
    public function the_destinations_of_the_old_urls_really_open(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get('/avaliacao/pautas')->assertOk();
        $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao")->assertOk();
        $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao/csv")->assertOk();
    }

    #[Test]
    public function a_redirect_does_not_hand_anyone_a_class_they_could_not_see(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $stranger = User::factory()->create();

        // O redirect não decide nada — é o DESTINO que aplica o seu gate. Seguir
        // a redireção até ao fim é a única prova que interessa.
        $this->actingAs($stranger)->get("/classes/{$classUlid}/report")
            ->assertRedirect("/classes/{$classUlid}/pauta-avaliacao");
        $this->actingAs($stranger)->get("/classes/{$classUlid}/pauta-avaliacao")->assertNotFound();

        $this->actingAs($stranger)->get("/classes/{$classUlid}/report/export")
            ->assertRedirect("/classes/{$classUlid}/pauta-avaliacao/csv");
        $this->actingAs($stranger)->get("/classes/{$classUlid}/pauta-avaliacao/csv")->assertNotFound();
    }

    // --------------------------------------------- (C) classificações decididas

    #[Test]
    public function the_decided_classification_reaches_the_new_pauta(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = $this->teacher();

        $response = $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao");
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');
        $students = collect($page['props']['sheet']['students']);

        $carolina = $students->firstWhere('name', 'Carolina Nunes');
        $this->assertNotNull($carolina);
        $this->assertSame('confirmed', $carolina['classification']['status']);
        $this->assertSame('5.000', $carolina['classification']['final_value']);

        // UMA PROPOSTA NÃO É UMA NOTA, e o ecrã não a transforma numa. Quem
        // ainda não foi decidido chega com `final_value` a null — nunca com a
        // proposta copiada para lá, e nunca com um zero no lugar da ausência.
        // A distinção em si já está coberta por ClassificationDecisionTest e
        // por resources/js/pages/evaluation-sheets/Show.test.ts; aqui prova-se
        // que chega intacta a este ecrã.
        $undecided = $students->filter(
            fn (array $student): bool => $student['classification'] !== null
                && $student['classification']['status'] !== 'confirmed',
        );

        $this->assertNotEmpty($undecided);

        foreach ($undecided as $student) {
            $this->assertNull($student['classification']['final_value']);
            $this->assertNull($student['classification']['final_scale_level_label']);
        }

        // E quem não tem sequer proposta não ganha nenhuma no caminho.
        $diogo = $students->firstWhere('name', 'Diogo Ferreira');
        $this->assertNotNull($diogo);
        $this->assertNull($diogo['classification']);
    }

    // ------------------------------------------------------------ (D) impressão

    #[Test]
    public function the_pauta_is_printed_from_its_own_screen_and_never_prints_the_controls(): void
    {
        $screen = file_get_contents(resource_path('js/pages/evaluation-sheets/Show.vue'));

        $this->assertNotFalse($screen);

        // Sem página paralela: a impressão vive neste ecrã, em @media print.
        $this->assertStringContainsString('@media print', $screen);
        $this->assertStringContainsString('window.print()', $screen);

        // Os controlos interativos ficam de fora do papel: a regra existe em
        // CSS, e cada bloco de controlos está marcado com ela — o cabeçalho
        // (que contém o seletor de período), a barra de ações com os botões, o
        // formulário de guardar, e os toggles «Mostrar:».
        $this->assertStringContainsString('.print-hide', $screen);
        $this->assertStringContainsString('display: none !important', $screen);
        $this->assertStringContainsString('print-hide flex flex-wrap items-center justify-between', $screen);
        $this->assertStringContainsString('print-hide flex flex-wrap items-center gap-3', $screen);
        $this->assertStringContainsString('print-hide space-y-3', $screen);
        $this->assertStringContainsString('class="print-hide"', $screen);

        // E o cabeçalho impresso diz de que turma, de que disciplina, de que
        // período (com a terminologia dinâmica, nunca uma palavra fixa) e de
        // que dia é a folha.
        $this->assertStringContainsString('selectedPeriod.kind_label', $screen);
        $this->assertStringContainsString('printedOn', $screen);
    }

    // ----------------------------------------------------------------- (E) CSV

    #[Test]
    public function the_csv_carries_the_decided_classification_and_the_whole_sheet(): void
    {
        [$classUlid, $periodUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = $this->teacher();

        $response = $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao/csv/{$periodUlid}");

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'attachment; filename="pauta-avaliacao_',
            (string) $response->headers->get('content-disposition'),
        );

        $body = $response->getContent();
        $this->assertIsString($body);

        // BOM UTF-8 à cabeça, para o Excel abrir os acentos bem.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $this->assertStringContainsString('Nº,Aluno', $body);
        $this->assertStringContainsString('Carolina Nunes', $body);

        // Todos os domínios do perfil, com quantitativo E apreciação.
        foreach (['Oralidade', 'Leitura', 'Escrita', 'Gramática', 'Educação Literária'] as $domain) {
            $this->assertStringContainsString("{$domain} — Percentagem", $body);
            $this->assertStringContainsString("{$domain} — Apreciação", $body);
        }

        $this->assertStringContainsString('Global — Percentagem', $body);
        $this->assertStringContainsString('Global — Apreciação', $body);
        $this->assertStringContainsString('Nível atribuído', $body);

        // A decisão do professor está lá, e diz-se DECISÃO. Num CSV não há
        // negrito nem itálico: a origem do nível tem de vir escrita, ou uma
        // proposta acabaria por se ler como uma nota.
        $decidedLabel = $this->asTenant($teacher, function (): string {
            $classification = Classification::query()
                ->whereNotNull('final_scale_level_id')
                ->with('finalScaleLevel')
                ->firstOrFail();

            return (string) $classification->finalScaleLevel?->label;
        });

        $this->assertStringContainsString($decidedLabel, $body);
        $this->assertStringContainsString('Decisão do professor', $body);
    }

    #[Test]
    public function the_csv_exports_everything_because_the_server_never_hears_about_the_toggles(): void
    {
        [$classUlid, $periodUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = $this->teacher();

        $plain = $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao/csv/{$periodUlid}");
        $plain->assertOk();

        // Os toggles são client-side e NUNCA viajam. Pedir o ficheiro com eles
        // todos desligados devolve exatamente os mesmos bytes — não porque o
        // servidor os ignore por opção, mas porque não sabe que existem.
        $withTogglesOff = $this->actingAs($teacher)->get(
            "/classes/{$classUlid}/pauta-avaliacao/csv/{$periodUlid}"
            .'?show_quantitative=0&show_domain_detail=0&show_warnings=0',
        );
        $withTogglesOff->assertOk();

        $this->assertSame($plain->getContent(), $withTogglesOff->getContent());

        // E o que os toggles esconderiam continua lá, coluna a coluna.
        $body = (string) $withTogglesOff->getContent();
        $this->assertStringContainsString('Oralidade — Percentagem', $body);
        $this->assertStringContainsString('Global — Valor na escala', $body);
        $this->assertStringContainsString('Avisos de cobertura', $body);
    }

    #[Test]
    public function exporting_the_csv_leaves_an_audit_trail_without_naming_a_single_student(): void
    {
        [$classUlid, $periodUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao/csv/{$periodUlid}")->assertOk();

        $event = $this->asTenant($teacher, fn (): ?AuditEvent => AuditEvent::query()
            ->where('event', 'report.exported')
            ->latest('id')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame('SchoolClass', $event->subject_type);
        $this->assertSame($classUlid, $event->subject_ulid);

        /** @var array<string, mixed> $properties */
        $properties = $event->properties ?? [];
        $this->assertSame('csv', $properties['format']);
        $this->assertSame('evaluation_sheet', $properties['source']);
        $this->assertSame($periodUlid, $properties['academic_period_ulid']);
        $this->assertGreaterThan(0, $properties['student_count']);

        // CONTAGENS E IDENTIFICADORES, nunca pessoas. Um evento de auditoria é
        // imutável; um nome de aluno lá dentro seria para sempre.
        $this->assertStringNotContainsString('Carolina', (string) $event->summary);
        $this->assertStringNotContainsString('Carolina', json_encode($properties, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function exporting_the_csv_does_not_keep_a_snapshot(): void
    {
        [$classUlid, $periodUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao/csv/{$periodUlid}")->assertOk();

        // §10: o histórico é para momentos GUARDADOS e para exportações Inovar.
        // Uma leitura em CSV não é nem uma coisa nem outra, e não pode encher o
        // histórico de entradas que ninguém pediu.
        $this->assertSame(
            0,
            $this->asTenant($teacher, fn (): int => EvaluationSheetExport::query()->count()),
        );
    }

    // ------------------------------------- (F) Registos do professor, removidos

    #[Test]
    public function the_evidence_setting_routes_left_with_the_screen_that_used_them(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = $this->teacher();

        $enrollmentUlid = $this->asTenant($teacher, fn (): string => SchoolClass::where('ulid', $classUlid)
            ->firstOrFail()
            ->enrollments()
            ->firstOrFail()
            ->ulid);

        $this->actingAs($teacher)
            ->put("/classes/{$classUlid}/report/evidence-setting", ['include_evidence_in_report' => true])
            ->assertNotFound();

        $this->actingAs($teacher)
            ->put("/classes/{$classUlid}/report/students/{$enrollmentUlid}/evidence-setting", ['include_evidence_in_report' => false])
            ->assertNotFound();
    }

    #[Test]
    public function the_evidence_columns_survive_the_removal_of_the_screen(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = $this->teacher();

        // NÃO SE APAGAM DADOS POR ARRUMAÇÃO. As escolhas que os professores
        // fizeram no ecrã antigo continuam na base — sem migration a apagá-las
        // e sem ecrã a lê-las — para o dia em que os Registos entrarem de facto
        // nos relatórios.
        $enrollmentId = $this->asTenant($teacher, function () use ($classUlid): int {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();
            $class->update(['include_evidence_in_report' => true]);

            $enrollment = $class->enrollments()->firstOrFail();
            $enrollment->update(['include_evidence_in_report' => false]);

            return (int) $enrollment->getKey();
        });

        $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao")->assertOk();

        $this->asTenant($teacher, function () use ($classUlid, $enrollmentId): void {
            $this->assertTrue(SchoolClass::where('ulid', $classUlid)->firstOrFail()->include_evidence_in_report);

            $enrollment = Enrollment::findOrFail($enrollmentId);
            $this->assertFalse($enrollment->include_evidence_in_report);
            // A regra continua escrita, e continua a dizer o que dizia: o
            // override do aluno ganha ao valor da turma.
            $this->assertFalse($enrollment->includesEvidenceInReport());
        });
    }

    // ------------------------------------------------------------ (G) autorização

    #[Test]
    public function a_pauta_from_another_organization_is_not_found(): void
    {
        [$classUlid, $periodUlid] = $this->seedWithOneConfirmedGrade();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/classes/{$classUlid}/pauta-avaliacao")->assertNotFound();
        $this->actingAs($stranger)->get("/classes/{$classUlid}/pauta-avaliacao/csv")->assertNotFound();
        $this->actingAs($stranger)->get("/classes/{$classUlid}/pauta-avaliacao/csv/{$periodUlid}")->assertNotFound();
    }

    #[Test]
    public function a_period_that_is_not_this_classes_own_is_not_a_csv_anyone_gets(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = $this->teacher();

        // Sem isto, um ulid trocado no URL devolveria o ficheiro do primeiro
        // período com o nome do que foi pedido — pior do que um erro.
        $this->actingAs($teacher)
            ->get("/classes/{$classUlid}/pauta-avaliacao/csv/01JQZZZZZZZZZZZZZZZZZZZZZZ")
            ->assertNotFound();
    }
}
