import { describe, expect, it } from 'vitest';
import { capitalizeFirst } from './text';

describe('capitalizeFirst', () => {
    /**
     * The whole point of the helper: CSS `capitalize` uppercased every word,
     * including "de" and the half of the weekday after the hyphen.
     */
    it('uppercases only the first character of a pt-PT date', () => {
        expect(capitalizeFirst('quarta-feira, 9 de setembro de 2026')).toBe(
            'Quarta-feira, 9 de setembro de 2026',
        );
    });

    it('leaves the second half of a hyphenated weekday alone', () => {
        expect(capitalizeFirst('segunda-feira')).toBe('Segunda-feira');
        expect(capitalizeFirst('terça-feira')).toBe('Terça-feira');
    });

    it('handles an accented first character', () => {
        expect(capitalizeFirst('água')).toBe('Água');
        expect(capitalizeFirst('sábado')).toBe('Sábado');
    });

    it('leaves an already-capitalized string untouched', () => {
        expect(capitalizeFirst('Domingo')).toBe('Domingo');
    });

    it('does not lowercase anything, so later capitals survive', () => {
        expect(capitalizeFirst('teste de TPC')).toBe('Teste de TPC');
    });

    it('copes with an empty string', () => {
        expect(capitalizeFirst('')).toBe('');
    });
});
