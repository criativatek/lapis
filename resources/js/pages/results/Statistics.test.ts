import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Statistics from './Statistics.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn(), post: vi.fn() },
    usePage: () => ({ props: { modules: [], readOnlyModules: [] } }),
    useForm: (data: Record<string, unknown>) => ({
        ...data,
        errors: {},
        processing: false,
        clearErrors: vi.fn(),
        post: vi.fn(),
    }),
}));

/**
 * IMPRIMIR / PDF NA ESTATÍSTICA DA TURMA.
 *
 * O jsdom não pagina nem mede, por isso o que se prova aqui é o contrato da
 * página: o que fica dentro do papel e o que sai dele (`print:hidden`), o que o
 * cabeçalho escreve, e que o plano Base nunca imprime a leitura automática de
 * movimento. O layout real prova-se no browser, com `page.pdf`.
 */

const BAND_3 = { scale_level_id: 3, code: '3', label: 'Suficiente', sequence: 3, is_negative: false };
const BAND_4 = { scale_level_id: 4, code: '4', label: 'Bom', sequence: 4, is_negative: false };

const PERIODS = [
    { id: 1, ulid: 'per-1', label: '1.º Semestre', sequence: 1 },
    { id: 2, ulid: 'per-2', label: '2.º Semestre', sequence: 2 },
];

function student(id: number, name: string, band: typeof BAND_3) {
    return {
        enrollment_id: id,
        name,
        class_number: id,
        weighted_average: '66.0',
        accumulated_average: '64.0',
        primary_average: '64.0',
        supplementary_average: '66.0',
        coverage_warning: false,
        evolution: null,
        continuous_evolution: null,
        band,
        assigned: band,
        assigned_key: 'level-3',
        domains: [
            {
                domain_id: 1,
                weighted_average: '70.0',
                accumulated_average: '68.0',
                coverage_warning: false,
                evolution: null,
                self_assessment: null,
                mention: band,
            },
        ],
        self_assessment: null,
        classification: null,
        external: null,
        series: [],
        transition: 'unclassified',
    };
}

const MOVEMENT = {
    progressed: 2, stable: 1, regressed: 1, no_comparison: 0, comparable: 4, average_change: '3.0',
    percentages: { progressed: '50.0', stable: '25.0', regressed: '25.0', no_comparison: null },
};

function statistics(withMovement: boolean) {
    return {
        periods: PERIODS,
        selected_period: PERIODS[1],
        previous_period: PERIODS[0],
        domains: [{ id: 1, name: 'Oralidade' }],
        scale: {
            name: 'Qualitativa 1 a 5', kind: 'levels',
            bands: [{ label: 'Suficiente', sequence: 3, is_negative: false }],
            threshold: { noun: 'nível', value: '3', label: 'Suficiente', at_or_above: 'nível igual ou superior a 3', below: 'nível inferior a 3' },
        },
        primary: {
            kind: 'accumulated', label: 'Avaliação contínua', short_label: 'Contínua', caption: null,
            supplementary_label: 'Só no 2.º Semestre', has_supplementary: true, scopes: {},
        },
        summary: {
            students_total: 2, domain_students_total: 2, students_with_result: 2, students_without_result: 0,
            class_average: '66.0', accumulated_average: '64.0', primary_average: '64.0', supplementary_average: '66.0',
            partial_coverage_count: 0, most_common_band: null,
            success: {
                succeeded: 2, failed: 0, unplaced: 0, without_classification: 0, placed: 2,
                rate: '100.0', failure_rate: '0.0',
            },
        },
        ...(withMovement
            ? {
                  evolution: {
                      ...MOVEMENT,
                      transitions: {
                          failure_to_success: 0, success_to_failure: 0, held_success: 2, held_failure: 0,
                          unclassified: 0, no_assigned_classification: 0, comparable: 2,
                          percentages: { failure_to_success: null, success_to_failure: null, held_success: '100.0', held_failure: null },
                          share_of_class: { unclassified: null, no_assigned_classification: null },
                      },
                  },
                  continuous_evolution: MOVEMENT,
              }
            : {}),
        assigned_distribution: {
            bands: [
                { key: 'level-3', scale_level_id: 3, code: '3', label: 'Suficiente', sequence: 3, is_negative: false, count: 2, percentage: '100.0' },
            ],
            mode: 'levels', classified: 2, unplaced: 0, without_classification: 0,
        },
        distribution: [{ ...BAND_3, count: 2, percentage: '100.0' }, { ...BAND_4, count: 0, percentage: '0.0' }],
        universe: { value: 'attending_only', label: 'Apenas quem frequenta' },
        cohort: { not_attending_count: 0, not_attending_origins: [], external_included_count: 0, not_attending_without_result: 0 },
        notes: { domain_exclusion: null, external_inclusion: null, partial_period_attendance: null },
        domain_statistics: [
            {
                domain_id: 1, label: 'Oralidade', period_average: '70.0', accumulated_average: '68.0', evolution_average: null,
                students_with_result: 2, students_without_result: 0, partial_coverage_count: 0,
                qualitative_band: BAND_3, succeeded: 2, placed: 2, success_rate: '100.0',
            },
        ],
        period_series: [
            { period_id: 1, label: '1.º Semestre', sequence: 1, class_average: '63.0', accumulated_average: '63.0', primary_average: '63.0', primary_kind: 'accumulated', students_with_result: 2, domains: [{ domain_id: 1, average: '63.0', accumulated_average: '63.0', students_with_result: 2 }] },
            { period_id: 2, label: '2.º Semestre', sequence: 2, class_average: '66.0', accumulated_average: '64.0', primary_average: '64.0', primary_kind: 'accumulated', students_with_result: 2, domains: [{ domain_id: 1, average: '70.0', accumulated_average: '68.0', students_with_result: 2 }] },
        ],
        students: [student(1, 'Ana Costa', BAND_3), student(2, 'Rui Dias', BAND_4)],
    };
}

function props(overrides: Record<string, unknown> = {}, withMovement = true) {
    return {
        schoolClass: { ulid: 'cls-1', label: '7.º A', subject: 'Português', has_profile: true, scale_name: 'Qualitativa 1 a 5' },
        decision: { label: 'Classificação', classifies_by_level: true },
        cutoff: { date: null, label: null, is_open: true },
        universeOptions: [
            { value: 'attending_only', label: 'Apenas quem frequenta' },
            { value: 'all_class_students', label: 'Toda a turma' },
        ],
        suggestedInterimName: null,
        interimAssessments: [
            { ulid: 'int-1', name: 'Antes do Natal', reference_date: '2026-12-10', reference_date_label: '10/12/2026', period_label: '1.º Semestre', academic_period_id: 1 },
        ],
        statistics: statistics(withMovement),
        printDocument: {
            identity: { name: 'Escola Teste', header_lines: ['Rua Um, 1'], footer_note: 'Nota da escola', is_configured: true, logo_url: null },
            academic_year: '2026/2027',
            issued_at: '2026-10-23T12:00:00+01:00',
            issued_label: 'Emitido a 23/10/2026, 12:00',
        },
        ai: { available: true, reason: null },
        aiAnalysis: { period_id: 2, period_label: '2.º Semestre', summary: 'TEXTO DE IA', patterns: [], cautions: [], suggestions: [] },
        aiAnalysisError: null,
        ...overrides,
    };
}

const ClassAnalysisPanelStub = defineComponent({
    props: ['analysis'],
    setup: (p) => () => h('section', { 'data-test': 'ai-panel' }, p.analysis?.summary ?? ''),
});

let wrapper: VueWrapper | null = null;

function mountPage(overrides: Record<string, unknown> = {}, withMovement = true) {
    wrapper = mount(Statistics, {
        props: props(overrides, withMovement) as never,
        global: { stubs: { ClassAnalysisPanel: ClassAnalysisPanelStub } },
        attachTo: document.body,
    });

    return wrapper;
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.head.querySelectorAll('style[data-document-print-page]').forEach((element) => element.remove());
});

/** Está dentro de um ascendente `print:hidden`? Então não vai para o papel. */
function leavesThePaper(element: Element): boolean {
    return element.closest('.print\\:hidden') !== null;
}

describe('Estatística: Imprimir / PDF', () => {
    it('shows the print button, outside the paper, and prints the page itself', async () => {
        const print = vi.fn();
        vi.stubGlobal('print', print);
        const page = mountPage();

        const button = page.get('[data-test="print-button"]');
        expect(button.text()).toContain('Imprimir / PDF');
        expect(leavesThePaper(button.element)).toBe(true);

        await button.trigger('click');
        await Promise.resolve();

        expect(print).toHaveBeenCalledTimes(1);
        vi.unstubAllGlobals();
    });

    it('does not offer to print a class without results', () => {
        const base = props();
        const empty = {
            ...base,
            statistics: { ...base.statistics, summary: { ...base.statistics.summary, students_with_result: 0 } },
        };
        wrapper = mount(Statistics, { props: empty as never, global: { stubs: { ClassAnalysisPanel: ClassAnalysisPanelStub } } });

        expect(wrapper.find('[data-test="print-button"]').exists()).toBe(false);
    });

    it('writes the identity, the class, the school year, the period and the emission in the header', () => {
        const page = mountPage();
        const header = page.get('[data-test="print-header"]').text();

        expect(header).toContain('Escola Teste');
        expect(header).toContain('7.º A — Português');
        expect(header).toContain('2026/2027');
        expect(header).toContain('2.º Semestre');
        expect(header).toContain('Dados até hoje');
        expect(header).toContain('Universo: Apenas quem frequenta');
        expect(header).toContain('Emitido a 23/10/2026, 12:00');
        expect(page.get('[data-test="print-footer"]').text()).toContain('Nota da escola');
    });

    it('carries the cutoff, the universe and the reading the screen had into the header', async () => {
        const page = mountPage({
            cutoff: { date: '2026-12-15', label: '15/12/2026', is_open: false },
            statistics: { ...statistics(true), universe: { value: 'all_class_students', label: 'Toda a turma' } },
        });

        let options = page.get('[data-test="print-header-options"]').text();
        expect(options).toContain('Dados até 15/12/2026');
        expect(options).toContain('Universo: Toda a turma');
        expect(options).toContain('Leitura: Avaliação contínua');

        const periodOnly = page.findAll('button').find((button) => button.text() === 'Só no 2.º Semestre');
        await periodOnly!.trigger('click');

        options = page.get('[data-test="print-header-options"]').text();
        expect(options).toContain('Leitura: Só no 2.º Semestre');
    });

    it('takes every screen-only control out of the paper', () => {
        const page = mountPage();

        // Ligações, seletores, formulário, lista de momentos guardados, painel de IA.
        expect(leavesThePaper(page.get('a[href="/classes/cls-1/results"]').element)).toBe(true);
        expect(leavesThePaper(page.get('#cutoff-date').element)).toBe(true);
        expect(leavesThePaper(page.get('input[name="universo-da-analise"]').element)).toBe(true);
        expect(leavesThePaper(page.get('[data-test="print-hidden-ai"]').element)).toBe(true);

        const interims = page.findAll('section').find((section) => section.text().includes('Antes do Natal'));
        expect(leavesThePaper(interims!.element)).toBe(true);

        const periodPicker = page.findAll('button').find((button) => button.text() === '1.º Semestre');
        expect(leavesThePaper(periodPicker!.element)).toBe(true);
    });

    it('keeps the indicators, the charts and the class map inside the paper', () => {
        const page = mountPage();

        for (const text of ['Taxa de sucesso', 'Alunos com resultado', 'Classificações positivas e negativas', 'Mapa da turma']) {
            const element = page.findAll('h2, p').find((candidate) => candidate.text().includes(text));
            expect(element, text).toBeDefined();
            expect(leavesThePaper(element!.element), text).toBe(false);
        }

        const map = page.get('table[data-heatmap]');
        expect(leavesThePaper(map.element)).toBe(false);
        expect(map.text()).toContain('Ana Costa');
    });

    it('writes the mention of each map cell, so that colour is never the only information', () => {
        const page = mountPage();
        const cells = page.findAll('[data-test="print-cell-mention"]');

        expect(cells).toHaveLength(2);
        expect(cells.map((cell) => cell.text())).toEqual(['3', '4']);
        // Escondida no ecrã, visível só no papel.
        expect(cells[0].classes()).toContain('hidden');
        expect(cells[0].classes()).toContain('print:block');
    });

    it('never prints the AI analysis, not even the transient reading of the last click', () => {
        const page = mountPage();
        const panel = page.get('[data-test="ai-panel"]');

        expect(panel.text()).toBe('TEXTO DE IA');
        expect(leavesThePaper(panel.element)).toBe(true);
        expect(page.get('[data-test="print-header"]').text()).not.toContain('TEXTO DE IA');
        expect(page.get('[data-test="print-footer"]').text()).not.toContain('TEXTO DE IA');
    });

    it('leaves the collapsed secondary reading as the teacher left it', async () => {
        const page = mountPage();
        const secondary = () => page.get('#distribuicao-calculada');

        expect((secondary().element as HTMLElement).style.display).toBe('none');

        await page.get('[data-print-heading]').trigger('click');

        expect((secondary().element as HTMLElement).style.display).not.toBe('none');
        expect(leavesThePaper(secondary().element)).toBe(false);
    });

    it('sets the zoom only for the paper and clears it afterwards', () => {
        const page = mountPage();
        const root = page.get('.estatistica-print').element as HTMLElement;
        const table = page.get('table[data-heatmap]').element as HTMLElement;

        // O jsdom não mede (offsetWidth = 0): a tabela "cabe" e a escala é 1.
        window.dispatchEvent(new Event('beforeprint'));
        expect(root.style.getPropertyValue('--estatistica-print-zoom')).toBe('1');
        expect(page.find('[data-test="print-notice"]').exists()).toBe(false);

        // Uma tabela medida com o dobro da folha encolhe-se para caber.
        Object.defineProperty(table, 'offsetWidth', { configurable: true, value: 1344 });
        window.dispatchEvent(new Event('beforeprint'));
        const zoom = Number(root.style.getPropertyValue('--estatistica-print-zoom'));
        expect(zoom).toBeGreaterThan(0.45);
        expect(zoom).toBeLessThan(0.55);

        window.dispatchEvent(new Event('afterprint'));
        expect(root.style.getPropertyValue('--estatistica-print-zoom')).toBe('');
    });

    it('warns on screen when the map only fits below the legibility floor', async () => {
        const page = mountPage();
        const table = page.get('table[data-heatmap]').element as HTMLElement;
        Object.defineProperty(table, 'offsetWidth', { configurable: true, value: 3000 });

        window.dispatchEvent(new Event('beforeprint'));
        await page.vm.$nextTick();

        expect(page.get('[data-test="print-notice"]').text()).toContain('imprime-se a');
    });

    it('asks for A4 portrait with page numbers and no stamp', () => {
        mountPage();
        const rule = document.head.querySelector('style[data-document-print-page]')?.textContent ?? '';

        expect(rule).toContain('size: A4 portrait');
        expect(rule).toContain('"Página " counter(page)');
    });
});

describe('Estatística impressa: Base nunca imprime a leitura automática de movimento', () => {
    const PRO_ONLY = ['Como evoluiu a turma', 'Progrediram', 'Regrediram', 'Mantiveram-se', 'Evolução na avaliação contínua'];

    it('prints the movement section on Pro', () => {
        const text = mountPage({}, true).text();

        for (const word of PRO_ONLY) {
            expect(text, word).toContain(word);
        }
    });

    it('prints none of it on Base, header and footer included, because the payload has none of it', () => {
        const page = mountPage({}, false);
        const text = page.text();

        for (const word of PRO_ONLY) {
            expect(text, word).not.toContain(word);
        }

        expect(page.get('[data-test="print-header"]').text()).not.toMatch(/evolu|tend[eê]ncia|progred/i);
        expect(page.get('[data-test="print-footer"]').text()).not.toMatch(/evolu|tend[eê]ncia|progred/i);
        // Os números e os gráficos de sempre continuam a imprimir-se.
        expect(text).toContain('Taxa de sucesso');
        expect(text).toContain('Mapa da turma');
    });
});
