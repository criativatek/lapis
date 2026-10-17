<?php

namespace App\Services\Timetable;

use App\Models\TimetableActivityKind;
use App\Models\TimetableActivitySession;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * As sessões das «outras atividades» em Excel (0.170.0): a folha «Outras
 * atividades» do ficheiro de «Exportar os meus dados» e o ficheiro do histórico.
 *
 * AS LINHAS SAEM DAQUI PARA OS DOIS SÍTIOS, para que a folha de um e o ficheiro
 * do outro nunca divirjam: data, início, fim, atividade, tipo, turma, n.º,
 * estado e sumário completo.
 *
 * O TEXTO NUNCA É REINTERPRETADO. Atividade, turma e sumário escrevem-se como
 * texto explícito (`DataType::TYPE_STRING`): o que um professor escreveu e
 * comece por `=`, `+` ou `@` é texto, nunca uma fórmula.
 */
final class ActivitySessionsXlsxWriter
{
    public const SHEET_TITLE = 'Outras atividades';

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return ['Data', 'Início', 'Fim', 'Atividade', 'Tipo', 'Turma', 'N.º', 'Estado', 'Sumário'];
    }

    /**
     * Preenche uma folha com cabeçalho já escrito na linha 1.
     *
     * @param  Collection<int, TimetableActivitySession>  $sessions  com `schoolClass` carregada
     * @return int a última linha escrita
     */
    public static function fillRows(Worksheet $sheet, Collection $sessions): int
    {
        $row = 2;

        foreach ($sessions as $session) {
            $sheet->setCellValue("A{$row}", Date::PHPToExcel($session->occurs_on->toDateTime()));
            $sheet->getStyle("A{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
            $sheet->setCellValueExplicit("B{$row}", substr($session->starts_at, 0, 5), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$row}", substr($session->ends_at, 0, 5), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", $session->activity_title, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$row}", TimetableActivityKind::tryFrom($session->activity_kind)?->label() ?? $session->activity_kind, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$row}", $session->schoolClass === null ? '—' : $session->schoolClass->label, DataType::TYPE_STRING);

            if ($session->session_number !== null) {
                $sheet->setCellValue("G{$row}", $session->session_number);
            }

            $sheet->setCellValueExplicit("H{$row}", $session->status->presentationLabel($session->hasSummary()), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("I{$row}", (string) $session->summary, DataType::TYPE_STRING);
            $sheet->getStyle("I{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

            $row++;
        }

        return $row - 1;
    }

    /**
     * O ficheiro do histórico, em bytes: uma só folha.
     *
     * @param  Collection<int, TimetableActivitySession>  $sessions
     */
    public function write(Collection $sessions): string
    {
        $spreadsheet = new Spreadsheet;

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(self::SHEET_TITLE);

            foreach (self::headers() as $index => $header) {
                $sheet->setCellValue([$index + 1, 1], $header);
            }

            $lastRow = self::fillRows($sheet, $sessions);
            $this->finish($sheet, count(self::headers()), $lastRow);

            return $this->render($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    private function finish(Worksheet $sheet, int $columnCount, int $lastRow): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $header = $sheet->getStyle("A1:{$lastColumn}1");
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

        if ($lastRow >= 1) {
            $sheet->setAutoFilter("A1:{$lastColumn}1");
        }

        $sheet->freezePane('A2');

        for ($column = 1; $column <= $columnCount; $column++) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        // O sumário é largo e quebra: uma largura fixa, em vez de uma linha
        // de metros de comprimento.
        $sheet->getColumnDimension($lastColumn)->setAutoSize(false)->setWidth(80);
    }

    private function render(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis_activity_sessions_xlsx_');

        if ($path === false) {
            throw new RuntimeException('Não foi possível preparar o ficheiro Excel das sessões.');
        }

        try {
            (new Xlsx($spreadsheet))->save($path);
            $contents = file_get_contents($path);

            return $contents === false ? '' : $contents;
        } finally {
            @unlink($path);
        }
    }
}
