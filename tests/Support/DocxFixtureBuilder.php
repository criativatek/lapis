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

    /**
     * Builds a fixture reproducing the REAL Intuitivo export's actual internal
     * structure: a whole grid ROW of images is laid out first, then that row's
     * captions afterward — e.g. for groupSize=6: image1..image6, caption1..
     * caption6, image7..image12, caption7..caption12, ... — NOT the simple
     * alternating image/caption/image/caption pattern build() produces.
     * PhotoFileParser's pairing must handle this; a single "pending image"
     * slot cannot (it only survives the LAST image of each group, discarding
     * the rest, and pairs it with the wrong caption).
     *
     * @param  list<array{name: string, imageBytes: string}>  $entries
     */
    public static function buildGrouped(array $entries, int $groupSize): string
    {
        $path = tempnam(sys_get_temp_dir(), 'photo_fixture_grouped_').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $relationships = '';
        $tableRows = '';

        // preserve_keys=true keeps each entry's original position as its index,
        // so image/relationship ids stay unique across groups instead of
        // restarting at 1 for every group (which would silently overwrite
        // earlier zip entries of the same name).
        foreach (array_chunk($entries, $groupSize, true) as $group) {
            $imageCells = '';
            $captionCells = '';

            foreach ($group as $index => $entry) {
                $n = $index + 1;
                $imageRid = "rImg{$n}";
                $chunkRid = "rChunk{$n}";

                $zip->addFromString("media/image{$n}.jpg", $entry['imageBytes']);
                $zip->addFromString("word/afchunk{$n}.htm", self::captionHtml($entry['name']));

                $relationships .= '<Relationship Id="'.$imageRid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="/media/image'.$n.'.jpg"/>';
                $relationships .= '<Relationship Id="'.$chunkRid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/aFChunk" Target="/word/afchunk'.$n.'.htm"/>';

                $imageCells .= '<w:tc><w:p><w:r><w:pict><v:shape><v:imagedata r:pict="'.$imageRid.'"/></v:shape></w:pict></w:r></w:p></w:tc>';
                $captionCells .= '<w:tc><w:p><w:r><w:altChunk r:id="'.$chunkRid.'"/></w:r></w:p></w:tc>';
            }

            // One row of images, immediately followed (in document order) by
            // one row of captions — the real export's actual layout, not an
            // alternating image/caption/image/caption row.
            $tableRows .= '<w:tr>'.$imageCells.'</w:tr><w:tr>'.$captionCells.'</w:tr>';
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

    /**
     * Builds a fixture reproducing a standard Word-generated table export
     * (confirmed against a real file, 2026-07-31): photos are normal inline
     * pictures (<w:drawing>...<a:blip r:embed="...">), laid out one table row
     * of photos followed by a separate row of plain-text names
     * (<w:tc>...<w:t>Nome</w:t>). Deliberately uses a RELATIVE relationship
     * target ("media/imageN.jpg", no leading slash) — the real file's own
     * convention, and the one build()/buildGrouped()'s absolute
     * ("/media/...") targets never exercise.
     *
     * @param  list<array{name: string, imageBytes: string}>  $entries
     */
    public static function buildTableGrid(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'photo_fixture_grid_').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $relationships = '';
        $imageCells = '';
        $captionCells = '';

        foreach ($entries as $index => $entry) {
            $n = $index + 1;
            $imageRid = "rImg{$n}";

            $zip->addFromString("word/media/image{$n}.jpg", $entry['imageBytes']);
            $relationships .= '<Relationship Id="'.$imageRid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image'.$n.'.jpg"/>';

            $imageCells .= '<w:tc><w:p><w:r><w:drawing><a:blip r:embed="'.$imageRid.'"/></w:drawing></w:r></w:p></w:tc>';
            $captionCells .= '<w:tc><w:p><w:r><w:t>'.htmlspecialchars($entry['name']).'</w:t></w:r></w:p></w:tc>';
        }

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body><w:tbl><w:tr>'.$imageCells.'</w:tr><w:tr>'.$captionCells.'</w:tr></w:tbl></w:body></w:document>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships.'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="jpg" ContentType="image/jpeg"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $relsXml);
        $zip->close();

        return $path;
    }

    /**
     * THE FILE AT THE HEART OF THIS WHOLE FLOW: an EB019 export produced with
     * «colocar o nome ao lado da foto» switched OFF.
     *
     * Same VML shape as build(), and the photos are all there — but there is
     * not one <w:altChunk> in the document, because there are no captions to
     * embed. Every image is present and nothing says whose it is. The parser
     * used to answer this file with an empty list, which is what left a class
     * of students and a folder of their photos with no way to meet.
     *
     * @param  list<string>  $imageBytes
     */
    public static function buildWithoutCaptions(array $imageBytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'photo_fixture_uncaptioned_').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $relationships = '';
        $imageCells = '';

        foreach ($imageBytes as $index => $bytes) {
            $n = $index + 1;
            $imageRid = "rImg{$n}";

            $zip->addFromString("media/image{$n}.jpg", $bytes);
            $relationships .= '<Relationship Id="'.$imageRid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="/media/image'.$n.'.jpg"/>';
            $imageCells .= '<w:tc><w:p><w:r><w:pict><v:shape><v:imagedata r:pict="'.$imageRid.'"/></v:shape></w:pict></w:r></w:p></w:tc>';
        }

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:v="urn:schemas-microsoft-com:vml" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body><w:tbl><w:tr>'.$imageCells.'</w:tr></w:tbl></w:body></w:document>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships.'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="jpg" ContentType="image/jpeg"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $relsXml);
        $zip->close();

        return $path;
    }

    /**
     * The same omission in the modern-Word-table shape: a row of pictures and
     * no row of names under it.
     *
     * @param  list<string>  $imageBytes
     */
    public static function buildTableGridWithoutCaptions(array $imageBytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'photo_fixture_grid_uncaptioned_').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $relationships = '';
        $imageCells = '';

        foreach ($imageBytes as $index => $bytes) {
            $n = $index + 1;
            $imageRid = "rImg{$n}";

            $zip->addFromString("word/media/image{$n}.jpg", $bytes);
            $relationships .= '<Relationship Id="'.$imageRid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image'.$n.'.jpg"/>';
            $imageCells .= '<w:tc><w:p><w:r><w:drawing><a:blip r:embed="'.$imageRid.'"/></w:drawing></w:r></w:p></w:tc>';
        }

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body><w:tbl><w:tr>'.$imageCells.'</w:tr></w:tbl></w:body></w:document>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships.'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="jpg" ContentType="image/jpeg"/>'
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
