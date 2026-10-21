<?php

namespace Tests\Unit\Assessment;

use App\Models\ClassificationScope;
use App\Models\SheetMomentKind;
use App\Services\Assessment\Export\EvaluationSheetCellText;
use App\Services\Assessment\Export\EvaluationSheetCsvWriter;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Assessment\Export\EvaluationSheetXlsxWriter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EvaluationSheetSample;
use Tests\TestCase;

/**
 * O CSV e o Excel da pauta, byte a byte e célula a célula, contra o que
 * escreviam ANTES de o texto das células ter sido extraído para
 * `EvaluationSheetCellText`.
 *
 * Os ficheiros em `tests/Fixtures/EvaluationSheet/` foram gerados pelo código
 * anterior à extração, não por este: um teste que comparasse o escritor com ele
 * próprio não provaria nada. Se um destes testes falhar, o que mudou foi o que
 * um professor encontra no ficheiro — e isso nunca é um efeito colateral aceitável
 * de uma refatoração (§13.3: a proposta nunca passa por decisão).
 *
 * O documento cobre as ramificações do texto: decisão em domínio, proposta sem
 * decisão, linha sem classificação, valor decidido numa escala de percentagem,
 * domínio em falta, aviso de cobertura, aluno sem número, e as duas origens
 * (momento guardado e pauta viva), com e sem autoavaliação.
 */
class EvaluationSheetWritersCharacterizationTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/EvaluationSheet/';

    // ------------------------------------------------------------ CSV

    #[Test]
    public function the_csv_of_a_kept_sheet_is_unchanged(): void
    {
        $this->assertSameAsFixture('kept.csv', (new EvaluationSheetCsvWriter)->write(EvaluationSheetSample::kept(withSelfAssessment: false)));
    }

    #[Test]
    public function the_csv_of_a_kept_sheet_with_self_assessment_is_unchanged(): void
    {
        $this->assertSameAsFixture('kept-self-assessment.csv', (new EvaluationSheetCsvWriter)->write(EvaluationSheetSample::kept(withSelfAssessment: true)));
    }

    #[Test]
    public function the_csv_of_the_live_sheet_is_unchanged(): void
    {
        $this->assertSameAsFixture('live.csv', (new EvaluationSheetCsvWriter)->write(EvaluationSheetSample::live()));
    }

    // ------------------------------------------------------------ Excel

    #[Test]
    public function the_xlsx_of_a_kept_sheet_is_unchanged(): void
    {
        $this->assertSameAsFixture('kept.xlsx.txt', $this->fingerprint((new EvaluationSheetXlsxWriter)->write(EvaluationSheetSample::kept(withSelfAssessment: false))));
    }

    #[Test]
    public function the_xlsx_of_a_kept_sheet_with_self_assessment_is_unchanged(): void
    {
        $this->assertSameAsFixture('kept-self-assessment.xlsx.txt', $this->fingerprint((new EvaluationSheetXlsxWriter)->write(EvaluationSheetSample::kept(withSelfAssessment: true))));
    }

    #[Test]
    public function the_xlsx_of_the_live_sheet_is_unchanged(): void
    {
        $this->assertSameAsFixture('live.xlsx.txt', $this->fingerprint((new EvaluationSheetXlsxWriter)->write(EvaluationSheetSample::live())));
    }

    // ------------------------------------------------------------ o texto das células

    #[Test]
    public function the_proposal_is_never_read_as_a_decision(): void
    {
        $proposedOnly = ['proposed_scale_level_code' => 'B', 'final_scale_level_code' => null, 'final_value' => null];

        $this->assertSame('B', EvaluationSheetCellText::proposed($proposedOnly));
        $this->assertSame(['', 'Proposta do Lapispro (não decidida)'], EvaluationSheetCellText::decided($proposedOnly));
        $this->assertSame(['', 'Sem classificação registada'], EvaluationSheetCellText::decided(null));
        $this->assertSame('', EvaluationSheetCellText::proposed(null));
    }

    #[Test]
    public function a_decision_on_a_percentage_scale_reads_as_a_percentage_and_never_as_a_level(): void
    {
        $decided = EvaluationSheetCellText::decided(['final_value' => '65', 'final_value_is_percentage' => true]);

        $this->assertSame('Decisão do professor', $decided[1]);
        $this->assertStringContainsString('%', $decided[0]);
        $this->assertSame(['5', 'Decisão do professor'], EvaluationSheetCellText::decided(['final_value' => '5', 'final_value_is_percentage' => false]));
    }

    #[Test]
    public function the_level_of_a_row_prefers_the_code_then_the_label_and_a_missing_row_is_empty(): void
    {
        $this->assertSame('B', EvaluationSheetCellText::level(['scale_level_code' => 'B', 'scale_level_label' => 'Bom']));
        $this->assertSame('Bom', EvaluationSheetCellText::level(['scale_level_code' => null, 'scale_level_label' => 'Bom']));
        $this->assertSame('', EvaluationSheetCellText::level(['scale_level_code' => null]));
        $this->assertSame('', EvaluationSheetCellText::level(null));

        $this->assertSame('MB', EvaluationSheetCellText::decidedLevel(['decided_scale_level_code' => 'MB']));
        $this->assertSame('Muito Bom', EvaluationSheetCellText::decidedLevel(['decided_scale_level_label' => 'Muito Bom']));
        // Uma fotografia antiga não traz a chave: ninguém se pronunciou.
        $this->assertSame('', EvaluationSheetCellText::decidedLevel(['scale_level_code' => 'B']));
        $this->assertSame('', EvaluationSheetCellText::decidedLevel(null));
    }

    // ------------------------------------------------------------ fromLiveSheet e o momento

    #[Test]
    public function the_live_document_still_names_the_final_moment_when_none_is_given(): void
    {
        $live = EvaluationSheetSample::live();

        $this->assertSame('Semestre — 1.º Semestre', $live->momentLabel);
        $this->assertFalse($live->isHistorical);
        $this->assertNull($live->keptAt);
        $this->assertNull($live->effectiveAt);
    }

    #[Test]
    public function the_live_document_can_be_prepared_for_a_given_moment_and_nothing_else_changes(): void
    {
        $period = EvaluationSheetSample::period();
        $sheet = EvaluationSheetSample::sheet(withSelfAssessment: false);

        $final = EvaluationSheetDocument::fromLiveSheet(EvaluationSheetSample::schoolClass(), $period, $sheet, ClassificationScope::Period, SheetMomentKind::Final);
        $interim = EvaluationSheetDocument::fromLiveSheet(EvaluationSheetSample::schoolClass(), $period, $sheet, ClassificationScope::Period, SheetMomentKind::Interim);
        $default = EvaluationSheetDocument::fromLiveSheet(EvaluationSheetSample::schoolClass(), $period, $sheet, ClassificationScope::Period);

        $this->assertEquals($default, $final);
        $this->assertSame('Momento intercalar do 1.º Semestre', $interim->momentLabel);

        // Só o título do momento muda: os números são os mesmos nos dois (§ SheetMomentKind).
        $this->assertSame($final->domains, $interim->domains);
        $this->assertSame($final->students, $interim->students);
        $this->assertSame($final->scopeLabel, $interim->scopeLabel);
        $this->assertSame($final->periodLabel, $interim->periodLabel);
    }

    // ------------------------------------------------------------ fixtures

    private function assertSameAsFixture(string $name, string $actual): void
    {
        $path = self::FIXTURES.$name;

        $this->assertFileExists($path);
        $this->assertSame(
            (string) file_get_contents($path),
            $actual,
            "O ficheiro «{$name}» já não é igual ao que a pauta escrevia antes da extração.",
        );
    }

    /**
     * Cada célula do Excel, com o que o professor vê: valor, tipo, formato,
     * negrito, cor de fundo; mais fusões, painel congelado, filtro e larguras.
     */
    private function fingerprint(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis_characterization_');
        $this->assertIsString($path);
        file_put_contents($path, $bytes);

        try {
            $spreadsheet = (new XlsxReader)->load($path);
        } finally {
            @unlink($path);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $lines = [
            'title='.$sheet->getTitle(),
            'doc-title='.$spreadsheet->getProperties()->getTitle(),
            'doc-description='.$spreadsheet->getProperties()->getDescription(),
            'freeze='.$sheet->getFreezePane(),
            'autofilter='.$sheet->getAutoFilter()->getRange(),
            'merges='.implode(',', array_values($sheet->getMergeCells())),
        ];

        $highest = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($column = 1; $column <= $highest; $column++) {
            $lines[] = 'width '.Coordinate::stringFromColumnIndex($column).'='.$sheet->getColumnDimensionByColumn($column)->getWidth();
        }

        foreach ($sheet->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            $style = $sheet->getStyle($coordinate);

            $lines[] = implode('|', [
                $coordinate,
                $cell->getDataType(),
                json_encode($cell->getValue(), JSON_UNESCAPED_UNICODE),
                $style->getNumberFormat()->getFormatCode(),
                $style->getFont()->getBold() ? 'bold' : 'plain',
                $style->getFill()->getFillType(),
                $style->getFill()->getStartColor()->getRGB(),
            ]);
        }

        $spreadsheet->disconnectWorksheets();

        return implode("\n", $lines)."\n";
    }
}
