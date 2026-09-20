import { describe, expect, it } from 'vitest';
import { buildGridFromWords  } from './characterisation-ocr-grid';
import type {OcrWord} from './characterisation-ocr-grid';

function word(text: string, x0: number, y0: number, width: number, height: number, confidence = 95): OcrWord {
    return { text, confidence, bbox: { x0, y0, x1: x0 + width, y1: y0 + height } };
}

describe('buildGridFromWords', () => {
    it('clusters words into rows even when baselines wobble slightly', () => {
        // Same visual row, but the second word's baseline drifts 4px down —
        // exactly the wobble a photographed (not scanned) page produces.
        const words = [
            word('Maria', 0, 100, 60, 20),
            word('Santos', 100, 104, 60, 20),
            word('João', 0, 200, 60, 20),
            word('Pinto', 100, 198, 60, 20),
        ];

        const grid = buildGridFromWords(words, 30);

        expect(grid.rows).toHaveLength(2);
        expect(grid.rows[0].map((cell) => cell.text)).toEqual(['Maria', 'Santos']);
        expect(grid.rows[1].map((cell) => cell.text)).toEqual(['João', 'Pinto']);
    });

    it('infers columns from x-alignment and leaves a missing word as an empty cell, never shifting the row', () => {
        const words = [
            // Row 1: all three columns present.
            word('Maria', 0, 0, 60, 20),
            word('ACN5', 200, 0, 60, 20),
            word('CRI', 400, 0, 60, 20),
            // Row 2: the middle column has nothing at x=200 — must stay
            // empty, not pull 'CRI' left into column 2.
            word('João', 0, 100, 60, 20),
            word('CRI', 400, 100, 60, 20),
        ];

        const grid = buildGridFromWords(words, 30);

        expect(grid.rows).toHaveLength(2);
        expect(grid.rows[0].map((cell) => cell.text)).toEqual(['Maria', 'ACN5', 'CRI']);
        expect(grid.rows[1].map((cell) => cell.text)).toEqual(['João', '', 'CRI']);
        expect(grid.rows[1][1].confidence).toBeNull();
    });

    it('never shifts subsequent columns when an early cell in the row is empty', () => {
        const words = [
            word('A', 0, 0, 40, 20),
            word('B', 150, 0, 40, 20),
            word('C', 300, 0, 40, 20),
            // Row 2 skips the FIRST column this time.
            word('Y', 150, 100, 40, 20),
            word('Z', 300, 100, 40, 20),
        ];

        const grid = buildGridFromWords(words, 30);

        expect(grid.rows[1].map((cell) => cell.text)).toEqual(['', 'Y', 'Z']);
    });

    it('passes a low-confidence token through byte-for-byte unchanged, flagged with its confidence', () => {
        // 'ACN5' misread with low confidence must never become 'ACNS' or any
        // other "corrected" guess — this module has no correction logic at
        // all, and this test pins that.
        const words = [word('ACN5', 0, 0, 60, 20, 41)];

        const grid = buildGridFromWords(words, 30);

        expect(grid.rows[0][0].text).toBe('ACN5');
        expect(grid.rows[0][0].confidence).toBeCloseTo(0.41);
    });

    it('preserves an intra-cell line break when the vertical gap indicates a new line within the same column', () => {
        const words = [
            word('Precisa', 0, 0, 100, 20),
            word('de apoio', 0, 40, 100, 20), // big vertical gap, same column
        ];

        const grid = buildGridFromWords(words, 30);

        expect(grid.rows[0][0].text).toBe('Precisa\nde apoio');
    });

    it('returns an empty grid for no words', () => {
        expect(buildGridFromWords([], 30)).toEqual({ rows: [] });
    });
});
