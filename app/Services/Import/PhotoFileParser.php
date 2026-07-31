<?php

// app/Services/Import/PhotoFileParser.php

namespace App\Services\Import;

use App\Domain\Import\PhotoMatch;

/**
 * Reads a Word photo export. Two real, unrelated internal formats are known
 * to occur (confirmed against real files, not assumed):
 *
 * 1. The "Intuitivo" export: each photo is a VML image
 *    (<v:imagedata r:pict="...">), and its caption is a separate HTML chunk
 *    embedded via <w:altChunk r:id="..."> ("Alternative Format Import Part").
 * 2. A modern Word table export: each photo is a normal inline picture
 *    (<w:drawing>...<a:blip r:embed="...">), laid out one table row of
 *    photos followed by a separate table row of plain-text names
 *    (<w:tc>...<w:t>Nome</w:t>...).
 *
 * Both share the same underlying shape once you look past the vocabulary:
 * a whole ROW (or group) of images is laid out first, then that group's
 * captions afterward — never a strict alternating image/caption/image/caption
 * pattern. Both extractors below use the same FIFO-queue pairing for this
 * reason (see extractMatches()'s docblock for why a single "pending" slot
 * would be wrong). extractTableGridMatches() only runs when the Intuitivo
 * shape yields nothing, since a real file is always one format or the other,
 * never both.
 */
class PhotoFileParser
{
    protected const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    protected const NS_V = 'urn:schemas-microsoft-com:vml';

    protected const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

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

        if ($matches === []) {
            $matches = $this->extractTableGridMatches($documentXml, $relationships, $zip);
        }

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
            $target = $node->getAttribute('Target');

            // OOXML package-relationship targets are resolved one of two ways
            // (OPC §9.3): a leading "/" makes the path absolute from the zip
            // root; otherwise it's relative to THIS PART's own folder — and
            // since this file is always word/_rels/document.xml.rels, that
            // folder is "word/". Confirmed against two real files that use
            // each convention: the Intuitivo export ("/media/image.jpg",
            // absolute) and a standard Word-generated table export
            // ("media/image1.jpeg", relative to word/) — treating every
            // target as root-relative silently broke the second one.
            $map[$id] = str_starts_with($target, '/')
                ? ltrim($target, '/')
                : 'word/'.$target;
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

    /**
     * The modern-Word-table fallback shape (see class docblock). Every table
     * row is walked in document order; a row's <a:blip> pictures are enqueued
     * FIFO, then that same row's (or a later row's) non-empty table cells
     * each claim the oldest still-unpaired image. A row's own image cells
     * never carry text, so scanning a row for both kinds together — images
     * first, then text cells — never double-counts a cell as both.
     *
     * @param  array<string, string>  $relationships
     * @return list<PhotoMatch>
     */
    protected function extractTableGridMatches(string $documentXml, array $relationships, \ZipArchive $zip): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($documentXml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS_W);
        $xpath->registerNamespace('a', self::NS_A);
        $xpath->registerNamespace('r', self::NS_R);

        $rows = $xpath->query('//w:tr');

        $matches = [];

        if ($rows === false) {
            return $matches;
        }

        /** @var list<string|null> $pendingImageTargets FIFO queue, oldest first */
        $pendingImageTargets = [];

        foreach ($rows as $row) {
            if (! $row instanceof \DOMElement) {
                continue;
            }

            $blips = $xpath->query('.//a:blip', $row);

            if ($blips !== false) {
                foreach ($blips as $blip) {
                    /** @var \DOMElement $blip */
                    $rid = $blip->getAttributeNS(self::NS_R, 'embed');
                    $pendingImageTargets[] = $relationships[$rid] ?? null;
                }
            }

            $cells = $xpath->query('.//w:tc', $row);

            if ($cells === false) {
                continue;
            }

            foreach ($cells as $cell) {
                if (! $cell instanceof \DOMElement) {
                    continue;
                }

                $name = $this->extractName($this->cellText($xpath, $cell));

                if ($name === '') {
                    continue;
                }

                if ($pendingImageTargets === []) {
                    continue; // A caption with no preceding image — nothing to pair.
                }

                $imageTarget = array_shift($pendingImageTargets);

                if ($imageTarget === null) {
                    continue; // That image's own relationship could not be resolved.
                }

                $imageBytes = $zip->getFromName($imageTarget);

                if ($imageBytes !== false) {
                    $matches[] = new PhotoMatch(
                        name: $name,
                        imageBytes: $imageBytes,
                        extension: strtolower(pathinfo($imageTarget, PATHINFO_EXTENSION)) ?: 'jpg',
                    );
                }
            }
        }

        return $matches;
    }

    /**
     * A cell's own plain text, ignoring any purely structural/spacer cell
     * (no <w:t> runs at all) rather than misreading it as an empty caption.
     */
    protected function cellText(\DOMXPath $xpath, \DOMElement $cell): string
    {
        $textNodes = $xpath->query('.//w:t', $cell);

        if ($textNodes === false) {
            return '';
        }

        $text = '';

        foreach ($textNodes as $textNode) {
            if ($textNode instanceof \DOMElement) {
                $text .= $textNode->textContent;
            }
        }

        return trim($text);
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
