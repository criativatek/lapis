<?php

namespace App\Services\Reporting\Export;

use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;
use PhpOffice\PhpWord\Style\Tab;
use PhpOffice\PhpWord\Writer\Word2007;

/**
 * The Word file (§48).
 *
 * A REAL DOCUMENT, NOT A PICTURE OF ONE. Headings are headings, paragraphs are
 * paragraphs, tables are tables, and the letterhead is a Word header that
 * repeats on every page. A teacher who opens this must be able to change a
 * sentence and print it — which is the entire reason a school asks for .docx
 * rather than only a PDF, and which an exported image would defeat.
 *
 * IT READS THE SAME STRUCTURE AS THE PDF (§47). Not the report, not the frozen
 * document, not the database: exactly what ReportDocumentBuilder produced, so
 * the two files cannot say different things.
 *
 * The logo goes in as bytes through a temporary file, because PhpWord's image
 * writer takes a path. It is removed immediately afterwards; nothing readable
 * is left behind in the system temp directory (§65).
 */
class DocxRenderer
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function render(array $document): string
    {
        $word = new PhpWord;

        // «pt-PT» spelled out rather than PhpWord's Language::PT_BR: the
        // library only ships a constant for Brazilian Portuguese, and tagging a
        // Portuguese school's document as pt-BR makes Word's own spell-checker
        // underline half of it. setLatin validates the locale format, so an
        // arbitrary string is not being smuggled past anything.
        $word->getSettings()->setThemeFontLang(new Language('pt-PT'));

        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(11);

        $word->addTitleStyle(1, ['size' => 16, 'bold' => true], ['spaceAfter' => 120]);
        $word->addTitleStyle(2, ['size' => 12, 'bold' => true], ['spaceBefore' => 240, 'spaceAfter' => 80]);

        $section = $word->addSection([
            'marginTop' => 1418,   // 2.5 cm in twips
            'marginBottom' => 1134,
            'marginLeft' => 1021,
            'marginRight' => 1021,
        ]);

        $temporaryLogo = $this->header($section, $document);
        $this->footer($section, $document);

        try {
            $this->body($section, $document);

            return $this->write($word);
        } finally {
            if ($temporaryLogo !== null && is_file($temporaryLogo)) {
                @unlink($temporaryLogo);
            }
        }
    }

    /**
     * The letterhead, as a Word header so it repeats on every page.
     *
     * @param  array<string, mixed>  $document
     * @return string|null The temporary logo path, for the caller to remove.
     */
    protected function header(Section $section, array $document): ?string
    {
        $header = $section->addHeader();
        $identity = $document['identity'];

        $logoPath = null;

        if ($identity['logo'] !== null) {
            $logoPath = $this->temporaryLogo($identity['logo']);
        }

        // A two-column table rather than floats: Word's own layout primitive,
        // and the one that survives being opened in LibreOffice too.
        $table = $header->addTable(['borderSize' => 0, 'cellMargin' => 0, 'width' => 100 * 50, 'unit' => 'pct']);
        $table->addRow();

        if ($logoPath !== null) {
            $table->addCell(1200)->addImage($logoPath, ['height' => 48, 'alignment' => Jc::START]);
        }

        $cell = $table->addCell($logoPath === null ? 9600 : 8400);
        $cell->addText($identity['name'], ['bold' => true, 'size' => 11]);

        foreach ($identity['header_lines'] as $line) {
            $cell->addText($line, ['size' => 8, 'color' => '555555']);
        }

        return $logoPath;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function footer(Section $section, array $document): void
    {
        $footer = $section->addFooter();

        $note = $document['identity']['footer_note'] ?? $document['identity']['name'];

        // Real tabs, so a double-quoted string: in single quotes «\t» is a
        // backslash and a t, and the footer would print it.
        $footer->addPreserveText(
            $note."\t\tPágina {PAGE} / {NUMPAGES}",
            ['size' => 8, 'color' => '666666'],
            ['tabs' => [
                new Tab(Tab::TAB_STOP_RIGHT, 9600),
            ]],
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function body(Section $section, array $document): void
    {
        $section->addTitle($document['title'], 1);
        $section->addText($document['subtitle'], ['size' => 9, 'color' => '555555'], ['spaceAfter' => 240]);

        if ($document['draft_note'] !== null) {
            $section->addText(
                $document['draft_note'],
                ['bold' => true, 'size' => 9, 'color' => '92400E'],
                ['spaceAfter' => 240, 'shading' => ['fill' => 'FFFBEB']],
            );
        }

        foreach ($document['sections'] as $reportSection) {
            $section->addTitle($reportSection['heading'], 2);

            foreach ($reportSection['paragraphs'] as $paragraph) {
                // Single newlines inside a paragraph are real — the listing
                // sections keep one name per line — so they become their own
                // lines rather than being flattened into a run-on.
                foreach (explode("\n", $paragraph) as $line) {
                    if (trim($line) === '') {
                        continue;
                    }

                    $section->addText(
                        htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        [],
                        ['alignment' => Jc::BOTH, 'spaceAfter' => 80],
                    );
                }
            }

            foreach ($reportSection['tables'] as $table) {
                $this->table($section, $table);
            }
        }

        $this->signature($section, $document);
    }

    /**
     * @param  array{caption: string|null, headers: list<string>, rows: list<list<string>>}  $table
     */
    protected function table(Section $section, array $table): void
    {
        if ($table['caption'] !== null) {
            $section->addText($table['caption'], ['size' => 8, 'color' => '555555'], ['spaceBefore' => 120]);
        }

        $element = $section->addTable([
            'borderSize' => 3,
            'borderColor' => 'DDDDDD',
            'cellMargin' => 60,
            'width' => 100 * 50,
            'unit' => 'pct',
        ]);

        // Repeat the header row when a table crosses a page break, so the
        // second page of a chronology is still readable.
        $element->addRow(null, ['tblHeader' => true]);

        foreach ($table['headers'] as $header) {
            $element->addCell(null, ['bgColor' => 'F3F4F6'])
                ->addText(htmlspecialchars($header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ['bold' => true, 'size' => 9]);
        }

        foreach ($table['rows'] as $row) {
            $element->addRow();

            foreach ($row as $cell) {
                $element->addCell()->addText(
                    htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    ['size' => 9],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function signature(Section $section, array $document): void
    {
        $meta = $document['meta'];

        if ($meta['status'] === 'finalized') {
            $parts = array_filter([
                'Relatório finalizado',
                $meta['finalized_by'] === null ? null : 'por '.$meta['finalized_by'],
                $meta['finalized_at'] === null
                    ? null
                    : 'em '.Carbon::parse($meta['finalized_at'])->format('d/m/Y'),
            ]);

            $section->addText(implode(' ', $parts).'.', ['size' => 9], ['spaceBefore' => 480]);
        } elseif ($meta['author'] !== null) {
            $section->addText((string) $meta['author'], ['size' => 9], ['spaceBefore' => 480]);
        }

        $section->addText('___________________________________', ['size' => 9], ['spaceBefore' => 480]);
        $section->addText('O(A) professor(a)', ['size' => 9]);
    }

    /**
     * @param  array{data: string, mime: string, extension: string}  $logo
     */
    protected function temporaryLogo(array $logo): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-logo-').'.'.$logo['extension'];

        file_put_contents($path, $logo['data']);

        return $path;
    }

    protected function write(PhpWord $word): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-docx-');

        try {
            (new Word2007($word))->save($path);

            return (string) file_get_contents($path);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
