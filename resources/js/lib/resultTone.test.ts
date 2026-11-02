import { describe, expect, it } from 'vitest';
import { meanOf, NEGATIVE_BELOW_PERCENT, resultTone, resultToneClasses, resultToneTitle } from './resultTone';

describe('resultTone', () => {
    it('não tem tom sem valor', () => {
        expect(resultTone(null, 60)).toBeNull();
    });

    it('respeita o limiar exato de 49,5', () => {
        expect(NEGATIVE_BELOW_PERCENT).toBe(49.5);
        expect(resultTone(49.4999, null)).toBe('negative');
        expect(resultTone(49.5, null)).toBeNull();
        expect(resultTone(49.5000001, null)).toBeNull();
    });

    it('só é verde estritamente acima da média', () => {
        expect(resultTone(60, 60)).toBeNull();
        expect(resultTone(60.0001, 60)).toBe('above');
        expect(resultTone(70, null)).toBeNull();
    });

    it('vermelho prevalece sobre verde', () => {
        expect(resultTone(40, 30)).toBe('negative');
    });

    it('expõe classes e títulos para os dois tons', () => {
        expect(resultToneClasses.negative).toContain('text-red-700');
        expect(resultToneClasses.above).toContain('text-green-700');
        expect(resultToneTitle.negative).toBe('Inferior a 49,5 %');
        expect(resultToneTitle.above).toBe('Superior à média da turma');
    });
});

describe('meanOf', () => {
    it('conta o zero e exclui null', () => {
        expect(meanOf([0, 100, null])).toBe(50);
    });

    it('devolve null sem valores', () => {
        expect(meanOf([])).toBeNull();
        expect(meanOf([null, null])).toBeNull();
    });

    it('não arredonda', () => {
        expect(meanOf([1, 2, 2])).toBeCloseTo(5 / 3, 10);
    });
});
