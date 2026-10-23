import { describe, expect, it } from 'vitest';
import {
    A4_MAX_COLUMNS,
    illegibleScaleNotice,
    LEGIBLE_SCALE,
    momentsColumnCount,
    printScale,
    recommendedPaper,
    usableWidthPx,
} from './quadroPrint';

describe('recommendedPaper', () => {
    it('sends «Por domínio» to A3 whatever the column count', () => {
        expect(recommendedPaper('domains', 1)).toBe('A3');
    });

    it('keeps a narrow «Ao longo do ano» on A4 and widens at the threshold', () => {
        expect(recommendedPaper('moments', A4_MAX_COLUMNS)).toBe('A4');
        expect(recommendedPaper('moments', A4_MAX_COLUMNS + 1)).toBe('A3');
    });
});

describe('momentsColumnCount', () => {
    it('counts the student, each moment block and the formal indicator', () => {
        expect(momentsColumnCount(4, true)).toBe(1 + 4 * 3 + 3);
        expect(momentsColumnCount(4, false)).toBe(1 + 4 * 2 + 2);
    });

    it('drops columns when the quantitative values are hidden, which can bring it back to A4', () => {
        expect(recommendedPaper('moments', momentsColumnCount(4, true))).toBe('A3');
        expect(recommendedPaper('moments', momentsColumnCount(4, false))).toBe('A4');
    });
});

describe('usableWidthPx', () => {
    it('is wider on A3 than on A4, both landscape with 10 mm margins', () => {
        expect(usableWidthPx('A4')).toBeCloseTo(((297 - 20) * 96) / 25.4, 5);
        expect(usableWidthPx('A3')).toBeGreaterThan(usableWidthPx('A4'));
    });
});

describe('printScale', () => {
    it('does not touch a table that already fits', () => {
        expect(printScale(800, 1000)).toEqual({ scale: 1, legible: true });
        expect(printScale(1000, 1000)).toEqual({ scale: 1, legible: true });
    });

    it('shrinks to the usable width and never past it', () => {
        const { scale, legible } = printScale(2000, 1000);

        expect(scale).toBe(0.5);
        expect(legible).toBe(true);
        expect(2000 * scale).toBeLessThanOrEqual(1000);

        const odd = printScale(1234.5, 1047);
        expect(1234.5 * odd.scale).toBeLessThanOrEqual(1047);
    });

    it('still fits but flags it when the result is below the legibility floor', () => {
        const { scale, legible } = printScale(4000, 1000);

        expect(scale).toBe(0.25);
        expect(scale).toBeLessThan(LEGIBLE_SCALE);
        expect(legible).toBe(false);
    });

    it('never goes to zero and ignores invalid measurements', () => {
        expect(printScale(1_000_000, 1000).scale).toBeGreaterThan(0);
        expect(printScale(0, 1000)).toEqual({ scale: 1, legible: true });
        expect(printScale(Number.NaN, 1000)).toEqual({ scale: 1, legible: true });
        expect(printScale(1000, 0)).toEqual({ scale: 1, legible: true });
    });
});

describe('illegibleScaleNotice', () => {
    it('says the percentage and what the teacher can switch off', () => {
        const text = illegibleScaleNotice(0.384);

        expect(text).toContain('38 %');
        expect(text).toContain('desempenho acumulado');
    });
});
