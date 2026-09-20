<?php

namespace Tests\Unit\Characterisation\Extraction;

use App\Services\Characterisation\Import\Extraction\DocxTableExtractor;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

/**
 * .docx fixtures are built here, programmatically, as minimal OOXML zips —
 * not committed binaries. A test that ships a real Word file gives no reader
 * of the diff any idea what structure is being asserted on; a string of
 * w:tbl/w:tr/w:tc markup does.
 */
class DocxTableExtractorTest extends TestCase
{
    private function extractor(): DocxTableExtractor
    {
        return new DocxTableExtractor;
    }

    /**
     * @param  list<string>  $tablesXml  one w:tbl...w:tbl string per table
     */
    private function buildDocx(array $tablesXml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx').'.docx';

        $body = implode('', $tablesXml);

        $documentXml = <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
              <w:body>
                {$body}
                <w:sectPr/>
              </w:body>
            </w:document>
            XML;

        $contentTypes = <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
              <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
              <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
            </Types>
            XML;

        $rels = <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
              <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
            </Relationships>
            XML;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();

        return $path;
    }

    private function cell(string $text, ?int $gridSpan = null, ?string $vMerge = null): string
    {
        $properties = '';

        if ($gridSpan !== null || $vMerge !== null) {
            $properties = '<w:tcPr>';

            if ($gridSpan !== null) {
                $properties .= '<w:gridSpan w:val="'.$gridSpan.'"/>';
            }

            if ($vMerge !== null) {
                $properties .= $vMerge === 'restart' ? '<w:vMerge w:val="restart"/>' : '<w:vMerge/>';
            }

            $properties .= '</w:tcPr>';
        }

        return '<w:tc>'.$properties.'<w:p><w:r><w:t>'.$text.'</w:t></w:r></w:p></w:tc>';
    }

    public function test_it_reads_a_docx_with_one_table(): void
    {
        $table = '<w:tbl>'.
            '<w:tr>'.$this->cell('Nome').$this->cell('Observações').'</w:tr>'.
            '<w:tr>'.$this->cell('Ana Silva').$this->cell('Participa').'</w:tr>'.
            '</w:tbl>';

        $path = $this->buildDocx([$table]);

        $tables = $this->extractor()->extract(
            new UploadedFile($path, 'caracterizacao.docx', null, null, true),
        );

        $this->assertCount(1, $tables);
        $this->assertSame('Nome', $tables[0]->rows[0]->cells[0]->text);
        $this->assertSame('Participa', $tables[0]->rows[1]->cells[1]->text);

        @unlink($path);
    }

    public function test_it_reads_a_docx_with_several_tables(): void
    {
        $first = '<w:tbl><w:tr>'.$this->cell('Nome').$this->cell('Observações').'</w:tr></w:tbl>';
        $second = '<w:tbl><w:tr>'.$this->cell('MU').$this->cell('Medidas Universais').'</w:tr></w:tbl>';

        $path = $this->buildDocx([$first, $second]);

        $tables = $this->extractor()->extract(
            new UploadedFile($path, 'caracterizacao.docx', null, null, true),
        );

        $this->assertCount(2, $tables);
        $this->assertSame('Nome', $tables[0]->rows[0]->cells[0]->text);
        $this->assertSame('MU', $tables[1]->rows[0]->cells[0]->text);

        @unlink($path);
    }

    public function test_it_reads_gridspan_and_vmerge(): void
    {
        $table = '<w:tbl>'.
            '<w:tr>'.$this->cell('Nome', gridSpan: 2).$this->cell('Observações').'</w:tr>'.
            '<w:tr>'.$this->cell('Turma A', vMerge: 'restart').$this->cell('Ana Silva').$this->cell('Participa').'</w:tr>'.
            '<w:tr>'.$this->cell('', vMerge: 'continue').$this->cell('Bruno Costa').$this->cell('Falta muito').'</w:tr>'.
            '</w:tbl>';

        $path = $this->buildDocx([$table]);

        $tables = $this->extractor()->extract(
            new UploadedFile($path, 'caracterizacao.docx', null, null, true),
        );

        $this->assertCount(1, $tables);
        $extracted = $tables[0];

        // Header's colspan cell.
        $this->assertSame(2, $extracted->rows[0]->cells[0]->colspan);

        // The vertically-merged origin cell now carries rowspan 2, and the
        // continuation row produced no separate cell for that column.
        $originCell = $extracted->rows[1]->cells[0];
        $this->assertSame('Turma A', $originCell->text);
        $this->assertSame(2, $originCell->rowspan);

        $thirdRowFirstCell = $extracted->rows[2]->cells[0];
        $this->assertSame('Bruno Costa', $thirdRowFirstCell->text);

        @unlink($path);
    }

    public function test_an_invalid_docx_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fake').'.docx';
        file_put_contents($path, 'not actually a zip');

        $this->expectException(UnreadableSpreadsheet::class);

        try {
            $this->extractor()->extract(
                new UploadedFile($path, 'caracterizacao.docx', null, null, true),
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_it_does_not_support_a_docx_it_cannot_open_as_a_zip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fake').'.docx';
        file_put_contents($path, 'not actually a zip');

        $this->assertFalse($this->extractor()->supports(
            new UploadedFile($path, 'caracterizacao.docx', null, null, true),
        ));

        @unlink($path);
    }
}
