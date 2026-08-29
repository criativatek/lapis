import { describe, expect, it } from 'vitest';
import { detectPersonalData } from './personalData';

/**
 * THE DETECTOR'S TWO FAILURE MODES, AND THE SECOND IS THE DANGEROUS ONE.
 *
 * Missing an e-mail is a miss. Firing on «Educação Literária» is worse: a guard
 * that interrupts ordinary pedagogical writing gets clicked through without
 * being read within a week, and then it protects nobody. So this file spends at
 * least as much effort on what must NOT be flagged as on what must.
 *
 * IT ALSO PINS THE LIMIT DOWN. Arbitrary personal names are not detected, on
 * purpose — see the module docblock — and there is a test that says so, because
 * a future reader could otherwise mistake the absence of that rule for a bug
 * and «fix» it by flagging capitalised words.
 */

const rules = (text: string, names: string[] = []) =>
    detectPersonalData(text, { knownNames: names }).map((finding) => finding.rule);

describe('detectPersonalData — what must be flagged', () => {
    it('flags an e-mail address', () => {
        expect(rules('Falar com ana.marques@escola.test sobre a leitura.')).toContain('emails');
    });

    it('flags a Portuguese telephone number, spaced or not', () => {
        expect(rules('Contacto 912345678.')).toContain('phone_numbers');
        expect(rules('Contacto 912 345 678.')).toContain('phone_numbers');
        expect(rules('Contacto +351912345678.')).toContain('phone_numbers');
    });

    it('flags a postal code', () => {
        expect(rules('Morada: Rua das Flores 12, 2400-123 Leiria.')).toContain('postal_codes');
    });

    it('flags a student or process number written as such', () => {
        expect(rules('O aluno n.º 12 faltou.')).toContain('record_numbers');
        expect(rules('Ver o nº 7 da pauta.')).toContain('record_numbers');
        expect(rules('Número 21 da turma.')).toContain('record_numbers');
    });

    it('flags a ULID and a UUID', () => {
        // A real ULID: 26 characters of Crockford base32, which excludes I, L,
        // O and U precisely so an identifier cannot be misread as a word.
        expect(rules('Ver 01M159J50APP8CWEQ33QPMFYB4 agora.')).toContain('identifiers');
        expect(rules('Ver 3f2504e0-4f89-41d3-9a0c-0305e82c3301 agora.')).toContain('identifiers');
    });

    it('flags a long run of digits', () => {
        expect(rules('Processo 20260012345.')).toContain('long_numbers');
    });

    it('flags a link', () => {
        expect(rules('Ver https://escola.test/aluno/12 para o detalhe.')).toContain('urls');
        expect(rules('Ver www.escola.test para o detalhe.')).toContain('urls');
    });

    it('reports each kind once, and never the matched value', () => {
        const findings = detectPersonalData('a@b.pt e c@d.pt e 912345678');

        expect(findings.filter((finding) => finding.rule === 'emails')).toHaveLength(1);

        for (const finding of findings) {
            expect(finding.label).not.toContain('@');
            expect(finding.label).not.toContain('912345678');
        }
    });
});

describe('detectPersonalData — what must NOT be flagged', () => {
    it('leaves ordinary pedagogical writing alone', () => {
        const sentences = [
            'Consolidar a organização e a coesão textual.',
            'Melhorar a leitura em voz alta ao longo do 2.º período.',
            'A turma manteve o desempenho no domínio da Escrita.',
            'Trabalhar a Educação Literária com textos mais longos.',
            'Rever a Gramática antes do teste de janeiro.',
        ];

        for (const sentence of sentences) {
            expect(detectPersonalData(sentence)).toEqual([]);
        }
    });

    /**
     * THE RULE THIS PRODUCT DELIBERATELY DOES NOT HAVE. «Leitura», «Português»,
     * «Setembro» and the first word of every sentence are capitalised, and a
     * name rule would fire on all of them.
     */
    it('does not treat a capitalised word as a name', () => {
        expect(detectPersonalData('Leitura e Escrita em Setembro.')).toEqual([]);
        expect(detectPersonalData('Trabalhar Oralidade no Português do 7.º ano.')).toEqual([]);
    });

    /**
     * AND IT DOES NOT DETECT AN ARBITRARY NAME EITHER. This is the limit the
     * Política de Privacidade states out loud: the guard reduces risk and does
     * not eliminate it. If somebody ever makes this test fail by adding a name
     * heuristic, the Policy and the Centro de Ajuda have to change on the same
     * day.
     */
    it('does not detect an arbitrary personal name', () => {
        expect(detectPersonalData('Como posso ajudar o João Silva?')).toEqual([]);
    });

    it('does not mistake grades, percentages or class numbers for identifiers', () => {
        expect(detectPersonalData('Passou de 72,4% para 80,1% neste período.')).toEqual([]);
        expect(detectPersonalData('O aluno 12 da pauta subiu para nível 4.')).toEqual([]);
        expect(detectPersonalData('Três domínios acima de 60 e um abaixo de 50.')).toEqual([]);
    });

    it('says nothing about empty or blank text', () => {
        expect(detectPersonalData('')).toEqual([]);
        expect(detectPersonalData('   \n  ')).toEqual([]);
    });
});

describe('detectPersonalData — the one name it can see', () => {
    it('flags a name the calling screen already had', () => {
        expect(rules('Como posso ajudar a Ana Marques?', ['Ana Marques'])).toContain('known_name');
    });

    it('flags a surname on its own, because that is how teachers write', () => {
        expect(rules('Falar com a Marques sobre a leitura.', ['Ana Marques'])).toContain('known_name');
    });

    it('is accent- and case-tolerant', () => {
        expect(rules('conversar com a joao pereira amanha', ['João Pereira'])).toContain('known_name');
    });

    /**
     * Short parts are skipped: «Ana» is a name and also a fragment of ordinary
     * Portuguese, and a two-letter particle would match every sentence.
     */
    it('does not fire on a short name part inside another word', () => {
        expect(rules('Analisar a coesão textual.', ['Ana Marques'])).not.toContain('known_name');
        expect(rules('Consolidar a leitura.', ['Rui de Sá'])).not.toContain('known_name');
    });

    it('flags nothing when the screen passes no names — the Centro de Ajuda case', () => {
        expect(detectPersonalData('Como posso ajudar a Ana Marques?')).toEqual([]);
    });
});
