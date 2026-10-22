import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import GridDocument from './GridDocument.vue';

type Props = InstanceType<typeof GridDocument>['$props'];
type Doc = Props['document'];

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

const chartsOn = { self_assessment: false, orientation: 'landscape', charts: true };

function makeCharts(overrides: Record<string, unknown> = {}) {
    return {
        proposals: {
            title: 'Distribuição dos níveis propostos',
            basis: 'Proposta do Lapispro, por nível da escala',
            available: true,
            unavailable_message: null as string | null,
            n_label: 'n = 8 alunos com proposta',
            bars: [
                { label: '1 · Insuficiente', value_text: '1 · 12,5 %', detail: null, width: '12.5' as string | null, negative: true },
                { label: '2 · Insuficiente', value_text: '0 · 0,0 %', detail: null, width: '0.0' as string | null, negative: true },
                { label: '3 · Suficiente', value_text: '7 · 87,5 %', detail: null, width: '87.5' as string | null, negative: false },
            ],
            missing_note: 'Sem proposta: 1' as string | null,
            unplaced_note: null as string | null,
            caution: null as string | null,
            empty_message: 'Sem dados: nenhum aluno tem ainda um nível para contar.',
        },
        assigned: null as Record<string, unknown> | null,
        means: {
            title: 'Média da turma',
            basis: 'Global e por domínio',
            axis: 'Escala absoluta de 0 a 100 %.',
            bars: [
                { label: 'Global', value_text: '67,9 %', detail: 'n = 8 alunos com resultado', width: '67.9' as string | null, negative: false },
                { label: 'Oralidade', value_text: '—', detail: 'sem resultados', width: null as string | null, negative: false },
            ],
            missing_note: null as string | null,
            unplaced_note: null as string | null,
            caution: null as string | null,
            empty_message: 'Sem resultados: nenhum aluno tem ainda um resultado calculado.',
        },
        ...overrides,
    };
}

const cell = (text: string, overrides: Record<string, unknown> = {}) => ({ text, detail: null, origin: null, mark: null, ...overrides });

function makeBlock(overrides: Record<string, unknown> = {}) {
    return {
        caption: null,
        columns: [
            { kind: 'domain_result', label: 'Resultado' },
            { kind: 'domain_appreciation', label: 'Apreciação' },
            { kind: 'result', label: 'Resultado' },
            { kind: 'proposal', label: 'Proposta do Lapispro' },
            { kind: 'assigned', label: 'Nível atribuído' },
            { kind: 'status', label: 'Estado' },
            { kind: 'coverage', label: 'Avisos de cobertura' },
        ],
        group_spans: [
            { label: 'Oralidade', span: 2 },
            { label: 'Global', span: 1 },
            { label: 'Classificação', span: 3 },
            { label: null, span: 1 },
        ],
        rows: [
            {
                number: '1',
                name: 'Ana Silva',
                cells: [
                    cell('81,3%'),
                    cell('MB', { origin: 'decision', mark: 'prof.', detail: 'Proposta: B' }),
                    cell('78,5%', { detail: 'B' }),
                    cell('B'),
                    cell('MB', { origin: 'decision' }),
                    cell('Confirmada'),
                    cell('', {}),
                ],
            },
            {
                number: '',
                name: 'Rui Costa',
                cells: [cell('—'), cell('—'), cell('—'), cell('—'), cell('—', { detail: 'não decidido' }), cell('Proposta'), cell('Global')],
            },
        ],
        ...overrides,
    };
}

function makeDocument(overrides: Record<string, unknown> = {}): Doc {
    return {
        title: 'Semestre — 1.º Semestre',
        kind_label: 'Grelha factual de avaliação da turma',
        subtitle: '7.º A · Português · 1.º Semestre',
        identity: { name: 'Escola Teste', header_lines: [], footer_note: null, is_configured: true, logo_url: null },
        issued_label: 'Emitido a 15/12/2026, 10:00',
        meta: {
            class: '7.º A',
            subject: 'Português',
            academic_year: '2026/2027',
            period_kind: 'Semestre',
            period: '1.º Semestre',
            moment: 'Semestre — 1.º Semestre',
            scope: 'Período',
            reference_date: null,
        },
        source: { is_historical: false, label: 'Estado à data de emissão', kept_at: null, author: null },
        state: { is_provisional: true, label: 'PROVISÓRIO' },
        options: { self_assessment: false, orientation: 'landscape', charts: false },
        charts: null,
        self_assessment_available: true,
        orientation: 'landscape',
        blocks: [makeBlock()],
        summary: { students: 2, domains: 1, blocks: 1, decided: 1, pending: 1 },
        notes: ['O resultado é a percentagem calculada pelo motor de avaliação a partir das pontuações registadas.'],
        warnings: [],
        notice: 'Documento gerado a partir dos registos à data de emissão.',
        is_empty: false,
        empty_message: 'Não há alunos nesta turma.',
        ...overrides,
    } as Doc;
}

function mountPage(overrides: Partial<Props> | Record<string, unknown> = {}) {
    const wrapper = mount(GridDocument, {
        props: {
            schoolClass: { ulid: 'class-1', label: '7.º A' },
            source: {
                kind: 'live',
                moment: 'final',
                back: { href: '/classes/class-1/pauta-avaliacao/period-1', label: 'Voltar à pauta' },
                base_url: '/classes/class-1/pauta-avaliacao/impressao/period-1',
                fixed_query: {},
            },
            document: makeDocument(),
            ...overrides,
        } as Props,
    });
    wrappers.push(wrapper);

    return wrapper;
}

describe('evaluation-sheets/GridDocument.vue', () => {
    afterEach(() => {
        wrappers.forEach((wrapper) => wrapper.unmount());
        wrappers.length = 0;
        routerGet.mockReset();
        vi.restoreAllMocks();
    });

    it('prints exactly what the server composed: every cell, mark and detail, nothing recomputed', () => {
        const wrapper = mountPage();
        const text = wrapper.text();

        for (const expected of ['Ana Silva', 'Rui Costa', '81,3%', 'prof.', 'Proposta: B', '78,5%', 'Confirmada', 'não decidido', 'Oralidade', 'Classificação']) {
            expect(text).toContain(expected);
        }

        const grid = wrapper.find('[data-block] table');
        const headers = grid.findAll('thead tr:nth-of-type(2) th').map((th) => th.text());
        expect(headers).toEqual(['Nº', 'Nome', 'Resultado', 'Apreciação', 'Resultado', 'Proposta do Lapispro', 'Nível atribuído', 'Estado', 'Avisos de cobertura']);
        expect(grid.findAll('thead tr:nth-of-type(1) th').map((th) => th.text())).toEqual(['', 'Oralidade', 'Global', 'Classificação', '']);
    });

    it('keeps the teacher decision apart from the proposal, in words: the mark and the proposal underneath', () => {
        const wrapper = mountPage();
        const decided = wrapper.findAll('[data-origin="decision"]')[0];

        expect(decided.text()).toContain('MB');
        expect(decided.text()).toContain('prof.');
        expect(decided.text()).toContain('Proposta: B');
    });

    it('offers the PDF as a plain link carrying the options the preview is showing', () => {
        const wrapper = mountPage();
        const link = wrapper.findAll('a').find((anchor) => anchor.text() === 'PDF');

        expect(link).toBeDefined();
        expect(link!.attributes('href')).toBe('/classes/class-1/pauta-avaliacao/impressao/period-1/pdf?autoavaliacao=0&graficos=0&orientacao=horizontal');
        expect(wrapper.findAll('button').map((button) => button.text())).toContain('Imprimir');
    });

    it('keeps the moment and every chosen option in the PDF link', () => {
        const wrapper = mountPage({
            source: {
                kind: 'live',
                moment: 'interim',
                back: { href: '/classes/class-1/pauta-avaliacao/period-1?momento=interim', label: 'Voltar à pauta' },
                base_url: '/classes/class-1/pauta-avaliacao/impressao/period-1',
                fixed_query: { momento: 'interim' },
            },
            document: makeDocument({ options: { self_assessment: true, orientation: 'portrait', charts: false } }),
        });

        const link = wrapper.findAll('a').find((anchor) => anchor.text() === 'PDF');

        expect(link!.attributes('href')).toBe('/classes/class-1/pauta-avaliacao/impressao/period-1/pdf?momento=interim&autoavaliacao=1&graficos=0&orientacao=vertical');
    });

    it('points the PDF of a kept moment at that moment, never at the live sheet', () => {
        const wrapper = mountPage({
            source: {
                kind: 'snapshot',
                moment: null,
                back: { href: '/classes/class-1/pauta-avaliacao/historico/exp-1', label: 'Voltar à pauta guardada' },
                base_url: '/classes/class-1/pauta-avaliacao/historico/exp-1/impressao',
                fixed_query: {},
            },
            document: makeDocument({ source: { is_historical: true, label: 'Momento guardado a 16/12/2026, 10:30', kept_at: null, author: null } }),
        });

        const link = wrapper.findAll('a').find((anchor) => anchor.text() === 'PDF');

        expect(link!.attributes('href')).toBe('/classes/class-1/pauta-avaliacao/historico/exp-1/impressao/pdf?autoavaliacao=0&graficos=0&orientacao=horizontal');
    });

    it('marks a provisional grid on screen and on the sheet itself, which is what is printed', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('Valores provisórios.');
        expect(wrapper.text()).toContain('1 aluno ainda sem nível atribuído');
        expect(wrapper.find('.document-print').text()).toContain('PROVISÓRIO');
    });

    it('puts the provisional stamp and the page numbers in the page margins, so every printed sheet has them', () => {
        const provisional = mountPage();
        const rule = document.head.querySelector('style[data-document-print-page]')?.textContent ?? '';

        expect(rule).toContain('@top-right { content: "PROVISÓRIO";');
        expect(rule).toContain('@bottom-right { content: "Página " counter(page) " / " counter(pages);');

        provisional.unmount();
        wrappers.pop();

        const settled = mountPage({ document: makeDocument({ state: { is_provisional: false, label: 'Classificações tomadas' } }) });
        const settledRule = document.head.querySelector('style[data-document-print-page]')?.textContent ?? '';

        expect(settledRule).not.toContain('@top-right');
        expect(settledRule).toContain('@bottom-right');
        settled.unmount();
        wrappers.pop();
    });

    it('does not claim provisional values once everyone has a level assigned', () => {
        const wrapper = mountPage({
            document: makeDocument({ state: { is_provisional: false, label: 'Classificações tomadas' }, summary: { students: 2, domains: 1, blocks: 1, decided: 2, pending: 0 } }),
        });

        expect(wrapper.text()).not.toContain('Valores provisórios.');
        expect(wrapper.find('.document-print').text()).toContain('Classificações tomadas');
    });

    it('says what the live sheet is: the state on the day of issue, generated on the spot', () => {
        const wrapper = mountPage();

        expect(wrapper.find('.document-print').text()).toContain('Estado à data de emissão');
        expect(wrapper.text()).toContain('gerado na hora, a partir dos registos atuais');
        expect(wrapper.text()).not.toContain('Pauta guardada');
    });

    it('identifies a kept moment, when and by whom, and that nothing has changed since', () => {
        const wrapper = mountPage({
            source: {
                kind: 'snapshot',
                moment: null,
                back: { href: '/classes/class-1/pauta-avaliacao/historico/exp-1', label: 'Voltar à pauta guardada' },
                base_url: '/classes/class-1/pauta-avaliacao/historico/exp-1/impressao',
                fixed_query: {},
            },
            document: makeDocument({
                title: 'Momento de novembro',
                source: { is_historical: true, label: 'Momento guardado a 16/12/2026, 10:30', kept_at: '2026-12-16T10:30:00+00:00', author: 'Professora Teste' },
                meta: { ...makeDocument().meta, reference_date: '15/12/2026' },
                warnings: ['Aviso registado no momento.'],
            }),
        });

        expect(wrapper.text()).toContain('Pauta guardada — vista só de leitura.');
        expect(wrapper.text()).toContain('Momento guardado a 16/12/2026, 10:30 por Professora Teste');
        expect(wrapper.text()).toContain('não refletem alterações posteriores');
        expect(wrapper.find('.document-print').text()).toContain('Data de referência 15/12/2026');
        expect(wrapper.find('.document-print').text()).toContain('Aviso registado: Aviso registado no momento.');
        expect(wrapper.find('a').attributes('href')).toBe('/classes/class-1/pauta-avaliacao/historico/exp-1');
        expect(wrapper.find('a').text()).toContain('Voltar à pauta guardada');
    });

    it('offers the self-assessment, off, and disables it — saying why — when the sheet has none', () => {
        const offered = mountPage();
        expect(offered.find('#sheet-doc-self-assessment').attributes('disabled')).toBeUndefined();
        expect(offered.find('#sheet-doc-self-assessment').attributes('aria-checked')).toBe('false');

        const none = mountPage({ document: makeDocument({ self_assessment_available: false }) });
        expect(none.find('#sheet-doc-self-assessment').attributes('disabled')).toBeDefined();
        expect(none.text()).toContain('não há autoavaliação nesta pauta');
    });

    it('turns the self-assessment on by asking the server again, keeping the moment', async () => {
        const wrapper = mountPage({
            source: {
                kind: 'live',
                moment: 'interim',
                back: { href: '/classes/class-1/pauta-avaliacao/period-1?momento=interim', label: 'Voltar à pauta' },
                base_url: '/classes/class-1/pauta-avaliacao/impressao/period-1',
                fixed_query: { momento: 'interim' },
            },
        });

        await wrapper.find('#sheet-doc-self-assessment').trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/classes/class-1/pauta-avaliacao/impressao/period-1',
            { momento: 'interim', autoavaliacao: '1', graficos: '0', orientacao: 'horizontal' },
            { replace: true, preserveScroll: true, preserveState: true },
        );
    });

    it('changes the orientation by asking the server again, keeping the options', async () => {
        const wrapper = mountPage({ document: makeDocument({ options: { self_assessment: true, orientation: 'landscape', charts: false } }) });

        await wrapper.find('#sheet-doc-orientation').setValue('portrait');

        expect(routerGet).toHaveBeenCalledWith(
            '/classes/class-1/pauta-avaliacao/impressao/period-1',
            { autoavaliacao: '1', graficos: '0', orientacao: 'vertical' },
            { replace: true, preserveScroll: true, preserveState: true },
        );
    });

    it('prints in the orientation the server applied, and says so', () => {
        const portrait = mountPage({ document: makeDocument({ orientation: 'portrait', options: { self_assessment: false, orientation: 'auto', charts: false } }) });

        expect(portrait.text()).toContain('Orientação aplicada: vertical');
        expect(portrait.find('.document-print').exists()).toBe(true);
    });

    it('lets a wide sheet scroll on a narrow screen but never clip on paper, one block per page', () => {
        const wrapper = mountPage({ document: makeDocument({ blocks: [makeBlock({ caption: 'Parte 1 de 2' }), makeBlock({ caption: 'Parte 2 de 2' })] }) });
        const blocks = wrapper.findAll('[data-block]');

        expect(blocks).toHaveLength(2);
        expect(blocks[0].find('h2').text()).toBe('Parte 1 de 2');
        expect(blocks[0].classes()).not.toContain('print:break-before-page');
        expect(blocks[1].classes()).toContain('print:break-before-page');

        for (const block of blocks) {
            const scroller = block.find('div.overflow-x-auto');
            expect(scroller.exists()).toBe(true);
            expect(scroller.classes()).toContain('print:overflow-visible');
        }
    });

    it('draws an empty class as a sentence, not as an empty table', () => {
        const wrapper = mountPage({ document: makeDocument({ is_empty: true, blocks: [] }) });

        expect(wrapper.text()).toContain('Não há alunos nesta turma.');
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('keeps the controls out of the printed sheet', () => {
        const wrapper = mountPage();

        expect(wrapper.find('.print\\:hidden').exists()).toBe(true);
        expect(wrapper.find('.document-print').find('button').exists()).toBe(false);
    });

    it('offers the charts, off, and asks the server again with graficos=1 keeping the other options', async () => {
        const wrapper = mountPage();

        expect(wrapper.find('#sheet-doc-charts').attributes('aria-checked')).toBe('false');
        expect(wrapper.find('[data-charts]').exists()).toBe(false);

        await wrapper.find('#sheet-doc-charts').trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/classes/class-1/pauta-avaliacao/impressao/period-1',
            { autoavaliacao: '0', graficos: '1', orientacao: 'horizontal' },
            { replace: true, preserveScroll: true, preserveState: true },
        );
    });

    it('draws every bar with the length, text and note the server measured and composed', () => {
        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts: makeCharts() }) });
        const section = wrapper.find('[data-charts]');

        // The assigned chart is absent when nobody was given a level: two charts, not three.
        expect(section.findAll('[data-chart]')).toHaveLength(2);
        expect(section.text()).toContain('Distribuição dos níveis propostos');
        expect(section.text()).toContain('Proposta do Lapispro, por nível da escala · n = 8 alunos com proposta');
        expect(section.text()).toContain('Sem proposta: 1');

        const first = section.findAll('[data-chart]')[0];
        expect(first.text()).toContain('1 · 12,5 %');
        expect(first.text()).toContain('7 · 87,5 %');
        expect(first.findAll('li span[style]').map((fill) => fill.attributes('style'))).toEqual(['width: 12.5%;', 'width: 87.5%;']);

        const means = section.findAll('[data-chart]')[1];
        expect(means.text()).toContain('Escala absoluta de 0 a 100 %.');
        expect(means.text()).toContain('n = 8 alunos com resultado');
    });

    it('draws no fill for a level nobody has or a domain without results: an empty track, never a bar at zero', () => {
        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts: makeCharts() }) });
        const [proposals, means] = wrapper.findAll('[data-chart]');

        expect(proposals.findAll('li')[1].text()).toContain('0 · 0,0 %');
        expect(proposals.findAll('li')[1].find('span[style]').exists()).toBe(false);
        expect(means.findAll('li')[1].text()).toContain('—');
        expect(means.findAll('li')[1].find('span[style]').exists()).toBe(false);
    });

    it('draws the assigned chart apart from the proposals when somebody was given a level', () => {
        const assigned = { ...makeCharts().proposals, title: 'Distribuição dos níveis atribuídos', basis: 'Decisão do professor, por nível da escala', n_label: 'n = 2 de 8 alunos com nível atribuído' };
        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts: makeCharts({ assigned }) }) });
        const titles = wrapper.findAll('[data-chart] h2').map((heading) => heading.text());

        expect(titles).toEqual(['Distribuição dos níveis propostos', 'Distribuição dos níveis atribuídos', 'Média da turma']);
        expect(wrapper.text()).toContain('n = 2 de 8 alunos com nível atribuído');
    });

    it('marks a negative level apart, keeps the bars printable and a chart in one piece', () => {
        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts: makeCharts() }) });
        const fills = wrapper.findAll('[data-chart]')[0].findAll('li span[style]');

        expect(fills[0].classes()).toContain('bg-foreground/30');
        expect(fills[1].classes()).not.toContain('bg-foreground/30');

        for (const fill of wrapper.findAll('[data-chart] li span[style]')) {
            expect(fill.classes().join(' ')).toContain('print-color-adjust');
        }

        expect(wrapper.find('[data-chart]').classes()).toContain('break-inside-avoid');
    });

    it('says a level chart is not available for a sheet kept before the scale travelled with it, and still draws the means', () => {
        const charts = makeCharts();
        charts.proposals = { ...charts.proposals, available: false, bars: [], unavailable_message: 'Esta pauta foi guardada antes de a escala viajar com ela: o gráfico por nível não está disponível.', missing_note: null };

        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts }) });
        const [proposals, means] = wrapper.findAll('[data-chart]');

        expect(proposals.text()).toContain('guardada antes de a escala viajar com ela');
        expect(proposals.findAll('li')).toHaveLength(0);
        expect(means.findAll('li')).toHaveLength(2);
    });

    it('shows the caution, the results outside the bands and the students counted aside', () => {
        const charts = makeCharts();
        charts.proposals.caution = 'Poucos alunos (n = 3): leia com cautela.';
        charts.proposals.unplaced_note = '1 aluno com um nível que não consta da escala desta pauta não aparece em nenhuma barra.';
        charts.means.unplaced_note = '2 resultados acima de 100 % (por exemplo, por efeito de bónus) entram na média e a barra fica no fim do eixo.';
        charts.means.missing_note = 'Sem resultado global: 1';

        const text = mountPage({ document: makeDocument({ options: chartsOn, charts }) }).find('[data-charts]').text();

        for (const expected of ['Poucos alunos (n = 3): leia com cautela.', 'não consta da escala desta pauta', '2 resultados acima de 100 %', 'Sem resultado global: 1']) {
            expect(text).toContain(expected);
        }
    });

    it('draws charts in the sheet that prints and keeps the PDF link carrying graficos', () => {
        const wrapper = mountPage({ document: makeDocument({ options: chartsOn, charts: makeCharts() }) });

        expect(wrapper.find('.document-print').find('[data-charts]').exists()).toBe(true);
        expect(wrapper.findAll('a').find((anchor) => anchor.text() === 'PDF')!.attributes('href')).toContain('graficos=1');
    });
});
