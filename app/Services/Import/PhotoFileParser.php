<?php

// app/Services/Import/PhotoFileParser.php

namespace App\Services\Import;

use App\Domain\Import\PhotoMatch;

/**
 * Reads the "Intuitivo" photo export. Despite its .doc extension, the file is
 * a zip (OOXML): each photo is a VML image (<v:imagedata r:pict="...">), and
 * its caption is a separate HTML chunk embedded via <w:altChunk r:id="...">
 * ("Alternative Format Import Part") — not a picture-with-caption pair a
 * generic document reader would recognize.
 *
 * Pairing is done by XML DOCUMENT ORDER (a real structural guarantee, unlike
 * zip-entry byte order): every <v:imagedata> and <w:altChunk> node is walked
 * in the order they appear in document.xml, and each image is paired with
 * the next altChunk that follows it.
 */
class PhotoFileParser
{
    protected const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    protected const NS_V = 'urn:schemas-microsoft-com:vml';

    protected const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    protected const NS_PACKAGE_RELS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @return list<PhotoMatch>
     */
    public function parse(string $path): array
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RosterFileParseException('Não foi possível abrir o ficheiro de fotos.');
        }

        $relationships = $this->readRelationships($zip);
        $documentXml = $zip->getFromName('word/document.xml');

        if ($documentXml === false) {
            $zip->close();

            return [];
        }

        $matches = $this->extractMatches($documentXml, $relationships, $zip);
        $zip->close();

        return $matches;
    }

    /**
     * @return array<string, string> relationship id => target path (without leading slash)
     */
    protected function readRelationships(\ZipArchive $zip): array
    {
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');

        if ($relsXml === false) {
            return [];
        }

        $dom = new \DOMDocument;
        $dom->loadXML($relsXml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rel', self::NS_PACKAGE_RELS);

        $map = [];
        $nodes = $xpath->query('//rel:Relationship');

        if ($nodes === false) {
            return $map;
        }

        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            $id = $node->getAttribute('Id');
            $target = ltrim($node->getAttribute('Target'), '/');
            $map[$id] = $target;
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $relationships
     * @return list<PhotoMatch>
     */
    protected function extractMatches(string $documentXml, array $relationships, \ZipArchive $zip): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($documentXml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS_W);
        $xpath->registerNamespace('v', self::NS_V);
        $xpath->registerNamespace('r', self::NS_R);

        // Both node kinds, in one query, preserve document order.
        $nodes = $xpath->query('//v:imagedata | //w:altChunk');

        $matches = [];

        if ($nodes === false) {
            return $matches;
        }

        $pendingImageTarget = null;

        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            if ($node->localName === 'imagedata') {
                $rid = $node->getAttributeNS(self::NS_R, 'pict');
                $pendingImageTarget = $relationships[$rid] ?? null;

                continue;
            }

            // altChunk
            if ($pendingImageTarget === null) {
                continue; // A caption with no preceding image — nothing to pair.
            }

            $rid = $node->getAttributeNS(self::NS_R, 'id');
            $chunkTarget = $relationships[$rid] ?? null;

            if ($chunkTarget === null) {
                $pendingImageTarget = null;

                continue;
            }

            $imageBytes = $zip->getFromName($pendingImageTarget);
            $captionHtml = $zip->getFromName($chunkTarget);

            if ($imageBytes !== false && $captionHtml !== false) {
                $matches[] = new PhotoMatch(
                    name: $this->extractName($captionHtml),
                    imageBytes: $imageBytes,
                    extension: strtolower(pathinfo($pendingImageTarget, PATHINFO_EXTENSION)) ?: 'jpg',
                );
            }

            $pendingImageTarget = null;
        }

        return $matches;
    }

    protected function extractName(string $captionHtml): string
    {
        $text = strip_tags($captionHtml);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Drop a trailing status marker like "(MT)" — the situation already
        // comes from the roster's own SIT. column; only the plain name matters
        // for matching here.
        $text = preg_replace('/\s*\([^)]*\)\s*$/u', '', $text);

        return trim($text);
    }
}
