import { describe, expect, it } from 'vitest';
import { illegibleScaleNotice, LEGIBLE_SCALE, printScale, usableWidthPx } from './statisticsPrint';

describe('usableWidthPx', () => {
    it('is the A4 portrait width minus the 16 mm side margins', () => {
        expect(usableWidthPx()).toBeCloseTo(((210 - 32) * 96) / 25.4, 5);
    });
});

describe('printScale', () => {
    it('does not touch a table that already fits', () => {
        expect(printScale(500, 672)).toEqual({ scale: 1, legible: true });
        expect(printScale(672, 672)).toEqual({ scale: 1, legible: true });
    });

    it('shrinks to the usable width and never past it', () => {
        const { scale, legible } = printScale(1000, 600);

        expect(scale).toBe(0.6);
        expect(legible).toBe(true);
        expect(1000 * scale).toBeLessThanOrEqual(600);

        const odd = printScale(1234.5, 672.3);
        expect(1234.5 * odd.scale).toBeLessThanOrEqual(672.3);
    });

    it('still fits but flags it below the legibility floor', () => {
        const { scale, legible } = printScale(2000, 600);

        expect(scale).toBe(0.3);
        expect(scale).toBeLessThan(LEGIBLE_SCALE);
        expect(legible).toBe(false);
    });

    it('never goes to zero and ignores invalid measurements', () => {
        expect(printScale(1_000_000, 600).scale).toBeGreaterThan(0);
        expect(printScale(0, 600)).toEqual({ scale: 1, legible: true });
        expect(printScale(Number.NaN, 600)).toEqual({ scale: 1, legible: true });
        expect(printScale(600, 0)).toEqual({ scale: 1, legible: true });
    });
});

describe('illegibleScaleNotice', () => {
    it('says the percentage', () => {
        expect(illegibleScaleNotice(0.384)).toContain('38 %');
    });
});
