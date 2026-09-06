<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Models\User;
use App\Services\Assessment\CaptureEvaluationSheet;
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
    public function the_workbook_has_the_four_sheets_the_file_is_supposed_to_be_readable_by(): void
    {
        $spreadsheet = $this->open($this->download());

        $this->assertSame(
            ['Quadro Síntese', 'Domínios', 'Elementos de Avaliação', 'Configuração'],
            $spreadsheet->getSheetNames(),
        );
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
