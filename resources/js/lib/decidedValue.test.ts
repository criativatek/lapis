import { describe, expect, it } from 'vitest';
import { decidedScaleNote, decidedValueText } from './decidedValue';

describe('decidedValueText', () => {
    it('lê um valor decidido em percentagem como percentagem, sem os zeros do armazenamento', () => {
        expect(decidedValueText('65.000', true)).toBe('65%');
        expect(decidedValueText('72.500', true)).toBe('72,5%');
    });

    it('deixa o valor como veio quando a escala não é de percentagem', () => {
        expect(decidedValueText('16.000', false)).toBe('16.000');
        expect(decidedValueText('16.000', undefined)).toBe('16.000');
    });

    it('sem valor não há texto', () => {
        expect(decidedValueText(null, true)).toBeNull();
        expect(decidedValueText(undefined, false)).toBeNull();
    });
});

describe('decidedScaleNote', () => {
    it('diz a escala em que a decisão foi tomada', () => {
        expect(decidedScaleNote('Percentagem (0 a 100)')).toBe('Decidida na escala «Percentagem (0 a 100)» (versão anterior do perfil)');
    });

    it('não diz nada quando é a mesma escala', () => {
        expect(decidedScaleNote(null)).toBeNull();
        expect(decidedScaleNote(undefined)).toBeNull();
    });
});
