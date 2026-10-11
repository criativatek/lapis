import { describe, expect, it } from 'vitest';
import {
    isSearchableTerm,
    needsSubject,
    parseSummarySearchState,
    summarySearchRequestQuery,
    summarySearchScope,
    summarySearchUrl,
} from './lessonSummarySearch';
import type { SummarySearchState } from './lessonSummarySearch';
import type { TeacherClass } from './lessonWeekView';

const base: SummarySearchState = {
    term: 'frações equivalentes',
    classes: [],
    states: 'taught',
    other: false,
    from: null,
    to: null,
    page: 1,
};

const classes: TeacherClass[] = [
    { ulid: 'a', label: '7.º A', subject: 'Matemática', is_support_class: false, archived: false, identity_tone: null, groups: [] },
    { ulid: 'b', label: '8.º B', subject: 'Matemática', is_support_class: false, archived: true, identity_tone: null, groups: [] },
];

describe('o estado da pesquisa no URL', () => {
    it('sem `q` (ou só com espaços) não se está a pesquisar', () => {
        expect(parseSummarySearchState('/lessons?week=2026-10-05&view=semana')).toBeNull();
        expect(parseSummarySearchState('/lessons?q=%20%20')).toBeNull();
    });

    it('lê a expressão, as turmas, os estados, o período e a página — e ignora o que não reconhece', () => {
        expect(
            parseSummarySearchState('/lessons?week=2026-10-05&q=fra%C3%A7%C3%B5es&q_classes=a,b&q_states=both&q_other=1&q_from=2026-10-01&q_to=nada&q_page=3'),
        ).toEqual({ term: 'frações', classes: ['a', 'b'], states: 'both', other: true, from: '2026-10-01', to: null, page: 3 });
        expect(parseSummarySearchState('/lessons?q=x&q_states=todas&q_page=-2')).toMatchObject({ states: 'taught', page: 1 });
    });

    it('escreve a pesquisa POR CIMA do calendário e retira-a sem tocar em mais nada', () => {
        const calendar = '/lessons?week=2026-10-05&view=turma&class=a&classes=a&states=prepared&density=compacto';
        const searching = summarySearchUrl(calendar, { ...base, classes: ['a'], page: 2, from: '2026-10-01' });

        expect(searching).toBe(
            '/lessons?week=2026-10-05&view=turma&class=a&classes=a&states=prepared&density=compacto&q=fra%C3%A7%C3%B5es+equivalentes&q_classes=a&q_states=taught&q_from=2026-10-01&q_page=2',
        );
        expect(summarySearchUrl(searching, null)).toBe(calendar);
        expect(parseSummarySearchState(searching)?.term).toBe('frações equivalentes');
    });

    it('o pedido ao servidor diz os estados por extenso, e as outras ocorrências só quando pedidas', () => {
        expect(new URLSearchParams(summarySearchRequestQuery(base)).get('states')).toBe('taught');
        expect(new URLSearchParams(summarySearchRequestQuery({ ...base, states: 'both', other: true })).get('states')).toBe('taught,prepared,other');
        expect(Object.fromEntries(new URLSearchParams(summarySearchRequestQuery({ ...base, term: '  equações  ', classes: ['a'], to: '2026-12-31' })))).toEqual({
            q: 'equações',
            classes: 'a',
            states: 'taught',
            to: '2026-12-31',
            page: '1',
        });
    });
});

describe('o que vale a pena pedir', () => {
    it('pelo menos dois caracteres, sem contar as aspas à volta nem os espaços', () => {
        expect(isSearchableTerm('f')).toBe(false);
        expect(isSearchableTerm('  f  ')).toBe(false);
        expect(isSearchableTerm('"f"')).toBe(false);
        expect(isSearchableTerm('«a»')).toBe(false);
        expect(isSearchableTerm('fr')).toBe(true);
        expect(isSearchableTerm('“frações equivalentes”')).toBe(true);
    });
});

describe('o âmbito, dito por inteiro', () => {
    it('ano letivo, todas as turmas e só as lecionadas, por omissão', () => {
        expect(summarySearchScope('2026/2027', classes, base)).toEqual(['Ano letivo 2026/2027', 'Todas as turmas', 'Aulas lecionadas']);
    });

    it('as turmas escolhidas, os estados e o período', () => {
        expect(summarySearchScope('2026/2027', classes, { ...base, classes: ['b', 'a'], states: 'both', from: '2026-10-01', to: '2026-10-31' })).toEqual([
            'Ano letivo 2026/2027',
            '8.º B, 7.º A',
            'Aulas lecionadas e preparadas',
            'De 01/10/2026 a 31/10/2026',
        ]);
        expect(summarySearchScope('2026/2027', classes, { ...base, states: 'prepared', other: true, to: '2026-12-31' }).slice(2)).toEqual([
            'Aulas preparadas e outras ocorrências',
            'Até 31/12/2026',
        ]);
    });

    it('a disciplina só aparece quando é preciso distinguir', () => {
        expect(needsSubject(classes)).toBe(false);
        expect(needsSubject([...classes, { ...classes[0]!, ulid: 'c', subject: 'Ciências Naturais' }])).toBe(true);

        const twoSubjects = [...classes, { ...classes[0]!, ulid: 'c', subject: 'Ciências Naturais' }];
        expect(summarySearchScope('2026/2027', twoSubjects, { ...base, classes: ['a', 'c'] })[1]).toBe('7.º A · Matemática, 7.º A · Ciências Naturais');
    });
});
