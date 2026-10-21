import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import GridDocument from './GridDocument.vue';

type Doc = InstanceType<typeof GridDocument>['$props']['document'];

const routerGet = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { get: routerGet },
}));

const wrappers: VueWrapper[] = [];

const cell = (text: string, overrides: Record<string, unknown> = {}) => ({
    text,
    detail: null,
    state: null,
    negative: null,
    ...overrides,
});

function makeBlock(overrides: Record<string, unknown> = {}) {
    return {
        caption: null,
        columns: [
            { kind: 'item', code: 'Q1', group: null, quota: '10', bonus: false },
            { kind: 'item', code: 'Q2', group: null, quota: '10', bonus: false },
            { kind: 'raw', label: 'Pontuação bruta', quota: '20' },
            { kind: 'result', label: 'Resultado', quota: null },
            { kind: 'level', label: 'Nível', quota: null },
            { kind: 'domain', label: 'Geometria', quota: null },
        ],
        group_spans: null,
        rows: [
            {
                number: '1',
                name: 'Ana Silva',
                cells: [
                    cell('9', { state: 'assessed' }),
                    cell('8', { state: 'assessed' }),
                    cell('17/20'),
                    cell('85,0 %', { state: 'classified' }),
                    cell('Muito Bom'),
                    cell('80,0 %', { detail: 'Bom', state: 'classified' }),
                ],
            },
            {
                number: '2',
                name: 'Rui Costa',
                cells: [cell('Aus', { state: 'absent' }), cell('—', { state: 'pending' }), cell('—'), cell('Ausente'), cell('—'), cell('—')],
            },
        ],
        ...overrides,
    };
}

function makeDocument(overrides: Record<string, unknown> = {}): Doc {
    return {
        title: 'Teste 1',
        kind_label: 'Grelha do elemento de avaliação',
        subtitle: '8.º A · Matemática · 1.º Período · 15/10/2026',
        identity: { name: 'Escola Teste', header_lines: [], footer_note: null, is_configured: true, logo_url: null },
        issued_label: 'Emitido a 20/10/2026, 10:00',
        meta: { class: '8.º A', subject: 'Matemática', period: '1.º Período', applied_on: '15/10/2026', type: 'Teste', purpose: 'Sumativa', status: 'Em correção' },
        is_diagnostic: false,
        state: { is_provisional: false, label: 'Correção concluída' },
        options: { raw_score: true, result: true, domains: true, legend: true, charts: false, orientation: 'auto' },
        orientation: 'portrait',
        charts_available: true,
        charts: null,
        blocks: [makeBlock()],
        summary: { students: 2, excluded: 0, questions: 2, blocks: 1, domains: 2 },
        excluded_note: null,
        legend: {
            questions: [
                { code: 'Q1', group: null, label: 'Equações', quota: '10', bonus: false, domains: ['Números e Operações 100 %'] },
                { code: 'Q2', group: null, label: 'Áreas', quota: '10', bonus: false, domains: ['Geometria 100 %'] },
            ],
            states: [
                { short: '—', label: 'Por avaliar (não é zero)' },
                { short: 'Aus', label: 'Ausente' },
            ],
        },
        notes: [
            'O resultado é calculado pelo motor de avaliação a partir das pontuações registadas; não é a classificação do período.',
        ],
        notice: 'Documento gerado a partir dos registos à data de emissão; não é um relatório finalizado.',
        is_empty: false,
        empty_message: 'Não há alunos abrangidos por este elemento de avaliação.',
        ...overrides,
    } as Doc;
}

const chartsOn = { raw_score: true, result: true, domains: true, legend: true, charts: true, orientation: 'auto' };

function makeCharts() {
    const axis = 'Escala absoluta de 0 a 100 %.';

    return {
        distribution: {
            title: 'Distribuição dos resultados',
            basis: 'Por nível da escala',
            n: 8,
            n_label: 'n = 8 alunos com resultado',
            bars: [
                { label: 'Suficiente', value_text: '5 · 62,5 %', detail: null, width: '62.5', negative: false },
                { label: 'Insuficiente', value_text: '3 · 37,5 %', detail: null, width: '37.5', negative: true },
            ],
            missing: [],
            missing_note: null as string | null,
            caution: null as string | null,
            empty_message: 'Sem resultados: nenhum aluno tem ainda um resultado calculado.',
        },
        means: {
            title: 'Média da turma',
            basis: 'Global',
            axis,
            bars: [{ label: 'Global', value_text: '58,0 %', detail: 'n = 8 alunos com resultado', width: '58.0', negative: false }],
            caution: null as string | null,
            empty_message: 'Sem resultados: nenhum aluno tem ainda um resultado calculado.',
        },
        questions: {
            title: 'Média por questão',
            basis: 'Pontos obtidos sobre a cotação, entre os alunos avaliados em cada questão',
            axis,
            note: 'Ausências, dispensas e questões por avaliar não contam como zero.',
            bars: [
                { label: 'Q1', value_text: '63,3 %', detail: '8 de 8 avaliados', width: '63.3' as string | null, negative: false },
                { label: 'Q2', value_text: '—', detail: '0 de 8 avaliados · 8 dispensados', width: null as string | null, negative: false },
            ],
            caution: null as string | null,
            empty_message: 'Sem resultados: nenhuma questão tem ainda pontuações avaliadas.',
        },
    };
}

function mountPage(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(GridDocument, {
        props: {
            instrument: { ulid: 'inst-1', title: 'Teste 1', class_ulid: 'class-1' },
            document: makeDocument(),
            ...overrides,
        },
    });
    wrappers.push(wrapper);

    return wrapper;
}

describe('instruments/GridDocument.vue', () => {
    afterEach(() => {
        wrappers.forEach((wrapper) => wrapper.unmount());
        wrappers.length = 0;
        routerGet.mockReset();
        vi.restoreAllMocks();
    });

    it('prints exactly what the server composed: every cell text, header and note, nothing recomputed', () => {
        const wrapper = mountPage();
        const text = wrapper.text();

        for (const expected of ['Ana Silva', 'Rui Costa', '17/20', '85,0 %', 'Muito Bom', '80,0 %', 'Bom', 'Aus', 'Ausente', 'Pontuação bruta', 'Geometria']) {
            expect(text).toContain(expected);
        }

        const grid = wrapper.find('[data-block] table');
        const headers = grid.findAll('thead tr:nth-of-type(1) th').map((th) => th.text());
        expect(headers).toEqual(['Nº', 'Nome', 'Q1', 'Q2', 'Pontuação bruta', 'Resultado', 'Nível', 'Geometria']);
        // The quota line carries the server's quotas, the total under the raw score.
        expect(grid.find('thead tr:nth-of-type(2)').text()).toContain('Cotação');
        expect(grid.find('thead tr:nth-of-type(2)').text()).toContain('20');
    });

    it('keeps the label «Resultado» and says in the document that it is not the period classification', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('Resultado');
        expect(wrapper.text()).not.toContain('Classificação oficial');
        expect(wrapper.text()).toContain('não é a classificação do período');
    });

    it('marks a provisional grid on screen and on the sheet itself, which is what is printed', () => {
        const wrapper = mountPage({
            document: makeDocument({ state: { is_provisional: true, label: 'PROVISÓRIO' } }),
        });

        expect(wrapper.text()).toContain('Valores provisórios.');
        // The badge is inside the printable sheet, not in the print:hidden banner.
        const sheet = wrapper.find('.document-print');
        expect(sheet.text()).toContain('PROVISÓRIO');
    });

    it('puts the provisional stamp and the page numbers in the page margins, so every printed sheet has them', () => {
        const provisional = mountPage({ document: makeDocument({ state: { is_provisional: true, label: 'PROVISÓRIO' } }) });
        const rule = document.head.querySelector('style[data-document-print-page]')?.textContent ?? '';

        expect(rule).toContain('@top-right { content: "PROVISÓRIO";');
        expect(rule).toContain('@bottom-right { content: "Página " counter(page) " / " counter(pages);');

        provisional.unmount();
        wrappers.pop();

        const concluded = mountPage();
        const concludedRule = document.head.querySelector('style[data-document-print-page]')?.textContent ?? '';

        expect(concludedRule).not.toContain('@top-right');
        expect(concludedRule).toContain('@bottom-right');
        concluded.unmount();
        wrappers.pop();
    });

    it('draws no charts section when the server sent none, and offers the option', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-charts]').exists()).toBe(false);
        expect(wrapper.find('#grid-doc-charts').attributes('disabled')).toBeUndefined();
    });

    it('disables the charts option, saying why, while the correction is not concluded', () => {
        const wrapper = mountPage({ document: makeDocument({ charts_available: false }) });

        expect(wrapper.find('#grid-doc-charts').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('só com a correção concluída');
    });

    it('turns the charts on by asking the server again with graficos=1', async () => {
        const wrapper = mountPage();

        await wrapper.find('#grid-doc-charts').trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/instruments/inst-1/impressao',
            { bruta: '1', resultado: '1', dominios: '1', legenda: '1', graficos: '1', orientacao: 'auto' },
            { replace: true, preserveScroll: true, preserveState: true },
        );
    });

    it('draws every bar with the length, text and detail the server measured and composed', () => {
        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts: makeCharts() }) });
        const section = wrapper.find('[data-charts]');

        expect(section.findAll('[data-chart]')).toHaveLength(3);
        expect(section.text()).toContain('Distribuição dos resultados');
        expect(section.text()).toContain('Por nível da escala · n = 8 alunos com resultado');

        const first = section.findAll('[data-chart]')[0];
        expect(first.text()).toContain('5 · 62,5 %');
        expect(first.text()).toContain('3 · 37,5 %');

        const widths = first.findAll('li span[style]').map((fill) => fill.attributes('style'));
        expect(widths).toEqual(['width: 62.5%;', 'width: 37.5%;']);

        // A question with nobody assessed has no bar, only its coverage.
        const question = section.findAll('[data-chart]')[2].findAll('li')[1];
        expect(question.find('span[style]').exists()).toBe(false);
        expect(question.text()).toContain('0 de 8 avaliados · 8 dispensados');
    });

    it('draws no fill for a bar of zero length, so a level nobody reached shows an empty track', () => {
        const charts = makeCharts();
        charts.distribution.bars.push({ label: 'Fraco', value_text: '0 · 0,0 %', detail: null, width: '0.0', negative: true });

        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts }) });
        const rows = wrapper.findAll('[data-chart]')[0].findAll('li');

        expect(rows).toHaveLength(3);
        expect(rows[2].text()).toContain('0 · 0,0 %');
        expect(rows[2].find('span[style]').exists()).toBe(false);
    });

    it('marks a bar below the threshold apart from the others', () => {
        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts: makeCharts() }) });
        const fills = wrapper.findAll('[data-chart]')[0].findAll('li span[style]');

        expect(fills[0].classes()).not.toContain('bg-foreground/30');
        expect(fills[1].classes()).toContain('bg-foreground/30');
    });

    it('keeps the bars printable and a chart in one piece', () => {
        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts: makeCharts() }) });

        for (const fill of wrapper.findAll('[data-chart] li span[style]')) {
            expect(fill.classes().join(' ')).toContain('print-color-adjust');
        }

        expect(wrapper.find('[data-chart]').classes()).toContain('break-inside-avoid');
    });

    it('says plainly when there is nothing to draw, and shows the caution and the missing note', () => {
        const charts = makeCharts();
        charts.distribution.bars = [];
        charts.distribution.missing_note = 'Sem resultado: 8 ausentes';
        charts.distribution.caution = 'Poucos alunos com resultado (n = 3): leia com cautela.';

        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts }) });
        const first = wrapper.findAll('[data-chart]')[0];

        expect(first.text()).toContain('Sem resultados: nenhum aluno tem ainda um resultado calculado.');
        expect(first.findAll('li')).toHaveLength(0);
        expect(first.text()).toContain('Sem resultado: 8 ausentes');
        expect(first.text()).toContain('leia com cautela');
    });

    it('does not claim provisional values for a concluded grid', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).not.toContain('Valores provisórios.');
        expect(wrapper.find('.document-print').text()).toContain('Correção concluída');
    });

    it('flags a diagnostic instrument on screen and on the sheet', () => {
        const wrapper = mountPage({ document: makeDocument({ is_diagnostic: true }) });

        expect(wrapper.text()).toContain('Não conta para as médias classificativas.');
        expect(wrapper.find('.document-print').text()).toContain('Avaliação diagnóstica');
    });

    it('shows a group header line with the groups the server composed, only when it sent one', () => {
        const withGroups = mountPage({
            document: makeDocument({
                blocks: [makeBlock({ group_spans: [{ label: 'Oralidade', span: 1 }, { label: 'Gramática', span: 1 }, { label: null, span: 4 }] })],
            }),
        });

        const groupLine = withGroups.find('[data-block] table thead tr:nth-of-type(1)');
        expect(groupLine.text()).toContain('Oralidade');
        expect(groupLine.text()).toContain('Gramática');

        const without = mountPage();
        expect(without.findAll('[data-block] table thead tr')).toHaveLength(2); // headers + quota line, no group line
    });

    it('draws one table per block, captions only when there are several, and starts the next block on a new page', () => {
        const single = mountPage();
        expect(single.findAll('[data-block]')).toHaveLength(1);
        expect(single.text()).not.toContain('Parte 1 de 2');

        const wrapper = mountPage({
            document: makeDocument({
                orientation: 'landscape',
                blocks: [makeBlock({ caption: 'Parte 1 de 2' }), makeBlock({ caption: 'Parte 2 de 2' })],
            }),
        });

        const sections = wrapper.findAll('[data-block]');
        expect(sections.map((section) => section.find('h2').text())).toEqual(['Parte 1 de 2', 'Parte 2 de 2']);
        expect(sections[0].classes()).not.toContain('print:break-before-page');
        expect(sections[1].classes()).toContain('print:break-before-page');
    });

    it('wraps the sheet in the print hook with the orientation the server chose, compact when landscape', () => {
        const portrait = mountPage();
        expect(portrait.find('.document-print').attributes('data-orientation')).toBe('portrait');
        expect(portrait.find('.document-print').attributes('data-compact')).toBe('false');

        const landscape = mountPage({ document: makeDocument({ orientation: 'landscape' }) });
        expect(landscape.find('.document-print').attributes('data-orientation')).toBe('landscape');
        expect(landscape.find('.document-print').attributes('data-compact')).toBe('true');
    });

    it('never lets a table wrapper clip the paper', () => {
        const wrapper = mountPage();

        for (const scroller of wrapper.findAll('.overflow-x-auto')) {
            expect(scroller.classes()).toContain('print:overflow-visible');
        }
    });

    it('turns an option off by asking the server again, with the whole query, replacing the history entry', async () => {
        const wrapper = mountPage();

        await wrapper.find('#grid-doc-raw').trigger('click');

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet).toHaveBeenCalledWith(
            '/instruments/inst-1/impressao',
            { bruta: '0', resultado: '1', dominios: '1', legenda: '1', graficos: '0', orientacao: 'auto' },
            { replace: true, preserveScroll: true, preserveState: true },
        );
    });

    it('sends the orientation in the words the server reads', async () => {
        const wrapper = mountPage();

        await wrapper.find('#grid-doc-orientation').setValue('landscape');

        expect(routerGet).toHaveBeenCalledWith(
            '/instruments/inst-1/impressao',
            expect.objectContaining({ orientacao: 'horizontal' }),
            expect.anything(),
        );
    });

    it('disables the domain option when the instrument touches fewer than two domains', () => {
        const wrapper = mountPage({ document: makeDocument({ summary: { students: 2, excluded: 0, questions: 2, blocks: 1, domains: 1 } }) });

        expect(wrapper.find('#grid-doc-domains').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('só quando o elemento toca dois ou mais domínios');
    });

    it('prints through the browser, and offers the PDF as a plain download link', async () => {
        const print = vi.spyOn(window, 'print').mockImplementation(() => undefined);
        const wrapper = mountPage();

        const printButton = wrapper.findAll('button').find((button) => button.text().includes('Imprimir'));
        const pdfLink = wrapper.findAll('a').find((anchor) => anchor.text() === 'PDF');

        expect(pdfLink?.attributes('href')).toBe(
            '/instruments/inst-1/impressao/pdf?bruta=1&resultado=1&dominios=1&legenda=1&graficos=0&orientacao=auto',
        );
        expect(pdfLink?.attributes('disabled')).toBeUndefined();

        await printButton?.trigger('click');
        await wrapper.vm.$nextTick();
        await Promise.resolve();

        expect(print).toHaveBeenCalledTimes(1);
    });

    it('asks for the PDF with the options the preview is showing, in the words the server reads', () => {
        const wrapper = mountPage({
            document: makeDocument({
                options: { raw_score: false, result: true, domains: false, legend: false, charts: false, orientation: 'landscape' },
                orientation: 'landscape',
            }),
        });

        const pdfLink = wrapper.findAll('a').find((anchor) => anchor.text() === 'PDF');

        expect(pdfLink?.attributes('href')).toBe(
            '/instruments/inst-1/impressao/pdf?bruta=0&resultado=1&dominios=0&legenda=0&graficos=0&orientacao=horizontal',
        );
    });

    it('lists the legend of the questions only when the server sent it, and the states in use', () => {
        const wrapper = mountPage();
        expect(wrapper.text()).toContain('Equações');
        expect(wrapper.text()).toContain('Números e Operações 100 %');
        expect(wrapper.text()).toContain('Por avaliar (não é zero)');

        const without = mountPage({ document: makeDocument({ legend: { questions: null, states: [] } }) });
        expect(without.text()).not.toContain('Equações');
    });

    it('says plainly when nobody is covered, and shows the excluded count note', () => {
        const wrapper = mountPage({
            document: makeDocument({ is_empty: true, blocks: [], excluded_note: '1 aluno não abrangido por este elemento de avaliação não consta.' }),
        });

        expect(wrapper.text()).toContain('Não há alunos abrangidos por este elemento de avaliação.');
        expect(wrapper.text()).toContain('1 aluno não abrangido');
        expect(wrapper.findAll('[data-block]')).toHaveLength(0);
    });
});
