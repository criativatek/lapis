<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\DomainAppreciationDecision;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Models\User;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Services\Assessment\ContinuousAssessment;
use App\Support\Assessment\ReadingVocabulary;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O Quadro Síntese em Excel, aberto programaticamente — porque «gerou um
 * ficheiro» não é a mesma afirmação que «gerou um ficheiro que abre e diz o que
 * devia dizer» (§76).
 *
 * E o ecrã que o oferece, com a pergunta que qualquer exportação de dados de
 * menores tem de responder: quem é que a pode pedir (§48, §63).
 */
class ClassSynopsisExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));
        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
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

    private function classUlid(): string
    {
        return $this->asTenant(fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()->ulid);
    }

    private function download(): string
    {
        $response = $this->actingAs($this->teacher)
            ->get('/classes/'.$this->classUlid().'/results/quadro-sintese/xlsx');

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        return (string) $response->getContent();
    }

    /** Abre o ficheiro devolvido, escrevendo-o para um ficheiro temporário só do teste. */
    private function open(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-synopsis-').'.xlsx';
        file_put_contents($path, $bytes);

        try {
            return (new XlsxReader)->load($path);
        } finally {
            @unlink($path);
        }
    }

    // -------------------------------------------------------------- as folhas

    #[Test]
    public function the_workbook_has_the_six_sheets_the_file_is_supposed_to_be_readable_by(): void
    {
        $spreadsheet = $this->open($this->download());

        // A ORDEM É A HIERARQUIA DAS LEITURAS (§14): os resultados formais de
        // cada momento, a conclusão FORMAL do ano por domínio, e só então a
        // leitura ANALÍTICA — antes de se descer ao elemento que a produziu.
        $this->assertSame(
            [
                'Quadro Síntese',
                'Domínios',
                'Contínua Final por Domínio',
                'Desempenho acumulado',
                'Elementos de Avaliação',
                'Configuração',
            ],
            $spreadsheet->getSheetNames(),
        );
    }

    #[Test]
    public function the_final_reading_of_each_domain_is_a_sheet_of_its_own(): void
    {
        $sheet = $this->open($this->download())->getSheetByName('Contínua Final por Domínio');

        $this->assertNotNull($sheet, 'O ficheiro não leva a conclusão formal de cada domínio.');

        $headers = array_keys($this->headerColumns($sheet, 1));

        // O RESULTADO FORMAL DE CADA UNIDADE em coluna própria, com o nome que a
        // escola lhe deu — nunca «P1».
        $this->assertContains('Domínio', $headers);
        $this->assertContains('1.º Semestre (%)', $headers);
        $this->assertContains('2.º Semestre (%)', $headers);

        // …e a conclusão que eles formam, com as duas metades do juízo.
        $this->assertContains('Média final (%)', $headers);
        $this->assertContains('Unidades contadas', $headers);
        $this->assertContains('Proposta final', $headers);
        $this->assertContains('Decisão final', $headers);
        $this->assertContains('Menção vigente', $headers);

        $text = $this->textOf($sheet);
        $this->assertStringContainsString('Oralidade', $text);
        $this->assertStringContainsString('Carolina Nunes', $text);
    }

    #[Test]
    public function a_final_domain_decision_travels_beside_the_proposal_it_replaced(): void
    {
        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $last = ContinuousAssessment::finalUnitOf($class);
            $enrollment = $class->enrollments()->with('student.identity')->get()
                ->first(fn ($candidate): bool => $candidate->student->identity->display_name === 'Carolina Nunes');

            DomainAppreciationDecision::create([
                'enrollment_id' => $enrollment->getKey(),
                'academic_period_id' => $last->getKey(),
                'scope' => ClassificationScope::Accumulated,
                'domain_id' => Domain::where('name', 'Escrita')->firstOrFail()->id,
                'scale_level_id' => $class->profileVersion->scale->levels()->where('code', '3')->firstOrFail()->id,
                'decided_by' => $this->teacher->getKey(),
            ]);
        });

        $sheet = $this->open($this->download())->getSheetByName('Contínua Final por Domínio');
        $columns = $this->headerColumns($sheet, 1);

        $row = null;
        foreach ($sheet->toArray(null, true, false, false) as $index => $cells) {
            if (($cells[1] ?? null) === 'Carolina Nunes' && ($cells[2] ?? null) === 'Escrita') {
                $row = $cells;

                break;
            }
        }

        $this->assertNotNull($row, 'A linha de Escrita da Carolina não está na folha.');

        // AS TRÊS COISAS, LADO A LADO: o que o Lapispro propôs, o que o
        // professor decidiu, e o que vale. Um ficheiro que só levasse a
        // vigente esconderia precisamente a informação que explica a decisão.
        $this->assertSame('5', (string) $row[$columns['Proposta final'] - 1]);
        $this->assertSame('3', (string) $row[$columns['Decisão final'] - 1]);
        $this->assertSame('3', (string) $row[$columns['Menção vigente'] - 1]);
        $this->assertSame('Decisão do professor', (string) $row[$columns['Origem'] - 1]);

        // E A MÉDIA NÃO SE MEXE por causa de uma decisão qualitativa.
        $this->assertSame(90.0, (float) $row[$columns['Média final (%)'] - 1]);
    }

    #[Test]
    public function the_configuration_says_what_the_final_domain_average_counts(): void
    {
        $configuration = $this->textOf($this->open($this->download())->getSheetByName('Configuração'));

        // Quem abre o ficheiro daqui a três anos não tem o ecrã ao lado: a folha
        // tem de dizer o que entra na média e — sobretudo — o que não entra.
        $this->assertStringContainsString('Como ler a folha «Contínua Final por Domínio»', $configuration);
        $this->assertStringContainsString('resultados formais', $configuration);
        $this->assertStringContainsString('fotografias intercalares não entram', $configuration);

        // E que uma proposta que ninguém alterou não está à espera de nada.
        $this->assertStringContainsString('VIGORA', $configuration);
    }

    #[Test]
    public function the_first_sheet_names_every_structural_moment_and_the_continuous_average(): void
    {
        $sheet = $this->open($this->download())->getSheetByName('Quadro Síntese');
        $text = $this->textOf($sheet);

        // A cronologia inteira, fotografias incluídas — e os acentos intactos,
        // que é a primeira coisa que um ficheiro mal escrito perde.
        $this->assertStringContainsString('Intercalar 1.º Semestre', $text);
        $this->assertStringContainsString('1.º Semestre', $text);
        $this->assertStringContainsString('Intercalar 2.º Semestre', $text);
        $this->assertStringContainsString('Avaliação contínua', $text);
        $this->assertStringContainsString('Carolina Nunes', $text);

        // E, onde a fotografia não existe, diz-se porquê em vez de ficar um
        // espaço em branco que se lê como um resultado nulo.
        $this->assertStringContainsString('Momento não guardado', $text);
    }

    #[Test]
    public function the_continuous_average_in_the_file_is_the_mean_of_the_formal_units_only(): void
    {
        // Uma fotografia intercalar guardada não pode mudar a média: ela existe
        // na cronologia e fica fora da conta (§56).
        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(CaptureEvaluationSheet::class)->capture(
                $class,
                $period,
                ClassificationScope::Period,
                'Momento intercalar do 1.º Semestre',
                Carbon::parse('2026-11-20'),
                $this->teacher,
                moment: SheetMomentKind::Interim,
            );
        });

        $sheet = $this->open($this->download())->getSheetByName('Quadro Síntese');
        $row = $this->rowOf($sheet, 'Carolina Nunes');
        $this->assertNotNull($row);

        // A coluna «Média (%)» da avaliação contínua: (91,3 + 87,5) / 2 = 89,4.
        // Nunca o acumulado do motor (89,7), e nunca uma média de quatro
        // momentos.
        $values = [];
        foreach ($sheet->getRowIterator($row, $row) as $sheetRow) {
            foreach ($sheetRow->getCellIterator() as $cell) {
                $value = $cell->getValue();

                if (is_numeric($value)) {
                    $values[] = round((float) $value, 1);
                }
            }
        }

        $this->assertContains(89.4, $values, 'A média contínua tem de estar no ficheiro.');
        $this->assertNotContains(89.7, $values, 'O acumulado do motor não é a avaliação contínua.');
    }

    #[Test]
    public function the_domains_sheet_says_for_every_row_whether_that_moment_counts(): void
    {
        $sheet = $this->open($this->download())->getSheetByName('Domínios');
        $text = $this->textOf($sheet);

        $this->assertStringContainsString('Entra na avaliação contínua', $text);
        $this->assertStringContainsString('Apreciação vigente', $text);
        $this->assertStringContainsString('Proposta do Lapispro', $text);
        $this->assertStringContainsString('Decisão do professor', $text);
    }

    #[Test]
    public function the_elements_sheet_carries_the_date_the_domain_and_what_happened_to_each_student(): void
    {
        $sheet = $this->open($this->download())->getSheetByName('Elementos de Avaliação');
        $text = $this->textOf($sheet);

        $this->assertStringContainsString('Elemento', $text);
        $this->assertStringContainsString('Domínios', $text);
        $this->assertStringContainsString('Peso declarado', $text);
        // Um elemento anterior ao ingresso de um aluno diz-se por palavras e
        // nunca por um zero (§11, §53).
        $this->assertStringContainsString('Não aplicável — fora do período de matrícula', $text);
    }

    #[Test]
    public function the_configuration_sheet_makes_the_file_readable_years_later(): void
    {
        $sheet = $this->open($this->download())->getSheetByName('Configuração');
        $text = $this->textOf($sheet);

        $this->assertStringContainsString('Unidades formais que entram na avaliação contínua', $text);
        $this->assertStringContainsString('Domínios e o seu peso no perfil', $text);
        $this->assertStringContainsString('Níveis da escala', $text);
        $this->assertStringContainsString('Como ler este ficheiro', $text);
        // A frase que impede a confusão que este ficheiro todo existe para
        // evitar, escrita no próprio ficheiro.
        $this->assertStringContainsString('NÃO entra na avaliação contínua', $text);
    }

    // ------------------------------------------------------------- formatação

    #[Test]
    public function the_grid_freezes_the_name_and_offers_a_filter(): void
    {
        $sheet = $this->open($this->download())->getSheetByName('Quadro Síntese');

        $this->assertNotNull($sheet->getFreezePane());
        $this->assertNotSame('', (string) $sheet->getAutoFilter()->getRange());
    }

    #[Test]
    public function a_percentage_is_a_number_and_a_level_is_text(): void
    {
        $sheet = $this->open($this->download())->getSheetByName('Elementos de Avaliação');
        $header = $this->headerColumns($sheet, 1);
        $row = $this->rowOf($sheet, 'Carolina Nunes');
        $this->assertNotNull($row);

        $result = $sheet->getCell([$header['Resultado (%)'], $row])->getValue();
        $level = $sheet->getCell([$header['Nível'], $row])->getValue();

        $this->assertIsFloat($result);
        // O NÍVEL É TEXTO. Um «4» convertido em número deixa de ser um nível e
        // passa a poder ser somado a outro, que não é uma operação pedagógica.
        $this->assertIsString($level);
    }

    // ------------------------------------------------------------- isolamento

    #[Test]
    public function a_teacher_from_another_organization_cannot_download_this_class(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get('/classes/'.$this->classUlid().'/results/quadro-sintese/xlsx')
            ->assertNotFound();
    }

    #[Test]
    public function a_teacher_from_another_organization_cannot_open_the_screen_either(): void
    {
        // A MESMA PORTA PARA OS DOIS. Esconder o botão de exportar não guarda
        // nada; o que guarda é a autorização, e ela é a mesma no ecrã e no
        // ficheiro. 404 e não 403: a existência da turma também não é dela (§63).
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get('/classes/'.$this->classUlid().'/results/quadro-sintese')
            ->assertNotFound();
    }

    #[Test]
    public function a_guest_gets_no_file_at_all(): void
    {
        $this->get('/classes/'.$this->classUlid().'/results/quadro-sintese/xlsx')
            ->assertRedirect('/login');
    }

    #[Test]
    public function the_download_leaves_an_audit_trail_without_a_single_student_name(): void
    {
        $this->download();

        $event = AuditEvent::query()->where('event', 'class-synopsis.exported')->latest('id')->first();

        $this->assertNotNull($event);
        $this->assertSame('xlsx', $event->properties['format']);
        $this->assertGreaterThan(0, $event->properties['students']);

        $encoded = json_encode($event->properties, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Carolina', (string) $encoded);
        $this->assertStringNotContainsString('Carolina', (string) $event->summary);
    }

    // --------------------------------------------------------------- o ecrã

    #[Test]
    public function the_screen_carries_the_synopsis_and_the_link_to_each_student_report(): void
    {
        $response = $this->actingAs($this->teacher)
            ->get('/classes/'.$this->classUlid().'/results/quadro-sintese');

        $response->assertOk();

        $page = $response->viewData('page');
        $synopsis = $page['props']['synopsis'];

        $this->assertCount(4, $synopsis['moments']);
        $this->assertNotEmpty($synopsis['students']);
        $this->assertNotNull($synopsis['students'][0]['enrollment_ulid']);
        // A leitura antiga continua a viajar: esta página ganhou uma segunda
        // vista, não perdeu a primeira.
        $this->assertArrayHasKey('progression', $page['props']);
    }

    #[Test]
    public function an_appreciation_is_tinted_by_its_position_on_the_scale_and_still_says_the_level(): void
    {
        $sheet = $this->open($this->download())->getSheetByName('Quadro Síntese');
        $row = $this->rowOf($sheet, 'Carolina Nunes');
        $this->assertNotNull($row);

        // A coluna «Apreciação vigente» do 1.º momento formal. A Carolina está
        // no topo da escala, e o topo é verde — por ser o topo, e não por ser o
        // número 5 (§24, §57).
        $tinted = [];

        foreach ($sheet->getRowIterator($row, $row) as $sheetRow) {
            foreach ($sheetRow->getCellIterator() as $cell) {
                $value = $cell->getValue();

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $rgb = $cell->getStyle()->getFill()->getStartColor()->getRGB();

                if (in_array($rgb, ['D1FAE5', 'DBEAFE', 'FEF3C7', 'FEE2E2'], true)) {
                    $tinted[$value] = $rgb;
                }
            }
        }

        $this->assertNotEmpty($tinted, 'Nenhuma apreciação foi pintada.');

        // E A COR NUNCA É A ÚNICA INFORMAÇÃO: a célula pintada continua a
        // escrever o nível, e a folha «Configuração» diz o que a cor quer dizer.
        foreach ($tinted as $text => $rgb) {
            $this->assertNotSame('', trim((string) $text));
        }

        $configuration = $this->textOf($this->open($this->download())->getSheetByName('Configuração'));
        $this->assertStringContainsString('Cor da apreciação', $configuration);
        $this->assertStringContainsString('nunca é a única informação', $configuration);
    }

    #[Test]
    public function the_file_names_the_two_readings_apart_and_says_which_one_is_formal(): void
    {
        $spreadsheet = $this->open($this->download());

        // A coluna formal chama-se pelo nome canónico, e não «Acumulado».
        $quadro = $this->textOf($spreadsheet->getSheetByName('Quadro Síntese'));
        $this->assertStringContainsString(ReadingVocabulary::CONTINUOUS, $quadro);

        // E a folha «Configuração» põe as duas lado a lado, pela ordem da
        // hierarquia: o indicador formal primeiro, o analítico depois.
        $configuration = $this->textOf($spreadsheet->getSheetByName('Configuração'));

        $this->assertStringContainsString('As duas leituras do ano', $configuration);
        $this->assertStringContainsString(ReadingVocabulary::CONTINUOUS_EXPLANATION, $configuration);
        $this->assertStringContainsString(ReadingVocabulary::ACCUMULATED_LONG, $configuration);
        $this->assertStringContainsString(ReadingVocabulary::ACCUMULATED_EXPLANATION, $configuration);

        $this->assertLessThan(
            strpos($configuration, ReadingVocabulary::ACCUMULATED_LONG),
            strpos($configuration, ReadingVocabulary::CONTINUOUS_EXPLANATION),
            'O indicador formal vem primeiro: a ordem é a hierarquia.',
        );
    }

    // ------------------------------------------------- de onde vem o número

    #[Test]
    public function the_accumulated_sheet_writes_the_account_that_produces_the_number(): void
    {
        $sheet = $this->open($this->download())->getSheetByName('Desempenho acumulado');

        $this->assertNotNull($sheet, 'O ficheiro não leva a folha do desempenho acumulado.');

        $headers = $this->headerColumns($sheet, 1);

        // OS PONTOS SÃO A EXPLICAÇÃO. Sem a cotação de cada unidade não há como
        // perceber por que motivo o acumulado não é a média dos semestres — e é
        // exatamente essa pergunta que esta folha existe para responder.
        foreach (['Domínio', 'Unidade temporal', 'Pontos obtidos', 'Cotação', 'Resultado (%)', 'Peso efetivo (%)'] as $header) {
            $this->assertContains($header, array_keys($headers), "Falta a coluna «{$header}».");
        }

        $text = $this->textOf($sheet);

        // Uma linha por unidade temporal, e a linha que as soma.
        $this->assertStringContainsString('1.º Semestre', $text);
        $this->assertStringContainsString('2.º Semestre', $text);
        $this->assertStringContainsString('TOTAL', $text);
    }

    #[Test]
    public function the_file_explains_that_the_effective_weight_is_not_a_configured_one(): void
    {
        $configuration = $this->textOf($this->open($this->download())->getSheetByName('Configuração'));

        // A CONFUSÃO QUE ESTA FRASE EVITA: um professor que veja «peso efetivo
        // 81 %» pode razoavelmente procurar onde é que alguém configurou 81 %.
        // Ninguém configurou — é o que as cotações fazem.
        $this->assertStringContainsString('Como ler a folha «Desempenho acumulado»', $configuration);
        $this->assertStringContainsString('NÃO é um peso configurado', $configuration);
        $this->assertStringContainsString(ReadingVocabulary::ACCUMULATED_NOT_AN_AVERAGE, $configuration);
    }

    #[Test]
    public function the_elements_sheet_carries_the_points_and_not_only_the_percentage(): void
    {
        $sheet = $this->open($this->download())->getSheetByName('Elementos de Avaliação');
        $headers = array_keys($this->headerColumns($sheet, 1));

        // A percentagem diz COMO correu; os pontos dizem QUANTO o elemento pesa
        // no ano, que é a metade da história que faltava.
        $this->assertContains('Pontos obtidos', $headers);
        $this->assertContains('Cotação', $headers);
        $this->assertContains('Unidade temporal', $headers);
        $this->assertContains('Domínios', $headers);
    }

    // ------------------------------------------------------------ utilitários

    private function textOf(Worksheet $sheet): string
    {
        $text = '';

        foreach ($sheet->toArray(null, true, false, false) as $row) {
            foreach ($row as $value) {
                $text .= (string) $value."\n";
            }
        }

        return $text;
    }

    private function rowOf(Worksheet $sheet, string $needle): ?int
    {
        foreach ($sheet->toArray(null, true, false, false) as $index => $row) {
            foreach ($row as $value) {
                if ((string) $value === $needle) {
                    return $index + 1;
                }
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private function headerColumns(Worksheet $sheet, int $row): array
    {
        $columns = [];
        $index = 1;

        foreach ($sheet->toArray(null, true, false, false)[$row - 1] as $value) {
            if ((string) $value !== '') {
                $columns[(string) $value] = $index;
            }

            $index++;
        }

        return $columns;
    }
}
