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
        options: { self_assessment: false, orientation: 'landscape' },
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
        expect(link!.attributes('href')).toBe('/classes/class-1/pauta-avaliacao/impressao/period-1/pdf?autoavaliacao=0&orientacao=horizontal');
        expect(wrapper.findAll('button').map((button) => button.text())).toContain('Imprimir');
        // Charts are the next step: no control for what does not exist yet.
        expect(wrapper.text()).not.toContain('Gráficos');
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
            document: makeDocument({ options: { self_assessment: true, orientation: 'portrait' } }),
        });

        const link = wrapper.findAll('a').find((anchor) => anchor.text() === 'PDF');

        expect(link!.attributes('href')).toBe('/classes/class-1/pauta-avaliacao/impressao/period-1/pdf?momento=interim&autoavaliacao=1&orientacao=vertical');
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

        expect(link!.attributes('href')).toBe('/classes/class-1/pauta-avaliacao/historico/exp-1/impressao/pdf?autoavaliacao=0&orientacao=horizontal');
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
            { momento: 'interim', autoavaliacao: '1', orientacao: 'horizontal' },
            { replace: true, preserveScroll: true, preserveState: true },
        );
    });

    it('changes the orientation by asking the server again, keeping the options', async () => {
        const wrapper = mountPage({ document: makeDocument({ options: { self_assessment: true, orientation: 'landscape' } }) });

        await wrapper.find('#sheet-doc-orientation').setValue('portrait');

        expect(routerGet).toHaveBeenCalledWith(
            '/classes/class-1/pauta-avaliacao/impressao/period-1',
            { autoavaliacao: '1', orientacao: 'vertical' },
            { replace: true, preserveScroll: true, preserveState: true },
        );
    });

    it('prints in the orientation the server applied, and says so', () => {
        const portrait = mountPage({ document: makeDocument({ orientation: 'portrait', options: { self_assessment: false, orientation: 'auto' } }) });

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
});
