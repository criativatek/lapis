<?php

namespace App\Support\Changelog;

/**
 * Reads CHANGELOG.md's Keep a Changelog entries — one `## [version] — date`
 * heading per release, each with `### Category` subsections of `- ` bullets.
 * Every bullet is written as a single physical line in the file (never
 * hand-wrapped), so a per-line regex is enough — no markdown parser needed.
 */
class ChangelogParser
{
    /**
     * @return list<ChangelogEntry>
     */
    public function parse(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return [];
        }

        preg_match_all('/^## \[(.+?)\] — (.+)$/m', $content, $headings, PREG_OFFSET_CAPTURE);

        $entries = [];
        $count = count($headings[0]);

        for ($index = 0; $index < $count; $index++) {
            $start = $headings[0][$index][1] + strlen($headings[0][$index][0]);
            $end = $index + 1 < $count ? $headings[0][$index + 1][1] : strlen($content);

            $entries[] = new ChangelogEntry(
                version: $headings[1][$index][0],
                date: $headings[2][$index][0],
                sections: $this->parseSections(substr($content, $start, $end - $start)),
            );
        }

        return $entries;
    }

    /**
     * @return list<array{category: string, items: list<string>}>
     */
    protected function parseSections(string $body): array
    {
        preg_match_all('/^### (.+)$/m', $body, $headings, PREG_OFFSET_CAPTURE);

        $sections = [];
        $count = count($headings[0]);

        for ($index = 0; $index < $count; $index++) {
            $start = $headings[0][$index][1] + strlen($headings[0][$index][0]);
            $end = $index + 1 < $count ? $headings[0][$index + 1][1] : strlen($body);

            $sections[] = [
                'category' => $headings[1][$index][0],
                'items' => $this->parseItems(substr($body, $start, $end - $start)),
            ];
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    protected function parseItems(string $sectionBody): array
    {
        preg_match_all('/^- (.+)$/m', $sectionBody, $matches);

        return $matches[1];
    }
}
