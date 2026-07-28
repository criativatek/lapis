<?php

// tests/Support/DocxFixtureBuilder.php

namespace Tests\Support;

/**
 * Builds a minimal fixture reproducing the exact structure the real
 * "Intuitivo" photo export uses: each photo is a VML image inside a table
 * cell (`<v:imagedata r:pict="...">`), and its caption is a separate HTML
 * chunk embedded via `<w:altChunk r:id="...">` — not a normal picture-with-
 * caption. PhotoFileParser is written against this exact shape; a generic
 * python-docx/PHPWord-generated file would NOT reproduce it.
 */
class DocxFixtureBuilder
{
    /**
     * @param  list<array{name: string, imageBytes: string}>  $entries
     */
    public static function build(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'photo_fixture_').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $relationships = '';
        $tableRows = '';

        foreach ($entries as $index => $entry) {
            $n = $index + 1;
            $imageRid = "rImg{$n}";
            $chunkRid = "rChunk{$n}";

            $zip->addFromString("media/image{$n}.jpg", $entry['imageBytes']);
            $zip->addFromString("word/afchunk{$n}.htm", self::captionHtml($entry['name']));

            $relationships .= '<Relationship Id="'.$imageRid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="/media/image'.$n.'.jpg"/>';
            $relationships .= '<Relationship Id="'.$chunkRid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/aFChunk" Target="/word/afchunk'.$n.'.htm"/>';

            $tableRows .= '<w:tr><w:tc><w:p><w:r><w:pict><v:shape><v:imagedata r:pict="'.$imageRid.'"/></v:shape></w:pict></w:r></w:p></w:tc>';
            $tableRows .= '<w:tc><w:p><w:r><w:altChunk r:id="'.$chunkRid.'"/></w:r></w:p></w:tc></w:tr>';
        }

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:v="urn:schemas-microsoft-com:vml" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body><w:tbl>'.$tableRows.'</w:tbl></w:body></w:document>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships.'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="jpg" ContentType="image/jpeg"/>'
            .'<Default Extension="htm" ContentType="text/html"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $relsXml);
        $zip->close();

        return $path;
    }

    protected static function captionHtml(string $name): string
    {
        return '<html><head><meta charset="utf-8"/></head><body><div>'.htmlspecialchars($name).' </div></body></html>';
    }

    /**
     * A tiny valid 1x1 JPEG, generated at call time — never a real photo.
     */
    public static function tinyJpeg(): string
    {
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return (string) $bytes;
    }
}
