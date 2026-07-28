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
 * in the order they appear in document.xml. Images are collected into a FIFO
 * queue (never a single "pending" slot) and each altChunk claims the OLDEST
 * still-unpaired image. This matters because the real Intuitivo export does
 * NOT alternate image/caption/image/caption: it lays out a whole grid ROW of
 * images first, then that row's captions afterward (e.g. 6 images, then 6
 * captions). A single pending slot would discard all but the last image of
 * each row before any caption arrives. A FIFO queue handles both shapes: a
 * strictly alternating document never lets the queue grow past size 1 (so it
 * behaves identically to a single slot), and a grouped document dequeues the
 * Nth enqueued image for the Nth caption seen — correct in both cases because
 * within one row, the Nth image cell corresponds to the Nth caption cell in
 * reading order.
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

        /** @var list<string|null> $pendingImageTargets FIFO queue, oldest first */
        $pendingImageTargets = [];

        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            if ($node->localName === 'imagedata') {
                $rid = $node->getAttributeNS(self::NS_R, 'pict');
                $pendingImageTargets[] = $relationships[$rid] ?? null;

                continue;
            }

            // altChunk
            if ($pendingImageTargets === []) {
                continue; // A caption with no preceding image — nothing to pair.
            }

            $imageTarget = array_shift($pendingImageTargets);

            if ($imageTarget === null) {
                continue; // That image's own relationship could not be resolved.
            }

            $rid = $node->getAttributeNS(self::NS_R, 'id');
            $chunkTarget = $relationships[$rid] ?? null;

            if ($chunkTarget === null) {
                continue;
            }

            $imageBytes = $zip->getFromName($imageTarget);
            $captionHtml = $zip->getFromName($chunkTarget);

            if ($imageBytes !== false && $captionHtml !== false) {
                $matches[] = new PhotoMatch(
                    name: $this->extractName($captionHtml),
                    imageBytes: $imageBytes,
                    extension: strtolower(pathinfo($imageTarget, PATHINFO_EXTENSION)) ?: 'jpg',
                );
            }
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
