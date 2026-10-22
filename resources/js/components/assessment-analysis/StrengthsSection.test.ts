import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { ClassStrengths, StrengthsRow } from '@/lib/classStrengths';
import StrengthsSection from './StrengthsSection.vue';

/**
 * PONTOS FORTES E DIFICULDADES — um momento da turma.
 *
 * O QUE ESTES TESTES DEFENDEM: que a página diz sempre «k de n alunos»; que um empate não destaca
 * ninguém e é dito; que sem linha entre positivo e negativo não se contam alunos abaixo dela e o
 * motivo aparece; que a lista nominal só tem os alunos abaixo do nível positivo em mais de um
 * domínio, com linguagem que não diagnostica; e que a origem dos dados e as cautelas estão à vista.
 */

const MOMENT = {
    key: 'period:p2', kind: 'period' as const, label: '2.º Semestre', ordinal: 2,
    basis: { kind: 'live' as const, label: 'Calculado com os dados de hoje', as_of: null },
    students_total: 27, students_with_result: 25, partial_coverage: 3, few_students: false,
};

function row(name: string, mean: string | null, withValue: number, below: number | null, state: StrengthsRow['coverage']['state'] = 'complete', partial = 0): StrengthsRow {
    return {
        ulid: `d-${name}`, name,
        students: { with_value: withValue, of: 27 },
        mean,
        below_positive: below === null ? null : { count: below, of: withValue, share: withValue === 0 ? '0.0' : ((below / withValue) * 100).toFixed(1) },
        coverage: { state, partial },
    };
}

function strengths(over: Partial<ClassStrengths> = {}): ClassStrengths {
    return {
        state: 'ready', reason: null,
        options: [{ ...MOMENT, key: 'period:p1', label: '1.º Semestre', ordinal: 1 }, MOMENT],
        selection: { moment: MOMENT.key, defaulted: true },
        moment: MOMENT,
        basis_note: 'Este momento é calculado com os dados de hoje: se uma nota for corrigida, estes números podem mudar.',
        cautions: [],
        scale: null,
        negative_line: { known: true, reason: null, text: null, negative_levels: ['Insuficiente', 'Fraco'] },
        result: { ulid: null, name: null, students: { with_value: 25, of: 27 }, mean: '68.4', below_positive: { count: 4, of: 25, share: '16.0' }, coverage: { state: 'incomplete', partial: 3 } },
        domains: [row('Escrita', '77.5', 25, 2), row('Leitura', '61.2', 22, 8, 'incomplete', 3), row('Oralidade', null, 0, null, 'none')],
        ranking: {
            state: 'ok',
            highest_mean: '77.5',
            lowest_mean: '61.2',
            highest: { ulid: 'd-Escrita', name: 'Escrita', mean: '77.5', students: 25, few_students: false },
            highest_tie: [],
            lowest: { ulid: 'd-Leitura', name: 'Leitura', mean: '61.2', students: 22, few_students: false },
            lowest_tie: [],
        },
        students_below: {
            known: true, of: 25, none: 17, one: 5, two_or_more: 3,
            students: [
                { enrollment_ulid: 'e1', name: 'Ana Marques', class_number: 1, domains_with_value: 3, domains: [
                    { ulid: 'd-Leitura', name: 'Leitura', value: '40.0', band: { code: '2', label: 'Insuficiente', sequence: 2, is_negative: true } },
                    { ulid: 'd-Escrita', name: 'Escrita', value: '45.0', band: { code: '2', label: 'Insuficiente', sequence: 2, is_negative: true } },
                ] },
            ],
        },
        highlights: {
            strengths: [{ key: 'highest_mean', text: '«Escrita» tem a média mais alta dos domínios: 77,5% (a mais baixa é 61,2%); 25 aluno(s) com valor.', evidence: { domain: 'd-Escrita', count: 25, of: 27 } }],
            attention: [
                { key: 'lowest_mean', text: '«Leitura» tem a média mais baixa dos domínios: 61,2% (a mais alta é 77,5%); 22 aluno(s) com valor.', evidence: { domain: 'd-Leitura', count: 22, of: 27 } },
                { key: 'below_in_two_or_more', text: '3 de 25 aluno(s) com resultado abaixo do nível positivo em dois ou mais domínios.', evidence: { domain: null, count: 3, of: 25 } },
            ],
        },
        explanations: [{ key: 'below', title: 'O que é «abaixo do nível positivo»', text: 'Descreve uma nota numa escala: não é um diagnóstico de dificuldades de aprendizagem.' }],
        ...over,
    };
}

function mountSection(data: ClassStrengths) {
    return mount(StrengthsSection, { props: { strengths: data } });
}

describe('StrengthsSection', () => {
    it('says every count with the total it rests on', () => {
        const wrapper = mountSection(strengths());
        const table = wrapper.find('[data-test="strengths-domains"]').text();

        expect(table).toContain('25 de 27 alunos');
        expect(table).toContain('2 de 25 alunos (8,0%)');
        expect(table).toContain('8 de 22 alunos (36,4%)');
        expect(wrapper.find('[data-test="strengths-result"]').text()).toContain('25 de 27 alunos com resultado');
        expect(wrapper.find('[data-test="strengths-students-summary"]').text()).toContain('25 alunos com valor em pelo menos um domínio');
    });

    it('marks the highest and the lowest mean, and only those', () => {
        const marks = mountSection(strengths()).findAll('[data-test="strengths-mark"]').map((mark) => mark.text());

        expect(marks).toEqual(['Média mais alta', 'Média mais baixa']);
    });

    it('on a tie highlights nobody and says so', () => {
        const wrapper = mountSection(strengths({
            ranking: { state: 'ok', highest_mean: '77.5', lowest_mean: '61.2', highest: null, highest_tie: ['Escrita', 'Leitura'], lowest: null, lowest_tie: ['Oralidade', 'Gramática'] },
        }));

        expect(wrapper.findAll('[data-test="strengths-mark"]')).toHaveLength(0);
        expect(wrapper.find('[data-test="strengths-tie-high"]').text()).toContain('Empate na média mais alta: Escrita e Leitura. Nenhum é destacado.');
        expect(wrapper.find('[data-test="strengths-tie-low"]').text()).toContain('Empate na média mais baixa: Oralidade e Gramática. Nenhum é destacado.');
    });

    it('with fewer than two domains with a mean says there is nothing to rank', () => {
        const wrapper = mountSection(strengths({ ranking: { state: 'insufficient', highest_mean: null, lowest_mean: null, highest: null, highest_tie: [], lowest: null, lowest_tie: [] } }));

        expect(wrapper.find('[data-test="strengths-ranking-insufficient"]').exists()).toBe(true);
    });

    it('says the coverage of each domain in words, a domain with no result included', () => {
        const wrapper = mountSection(strengths());

        expect(wrapper.find('[data-test="strengths-domain-none"]').text()).toContain('Oralidade');
        expect(wrapper.find('[data-test="strengths-domain-none"]').text()).toContain('Sem resultados');
        expect(wrapper.find('[data-test="strengths-domain-incomplete"]').text().replace(/\s+/g, ' ')).toContain('Incompleta · parcial em 3');
        // A domain with no result shows a dash, never a zero.
        expect(wrapper.find('[data-test="strengths-domain-none"]').text()).not.toContain('0,0%');
    });

    it('lists strengths and what to observe, each as sent, and an empty list as a sentence', () => {
        const wrapper = mountSection(strengths());

        expect(wrapper.find('[data-test="strengths-list"]').text()).toContain('«Escrita» tem a média mais alta dos domínios: 77,5%');
        expect(wrapper.find('[data-test="attention-list"]').text()).toContain('3 de 25 aluno(s) com resultado abaixo do nível positivo em dois ou mais domínios.');

        const empty = mountSection(strengths({ highlights: { strengths: [], attention: [] } }));
        expect(empty.find('[data-test="strengths-list"]').text()).toContain('Nada a destacar com os dados deste momento.');
        expect(empty.find('[data-test="attention-list"]').text()).toContain('Nada a destacar com os dados deste momento.');
    });

    it('names only the students below the positive level in two or more domains, with the facts and a respectful frame', () => {
        const wrapper = mountSection(strengths());
        const list = wrapper.find('[data-test="strengths-students"]').text();

        expect(list).toContain('Ana Marques');
        expect(list).toContain('2 de 3 domínios com valor');
        expect(list).toContain('Leitura: 40,0% · Insuficiente');
        expect(wrapper.text()).toContain('descreve uma nota; não é um diagnóstico');
        expect(wrapper.find('[data-test="strengths-students-summary"]').text()).toContain('17 em nenhum domínio abaixo do nível positivo');
    });

    it('without a line between positive and negative counts nobody below it and says why', () => {
        const wrapper = mountSection(strengths({
            negative_line: { known: false, reason: 'scale_unknown', text: 'Este momento foi guardado antes de a escala viajar com ele: não se sabe onde ficava a linha entre positivo e negativo.', negative_levels: [] },
            domains: [row('Escrita', '77.5', 25, null)],
            result: { ulid: null, name: null, students: { with_value: 25, of: 27 }, mean: '68.4', below_positive: null, coverage: { state: 'incomplete', partial: 0 } },
            students_below: { known: false, of: 0, none: 0, one: 0, two_or_more: 0, students: [] },
        }));

        expect(wrapper.find('[data-test="strengths-no-line"]').text()).toContain('antes de a escala viajar');
        expect(wrapper.find('[data-test="strengths-students-unknown"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="strengths-students"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="strengths-domains"]').text()).not.toContain('abaixo');
    });

    it('says which levels are below the positive line on the moment\'s own scale', () => {
        expect(mountSection(strengths()).find('[data-test="strengths-line"]').text()).toContain('Insuficiente, Fraco');
    });

    it('says where the data come from and the cautions', () => {
        const wrapper = mountSection(strengths({ cautions: [{ code: 'few_students', text: 'Este momento tem resultados de apenas 3 aluno(s): leia as contagens com cautela.' }] }));
        const limits = wrapper.find('[data-test="strengths-limits"]').text();

        expect(limits).toContain('2.º Semestre: Calculado com os dados de hoje');
        expect(limits).toContain('25 de 27 alunos com resultado');
        expect(limits).toContain('3 com cobertura parcial');
        expect(limits).toContain('calculado com os dados de hoje');
        expect(limits).toContain('leia as contagens com cautela');
    });

    it('with no result anywhere says why instead of an empty analysis', () => {
        const wrapper = mountSection(strengths({
            state: 'unavailable', reason: 'Ainda não há resultados em nenhum momento: não há nada para analisar.',
            moment: null, options: [], domains: [], result: null,
        }));

        expect(wrapper.text()).toContain('Ainda não há resultados para analisar.');
        expect(wrapper.text()).toContain('não há nada para analisar');
        expect(wrapper.find('[data-test="strengths-domains"]').exists()).toBe(false);
    });

    it('asks for another moment, and does nothing when the same one is picked again', async () => {
        const wrapper = mountSection(strengths());

        await wrapper.find('[data-test="strengths-select"]').setValue('period:p2');
        expect(wrapper.emitted('select')).toBeUndefined();

        await wrapper.find('[data-test="strengths-select"]').setValue('period:p1');
        expect(wrapper.emitted('select')).toEqual([['period:p1']]);
    });

    it('follows the server when it falls back to another moment', async () => {
        const wrapper = mountSection(strengths());

        await wrapper.setProps({ strengths: strengths({ selection: { moment: 'period:p1', defaulted: true } }) });

        expect((wrapper.find('[data-test="strengths-select"]').element as HTMLSelectElement).value).toBe('period:p1');
    });

    it('explains the criteria in words', () => {
        expect(mountSection(strengths()).find('[data-test="strengths-how-below"]').text()).toContain('não é um diagnóstico');
    });
});
