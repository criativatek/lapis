import { describe, expect, it } from 'vitest';
import { domainShort, groupShort, mentionShort, questionNumber, SUMMARY_SHORT, uniqueMentionShorts } from './abbreviations';

describe('questionNumber', () => {
    it('tira o prefixo e deixa o número', () => {
        expect(questionNumber('Q12')).toBe('12');
        expect(questionNumber('q3')).toBe('3');
        expect(questionNumber('Item 3')).toBe('3');
        expect(questionNumber('Pergunta 7')).toBe('7');
        expect(questionNumber('P2.1')).toBe('2.1');
        expect(questionNumber('Q 4a')).toBe('4a');
    });

    it('deixa intacto o que não é um prefixo seguido de dígito', () => {
        expect(questionNumber('2.1')).toBe('2.1');
        expect(questionNumber('A')).toBe('A');
        expect(questionNumber('Prova')).toBe('Prova');
        expect(questionNumber('')).toBe('');
    });
});

describe('groupShort', () => {
    it('usa o numeral do rótulo', () => {
        expect(groupShort('GRUPO V - ESCRITA')).toBe('G.V');
        expect(groupShort('Grupo 2')).toBe('G.2');
        expect(groupShort('grupo iii — leitura')).toBe('G.III');
    });

    it('sem numeral, iniciais até 4 letras, sem partículas', () => {
        expect(groupShort('Expressão escrita')).toBe('EE');
        expect(groupShort('Compreensão do oral e da escrita')).toBe('COE');
        expect(groupShort('Um Dois Três Quatro Cinco')).toBe('UDTQ');
    });

    it('vazio ou nulo fica vazio', () => {
        expect(groupShort('')).toBe('');
        expect(groupShort(null)).toBe('');
    });
});

describe('domainShort', () => {
    it('uma palavra: quatro letras e ponto, ou a palavra inteira se for curta', () => {
        expect(domainShort('Oralidade')).toBe('Oral.');
        expect(domainShort('Leitura')).toBe('Leit.');
        expect(domainShort('Escrita')).toBe('Escr.');
        expect(domainShort('Gramática')).toBe('Gram.');
        expect(domainShort('Arte')).toBe('Arte');
    });

    it('várias palavras: três letras e ponto por palavra, sem partículas', () => {
        expect(domainShort('Educação Literária')).toBe('Edu. Lit.');
        expect(domainShort('Compreensão do Oral')).toBe('Com. Ora.');
    });

    it('evita colidir com siglas já atribuídas acrescentando letras', () => {
        expect(domainShort('Geometria', ['Geom.'])).toBe('Geome.');
        expect(domainShort('Geometria', ['Geom.', 'Geome.'])).toBe('Geomet.');
    });
});

describe('mentionShort', () => {
    it('iniciais das palavras com maiúscula', () => {
        expect(mentionShort('Muito Bom')).toBe('MB');
        expect(mentionShort('Insuficiente')).toBe('I');
        expect(mentionShort('Suficiente')).toBe('S');
        expect(mentionShort('Bom')).toBe('B');
        expect(mentionShort('Não Satisfaz')).toBe('NS');
        expect(mentionShort('Satisfaz bem')).toBe('SB');
    });
});

describe('uniqueMentionShorts', () => {
    it('siglas quando não colidem', () => {
        const map = uniqueMentionShorts([
            { code: '1', label: 'Insuficiente' },
            { code: '3', label: 'Suficiente' },
            { code: '4', label: 'Bom' },
            { code: '5', label: 'Muito Bom' },
        ]);

        expect(map.get('Muito Bom')).toBe('MB');
        expect(map.get('Insuficiente')).toBe('I');
    });

    it('códigos para TODAS quando duas colidem', () => {
        const map = uniqueMentionShorts([
            { code: '2', label: 'Satisfaz' },
            { code: '3', label: 'Satisfaz Bem' },
            { code: '4', label: 'Satisfaz Bastante' },
        ]);

        expect(map.get('Satisfaz')).toBe('2');
        expect(map.get('Satisfaz Bem')).toBe('3');
        expect(map.get('Satisfaz Bastante')).toBe('4');
    });
});

describe('SUMMARY_SHORT', () => {
    it('as colunas-resumo fixas', () => {
        expect(SUMMARY_SHORT).toEqual({
            raw: 'Bruta',
            result: 'Res.',
            level: 'Nív.',
            weighted_average: 'Média',
            assigned_level: 'Nível',
        });
    });
});
