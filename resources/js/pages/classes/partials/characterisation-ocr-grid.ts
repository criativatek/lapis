/**
 * Turns tesseract.js's flat list of recognised words (each with a bounding
 * box and a confidence) into the row/column grid an ExtractedTable needs.
 *
 * Why not just use tesseract's own line/paragraph segmentation? Because a
 * scanned table's "line" grouping is exactly what breaks on a photographed
 * page: a slightly rotated phone photo puts words from the same visual row
 * at different y-baselines, and tesseract's paragraph detector has no notion
 * of "these five words two centimetres apart are five different columns,
 * not one sentence with big spaces". Reconstructing rows from vertical
 * bounding-box OVERLAP (robust to wobble) and columns from x-coordinate
 * CLUSTERING across the whole image (consistent gaps repeat; prose doesn't)
 * is what actually recovers a grid.
 *
 * This module is deliberately pure — no tesseract.js import, no browser
 * API — so grid reconstruction can be unit-tested against hand-built word
 * boxes instead of real OCR output (real OCR is slow, non-deterministic
 * across platforms, and the thing under test here is the geometry, not
 * tesseract's model).
 */

export type OcrWord = {
    text: string;
    confidence: number; // tesseract's 0..100 word confidence, as reported
    bbox: { x0: number; y0: number; x1: number; y1: number };
};

export type OcrCell = {
    /** Joined with spaces within a visual line, and '\n' across a detected
     * intra-cell line break — never a value tesseract didn't produce. */
    text: string;
    /** Lowest word confidence contributing to this cell, 0..1 — the cell is
     * only as trustworthy as its least confident word. Empty cell → null:
     * there is no confidence question for a cell nothing was read into. */
    confidence: number | null;
};

export type OcrGrid = {
    /** rows[r][c] — always rectangular; a column with no word in a given
     * row is an empty OcrCell, never a gap that shifts later columns. */
    rows: OcrCell[][];
};

/**
 * A word belongs to a row if its vertical span overlaps a majority of the
 * row's own span. Threshold chosen loosely (any overlap starts a
 * comparison; the fraction only matters when a word straddles two rows) —
 * table rows in a photographed page are rarely closer together than half a
 * word's own height, so this does not need to be stricter to be reliable.
 */
type VerticalSpan = { y0: number; y1: number };

function verticalOverlap(a: VerticalSpan, b: VerticalSpan): number {
    const top = Math.max(a.y0, b.y0);
    const bottom = Math.min(a.y1, b.y1);
    const overlap = Math.max(0, bottom - top);
    const shorter = Math.min(a.y1 - a.y0, b.y1 - b.y0);

    return shorter <= 0 ? 0 : overlap / shorter;
}

/**
 * Two words belong to the same ROW BAND if their boxes overlap, or if the
 * gap between them is no more than one line-height — the second half is
 * what lets a cell that legitimately wraps to a second line (see
 * joinCellWords) still land in the same table row as its neighbours,
 * instead of clustering as a phantom extra row. joinCellWords then decides,
 * within that shared band, whether the gap was big enough to be a genuine
 * new line inside the cell.
 */
function sameRowBand(a: VerticalSpan, b: VerticalSpan): boolean {
    if (verticalOverlap(a, b) > 0.3) {
        return true;
    }

    const gap = a.y0 >= b.y1 ? a.y0 - b.y1 : b.y0 - a.y1;
    const shorter = Math.min(a.y1 - a.y0, b.y1 - b.y0);

    return shorter > 0 && gap <= shorter;
}

/**
 * Clusters words into rows by vertical overlap, then sorts each row
 * left-to-right. A single-linkage pass (grow each row's own y-band as words
 * join it) is deliberately used instead of a fixed-height bucket — a fixed
 * bucket assumes every line in the image is exactly N pixels tall, which is
 * false the moment the photo has any perspective skew across its height.
 */
function clusterIntoRows(words: OcrWord[]): OcrWord[][] {
    const sorted = [...words].sort((a, b) => a.bbox.y0 - b.bbox.y0);
    const rows: { band: { y0: number; y1: number }; words: OcrWord[] }[] = [];

    for (const word of sorted) {
        const row = rows.find((candidate) => sameRowBand(candidate.band, word.bbox));

        if (row) {
            row.words.push(word);
            // Grow the band to the union, not the average — a wobbly
            // baseline is exactly what this whole function exists to
            // tolerate, and shrinking the band back down would start
            // rejecting the very words it just accepted.
            row.band = {
                y0: Math.min(row.band.y0, word.bbox.y0),
                y1: Math.max(row.band.y1, word.bbox.y1),
            };
        } else {
            rows.push({ band: { y0: word.bbox.y0, y1: word.bbox.y1 }, words: [word] });
        }
    }

    return rows
        .sort((a, b) => a.band.y0 - b.band.y0)
        .map((row) => row.words.sort((a, b) => a.bbox.x0 - b.bbox.x0));
}

/**
 * Infers column boundaries from x-coordinates across every row at once. A
 * word's left edge (x0) is the signal, not its centre or right edge: a
 * short code left-aligned in a wide column and a long sentence in the next
 * column both start their cell at the same x, which is exactly the
 * alignment a table column guarantees and prose does not.
 *
 * Boundaries come from a simple 1-D clustering of x0 values: sort them,
 * and cut wherever the gap between consecutive values exceeds `gapPx` —
 * gaps that repeat across many rows are column starts; gaps from ordinary
 * word spacing inside one cell are far smaller and never survive being
 * measured against the WHOLE image's word starts, only against neighbours.
 */
function inferColumnStarts(words: OcrWord[], gapPx: number): number[] {
    const xs = [...new Set(words.map((word) => word.bbox.x0))].sort((a, b) => a - b);

    if (xs.length === 0) {
        return [];
    }

    const starts: number[] = [xs[0]];

    for (let index = 1; index < xs.length; index += 1) {
        if (xs[index] - xs[index - 1] > gapPx) {
            starts.push(xs[index]);
        }
    }

    return starts;
}

function columnIndexFor(x0: number, columnStarts: number[]): number {
    // The last boundary at or before this word's start — a word never
    // belongs to a column that starts to its right.
    let index = 0;

    for (let candidate = 0; candidate < columnStarts.length; candidate += 1) {
        if (columnStarts[candidate] <= x0) {
            index = candidate;
        }
    }

    return index;
}

/**
 * @param gapPx Minimum x0-to-x0 gap treated as a column boundary rather than
 *   ordinary word spacing within one cell. Callers pass a value derived from
 *   the image's own scale (see characterisation-image-extraction.ts) rather
 *   than a hardcoded pixel count, because a phone photo and a cropped
 *   screenshot of the same table are not the same number of pixels wide.
 */
export function buildGridFromWords(words: OcrWord[], gapPx: number): OcrGrid {
    if (words.length === 0) {
        return { rows: [] };
    }

    const rows = clusterIntoRows(words);
    const columnStarts = inferColumnStarts(words, gapPx);
    const columnCount = Math.max(1, columnStarts.length);

    const grid: OcrCell[][] = rows.map((rowWords) => {
        const cells: (OcrWord[] | null)[] = new Array(columnCount).fill(null);

        for (const word of rowWords) {
            const columnIndex = columnIndexFor(word.bbox.x0, columnStarts);
            cells[columnIndex] ??= [];
            (cells[columnIndex] as OcrWord[]).push(word);
        }

        return cells.map((cellWords): OcrCell => {
            // An empty cell is a real, structural empty cell — never
            // dropped, never causing the next column's word to slide left
            // to fill the gap. The whole reason columns are computed from
            // the image once, rather than "the next word wins the next
            // column", is to make this possible.
            if (cellWords === null || cellWords.length === 0) {
                return { text: '', confidence: null };
            }

            return joinCellWords(cellWords);
        });
    });

    return { rows: grid };
}

/**
 * Joins a cell's words left-to-right with spaces, but starts a new line
 * within the cell when the vertical gap between consecutive words is large
 * relative to their own height — a two-line note inside one table cell,
 * which a plain left-to-right word join would otherwise flatten into one
 * run-on line.
 */
function joinCellWords(cellWords: OcrWord[]): OcrCell {
    const sorted = [...cellWords].sort((a, b) => a.bbox.y0 - b.bbox.y0 || a.bbox.x0 - b.bbox.x0);
    const lines: OcrWord[][] = [];

    for (const word of sorted) {
        const currentLine = lines[lines.length - 1];
        const previous = currentLine?.[currentLine.length - 1];

        if (previous && word.bbox.y0 - previous.bbox.y1 > (previous.bbox.y1 - previous.bbox.y0) * 0.6) {
            lines.push([word]);
        } else if (currentLine) {
            currentLine.push(word);
        } else {
            lines.push([word]);
        }
    }

    const text = lines
        .map((line) => line.sort((a, b) => a.bbox.x0 - b.bbox.x0).map((word) => word.text).join(' '))
        .join('\n');

    // Never smoothed, never auto-corrected — the lowest confidence among
    // the words that make up this cell IS the cell's confidence. A
    // low-confidence word must stay visible as low confidence, not be
    // averaged away by a confident neighbour.
    const confidence = Math.min(...cellWords.map((word) => word.confidence)) / 100;

    return { text, confidence };
}
