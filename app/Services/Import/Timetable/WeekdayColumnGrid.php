<?php

namespace App\Services\Import\Timetable;

/**
 * Which weekday column a positioned cell belongs to.
 *
 * THE WHOLE REASON THIS CLASS EXISTS is that the linear text of a timetable
 * cannot answer that question. An empty cell emits no separator, so a row whose
 * Monday and Wednesday are filled and whose Tuesday is empty reads exactly like
 * a row whose Monday and Tuesday are filled. Counting separators therefore
 * misfiles a lesson onto the wrong day the moment a teacher has a free period —
 * which is to say, always. Positions do not have that problem: a cell is on
 * Wednesday because it is drawn where Wednesday is.
 *
 * Nothing here is hardcoded to one school's export: page size, font and margins
 * differ between systems, so the bands are established per document, by one of
 * two routes.
 *
 * FIRST, THE HEADING ROW, when it carries usable coordinates. A column labelled
 * «4ª FEIRA» drawn at a known x is the most direct answer this question can
 * have, and it needs no interpolation and no assumption about which weekdays the
 * teacher happens to work.
 *
 * SECOND, AND ONLY WHEN THAT FAILS, the data's own positions. Some exports draw
 * the whole heading row as one relatively-positioned block, which reports every
 * label at x = 0 and makes the first route impossible. The columns are then
 * recovered from the cells themselves: they are evenly spaced, so the task is to
 * find the base and step that explain every observed position as a whole number
 * of columns — preferring the LARGEST such step, since a smaller one always
 * "fits" too by scattering four columns across twenty imaginary ones, and is
 * ruled out by the grid not being allowed to be wider than the heading declares.
 *
 * KNOWN AND ACCEPTED LIMIT OF THE SECOND ROUTE: it is anchored on the leftmost
 * column actually seen, and interior gaps are recovered only because their
 * neighbours pin the spacing. A file whose first weekday is empty at every hour
 * of the week — or one where the occupied days never fall next to each other —
 * cannot be disambiguated from the file alone, because there is no absolute
 * anchor left to disambiguate it against. This is stated rather than hidden, and
 * it is exactly why the heading row is tried first.
 */
final readonly class WeekdayColumnGrid
{
    /**
     * Two x-positions within this many PDF units of each other are the same
     * column. Real column spacing in these exports is ~88 units, so this is
     * comfortably below "could be confused with the next column" while still
     * absorbing sub-unit rounding between runs.
     */
    public const TOLERANCE = 4.0;

    /**
     * @param  list<float>  $columns  one x per weekday column, left to right
     * @param  float  $tolerance  how far from a column's x a fragment may sit and still belong to it
     */
    private function __construct(
        public array $columns,
        public float $tolerance,
    ) {}

    /**
     * The heading row's own positions — one per weekday label, in printed order.
     *
     * Refused unless the labels really are spread out: an export that draws them
     * all at the same x has told us nothing, and pretending otherwise would put
     * every lesson on Monday.
     *
     * @param  list<float>  $positions
     */
    public static function fromLabelPositions(array $positions): ?self
    {
        if (count($positions) < 2) {
            return null;
        }

        $gaps = [];

        for ($index = 1; $index < count($positions); $index++) {
            $gaps[] = $positions[$index] - $positions[$index - 1];
        }

        $spacing = min($gaps);

        // Not strictly increasing, or collapsed onto one another: this is not a
        // usable reading of the heading row.
        if ($spacing <= self::TOLERANCE) {
            return null;
        }

        // A cell sits at a constant offset from its heading's x — padding,
        // alignment — so the band is the column's half-width rather than an
        // exact position.
        return new self($positions, $spacing / 2);
    }

    /**
     * @param  list<float>  $xValues  every data cell's x, in any order
     * @param  int  $columnCount  how many weekday columns the heading declares
     */
    public static function derive(array $xValues, int $columnCount, float $tolerance = self::TOLERANCE): ?self
    {
        if ($columnCount < 1) {
            return null;
        }

        $clusters = self::cluster($xValues, $tolerance);

        if ($clusters === []) {
            return null;
        }

        $base = $clusters[0];

        // A single observed column says nothing about the spacing, so there is
        // no grid to interpolate: everything that lands on it is that column,
        // and anything else is outside the table.
        if (count($clusters) === 1) {
            return new self([$base], $tolerance);
        }

        $step = self::step($clusters, $columnCount, $tolerance);

        if ($step === null) {
            return null;
        }

        $columns = [];

        for ($index = 0; $index < $columnCount; $index++) {
            $columns[] = $base + $step * $index;
        }

        return new self($columns, $tolerance);
    }

    /**
     * The 0-based column, or null when the position is not on the grid at all.
     *
     * Refused rather than rounded when it falls between bands, so an unexpected
     * layout produces an honest gap instead of a confident wrong day.
     */
    public function columnFor(float $x): ?int
    {
        $best = null;

        foreach ($this->columns as $index => $column) {
            $distance = abs($x - $column);

            // Outside every band: refused rather than rounded onto the nearest
            // one, so an unexpected layout produces an honest gap instead of a
            // confident wrong day.
            if ($distance > $this->tolerance) {
                continue;
            }

            if ($best === null || $distance < $best['distance']) {
                $best = ['index' => $index, 'distance' => $distance];
            }
        }

        return $best === null ? null : $best['index'];
    }

    /**
     * Distinct x-positions, ascending — one per column actually drawn.
     *
     * @param  list<float>  $xValues
     * @return list<float>
     */
    private static function cluster(array $xValues, float $tolerance): array
    {
        sort($xValues);

        $clusters = [];

        foreach ($xValues as $x) {
            if ($clusters === [] || $x - $clusters[count($clusters) - 1] > $tolerance) {
                $clusters[] = $x;
            }
        }

        return $clusters;
    }

    /**
     * The largest spacing that explains every observed column as a whole number
     * of steps from the leftmost one, without needing more columns than the
     * heading declares.
     *
     * Candidates come from every pair of observed columns divided by every
     * plausible number of steps between them, so a document missing an interior
     * column — Thursday, in the export this was built against — still yields the
     * true spacing rather than the doubled gap that separates its neighbours.
     *
     * @param  list<float>  $clusters  ascending, at least two
     */
    private static function step(array $clusters, int $columnCount, float $tolerance): ?float
    {
        $base = $clusters[0];
        $candidates = [];

        foreach ($clusters as $index => $left) {
            foreach (array_slice($clusters, $index + 1) as $right) {
                for ($steps = 1; $steps <= max(1, $columnCount - 1); $steps++) {
                    $candidate = ($right - $left) / $steps;

                    if ($candidate > $tolerance) {
                        $candidates[] = $candidate;
                    }
                }
            }
        }

        rsort($candidates);

        foreach ($candidates as $candidate) {
            if (self::explains($clusters, $base, $candidate, $columnCount, $tolerance)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<float>  $clusters
     */
    private static function explains(array $clusters, float $base, float $step, int $columnCount, float $tolerance): bool
    {
        foreach ($clusters as $cluster) {
            $offset = ($cluster - $base) / $step;
            $column = (int) round($offset);

            if ($column > $columnCount - 1) {
                return false;
            }

            if (abs($offset - $column) * $step > $tolerance) {
                return false;
            }
        }

        return true;
    }
}
