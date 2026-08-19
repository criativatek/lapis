<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * The two dimensional cuts of a school-wide report — by year of schooling and
 * by subject — which differ only in the noun.
 *
 * ALPHABETICAL, NEVER BY RATE (§25). The source already orders them that way
 * and this preserves it, then says so out loud: a list of a school's own
 * subjects sorted by success is a ranking with the word removed, and somebody
 * WILL read it as one.
 *
 * EACH ROW CARRIES ITS OWN DENOMINATOR and its own scale, because two rows on
 * different scales are not two points on one line (§26).
 */
class SchoolDimension
{
    public static function compose(ReportContext $context, mixed $rows, string $noun): ComposedSection
    {
        $list = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

        if ($list === []) {
            return ComposedSection::empty();
        }

        $parts = [];

        foreach ($list as $row) {
            $placed = (int) ($row['placed'] ?? 0);

            if ($placed === 0) {
                continue;
            }

            $rate = Phrase::percentage($row['success_rate'] ?? null);

            $parts[] = (string) $row['label'].' — '
                .($row['succeeded'] ?? 0).' de '.$placed
                .($rate === null ? '' : ' ('.$rate.')');
        }

        if ($parts === []) {
            return ComposedSection::empty();
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::sentence('Classificações positivas por', $noun.':', Phrase::items($parts)),
                'Os valores são apresentados por ordem alfabética e não constituem uma ordenação de desempenho.',
            ]),
            [ContentSource::Classification],
            ['rows' => $list],
        );
    }
}
