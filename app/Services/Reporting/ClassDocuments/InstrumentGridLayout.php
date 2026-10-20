<?php

namespace App\Services\Reporting\ClassDocuments;

/**
 * Where the columns of an instrument's grid go on paper: the orientation and
 * the BLOCKS of columns — a pure function, no database, no framework.
 *
 * WHY THE SPLIT IS DECIDED HERE AND NOT IN CSS. dompdf does not paginate
 * horizontally: a column that passes the right margin is not carried to another
 * page, it is lost, with no warning. A forty-question instrument therefore has to
 * be cut into blocks of columns BEFORE it is drawn, and the cut has to live in the
 * structure so the preview and the PDF cut it in the same place.
 *
 * EVERY BLOCK REPEATS «Nº» AND «Nome» and carries as many columns as fit beside
 * them. The questions come first, in their own order; the summary columns
 * (pontuação bruta, resultado, nível, domínios) follow and so land in the last
 * block — as ONE unit when they fit together, so a result is not parted from its
 * level, and one by one only when even they do not fit a block of their own.
 *
 * THE QUESTIONS ARE SHARED OUT EVENLY across the fewest blocks that can carry
 * them, rather than filled to the brim with a sliver left over: forty questions
 * become three blocks of about thirteen, not two full ones and a block of one.
 * Each cut then moves to a nearby GROUP BOUNDARY when there is one within a third
 * of a block, so a block tends to end where a section ends; only a group longer
 * than a block is split, and its label is repeated.
 *
 * NOTHING IS EVER DROPPED. A block always takes at least one column, so a width
 * that cannot hold even one still yields one column per block rather than a loop
 * or a missing question.
 *
 * THE WIDTHS ARE ESTIMATES in millimetres for 7.5–8 pt text, against the usable
 * width of A4 with the Standard letterhead margins (20 mm each side). They are
 * deliberately not the compact margins: the plan then holds with either, and a
 * column is never lost to a layout chosen later.
 */
final class InstrumentGridLayout
{
    public const PORTRAIT = 'portrait';

    public const LANDSCAPE = 'landscape';

    public const AUTO = 'auto';

    /** A4 minus 20 mm of margin on each side, in mm. */
    public const PORTRAIT_WIDTH = 170.0;

    public const LANDSCAPE_WIDTH = 257.0;

    public const NUMBER_WIDTH = 7.0;

    public const NAME_WIDTH = 40.0;

    public const ITEM_WIDTH = 9.5;

    public const RAW_WIDTH = 16.0;

    public const RESULT_WIDTH = 14.0;

    public const LEVEL_WIDTH = 18.0;

    public const DOMAIN_WIDTH = 12.0;

    /**
     * @param  list<int|null>  $itemGroups  the group of each question, in order — an id, or null for the implicit group; only EQUALITY matters
     * @param  list<float>  $summaryWidths  the width of each summary column, in order
     * @param  'auto'|'portrait'|'landscape'  $orientation  `auto` is portrait while everything fits one block in it, landscape otherwise
     * @return array{orientation: 'portrait'|'landscape', blocks: list<array{items: list<int>, summary: list<int>}>}
     */
    public static function plan(array $itemGroups, array $summaryWidths, string $orientation = self::AUTO): array
    {
        if ($orientation === self::PORTRAIT || $orientation === self::LANDSCAPE) {
            return [
                'orientation' => $orientation,
                'blocks' => self::blocks($itemGroups, $summaryWidths, self::widthOf($orientation)),
            ];
        }

        $portrait = self::blocks($itemGroups, $summaryWidths, self::PORTRAIT_WIDTH);

        if (count($portrait) === 1) {
            return ['orientation' => self::PORTRAIT, 'blocks' => $portrait];
        }

        return [
            'orientation' => self::LANDSCAPE,
            'blocks' => self::blocks($itemGroups, $summaryWidths, self::LANDSCAPE_WIDTH),
        ];
    }

    /**
     * The room for columns beside «Nº» and «Nome», in mm.
     */
    public static function roomFor(string $orientation): float
    {
        return self::widthOf($orientation) - self::NUMBER_WIDTH - self::NAME_WIDTH;
    }

    private static function widthOf(string $orientation): float
    {
        return $orientation === self::LANDSCAPE ? self::LANDSCAPE_WIDTH : self::PORTRAIT_WIDTH;
    }

    /**
     * @param  list<int|null>  $itemGroups
     * @param  list<float>  $summaryWidths
     * @return list<array{items: list<int>, summary: list<int>}>
     */
    private static function blocks(array $itemGroups, array $summaryWidths, float $width): array
    {
        $room = $width - self::NUMBER_WIDTH - self::NAME_WIDTH;
        $itemCount = count($itemGroups);
        $capacity = max(1, (int) floor($room / self::ITEM_WIDTH));

        // The summary stays together while it fits a block of its own, and then
        // it rides in the LAST block of questions, which has less room for them.
        $summaryTogether = $summaryWidths !== [] && array_sum($summaryWidths) <= $room;
        $lastCapacity = $summaryTogether
            ? max(1, (int) floor(($room - array_sum($summaryWidths)) / self::ITEM_WIDTH))
            : $capacity;

        if ($itemCount === 0) {
            return $summaryWidths === []
                ? [['items' => [], 'summary' => []]]
                : self::summaryBlocks($summaryWidths, $room, $summaryTogether);
        }

        // The fewest blocks of questions that carry them all.
        $count = $itemCount <= $lastCapacity
            ? 1
            : 1 + (int) ceil(($itemCount - $lastCapacity) / $capacity);

        $blocks = [];
        $start = 0;

        for ($index = 0; $index < $count - 1; $index++) {
            $blocksLeft = $count - $index;

            // Room in the blocks AFTER this one: the cut must leave no more
            // than that, or a question would have nowhere to go.
            $roomAfter = ($blocksLeft - 2) * $capacity + $lastCapacity;
            $lowest = max($start + 1, $itemCount - $roomAfter);
            $highest = min($start + $capacity, $itemCount - 1);
            $ideal = min($highest, max($lowest, $start + (int) ceil(($itemCount - $start) / $blocksLeft)));

            $end = self::snapToGroupBoundary($itemGroups, $ideal, $lowest, $highest, max(1, (int) floor(($ideal - $start) / 3)));

            $blocks[] = ['items' => range($start, $end - 1), 'summary' => []];
            $start = $end;
        }

        $blocks[] = [
            'items' => range($start, $itemCount - 1),
            'summary' => $summaryTogether ? array_keys($summaryWidths) : [],
        ];

        if ($summaryWidths !== [] && ! $summaryTogether) {
            array_push($blocks, ...self::summaryBlocks($summaryWidths, $room, false));
        }

        return $blocks;
    }

    /**
     * The cut moved to the nearest group boundary within `$tolerance` of where it
     * would fall, never outside `[$lowest, $highest]`; the cut itself when it is
     * already on a boundary or none is near.
     *
     * @param  list<int|null>  $itemGroups
     */
    private static function snapToGroupBoundary(array $itemGroups, int $ideal, int $lowest, int $highest, int $tolerance): int
    {
        $isBoundary = fn (int $cut): bool => $itemGroups[$cut - 1] !== $itemGroups[$cut];

        if ($isBoundary($ideal)) {
            return $ideal;
        }

        for ($offset = 1; $offset <= $tolerance; $offset++) {
            foreach ([$ideal - $offset, $ideal + $offset] as $candidate) {
                if ($candidate >= $lowest && $candidate <= $highest && $isBoundary($candidate)) {
                    return $candidate;
                }
            }
        }

        return $ideal;
    }

    /**
     * @param  array<int, float>  $summaryWidths  keyed by the summary column's index
     * @return list<array{items: list<int>, summary: list<int>}>
     */
    private static function summaryBlocks(array $summaryWidths, float $room, bool $together): array
    {
        if ($together) {
            return [['items' => [], 'summary' => array_keys($summaryWidths)]];
        }

        $blocks = [];
        $current = [];
        $used = 0.0;

        foreach ($summaryWidths as $index => $columnWidth) {
            if ($current !== [] && $used + $columnWidth > $room) {
                $blocks[] = ['items' => [], 'summary' => $current];
                $current = [];
                $used = 0.0;
            }

            $current[] = $index;
            $used += $columnWidth;
        }

        if ($current !== []) {
            $blocks[] = ['items' => [], 'summary' => $current];
        }

        return $blocks;
    }
}
