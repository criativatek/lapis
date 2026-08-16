<?php

namespace App\Services\Export;

use App\Support\Export\InovarTemplateException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Writes the mentions into the grid INOVAR gave us, and touches nothing else.
 *
 * THE TEMPLATE GOES IN AND THE TEMPLATE COMES OUT. Nothing is generated: the
 * file a school uploads to INOVAR is the file INOVAR produced, with some empty
 * cells now carrying F, I, S, B or MB. Building a lookalike from scratch would
 * mean guessing at a contract we do not own, and the first thing to find out
 * would be a rejected upload.
 *
 * ONLY THE CELLS ON THE LIST. Each one was decided by the preview the teacher
 * confirmed — a row that matched a student by process number, a column that
 * matched a domain by name — and every other cell, every style, every merge,
 * the sheet's own title and the columns nobody mapped are left exactly as they
 * were read.
 *
 * A round-trip through this writer was measured against a real grid before any
 * of it was built: title, dimensions, merges, all values and all 243 cell
 * styles came back identical. The one difference is that column widths become
 * explicit — the width itself never changes — which is the documented cost of
 * using this writer at all.
 */
class FillInovarTemplate
{
    /**
     * @param  list<array{row: int, column: string, code: string}>  $cells
     * @return string the path of the filled copy
     */
    public function fill(string $templatePath, string $sheetTitle, array $cells, string $destinationPath): string
    {
        try {
            $reader = IOFactory::createReader(IOFactory::identify($templatePath));
            $reader->setReadDataOnly(false);
            $spreadsheet = @$reader->load($templatePath);
        } catch (\Throwable $exception) {
            throw InovarTemplateException::unreadable($exception->getMessage());
        }

        try {
            $sheet = $spreadsheet->getSheetByName($sheetTitle);

            if ($sheet === null) {
                throw InovarTemplateException::notRecognized(
                    "A folha «{$sheetTitle}» já não existe neste ficheiro.",
                );
            }

            foreach ($cells as $cell) {
                // Explicitly as text: «MB» is a code, and letting the writer
                // decide a type for it is letting it decide something.
                $sheet->setCellValueExplicit(
                    $cell['column'].$cell['row'],
                    $cell['code'],
                    DataType::TYPE_STRING,
                );
            }

            // The same format it arrived in. A grid INOVAR sent as .xls goes
            // back as .xls; converting it would be changing the contract.
            IOFactory::createWriter($spreadsheet, IOFactory::identify($templatePath))->save($destinationPath);

            return $destinationPath;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
