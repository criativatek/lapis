import { describe, expect, it } from 'vitest';
import { combineSummaries, compareLines } from '@/lib/summaryMerge';

describe('compareLines', () => {
    it('assinala as linhas que só existem num dos textos', () => {
        expect(compareLines('Linha comum.\nSó minha.', 'Linha comum.\nSó gravada.')).toEqual([
            { text: 'Linha comum.', onlyHere: false },
            { text: 'Só minha.', onlyHere: true },
        ]);
    });

    it('ignora diferenças de espaços e linhas vazias nas pontas', () => {
        expect(compareLines('\n  Linha   comum. \n', 'Linha comum.')).toEqual([{ text: '  Linha   comum. ', onlyHere: false }]);
    });
});

describe('combineSummaries — o rascunho nunca perde nada', () => {
    it('acrescenta no fim as linhas gravadas que o rascunho ainda não tem', () => {
        expect(combineSummaries('O meu texto.\nLinha comum.', 'Linha comum.\nAcrescentada noutra janela.')).toBe(
            'O meu texto.\nLinha comum.\nAcrescentada noutra janela.',
        );
    });

    it('não duplica o que já está no rascunho', () => {
        expect(combineSummaries('A.\nB.', 'B.\nA.')).toBe('A.\nB.');
    });

    it('com o rascunho vazio, fica o texto gravado', () => {
        expect(combineSummaries('', 'Gravado.')).toBe('Gravado.');
    });
});
