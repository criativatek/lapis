import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { ClassTrends, Reading, Trend } from '@/lib/trajectories';
import TrendsSection from './TrendsSection.vue';

/**
 * TENDÊNCIAS E RESULTADOS ABAIXO DO NÍVEL POSITIVO PERSISTENTES — a turma ao longo do ano.
 *
 * O QUE ESTES TESTES DEFENDEM: que a página diz as duas regras (3 momentos para uma tendência, 2 para um
 * resultado persistente) como perguntas diferentes; que mostra os valores e os momentos que sustentam
 * cada conclusão; que um momento sem valor é «—» e nunca zero; que os momentos deixados de fora, as
 * lacunas e as cautelas estão à vista; e que a linguagem descreve notas sem diagnosticar.
 */

/** The spans of a trajectory sit flush against each other: compare the text without the spaces. */
const flat = (text: string): string => text.replace(/\s+/g, '');

const POINTS = [
    { key: 'period:a', label: 'Momento A', kind: 'kept_moment' as const, basis_kind: 'frozen' as const, comparable: true },
    { key: 'period:b', label: 'Momento B', kind: 'period' as const, basis_kind: 'live' as const, comparable: true },
    { key: 'period:c', label: 'Momento C', kind: 'period' as const, basis_kind: 'live' as const, comparable: true },
];

const NEG = { code: '2', label: 'Insuficiente', is_negative: true };

const upTrend: Trend = {
    state: 'consistent_up', with_value: 3, counts: { up: 2, flat: 0, down: 0 }, gaps: [],
    steps: [
        { from: 'Momento A', to: 'Momento B', direction: 'up', points: '5.0', previous: '60.0', current: '65.0' },
        { from: 'Momento B', to: 'Momento C', direction: 'up', points: '5.0', previous: '65.0', current: '70.0' },
    ],
};

const noTrend: Trend = { state: 'insufficient', with_value: 2, steps: [], counts: { up: 0, flat: 0, down: 0 }, gaps: [] };

function reading(values: Array<string | null>, trend: Trend, persistent: Reading['persistent'] = { state: 'no', run: 0, points: [], stopped_by: 'positive' }): Reading {
    return { values, negative: values.map((value) => (value === null ? null : Number(value) < 50)), trend, persistent };
}

const counts = (over: Partial<ClassTrends['counts']['domains'][number]> = {}) => ({
    of: 3, with_latest: 3,
    trend: { consistent_up: 1, consistent_down: 0, stable: 0, variable: 1, insufficient: 1 },
    persistent: { yes: 1, no: 2, no_recent: 0, no_value: 0, unknown: 0, insufficient: 0 },
    ...over,
});

function trends(over: Partial<ClassTrends> = {}): ClassTrends {
    const persistentYes: Reading['persistent'] = {
        state: 'yes', run: 2, stopped_by: 'start',
        points: [{ label: 'Momento B', value: '40.0', band: NEG }, { label: 'Momento C', value: '45.0', band: NEG }],
    };

    return {
        state: 'ready', reason: null,
        availability: { comparable_points: 3, trends: true, persistence: true, reason: null },
        reference: { key: 'period:c', label: 'Momento C' },
        points: POINTS, left_out: [],
        class: {
            global: { cohort: 3, means: ['60.0', '65.0', '70.0'], everyone: [{ students: 3, mean: '60.0' }, { students: 3, mean: '65.0' }, { students: 3, mean: '70.0' }], trend: upTrend },
            domains: [{ ulid: 'd1', name: 'Escrita', cohort: 0, means: [null, null, null], everyone: [], trend: noTrend }],
        },
        counts: { students: 3, global: counts(), domains: [{ ulid: 'd1', name: 'Escrita', ...counts() }] },
        students: [
            { enrollment_ulid: 'e1', name: 'Ana', class_number: 1, global: reading(['60.0', '65.0', '70.0'], upTrend), domains: { d1: reading(['55.0', null, '65.0'], noTrend) } },
            { enrollment_ulid: 'e2', name: 'Rui', class_number: 2, global: reading(['70.0', '40.0', '45.0'], { ...noTrend, state: 'variable', with_value: 3 }, persistentYes), domains: { d1: reading(['70.0', '40.0', '45.0'], noTrend, persistentYes) } },
            { enrollment_ulid: 'e3', name: 'Zé', class_number: 3, global: reading([null, '60.0', '70.0'], noTrend), domains: { d1: reading([null, null, null], noTrend) } },
        ],
        cautions: [], explanations: [{ key: 'persistent', title: 'O que é um resultado abaixo do nível positivo persistente', text: 'Não é uma tendência, não prevê o futuro e não é um diagnóstico.' }],
        ...over,
    };
}

describe('TrendsSection', () => {
    it('says the two rules side by side, as two different questions', () => {
        const rules = mount(TrendsSection, { props: { trends: trends() } }).find('[data-test="trends-rules"]').text();

        expect(rules).toContain('Tendência: pelo menos 3 momentos');
        expect(rules).toContain('Abaixo do nível positivo persistente: 2 momentos');
        expect(rules).toContain('não é uma tendência');
    });

    it('shows the line of moments and where it is anchored', () => {
        const line = mount(TrendsSection, { props: { trends: trends() } }).find('[data-test="trends-line"]').text();

        expect(line).toContain('Momento A');
        expect(line).toContain('Momento C');
        expect(line).toContain('3 comparáveis, ancorados em «Momento C»');
    });

    it('shows the class trend with the values it rests on and the cohort', () => {
        const row = mount(TrendsSection, { props: { trends: trends() } }).find('[data-test="trends-class-row"]').text();

        expect(row).toContain('Progressão consistente');
        expect(row).toContain('60,0% → 65,0% → 70,0%');
        expect(row).toContain('Subiu em 2 de 2 passos');
        expect(row).toContain('Média dos 3 alunos com valor em todos os momentos.');
    });

    it('counts the students by trend and by persistence, each with its total', () => {
        const row = mount(TrendsSection, { props: { trends: trends() } }).find('[data-test="trends-class-row"]').text();

        expect(row).toContain('↑ 1 progressão consistente');
        expect(row).toContain('1 de 3 alunos com valor no momento mais recente');
    });

    it('a domain with no value anywhere is dashes, never zeros, and says no trend can be read', () => {
        const domain = mount(TrendsSection, { props: { trends: trends() } }).find('[data-test="trends-domain-row"]').text();

        expect(domain).toContain('Escrita');
        expect(domain).toContain('Sem momentos suficientes');
        expect(domain).toContain('— → — → —');
        expect(domain).not.toContain('0,0%');
    });

    it('lists each student with the trajectory, the trend and — apart — the persistence with its evidence', () => {
        const wrapper = mountSection();
        const rui = wrapper.find('[data-test="trends-student-variable-persistent"]');

        expect(rui.text()).toContain('Rui');
        expect(flat(rui.text())).toContain(flat('70,0% → 40,0% → 45,0%'));
        expect(rui.text()).toContain('Evolução variável');
        expect(rui.text()).toContain('Abaixo do nível positivo em 2 momentos seguidos, incluindo o mais recente.');
        // The evidence: the moments and the values.
        expect(rui.text()).toContain('Momento B: 40,0% · Insuficiente');
        expect(rui.text()).toContain('Momento C: 45,0% · Insuficiente');
        expect(rui.text()).toContain('Também em: Escrita');
    });

    it('a missing value in a student is a dash in the trajectory', () => {
        const ze = mountSection().find('[data-test="trends-student-insufficient"]').text();

        expect(flat(ze)).toContain(flat('— → 60,0% → 70,0%'));
        expect(flat(ze)).not.toContain(flat('0,0% → 60'));
    });

    it('filters the students by trend and by persistence', async () => {
        const wrapper = mountSection();
        expect(wrapper.find('[data-test="trends-filter-persistent"]').text()).toBe('Abaixo do nível positivo persistente (1)');

        await wrapper.find('[data-test="trends-filter-persistent"]').trigger('click');
        expect(wrapper.findAll('[data-test="trends-students"] tbody tr').filter((row) => row.text().includes('Rui'))).toHaveLength(1);
        expect(wrapper.find('[data-test="trends-students"]').text()).not.toContain('Ana');

        await wrapper.find('[data-test="trends-filter-consistent_up"]').trigger('click');
        expect(wrapper.find('[data-test="trends-students"]').text()).toContain('Ana');
        expect(wrapper.find('[data-test="trends-students"]').text()).not.toContain('Rui');

        await wrapper.find('[data-test="trends-filter-stable"]').trigger('click');
        expect(wrapper.text()).toContain('Nenhum aluno neste grupo.');
    });

    it('opens a student\'s domains on demand', async () => {
        const wrapper = mountSection();

        expect(wrapper.find('[data-test="trends-student-detail"]').exists()).toBe(false);

        await wrapper.find('[data-test="trends-students"] button[aria-expanded]').trigger('click');

        expect(wrapper.find('[data-test="trends-student-detail"]').text()).toContain('Escrita');
        expect(flat(wrapper.find('[data-test="trends-student-detail"]').text())).toContain(flat('55,0% → — → 65,0%'));
    });

    it('with two comparable moments says that a trend needs three but a persistent negative can be read', () => {
        const wrapper = mount(TrendsSection, { props: { trends: trends({ availability: { comparable_points: 2, trends: false, persistence: true, reason: 'Há 2 momentos comparáveis: dá para ver resultados abaixo do nível positivo em momentos consecutivos, mas as tendências precisam de pelo menos 3.' } }) } });

        expect(wrapper.find('[data-test="trends-reason"]').text()).toContain('as tendências precisam de pelo menos 3');
    });

    it('lists the moments left out of the line, with the reason, and strikes them in the line', () => {
        const wrapper = mount(TrendsSection, {
            props: {
                trends: trends({
                    points: [{ ...POINTS[0], comparable: false }, POINTS[1], POINTS[2]],
                    left_out: [{ key: 'period:a', label: 'Momento A', reasons: [{ code: 'criteria_differ', text: 'Os domínios ou os pesos de avaliação mudaram entre os dois momentos.' }] }],
                }),
            },
        });

        expect(wrapper.find('[data-test="trends-left-out"]').text()).toContain('Momento A: Os domínios ou os pesos de avaliação mudaram');
        expect(wrapper.find('[data-test="trends-line"] .line-through').text()).toBe('Momento A');
    });

    it('shows the cautions', () => {
        const wrapper = mount(TrendsSection, { props: { trends: trends({ cautions: [{ code: 'few_in_cohort', text: 'A tendência da turma assenta em 2 aluno(s) com resultado em todos os momentos: leia-a com cautela.' }] }) } });

        expect(wrapper.find('[data-test="trends-limits"]').text()).toContain('assenta em 2 aluno(s)');
    });

    it('explains the criteria in words, and never diagnoses', () => {
        const wrapper = mountSection();

        expect(wrapper.find('[data-test="trends-how-persistent"]').text()).toContain('não é um diagnóstico');
        expect(wrapper.text().toLowerCase()).not.toContain('dislexia');
        expect(wrapper.text().toLowerCase()).not.toContain('recomend');
    });

    it('with no trajectories says why instead of an empty page', () => {
        const wrapper = mount(TrendsSection, { props: { trends: trends({ state: 'unavailable', reason: 'Ainda não há resultados em nenhum momento: não há trajetórias para ler.', class: { global: null, domains: [] } }) } });

        expect(wrapper.text()).toContain('Ainda não há trajetórias para ler.');
        expect(wrapper.find('[data-test="trends-students"]').exists()).toBe(false);
    });
});

function mountSection() {
    return mount(TrendsSection, { props: { trends: trends() } });
}
