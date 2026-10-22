import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { YearReading } from '@/lib/studentYear';
import type { StudentTrends } from '@/lib/trajectories';
import YearReadingSection from './YearReading.vue';

const get = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { get: (...args: unknown[]) => get(...args) },
}));

// The chart is a canvas: here a stand-in that exposes what it was asked to draw.
vi.mock('@/components/charts/StatChart.vue', () => ({
    __esModule: true,
    default: defineComponent({
        props: ['config', 'summary', 'headers', 'rows'],
        setup: (props) => () => h('pre', { 'data-test': 'chart-stub' }, JSON.stringify({ data: (props.config as { data: unknown }).data, headers: props.headers, rows: props.rows })),
    }),
}));

/**
 * «AO LONGO DO ANO» — um aluno, dois momentos à escolha, cada domínio ao longo do ano.
 *
 * O QUE ESTES TESTES DEFENDEM: que a secção mostra o que o servidor decidiu sem lhe tocar; que sem
 * comparabilidade não aparece variação e aparece o motivo; que um domínio sem valor é «sem comparação»
 * e nunca zero; que a origem dos dados está sempre à vista; e que o gráfico só existe com dados
 * suficientes e diz o que deixou de fora.
 */

const SUF = { code: '3', label: 'Suficiente', sequence: 3, is_negative: false };
const INSUF = { code: '2', label: 'Insuficiente', sequence: 2, is_negative: true };

const P1 = { key: 'period:p1', kind: 'period' as const, label: '1.º Semestre', ordinal: 1, partial_coverage: false, basis: { kind: 'live' as const, label: 'Calculado com os dados de hoje', as_of: null } };
const P2 = { ...P1, key: 'period:p2', label: '2.º Semestre', ordinal: 2 };

function reading(over: Partial<YearReading> = {}): YearReading {
    return {
        state: 'ready',
        reason: null,
        scale: null,
        options: [P1, P2],
        selection: { from: P1.key, to: P2.key, defaulted: true, swapped: false },
        from: P1,
        to: P2,
        comparability: { percentages: true, levels: true, reasons: [] },
        basis_note: 'Os dois momentos são calculados com os dados de hoje: se uma nota for corrigida, os dois podem mudar.',
        cautions: [],
        notices: [],
        result: {
            from: { value: '58.0', band: INSUF, coverage_warning: false },
            to: { value: '66.5', band: SUF, coverage_warning: false },
            movement: { state: 'up', points: '8.5', reason: null },
            level: { state: 'up', from: INSUF, to: SUF, crossed_line: 'to_positive', reason: null },
        },
        assigned: { from: INSUF, to: SUF, from_value: null, to_value: null, change: { state: 'up', from: INSUF, to: SUF, crossed_line: 'to_positive', reason: null } },
        domains: [
            { ulid: 'd1', name: 'Oralidade', weight_percent: '40.0000', from: '50.0', to: '70.0', movement: { state: 'up', points: '20.0', reason: null }, from_band: INSUF, to_band: SUF, level: { state: 'up', from: INSUF, to: SUF, crossed_line: 'to_positive', reason: null }, coverage_warning: [false, false] },
            { ulid: 'd2', name: 'Leitura', weight_percent: '60.0000', from: null, to: '63.0', movement: { state: 'unavailable', points: null, reason: 'missing_from' }, from_band: null, to_band: SUF, level: { state: 'unavailable', from: null, to: null, crossed_line: null, reason: 'missing', }, coverage_warning: [false, false] },
        ],
        series: {
            enough: true,
            points: [{ key: P1.key, kind: 'period', label: P1.label, basis_kind: 'live' }, { key: P2.key, kind: 'period', label: P2.label, basis_kind: 'live' }],
            result: ['58.0', '66.5'],
            domains: [{ ulid: 'd1', name: 'Oralidade', values: ['50.0', '70.0'] }, { ulid: 'd2', name: 'Leitura', values: [null, '63.0'] }],
            left_out: 0,
            note: null,
        },
        trends: null,
        explanations: [{ key: 'reading', title: 'Que resultado é este', text: 'Cada momento mostra o resultado do próprio período.' }],
        ...over,
    };
}

const flat = (text: string): string => text.replace(/\s+/g, '');

const NEGATIVE = { code: '2', label: 'Insuficiente', is_negative: true };

function trendsFixture(over: Partial<StudentTrends> = {}): StudentTrends {
    const points = [
        { key: 'period:p1', label: '1.º Semestre', kind: 'period' as const, basis_kind: 'live' as const, comparable: true },
        { key: 'period:m1', label: 'Fotografia de dezembro', kind: 'kept_moment' as const, basis_kind: 'frozen' as const, comparable: true },
        { key: 'period:p2', label: '2.º Semestre', kind: 'period' as const, basis_kind: 'live' as const, comparable: true },
    ];
    const trend = { state: 'consistent_down' as const, with_value: 3, counts: { up: 0, flat: 0, down: 2 }, gaps: [], steps: [
        { from: '1.º Semestre', to: 'Fotografia de dezembro', direction: 'down' as const, points: '-5.0', previous: '60.0', current: '55.0' },
        { from: 'Fotografia de dezembro', to: '2.º Semestre', direction: 'down' as const, points: '-8.0', previous: '55.0', current: '47.0' },
    ] };
    const persistent = { state: 'yes' as const, run: 1 + 1, stopped_by: 'positive' as const, points: [
        { label: 'Fotografia de dezembro', value: '55.0', band: { ...NEGATIVE, label: 'Insuficiente' } },
        { label: '2.º Semestre', value: '47.0', band: NEGATIVE },
    ] };

    return {
        availability: { comparable_points: 3, trends: true, persistence: true, reason: null },
        reference: { key: 'period:p2', label: '2.º Semestre' },
        points, left_out: [],
        result: { values: ['60.0', '55.0', '47.0'], negative: [false, true, true], trend, persistent },
        domains: [
            { ulid: 'd1', name: 'Oralidade', values: ['50.0', null, '70.0'], negative: [false, null, false], trend: { state: 'insufficient', with_value: 2, steps: [], counts: { up: 0, flat: 0, down: 0 }, gaps: [] }, persistent: { state: 'no', run: 0, points: [], stopped_by: 'positive' } },
        ],
        ...over,
    };
}

function mountSection(data: YearReading, query: Record<string, string> = {}) {
    return mount(YearReadingSection, {
        props: { reading: data, action: '/classes/c1/evolucao/e1', query, studentName: 'Carolina' },
        global: { stubs: { Sparkles: true } },
    });
}

describe('YearReading', () => {
    it('says which reading this is, so the numbers are not confused with the continuous one', () => {
        const text = mountSection(reading()).text();

        expect(text).toContain('Ao longo do ano');
        expect(text).toContain('resultado do próprio período');
        expect(text).toContain('leitura contínua');
    });

    it('shows the result, its movement in percentage points and the change of level, as sent', () => {
        const wrapper = mountSection(reading());
        const result = wrapper.find('[data-test="year-result"]').text();

        expect(result).toContain('58,0%');
        expect(result).toContain('66,5%');
        expect(result).toContain('Subida');
        expect(result).toContain('+8,5 p.p.');
        expect(wrapper.find('[data-test="year-result-level"]').text()).toBe('Insuficiente → Suficiente — passou a nível positivo');
        expect(wrapper.find('[data-test="year-assigned"]').text()).toContain('passou a nível positivo');
    });

    it('lists every domain with both ends, the variation and the level change — a missing end is «sem comparação», never zero', () => {
        const table = mountSection(reading()).find('[data-test="year-domains"]');

        expect(table.text()).toContain('Oralidade');
        expect(table.text()).toContain('50,0%');
        expect(table.text()).toContain('+20,0 p.p.');
        const leitura = table.findAll('tbody tr')[1].text();
        expect(leitura).toContain('Leitura');
        expect(leitura).toContain('—');
        expect(leitura).toContain('Sem resultado no primeiro momento.');
        expect(leitura).not.toContain('0,0');
    });

    it('says where each end comes from and how the two differ', () => {
        const text = mountSection(reading()).find('[data-test="year-limits"]').text();

        expect(text).toContain('1.º Semestre: Calculado com os dados de hoje');
        expect(text).toContain('2.º Semestre: Calculado com os dados de hoje');
        expect(text).toContain('calculados com os dados de hoje');
    });

    it('shows cautions, such as partial coverage', () => {
        const text = mountSection(reading({ cautions: [{ code: 'partial_coverage', text: 'O resultado de «1.º Semestre» assenta em avaliação parcial.' }] })).find('[data-test="year-limits"]').text();

        expect(text).toContain('assenta em avaliação parcial');
    });

    it('under different criteria shows NO variation and the reasons instead', () => {
        const wrapper = mountSection(reading({
            comparability: { percentages: false, levels: true, reasons: [{ code: 'criteria_differ', text: 'Os domínios ou os pesos de avaliação mudaram entre os dois momentos.' }] },
            result: {
                from: { value: '58.0', band: INSUF, coverage_warning: false },
                to: { value: '66.5', band: SUF, coverage_warning: false },
                movement: { state: 'unavailable', points: null, reason: 'not_comparable' },
                level: { state: 'up', from: INSUF, to: SUF, crossed_line: null, reason: null },
            },
            domains: [{ ulid: 'd1', name: 'Oralidade', weight_percent: null, from: '50.0', to: '70.0', movement: { state: 'unavailable', points: null, reason: 'not_comparable' }, from_band: INSUF, to_band: SUF, level: { state: 'unavailable', from: null, to: null, crossed_line: null, reason: 'not_comparable' }, coverage_warning: [false, false] }],
        }));

        expect(wrapper.find('[data-test="year-not-comparable"]').text()).toContain('mudaram entre os dois momentos');
        expect(wrapper.find('[data-test="year-result"]').text()).toContain('Os critérios mudaram entre os dois momentos.');
        expect(wrapper.find('[data-test="year-result"]').text()).not.toContain('p.p.');
        expect(wrapper.find('[data-test="year-domains"]').text()).not.toContain('p.p.');
    });

    it('draws the chart from the series, with an accessible table of the same numbers', async () => {
        const wrapper = mountSection(reading());
        await flushPromises();

        const drawn = JSON.parse(wrapper.find('[data-test="chart-stub"]').text());

        expect(drawn.data.labels).toEqual(['1.º Semestre', '2.º Semestre']);
        expect(drawn.data.datasets.map((set: { label: string }) => set.label)).toEqual(['Resultado global', 'Oralidade', 'Leitura']);
        // A moment without a value is a gap in the line, never a zero.
        expect(drawn.data.datasets[2].data).toEqual([null, 63]);
        expect(drawn.data.datasets[2].spanGaps).toBe(false);
        expect(drawn.headers).toEqual(['Momento', 'Resultado global', 'Oralidade', 'Leitura']);
        expect(drawn.rows[0]).toEqual(['1.º Semestre', '58,0%', '50,0%', '—']);
        expect(wrapper.find('[data-test="year-legend"]').text()).toContain('Oralidade');
        expect(wrapper.find('[data-test="year-chart-empty"]').exists()).toBe(false);
    });

    it('without enough comparable moments it says so instead of drawing a line', () => {
        const wrapper = mountSection(reading({ series: { enough: false, points: [], result: [], domains: [], left_out: 1, note: '1 momento(s) ficam fora do gráfico por terem critérios de avaliação diferentes.' } }));

        expect(wrapper.find('[data-test="year-chart-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="year-legend"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="year-chart-note"]').text()).toContain('ficam fora do gráfico');
    });

    it('explains the calculations in words', () => {
        expect(mountSection(reading()).find('[data-test="year-how-reading"]').text()).toContain('resultado do próprio período');
    });

    it('with fewer than two moments says why and offers nothing to choose', () => {
        const wrapper = mountSection(reading({
            state: 'unavailable', reason: 'Este aluno só tem resultado em 1 momento: a comparação precisa de pelo menos 2.',
            from: null, to: null, comparability: null, result: null, assigned: null, domains: [], options: [P2],
            series: { enough: false, points: [], result: [], domains: [], left_out: 0, note: null },
        }));

        expect(wrapper.find('[data-test="year-unavailable"]').text()).toContain('só tem resultado em 1 momento');
        expect(wrapper.find('[data-test="year-select-from"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="year-domains"]').exists()).toBe(false);
    });

    it('asks the server for the moments chosen, keeping the reading the page is on', async () => {
        get.mockClear();
        const wrapper = mountSection(reading(), { leitura: 'periodo' });

        await wrapper.find('[data-test="year-select-from"]').setValue('period:p2');
        await wrapper.find('[data-test="year-select-to"]').setValue('period:p1');

        expect(get).toHaveBeenLastCalledWith(
            '/classes/c1/evolucao/e1',
            { leitura: 'periodo', de: 'period:p2', para: 'period:p1' },
            expect.objectContaining({ preserveScroll: true }),
        );

        // The same moment on both sides is not a comparison: nothing is asked.
        get.mockClear();
        await wrapper.find('[data-test="year-select-to"]').setValue('period:p2');
        expect(get).not.toHaveBeenCalled();
    });

    it('follows the server when it orders the two moments in time', async () => {
        const wrapper = mountSection(reading());

        await wrapper.setProps({ reading: reading({ selection: { from: P2.key, to: P1.key, defaulted: false, swapped: true } }) });

        expect((wrapper.find('[data-test="year-select-from"]').element as HTMLSelectElement).value).toBe(P2.key);
        expect((wrapper.find('[data-test="year-select-to"]').element as HTMLSelectElement).value).toBe(P1.key);
    });
    it('shows the trend and the persistence as two separate answers, with the values that justify them', () => {
        const wrapper = mountSection(reading({ trends: trendsFixture() }));
        const result = wrapper.find('[data-test="year-trends-result"]');

        expect(wrapper.find('[data-test="year-trends"]').text()).toContain('Uma tendência precisa de 3 momentos');
        expect(flat(result.find('[data-test="trajectory-values"]').text())).toBe(flat('60,0% → 55,0% → 47,0%'));
        expect(result.find('[data-test="trajectory-trend"]').text()).toContain('Regressão consistente');
        expect(result.find('[data-test="trajectory-trend"]').text()).toContain('Desceu em 2 de 2 passos');
        expect(result.find('[data-test="trajectory-persistent"]').text()).toContain('Abaixo do nível positivo em 2 momentos seguidos, incluindo o mais recente.');
        expect(result.find('[data-test="trajectory-persistent"]').text()).toContain('Fotografia de dezembro: 55,0% · Insuficiente');
        expect(result.find('[data-test="trajectory-persistent"]').text()).toContain('2.º Semestre: 47,0% · Insuficiente');
    });

    it('writes a domain with a hole as a dash and says a trend is not possible with two values', () => {
        const domain = mountSection(reading({ trends: trendsFixture() })).find('[data-test="year-trends-domain"]');

        expect(flat(domain.find('[data-test="trajectory-values"]').text())).toBe(flat('50,0% → — → 70,0%'));
        expect(domain.find('[data-test="trajectory-trend"]').text()).toContain('Sem momentos suficientes');
        expect(domain.find('[data-test="trajectory-trend"]').text()).toContain('2 momentos com valor: uma tendência precisa de 3');
        // A moment without a value is a dash, never a zero (50,0 % is not 0,0 %).
        expect(domain.text()).not.toMatch(/(^|[^\d])0,0%/);
        expect(domain.find('[data-test="trajectory-persistent"]').exists()).toBe(false);
    });

    it('says the reason when there are not three comparable moments, and lists the ones left out', () => {
        const wrapper = mountSection(reading({
            trends: trendsFixture({
                availability: { comparable_points: 2, trends: false, persistence: true, reason: 'Há 2 momentos comparáveis: as tendências precisam de pelo menos 3.' },
                points: [
                    { key: 'period:p1', label: '1.º Semestre', kind: 'period', basis_kind: 'live', comparable: false },
                    { key: 'period:p2', label: '2.º Semestre', kind: 'period', basis_kind: 'live', comparable: true },
                ],
                left_out: [{ key: 'period:p1', label: '1.º Semestre', reasons: [{ code: 'criteria_differ', text: 'Os domínios ou os pesos de avaliação mudaram entre os dois momentos.' }] }],
            }),
        }));

        expect(wrapper.find('[data-test="year-trends-reason"]').text()).toContain('as tendências precisam de pelo menos 3');
        expect(wrapper.find('[data-test="year-trends-left-out"]').text()).toContain('1.º Semestre fica fora da linha');
        expect(wrapper.find('[data-test="year-trends-line"] .line-through').text()).toBe('1.º Semestre');
    });

    it('does not say «still negative» when the latest moment has no value', () => {
        const base = trendsFixture();
        const wrapper = mountSection(reading({
            trends: trendsFixture({ result: { ...base.result, values: ['60.0', '40.0', null], negative: [false, true, null], persistent: { state: 'no_recent', run: 0, points: [], stopped_by: 'no_value' } } }),
        }));

        expect(wrapper.find('[data-test="year-trends-result"] [data-test="trajectory-persistent"]').text()).toContain('não se pode dizer que continua abaixo do nível positivo');
    });

    it('has no trends block when the server sent none', () => {
        expect(mountSection(reading({ trends: null })).find('[data-test="year-trends"]').exists()).toBe(false);
    });

    it('never diagnoses or forecasts in the trends block', () => {
        const text = mountSection(reading({ trends: trendsFixture() })).find('[data-test="year-trends"]').text().toLowerCase();

        for (const word of ['dislexia', 'dificuldade de aprendizagem', 'recomend', 'provavelmente', 'vai piorar']) {
            expect(text).not.toContain(word);
        }

        expect(text).toContain('nenhuma é um diagnóstico');
    });
});
