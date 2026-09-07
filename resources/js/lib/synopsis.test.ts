import { describe, expect, it } from 'vitest';
import {
    appreciationClasses,
    appreciationTitle,
    percent,
    shortName,
    trendGlyph,
    trendTitle,
    trendTone,
} from '@/lib/synopsis';
import type { Appreciation, AppreciationValue, ScaleBand, SynopticTrend } from '@/lib/synopsis';

/**
 * O VOCABULÁRIO DO QUADRO SÍNTESE — as regras que a tabela e a legenda partilham.
 *
 * Duas delas são as que o produto não pode perder de vista:
 *
 *  1. A COR VEM DA POSIÇÃO NA ESCALA, nunca do número. Uma escala sem números
 *     nenhuns pinta-se exatamente como uma de 1 a 5.
 *  2. A COR NUNCA É A ÚNICA INFORMAÇÃO. Há sempre uma frase por trás, e ela diz
 *     de quem é o juízo — do professor ou do Lapispro.
 */

/** Uma escala de níveis com números, e outra sem nenhum. */
const NUMERIC: ScaleBand[] = [
    { code: '1', label: 'Muito Insuficiente', sequence: 1, is_negative: true },
    { code: '2', label: 'Insuficiente', sequence: 2, is_negative: true },
    { code: '3', label: 'Suficiente', sequence: 3, is_negative: false },
    { code: '4', label: 'Bom', sequence: 4, is_negative: false },
    { code: '5', label: 'Muito Bom', sequence: 5, is_negative: false },
];

const QUALITATIVE: ScaleBand[] = [
    { code: 'NS', label: 'Não Satisfaz', sequence: 1, is_negative: true },
    { code: 'S', label: 'Satisfaz', sequence: 2, is_negative: false },
    { code: 'SB', label: 'Satisfaz Bastante', sequence: 3, is_negative: false },
];

function appreciation(code: string, bands: ScaleBand[], origin: AppreciationValue['origin'] = 'proposed'): Appreciation {
    const band = bands.find((candidate) => candidate.code === code)!;

    return {
        origin,
        code: band.code,
        label: band.label,
        text: band.code,
        sequence: band.sequence,
        is_negative: band.is_negative,
    };
}

describe('a cor de uma apreciação', () => {
    it('vem da posição do nível na escala e nunca do número que ele tem', () => {
        // Numa escala de 1 a 5 o topo é verde e o fundo é vermelho.
        expect(appreciationClasses(appreciation('5', NUMERIC), NUMERIC)).toContain('emerald');
        expect(appreciationClasses(appreciation('1', NUMERIC), NUMERIC)).toContain('red');

        // E numa escala QUALITATIVA, sem número nenhum, exatamente a mesma
        // leitura: «SB» é o topo, e é verde, sem que nada tenha de saber o que
        // «SB» quer dizer (§24).
        expect(appreciationClasses(appreciation('SB', QUALITATIVE), QUALITATIVE)).toContain('emerald');
        expect(appreciationClasses(appreciation('NS', QUALITATIVE), QUALITATIVE)).toContain('red');
    });

    it('não pinta o que não consegue colocar na escala', () => {
        // Uma fotografia guardada com uma escala que entretanto mudou traz um
        // nível cuja posição já não existe. Sem cor é honesto; uma cor
        // adivinhada seria uma afirmação que ninguém fez (§15).
        const orphan: Appreciation = {
            origin: 'proposed',
            code: 'X',
            label: 'Nível de outra escala',
            text: 'X',
            sequence: null,
            is_negative: null,
        };

        expect(appreciationClasses(orphan, NUMERIC)).toBe('text-muted-foreground');
        expect(appreciationClasses(null, NUMERIC)).toBe('text-muted-foreground');
    });
});

describe('a frase de uma apreciação', () => {
    it('diz de quem é o juízo, e não só qual é', () => {
        // A distinção que no ecrã se faz por negrito tem de chegar a quem não o
        // vê (§25).
        expect(appreciationTitle(appreciation('4', NUMERIC, 'decided'))).toBe('Decisão do professor: 4 — Bom');

        // E UMA PROPOSTA POR DOMÍNIO É VIGENTE, não pendente: quem não a
        // alterou aceitou-a. A frase antiga — «ainda não alterada» — descrevia
        // uma espera que não existe.
        expect(appreciationTitle(appreciation('4', NUMERIC, 'proposed'))).toBe(
            'Proposta do Lapispro, vigente: 4 — Bom',
        );
        expect(appreciationTitle(appreciation('4', NUMERIC, 'proposed'))).not.toContain('ainda não');
    });

    it('nomeia o domínio quando a célula é de um domínio', () => {
        expect(appreciationTitle(appreciation('3', NUMERIC), 'Leitura')).toBe(
            'Leitura · Proposta do Lapispro, vigente: 3 — Suficiente',
        );
    });

    it('diz que não há apreciação em vez de ficar em silêncio', () => {
        expect(appreciationTitle(null)).toBe('Sem apreciação neste momento.');
        expect(appreciationTitle(null, 'Escrita')).toBe('Escrita: sem apreciação neste momento.');
    });
});

describe('a tendência', () => {
    const evolution: SynopticTrend = {
        direction: 'up',
        label: 'Evolução',
        from: { code: '3', label: 'Suficiente' },
        to: { code: '4', label: 'Bom' },
        from_moment: 'Intercalar 1.º Semestre',
    };

    it('tem sempre a palavra e o trajeto, e não só a seta', () => {
        // Uma seta sozinha não diz de onde para onde, nem desde quando (§27).
        expect(trendGlyph(evolution)).toBe('↑');
        expect(trendTitle(evolution)).toBe('Evolução: Suficiente → Bom desde Intercalar 1.º Semestre.');
    });

    it('distingue manutenção de regressão', () => {
        const flat: SynopticTrend = { ...evolution, direction: 'flat', label: 'Manutenção', to: { code: '3', label: 'Suficiente' } };
        const down: SynopticTrend = { ...evolution, direction: 'down', label: 'Regressão', to: { code: '2', label: 'Insuficiente' } };

        expect(trendGlyph(flat)).toBe('→');
        expect(trendGlyph(down)).toBe('↓');
        expect(trendTitle(down)).toContain('Regressão');
    });

    it('pinta o movimento com uma tinta que não é a do desempenho', () => {
        // Um aluno que subiu de Insuficiente para Suficiente evoluiu e continua
        // a precisar de apoio; a cor da tendência e a cor do nível dizem coisas
        // diferentes e não podem partilhar a mesma tinta.
        expect(trendTone(evolution)).toContain('emerald-600');
        expect(trendTone({ ...evolution, direction: 'down', label: 'Regressão' })).toContain('rose-600');
        expect(trendTone({ ...evolution, direction: 'flat', label: 'Manutenção' })).toBe('text-muted-foreground');
    });

    it('não inventa movimento onde não há termo de comparação', () => {
        expect(trendGlyph(null)).toBe('');
        expect(trendTitle(null)).toBeUndefined();
    });
});

describe('os números e os nomes', () => {
    it('escreve uma percentagem em português e uma ausência como ausência', () => {
        expect(percent('91.302083')).toBe('91,3 %');
        // «—», nunca 0: um elemento por realizar não é uma classificação de zero.
        expect(percent(null)).toBe('—');
        expect(percent(undefined)).toBe('—');
    });

    it('encurta um nome ao primeiro e ao último, que é como se chama alguém', () => {
        expect(shortName('Álvaro Simões Ribeiro Costa')).toBe('Álvaro Costa');
        expect(shortName('Marta Tomás')).toBe('Marta Tomás');
        // Um nome de um só elemento não tem o que encurtar.
        expect(shortName('Rita')).toBe('Rita');
        expect(shortName('  João   Silva  ')).toBe('João Silva');
    });
});
