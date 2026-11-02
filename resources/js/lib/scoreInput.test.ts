import { describe, expect, it } from 'vitest';
import { formatScoreInput, parseScore } from '@/lib/scoreInput';

describe('parseScore', () => {
    it.each([
        ['3,5', 3.5],
        ['3.5', 3.5],
        ['  3,5  ', 3.5],
        ['4', 4],
        ['0', 0],
        [',5', 0.5],
        ['.5', 0.5],
        ['-2', -2],
        ['-1,5', -1.5],
        ['3.333', 3.333],
        ['10,25', 10.25],
    ])('reads %j as %d', (raw, value) => {
        expect(parseScore(raw)).toEqual({ kind: 'number', value });
    });

    it.each(['', '   '])('treats %j as empty', (raw) => {
        expect(parseScore(raw)).toEqual({ kind: 'empty' });
    });

    it.each(['3,5,1', 'abc', '3.', '3,', '-', '--2', '1e3', '+2', '1 2', '1.2.3', ','])('rejects %j', (raw) => {
        expect(parseScore(raw)).toEqual({ kind: 'invalid' });
    });
});

describe('formatScoreInput', () => {
    it('uses a decimal comma and no useless trailing zeros', () => {
        expect(formatScoreInput(3.5)).toBe('3,5');
        expect(formatScoreInput(3.33)).toBe('3,33');
        expect(formatScoreInput(4)).toBe('4');
        expect(formatScoreInput(0)).toBe('0');
        expect(formatScoreInput(-1.5)).toBe('-1,5');
    });

    it('is empty for null', () => {
        expect(formatScoreInput(null)).toBe('');
    });
});
