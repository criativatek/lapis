<?php

namespace App\Services\Reporting\Export;

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

        // A HEADING NEVER ENDS A PAGE. `keepNext` binds each title to whatever
        // follows it, so Word can no longer leave «Distribuição das
        // classificações atribuídas» alone at the foot of a page with its table
        // overleaf — the most common way a generated .docx reads as machine
        // output rather than as a document (§48).
        $word->addTitleStyle(
            1,
            ['size' => 16, 'bold' => true],
            ['spaceAfter' => 120, 'keepNext' => true, 'keepLines' => true],
        );
        $word->addTitleStyle(
            2,
            ['size' => 12, 'bold' => true],
            ['spaceBefore' => 360, 'spaceAfter' => 120, 'keepNext' => true, 'keepLines' => true],
        );

        $section = $word->addSection([
            // 2.5 cm all round, in twips. The old 1.8 cm sides gave a 17 cm
            // measure — too long a line for 11 pt, and the reason the file read
            // as compressed beside its own PDF.
            'marginTop' => 1418,
            'marginBottom' => 1418,
            'marginLeft' => 1418,
            'marginRight' => 1418,
            'headerHeight' => 709,
            'footerHeight' => 709,
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
            $table->addCell(1000)->addImage($logoPath, ['height' => 36, 'alignment' => Jc::START]);
        }

        $cell = $table->addCell($logoPath === null ? 9600 : 8600);
        // Smaller than the document's own title, and tight: a letterhead
        // identifies the school, it is not a section of the report (§50).
        $cell->addText($identity['name'], ['bold' => true, 'size' => 9.5], ['spaceAfter' => 0]);

        foreach ($identity['header_lines'] as $line) {
            $cell->addText($line, ['size' => 7.5, 'color' => '666666'], ['spaceAfter' => 0]);
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
            // The right edge of the measure: 21 cm of paper less two 2.5 cm
            // margins, in twips. A stop past it pushes the page number off the
            // page instead of aligning it to the margin.
            ['tabs' => [
                new Tab(Tab::TAB_STOP_RIGHT, 9071),
            ]],
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function body(Section $section, array $document): void
    {
        $section->addTitle($document['title'], 1);

        // Only when it says something the title did not: DocumentHeading hands
        // back an empty line rather than one repeating the heading (§47).
        if ($document['subtitle'] !== '') {
            $section->addText(
                $document['subtitle'],
                ['size' => 9, 'color' => '555555'],
                ['spaceAfter' => 360, 'keepNext' => true],
            );
        }

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
                        // Widow control is Word's own default and PhpWord only
                        // writes the tag to turn it OFF, so it is not asked for
                        // here — what this line changes is the air after each
                        // paragraph, which the file did not have.
                        ['alignment' => Jc::BOTH, 'spaceAfter' => 140],
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
            // Bound to the table it names: a caption at the foot of one page
            // over a table on the next is a caption for nothing.
            $section->addText(
                $table['caption'],
                ['size' => 8, 'color' => '555555'],
                ['spaceBefore' => 160, 'spaceAfter' => 40, 'keepNext' => true],
            );
        }

        $element = $section->addTable([
            'borderSize' => 3,
            'borderColor' => 'DDDDDD',
            'cellMargin' => 80,
            'width' => 100 * 50,
            'unit' => 'pct',
        ]);

        // Repeat the header row when a table crosses a page break, so the
        // second page of a chronology is still readable. `cantSplit` keeps a
        // single row whole, which is what stops a name and its figure from
        // landing on two different pages.
        $element->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);

        foreach ($table['headers'] as $header) {
            $element->addCell(null, ['bgColor' => 'F3F4F6'])
                ->addText(htmlspecialchars($header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ['bold' => true, 'size' => 9]);
        }

        foreach ($table['rows'] as $row) {
            $element->addRow(null, ['cantSplit' => true]);

            foreach ($row as $cell) {
                $element->addCell()->addText(
                    htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    ['size' => 9],
                );
            }
        }

        // Air between a table and whatever follows it, which Word does not add
        // on its own.
        $section->addText('', [], ['spaceAfter' => 160]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function signature(Section $section, array $document): void
    {
        $meta = $document['meta'];

        if ($meta['status'] === 'finalized') {
            // The same sentence the PDF prints, composed once in the builder so
            // the two files cannot close differently (§47).
            $section->addText(
                (string) $meta['closing'],
                ['size' => 9],
                ['spaceBefore' => 600, 'keepNext' => true],
            );
        } elseif ($meta['author'] !== null) {
            $section->addText(
                (string) $meta['author'],
                ['size' => 9],
                ['spaceBefore' => 600, 'keepNext' => true],
            );
        }

        // The rule and its caption travel together and never alone: a signature
        // block split across a page break is a page with a line on it.
        $section->addText(
            '___________________________________',
            ['size' => 9],
            ['spaceBefore' => 600, 'keepNext' => true],
        );
        $section->addText('O(A) professor(a)', ['size' => 9, 'color' => '555555']);
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
