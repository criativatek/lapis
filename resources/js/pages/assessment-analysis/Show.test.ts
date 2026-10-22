import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { Comparison, ComparedStudent } from '@/lib/assessmentComparison';
import type { ClassStrengths } from '@/lib/classStrengths';
import type { ClassTrends } from '@/lib/trajectories';
import Show from './Show.vue';

const get = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: (...args: unknown[]) => get(...args) },
}));

/**
 * ANÁLISE DA AVALIAÇÃO — comparar dois momentos.
 *
 * O QUE ESTES TESTES DEFENDEM: que a página mostra o que o servidor calculou sem lhe tocar; que
 * quando os critérios não são comparáveis a variação NÃO aparece e o motivo sim; que um aluno
 * sem resultado num dos extremos aparece como «sem comparação» e nunca como zero; e que a origem
 * dos dados e as cautelas estão sempre à vista.
 */

const BOM = { code: '4', label: 'Bom', sequence: 4, is_negative: false };
const INSUF = { code: '2', label: 'Insuficiente', sequence: 2, is_negative: true };

const FROM = {
    key: 'period:p1', kind: 'period' as const, label: '1.º Semestre', ordinal: 1,
    basis: { kind: 'live' as const, label: 'Calculado com os dados de hoje', as_of: null },
    students_total: 6, students_with_result: 4, partial_coverage: 1, few_students: true,
};
const TO = { ...FROM, key: 'period:p2', label: '2.º Semestre', ordinal: 2, students_with_result: 6, partial_coverage: 0, few_students: false };

function student(name: string, over: Partial<ComparedStudent> = {}): ComparedStudent {
    return {
        enrollment_ulid: `e-${name}`, name, class_number: 1,
        from: { value: '60.0', band: INSUF, coverage_warning: false },
        to: { value: '70.5', band: BOM, coverage_warning: false },
        movement: { state: 'up', points: '10.5', reason: null },
        result_level: { state: 'up', from: INSUF, to: BOM, crossed_line: 'to_positive', reason: null },
        assigned: {
            from: INSUF, to: BOM, from_value: null, to_value: null,
            change: { state: 'up', from: INSUF, to: BOM, crossed_line: 'to_positive', reason: null },
        },
        domains: { d1: { from: '55.0', to: '65.0', movement: { state: 'up', points: '10.0', reason: null } } },
        ...over,
    };
}

function comparison(over: Partial<Comparison> = {}): Comparison {
    return {
        state: 'ready',
        reason: null,
        scale: { name: 'Escala 1 a 5', kind: 'levels', levels: [] },
        options: [FROM, TO],
        selection: { from: FROM.key, to: TO.key, defaulted: true, swapped: false },
        from: FROM,
        to: TO,
        comparability: { percentages: true, levels: true, reasons: [] },
        basis_note: 'Os dois momentos são calculados com os dados de hoje: se uma nota for corrigida, os dois podem mudar.',
        cautions: [{ code: 'few_students', text: 'A comparação assenta em apenas 4 aluno(s) com resultado nos dois momentos: leia as variações com cautela.' }],
        notices: [],
        class: {
            moments: { from: { students: 4, mean: '62.0', partial_coverage: 1 }, to: { students: 6, mean: '70.0', partial_coverage: 0 } },
            paired: { students: 4, from_mean: '62.0', to_mean: '70.5', change: { direction: 'up', points: '8.5', previous: '62.0', current: '70.5' } },
            counts: { up: 3, flat: 0, down: 1, compared: 4 },
            not_compared: { missing_from: 2, missing_to: 0, missing_both: 0 },
        },
        domains: [{
            ulid: 'd1', name: 'Oralidade', weight_percent: '40.0000',
            moments: { from: { students: 4, mean: '55.0' }, to: { students: 6, mean: '65.0' } },
            paired: { students: 4, from_mean: '55.0', to_mean: '65.0', change: { direction: 'up', points: '10.0', previous: '55.0', current: '65.0' } },
            counts: { up: 3, flat: 0, down: 1, compared: 4 },
        }],
        levels: {
            comparable: true, reasons: [],
            result: { counts: { up: 2, same: 1, down: 1, compared: 4, not_compared: 2 }, crossed: { to_positive: 1, to_negative: 0 }, distribution: [{ code: '2', label: 'Insuficiente', sequence: 2, is_negative: true, from: 2, to: 1 }] },
            assigned: { counts: { up: 1, same: 0, down: 0, compared: 1, not_compared: 5 }, crossed: { to_positive: 1, to_negative: 0 }, distribution: [{ code: '4', label: 'Bom', sequence: 4, is_negative: false, from: 0, to: 1 }] },
        },
        students: [
            student('Ana'),
            student('Rui', { movement: { state: 'down', points: '-4.0', reason: null }, assigned: { from: null, to: null, from_value: null, to_value: null, change: { state: 'unavailable', from: null, to: null, crossed_line: null, reason: 'missing' } } }),
            student('Zé', { from: { value: null, band: null, coverage_warning: false }, movement: { state: 'unavailable', points: null, reason: 'missing_from' } }),
        ],
        explanations: [{ key: 'movement', title: 'O que é «progrediu»', text: 'Compara-se o resultado de cada aluno nos dois momentos.' }],
        ...over,
    };
}

const STRENGTHS_MOMENT = {
    key: 'period:p2', kind: 'period' as const, label: '2.º Semestre', ordinal: 2,
    basis: { kind: 'live' as const, label: 'Calculado com os dados de hoje', as_of: null },
    students_total: 6, students_with_result: 6, partial_coverage: 0, few_students: false,
};

function strengths(over: Partial<ClassStrengths> = {}): ClassStrengths {
    return {
        state: 'ready', reason: null,
        options: [{ ...STRENGTHS_MOMENT, key: 'period:p1', label: '1.º Semestre', ordinal: 1 }, STRENGTHS_MOMENT],
        selection: { moment: 'period:p2', defaulted: true },
        moment: STRENGTHS_MOMENT,
        basis_note: 'Este momento é calculado com os dados de hoje.',
        cautions: [],
        scale: null,
        negative_line: { known: true, reason: null, text: null, negative_levels: ['Insuficiente'] },
        result: { ulid: null, name: null, students: { with_value: 6, of: 6 }, mean: '70.0', below_positive: { count: 1, of: 6, share: '16.7' }, coverage: { state: 'complete', partial: 0 } },
        domains: [],
        ranking: { state: 'insufficient', highest_mean: null, lowest_mean: null, highest: null, highest_tie: [], lowest: null, lowest_tie: [] },
        students_below: { known: true, of: 6, none: 5, one: 1, two_or_more: 0, students: [] },
        highlights: { strengths: [], attention: [] },
        explanations: [],
        ...over,
    };
}

function trends(): ClassTrends {
    return {
        state: 'unavailable', reason: 'Ainda não há resultados em nenhum momento: não há trajetórias para ler.',
        availability: { comparable_points: 0, trends: false, persistence: false, reason: null },
        reference: null, points: [], left_out: [], class: { global: null, domains: [] },
        counts: { students: 0, global: null, domains: [] }, students: [], cautions: [], explanations: [],
    };
}

function mountPage(data: Comparison) {
    return mount(Show, {
        props: {
            schoolClass: { ulid: 'c1', label: '7.º A', subject: 'Português', has_profile: true, scale_name: 'Escala 1 a 5' },
            availability: { comparison: data.state === 'ready', trends: false, reason: data.reason },
            comparison: data,
            strengths: strengths(),
            trends: trends(),
        },
    });
}

describe('assessment-analysis/Show', () => {
    it('shows the class evolution, the counts and each moment\'s mean as the server sent them', () => {
        const wrapper = mountPage(comparison());
        const text = wrapper.text();

        expect(text).toContain('+8,5 p.p.');
        expect(text).toContain('62,0% → 70,5%');
        expect(text).toContain('Progrediram');
        expect(text).toContain('de 4 alunos comparados');
        expect(wrapper.find('[data-test="class-means"]').text()).toContain('70,0%');
        expect(wrapper.find('[data-test="class-means"]').text()).toContain('6 alunos com resultado');
    });

    it('says where the data come from, its coverage and the cautions — always visible', () => {
        const wrapper = mountPage(comparison());

        expect(wrapper.find('[data-test="point-from"]').text()).toContain('Calculado com os dados de hoje');
        expect(wrapper.find('[data-test="point-from"]').text()).toContain('4 de 6 alunos com resultado');
        expect(wrapper.find('[data-test="point-from"]').text()).toContain('1 com cobertura parcial');
        expect(wrapper.find('[data-test="limits"]').text()).toContain('calculados com os dados de hoje');
        expect(wrapper.find('[data-test="limits"]').text()).toContain('leia as variações com cautela');
    });

    it('lists a student with a result at one end only as «sem comparação», never as zero', () => {
        const wrapper = mountPage(comparison());
        const rows = wrapper.findAll('[data-test="student-unavailable"]');

        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('Zé');
        expect(rows[0].text()).toContain('Sem comparação');
        expect(rows[0].text()).toContain('Sem resultado no primeiro momento.');
        expect(rows[0].text()).not.toContain('0,0');
        expect(wrapper.find('[data-test="not-compared-note"]').text()).toContain('Um resultado em falta não conta como zero.');
    });

    it('writes the level changes, including the crossing of the line', () => {
        const wrapper = mountPage(comparison());

        expect(wrapper.find('[data-test="levels-block"]').text()).toContain('passaram a nível positivo');
        expect(wrapper.find('[data-test="students-table"]').text()).toContain('Insuficiente → Bom — passou a nível positivo');
    });

    it('filters the students by what happened to them', async () => {
        const wrapper = mountPage(comparison());

        expect(wrapper.findAll('[data-test^="student-"]').filter((row) => /^student-(up|down|flat|unavailable)$/.test(row.attributes('data-test') ?? ''))).toHaveLength(3);

        await wrapper.find('[data-test="filter-down"]').trigger('click');
        const rows = wrapper.findAll('tr[data-test^="student-"]').filter((row) => row.attributes('data-test') !== 'student-detail');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('Rui');
        expect(rows[0].text()).toContain('Regrediu');

        await wrapper.find('[data-test="filter-flat"]').trigger('click');
        expect(wrapper.text()).toContain('Nenhum aluno neste grupo.');
    });

    it('opens a student\'s domains on demand', async () => {
        const wrapper = mountPage(comparison());

        expect(wrapper.find('[data-test="student-detail"]').exists()).toBe(false);

        await wrapper.find('[data-test="students-table"] button[aria-expanded]').trigger('click');

        const detail = wrapper.find('[data-test="student-detail"]');
        expect(detail.exists()).toBe(true);
        expect(detail.text()).toContain('Oralidade');
        expect(detail.text()).toContain('55,0% → 65,0%');
    });

    it('under different criteria shows NO variation and the reasons instead', () => {
        const wrapper = mountPage(comparison({
            comparability: { percentages: false, levels: true, reasons: [{ code: 'criteria_differ', text: 'Os domínios ou os pesos de avaliação mudaram entre os dois momentos: as percentagens não são diretamente comparáveis.' }] },
            class: {
                moments: { from: { students: 4, mean: '62.0' }, to: { students: 6, mean: '70.0' } },
                paired: { students: 4, from_mean: '62.0', to_mean: '70.5', change: null },
                counts: null,
                not_compared: { missing_from: 2, missing_to: 0, missing_both: 0 },
            },
            domains: [{
                ulid: 'd1', name: 'Oralidade', weight_percent: '40.0000',
                moments: { from: { students: 4, mean: '55.0' }, to: { students: 6, mean: '65.0' } },
                paired: { students: 4, from_mean: '55.0', to_mean: '65.0', change: null }, counts: null,
            }],
            students: [student('Ana', { movement: { state: 'unavailable', points: null, reason: 'not_comparable' } })],
        }));

        expect(wrapper.find('[data-test="not-comparable"]').text()).toContain('não são diretamente comparáveis');
        expect(wrapper.text()).toContain('Sem comparação: critérios diferentes');
        expect(wrapper.text()).not.toContain('p.p.');
        expect(wrapper.find('[data-test="students-table"]').text()).toContain('Os critérios mudaram entre os dois momentos.');
        // The two means are still shown, each on its own.
        expect(wrapper.find('[data-test="class-means"]').text()).toContain('62,0%');
    });

    it('refuses the levels, with the reason, when the scale is not demonstrably the same', () => {
        const wrapper = mountPage(comparison({
            levels: { comparable: false, reasons: [{ code: 'scale_unknown', text: 'Um dos momentos foi guardado antes de a escala viajar com ele.' }], result: null, assigned: null },
        }));

        expect(wrapper.find('[data-test="levels-refused"]').text()).toContain('antes de a escala viajar');
        expect(wrapper.find('[data-test="levels-block"]').exists()).toBe(false);
    });

    it('explains the calculations in words', () => {
        const wrapper = mountPage(comparison());

        expect(wrapper.find('[data-test="how-movement"]').text()).toContain('Compara-se o resultado de cada aluno');
    });

    it('with fewer than two moments says why instead of an empty comparison', () => {
        const wrapper = mountPage(comparison({
            state: 'unavailable', reason: 'Só existe 1 momento com resultados: a comparação precisa de pelo menos 2.',
            from: null, to: null, comparability: null, class: null, domains: [], levels: null, students: [], options: [FROM],
        }));

        expect(wrapper.text()).toContain('Ainda não há dois momentos para comparar.');
        expect(wrapper.text()).toContain('a comparação precisa de pelo menos 2');
        expect(wrapper.find('[data-test="students-table"]').exists()).toBe(false);
    });

    it('follows the server when it orders the two moments in time', async () => {
        const wrapper = mountPage(comparison());

        await wrapper.setProps({ comparison: comparison({ selection: { from: TO.key, to: FROM.key, defaulted: false, swapped: true } }) });

        expect((wrapper.find('[data-test="select-from"]').element as HTMLSelectElement).value).toBe(TO.key);
        expect((wrapper.find('[data-test="select-to"]').element as HTMLSelectElement).value).toBe(FROM.key);
    });

    it('asks the server for the moments chosen', async () => {
        get.mockClear();
        const wrapper = mountPage(comparison());

        await wrapper.find('[data-test="select-from"]').setValue('period:p2');
        await wrapper.find('[data-test="select-to"]').setValue('period:p1');

        expect(get).toHaveBeenLastCalledWith('/classes/c1/analise-avaliacao', { de: 'period:p2', para: 'period:p1' }, expect.objectContaining({ preserveScroll: true }));

        // The same moment on both sides is not a comparison: nothing is asked.
        get.mockClear();
        await wrapper.find('[data-test="select-to"]').setValue('period:p2');
        expect(get).not.toHaveBeenCalled();
    });
    it('has both sections on one page, with an in-page way to each', () => {
        const wrapper = mountPage(comparison());

        expect(wrapper.find('nav a[href="#comparar"]').exists()).toBe(true);
        expect(wrapper.find('nav a[href="#pontos-fortes-titulo"]').exists()).toBe(true);
        expect(wrapper.find('nav a[href="#tendencias-titulo"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="trends"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="strengths"]').exists()).toBe(true);
    });

    it('choosing a moment for the strengths keeps an explicit comparison, and the other way round', async () => {
        get.mockClear();
        const wrapper = mountPage(comparison({ selection: { from: FROM.key, to: TO.key, defaulted: false, swapped: false } }));

        await wrapper.find('[data-test="strengths-select"]').setValue('period:p1');
        expect(get).toHaveBeenLastCalledWith('/classes/c1/analise-avaliacao', { de: FROM.key, para: TO.key, momento: 'period:p1' }, expect.objectContaining({ preserveScroll: true }));

        // A default comparison is not pinned into the URL by choosing a moment for the strengths.
        get.mockClear();
        const other = mountPage(comparison());
        await other.find('[data-test="strengths-select"]').setValue('period:p1');
        expect(get).toHaveBeenLastCalledWith('/classes/c1/analise-avaliacao', { momento: 'period:p1' }, expect.anything());
    });
});
