<?php

namespace App\Services\Assessment\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * A pauta em Excel — um ficheiro DO LAPISPRO, não a grelha do Inovar.
 *
 * SÃO COISAS DIFERENTES E NÃO SE PARECEM DE PROPÓSITO. A exportação para o
 * Inovar preenche a grelha que a escola forneceu, célula a célula, e o seu
 * único critério é caber lá dentro. Este ficheiro serve outra coisa: um
 * arquivo que uma pessoa abre meses depois e lê sem ter a aplicação ao lado.
 * Por isso tem título, tem contexto, e tem a leitura da própria pauta —
 * cabeçalhos legíveis, painéis fixos, filtro, e a mesma cor por domínio que
 * está no ecrã.
 *
 * A COR IDENTIFICA O DOMÍNIO, NUNCA O DESEMPENHO (§19). É a mesma decisão que
 * a grelha tomou: um verde ao lado de um número não pode significar «bom», ou
 * o ficheiro passa a emitir juízos que ninguém escreveu. E nunca é a única
 * informação: cada domínio está escrito por extenso no cabeçalho.
 *
 * NÚMEROS COMO NÚMEROS, TEXTO COMO TEXTO. Uma percentagem escrita como texto
 * não soma nem ordena, e um nível «5» convertido em número por acidente deixa
 * de ser um nível — passa a poder ser somado a outro, que é uma operação sem
 * sentido pedagógico nenhum.
 */
class EvaluationSheetXlsxWriter
{
    /** Cinzento do cabeçalho — o mesmo do resto das exportações da aplicação. */
    private const HEADER_FILL = 'E5E7EB';

    private const BORDER = 'D1D5DB';

    /** Riscas alternadas, discretas: ajudam a ler a linha, não decoram. */
    private const STRIPE = 'F7F8FA';

    public function write(EvaluationSheetDocument $document): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle($document->momentLabel)
            ->setSubject('Pauta de Avaliação')
            ->setDescription("{$document->classLabel} · {$document->subject} · {$document->academicYear}")
            ->setCreator('Lapispro');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pauta');

        $withSelfAssessment = $document->hasSelfAssessment();
        $firstHeaderRow = $this->writeTitleBlock($sheet, $document) + 1;
        $columnCount = $this->writeHeader($sheet, $document, $firstHeaderRow, $withSelfAssessment);
        $lastRow = $this->writeStudents($sheet, $document, $firstHeaderRow + 2, $withSelfAssessment);

        $this->finish($sheet, $firstHeaderRow, $columnCount, $lastRow);
        $this->writeWarnings($sheet, $document, $lastRow + 2, $columnCount);

        return $this->render($spreadsheet);
    }

    /**
     * O bloco de identidade: de que pauta se trata, e de quando.
     *
     * @return int a última linha escrita
     */
    protected function writeTitleBlock(Worksheet $sheet, EvaluationSheetDocument $document): int
    {
        $sheet->setCellValue('A1', 'Pauta de Avaliação — '.$document->momentLabel);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $lines = [
            ['Turma', $document->classLabel],
            ['Disciplina', $document->subject],
            ['Ano letivo', $document->academicYear],
            [$document->periodKindLabel, $document->periodLabel],
            ['Âmbito', $document->scopeLabel],
        ];

        if ($document->effectiveAt !== null) {
            $lines[] = ['Data de referência', $document->effectiveAt];
        }

        if ($document->isHistorical) {
            $lines[] = ['Pauta guardada em', (string) $document->keptAt];

            if ($document->authorName !== null) {
                $lines[] = ['Guardada por', $document->authorName];
            }

            $lines[] = ['Origem dos valores', 'Momento guardado — não reflete alterações posteriores.'];
        }

        $row = 2;

        foreach ($lines as [$label, $value]) {
            $sheet->setCellValue([1, $row], $label);
            $sheet->getStyle([1, $row])->getFont()->setBold(true);
            $this->writeText($sheet, 2, $row, $value);
            $row++;
        }

        // Uma linha em branco entre a identidade e a tabela. Sem ela o
        // autofiltro apanha o bloco de título e o Excel oferece-se para filtrar
        // «Turma».
        return $row;
    }

    /**
     * Dois níveis de cabeçalho, como no ecrã: o domínio por cima, o que cada
     * coluna dele diz por baixo.
     *
     * @return int o número de colunas
     */
    protected function writeHeader(
        Worksheet $sheet,
        EvaluationSheetDocument $document,
        int $headerRow,
        bool $withSelfAssessment,
    ): int {
        $secondRow = $headerRow + 1;
        $perDomain = $withSelfAssessment ? 3 : 2;

        $sheet->setCellValue([1, $headerRow], 'Nº');
        $sheet->setCellValue([2, $headerRow], 'Aluno');
        $sheet->mergeCells([1, $headerRow, 1, $secondRow]);
        $sheet->mergeCells([2, $headerRow, 2, $secondRow]);

        $column = 3;

        foreach ($document->domains as $domain) {
            $sheet->setCellValue([$column, $headerRow], (string) $domain['name']);
            $sheet->mergeCells([$column, $headerRow, $column + $perDomain - 1, $headerRow]);

            $sheet->setCellValue([$column, $secondRow], 'Quant.');
            $sheet->setCellValue([$column + 1, $secondRow], 'Apreciação');

            if ($withSelfAssessment) {
                $sheet->setCellValue([$column + 2, $secondRow], 'Autoav.');
            }

            // A cor do domínio, no cabeçalho e por baixo dele — identidade, e
            // a mesma que o ecrã mostra.
            $this->fill($sheet, $column, $headerRow, $column + $perDomain - 1, $secondRow, $this->rgb($domain));

            $column += $perDomain;
        }

        $globalColumns = $withSelfAssessment ? 4 : 3;
        $sheet->setCellValue([$column, $headerRow], 'Global');
        $sheet->mergeCells([$column, $headerRow, $column + $globalColumns - 1, $headerRow]);
        $sheet->setCellValue([$column, $secondRow], 'Percentagem');
        $sheet->setCellValue([$column + 1, $secondRow], 'Valor na escala');
        $sheet->setCellValue([$column + 2, $secondRow], 'Nível');

        if ($withSelfAssessment) {
            $sheet->setCellValue([$column + 3, $secondRow], 'Autoavaliação');
        }

        $column += $globalColumns;

        // A PROPOSTA E A DECISÃO EM COLUNAS DIFERENTES. É a separação que o
        // ecrã faz por tipografia, e num ficheiro só a estrutura a pode fazer.
        $sheet->setCellValue([$column, $headerRow], 'Classificação');
        $sheet->mergeCells([$column, $headerRow, $column + 2, $headerRow]);
        $sheet->setCellValue([$column, $secondRow], 'Proposta do Lapispro');
        $sheet->setCellValue([$column + 1, $secondRow], 'Nível atribuído');
        $sheet->setCellValue([$column + 2, $secondRow], 'Origem do nível');
        $column += 3;

        $sheet->setCellValue([$column, $headerRow], 'Avisos de cobertura');
        $sheet->mergeCells([$column, $headerRow, $column, $secondRow]);

        return $column;
    }

    /**
     * @return int a última linha de dados
     */
    protected function writeStudents(
        Worksheet $sheet,
        EvaluationSheetDocument $document,
        int $firstRow,
        bool $withSelfAssessment,
    ): int {
        $row = $firstRow;

        foreach ($document->students as $index => $student) {
            /** @var array<int, array<string, mixed>> $studentDomains */
            $studentDomains = $student['domains'] ?? [];
            $byDomainId = [];

            foreach ($studentDomains as $studentDomain) {
                $byDomainId[(int) $studentDomain['domain_id']] = $studentDomain;
            }

            if (($student['class_number'] ?? null) !== null) {
                $sheet->setCellValue([1, $row], (int) $student['class_number']);
            }

            $this->writeText($sheet, 2, $row, (string) $student['name']);

            $column = 3;
            $warnings = [];

            foreach ($document->domains as $domain) {
                $studentDomain = $byDomainId[(int) $domain['domain_id']] ?? null;

                $this->writePercentage($sheet, $column, $row, $studentDomain['normalized_value'] ?? null);
                $this->writeText($sheet, $column + 1, $row, $this->levelOf($studentDomain));

                if ($withSelfAssessment) {
                    $this->writeText($sheet, $column + 2, $row, (string) ($studentDomain['self_assessment']['code'] ?? ''));
                }

                $this->fill($sheet, $column, $row, $column + ($withSelfAssessment ? 2 : 1), $row, $this->rgb($domain), soft: true);

                if ($studentDomain !== null && ($studentDomain['has_coverage_warning'] ?? false) === true) {
                    $warnings[] = (string) $domain['name'];
                }

                $column += $withSelfAssessment ? 3 : 2;
            }

            /** @var array<string, mixed> $overall */
            $overall = $student['overall'];

            $this->writePercentage($sheet, $column, $row, $overall['normalized_value'] ?? null);
            // O VALOR NA ESCALA É TEXTO. Um «3» numa escala de 1 a 5 não é uma
            // quantidade a somar, e escrevê-lo como número convidaria a folha a
            // fazer médias de níveis — que não é uma operação que exista.
            $this->writeText($sheet, $column + 1, $row, (string) ($overall['scale_value'] ?? ''));
            $this->writeText($sheet, $column + 2, $row, $this->levelOf($overall));

            if ($withSelfAssessment) {
                $this->writeText($sheet, $column + 3, $row, (string) ($student['self_assessment']['code'] ?? ''));
                $column++;
            }

            $column += 3;

            /** @var array<string, mixed>|null $classification */
            $classification = $student['classification'] ?? null;

            $this->writeText($sheet, $column, $row, $this->proposed($classification));
            [$decided, $origin] = $this->decided($classification);
            $this->writeText($sheet, $column + 1, $row, $decided);
            $sheet->getStyle([$column + 1, $row])->getFont()->setBold($decided !== '');
            $this->writeText($sheet, $column + 2, $row, $origin);
            $column += 3;

            if (($overall['has_coverage_warning'] ?? false) === true) {
                array_unshift($warnings, 'Global');
            }

            $this->writeText($sheet, $column, $row, implode('; ', $warnings));

            if ($index % 2 === 1) {
                // A risca só nas colunas que não têm cor de domínio: sobrepô-la
                // à cor apagaria a identidade que a cor está lá para dar.
                $this->fill($sheet, $column - 3, $row, $column, $row, self::STRIPE);
            }

            $row++;
        }

        return $row - 1;
    }

    /**
     * Os avisos registados, por baixo da tabela e fora dela.
     *
     * Fora do intervalo do autofiltro de propósito: são frases sobre o momento,
     * não linhas de dados, e filtrá-las junto com os alunos não faria sentido
     * nenhum.
     */
    protected function writeWarnings(Worksheet $sheet, EvaluationSheetDocument $document, int $row, int $columnCount): void
    {
        if ($document->warnings === []) {
            return;
        }

        $sheet->setCellValue([1, $row], 'Avisos registados no momento em que esta pauta foi guardada');
        $sheet->getStyle([1, $row])->getFont()->setBold(true);
        $sheet->mergeCells([1, $row, $columnCount, $row]);
        $row++;

        foreach ($document->warnings as $warning) {
            $this->writeText($sheet, 1, $row, $warning);
            $sheet->mergeCells([1, $row, $columnCount, $row]);
            $row++;
        }
    }

    protected function finish(Worksheet $sheet, int $headerRow, int $columnCount, int $lastRow): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $secondRow = $headerRow + 1;

        $header = $sheet->getStyle("A{$headerRow}:{$lastColumn}{$secondRow}");
        $header->getFont()->setBold(true);
        $header->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);

        // O cabeçalho das colunas que não são de domínio leva o cinzento; as de
        // domínio já ficaram com a sua cor e não podem ser repintadas.
        $sheet->getStyle("A{$headerRow}:B{$secondRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);

        if ($lastRow >= $secondRow + 1) {
            $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::BORDER);

            // FILTRA A LINHA DE BAIXO DO CABEÇALHO, que é a que nomeia cada
            // coluna — a de cima agrupa domínios e as suas células estão unidas.
            $sheet->setAutoFilter("A{$secondRow}:{$lastColumn}{$lastRow}");
        }

        // O nome do aluno fica à vista quando se rola para a direita, e o
        // cabeçalho quando se rola para baixo — que é a razão de existir de
        // uma pauta larga ser legível de todo.
        $sheet->freezePane('C'.($secondRow + 1));

        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(30);

        for ($column = 3; $column <= $columnCount; $column++) {
            $sheet->getColumnDimensionByColumn($column)->setWidth(13);
        }

        // A última coluna leva frases, não códigos.
        $sheet->getColumnDimensionByColumn($columnCount)->setWidth(34);
        $sheet->getRowDimension($headerRow)->setRowHeight(22);
        $sheet->getRowDimension($secondRow)->setRowHeight(28);
    }

    /** @param  array<string, mixed>|null  $row */
    protected function levelOf(?array $row): string
    {
        if ($row === null) {
            return '';
        }

        return (string) ($row['scale_level_code'] ?? $row['scale_level_label'] ?? '');
    }

    /** @param  array<string, mixed>|null  $classification */
    protected function proposed(?array $classification): string
    {
        if ($classification === null) {
            return '';
        }

        $proposed = $classification['proposed_scale_level_code']
            ?? $classification['proposed_scale_level_label']
            ?? $classification['proposed_value'];

        return $proposed === null ? '' : (string) $proposed;
    }

    /**
     * @param  array<string, mixed>|null  $classification
     * @return array{string, string}
     */
    protected function decided(?array $classification): array
    {
        if ($classification === null) {
            return ['', 'Sem classificação registada'];
        }

        $final = $classification['final_scale_level_code']
            ?? $classification['final_scale_level_label']
            ?? $classification['final_value'];

        if ($final !== null) {
            return [(string) $final, 'Decisão do professor'];
        }

        $proposed = $classification['proposed_scale_level_code']
            ?? $classification['proposed_scale_level_label']
            ?? $classification['proposed_value'];

        return ['', $proposed === null
            ? 'Sem classificação registada'
            : 'Proposta do Lapispro (não decidida)'];
    }

    /** @param  array<string, mixed>  $domain */
    protected function rgb(array $domain): string
    {
        $color = (string) ($domain['color'] ?? '');
        $hex = ltrim($color, '#');

        // Um payload antigo pode não trazer cor nenhuma. Um cinzento neutro é
        // melhor do que um erro, e melhor do que uma cor inventada.
        return preg_match('/^[0-9A-Fa-f]{6}$/', $hex) === 1 ? strtoupper($hex) : 'F3F4F6';
    }

    protected function fill(Worksheet $sheet, int $fromColumn, int $fromRow, int $toColumn, int $toRow, string $rgb, bool $soft = false): void
    {
        $range = Coordinate::stringFromColumnIndex($fromColumn).$fromRow
            .':'.Coordinate::stringFromColumnIndex($toColumn).$toRow;

        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($soft ? $this->lighten($rgb) : $rgb);
    }

    /**
     * A mesma cor, mais lavada — o equivalente ao fundo suave que a grelha usa
     * nas células, para que a coluna se leia sem que a cor grite.
     */
    protected function lighten(string $rgb): string
    {
        $mixed = '';

        foreach ([0, 2, 4] as $offset) {
            $channel = (int) hexdec(substr($rgb, $offset, 2));
            $mixed .= str_pad(dechex((int) round($channel + (255 - $channel) * 0.55)), 2, '0', STR_PAD_LEFT);
        }

        return strtoupper($mixed);
    }

    protected function writeText(Worksheet $sheet, int $column, int $row, string $value): void
    {
        if ($value === '') {
            return;
        }

        // EXPLICITAMENTE TEXTO. Sem isto o Excel lê «3» como número e um código
        // de nível deixa de ser um código; e um número de processo com zeros à
        // esquerda perdia-os pelo caminho.
        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);
        $sheet->getStyle([$column, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * A percentagem do motor, COMO NÚMERO — vazia quando não há valor.
     *
     * Nunca zero: sem elementos não é zero (§13.3), e uma célula vazia é a
     * única escrita que não convida a folha a somar a ausência.
     */
    protected function writePercentage(Worksheet $sheet, int $column, int $row, mixed $value): void
    {
        if ($value === null || ! is_numeric($value)) {
            return;
        }

        $sheet->setCellValue([$column, $row], round((float) $value, 1));
        $sheet->getStyle([$column, $row])->getNumberFormat()->setFormatCode('0.0');
        $sheet->getStyle([$column, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    protected function render(Spreadsheet $spreadsheet): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'lapis_pauta_xlsx_');

        if ($tempPath === false) {
            throw new RuntimeException('Não foi possível preparar o ficheiro Excel da pauta.');
        }

        try {
            (new Xlsx($spreadsheet))->save($tempPath);
            $contents = file_get_contents($tempPath);

            return $contents === false ? '' : $contents;
        } finally {
            @unlink($tempPath);
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** O tipo MIME real de um `.xlsx`, para o browser não lhe chamar outra coisa. */
    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
}
