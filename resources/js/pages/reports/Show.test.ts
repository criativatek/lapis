import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Show from './Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), get: vi.fn() },
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            post: vi.fn(),
            put: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
        }),
}));

type Block =
    | { kind: 'paragraph'; text: string }
    | { kind: 'list'; lead: string | null; items: string[] };

const wrappers: VueWrapper[] = [];

/**
 * The section shapes below are what `ReportController::sectionsPayload` sends:
 * `body` is the text the teacher edits, `blocks` is that same body already cut
 * into the structure the .docx and the PDF are built from.
 */
function section(overrides: Record<string, unknown> = {}) {
    return {
        ulid: 'section-1',
        key: 'interventions_summary',
        heading: 'Medidas de apoio',
        position: 1,
        included: true,
        body: 'Foram registadas duas intervenções pedagógicas.',
        blocks: [
            { kind: 'paragraph', text: 'Foram registadas duas intervenções pedagógicas.' },
        ] as Block[],
        edited: false,
        can_restore: false,
        has_content: true,
        sources: [],
        data: null,
        ...overrides,
    };
}

function baseProps(sections = [section()]) {
    return {
        report: {
            ulid: 'report-1',
            title: 'Relatório de turma',
            type: 'class',
            type_label: 'Turma',
            // Finalized, so the page opens on the document rather than the
            // editor: the preview is what this file is about.
            status: 'finalized',
            status_label: 'Finalizado',
            tone: 'neutral',
            scope_label: '1.º Período',
            scope_kind: 'term',
            subject_label: 'Matemática',
            teacher_input: {},
            name_students: false,
            author: 'Ana Martins',
            updated_at: '2026-08-31T10:00:00+01:00',
            finalized_at: '2026-08-31T10:00:00+01:00',
            finalized_by: 'Ana Martins',
            based_on: null,
        },
        sections,
        identity: {
            name: 'Agrupamento de Escolas de Exemplo',
            header_lines: [],
            footer_note: null,
            logo_url: null,
            is_configured: true,
        },
        heading: { title: 'Relatório de turma', subtitle: '7.º A · Matemática' },
        signatureCaption: 'Docente responsável',
        logo: { shown: false, available: false },
        characterisation: { available: false, asks_planning: false, planning: [] },
        library: null,
        enrollments: [],
        comparison: null,
        can: { update: false, finalize: false, delete: false, export: false, derive: false },
        canSaveTemplate: { personal: false, institutional: false },
        ai: { available: false, reason: null, modes: [] },
    };
}

function render(sections?: ReturnType<typeof section>[]) {
    const wrapper = mount(Show, { props: baseProps(sections) });
    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('reports/Show — the preview is the same document as the file', () => {
    // ------------------------------------------------------ §2 o encerramento

    it('names a role under the signature rule and never a gender', () => {
        expect(render().text()).toContain('Docente responsável');
    });

    it('takes the caption from the server instead of keeping its own copy', () => {
        // Nothing about the caption is decided on this side: change what the
        // server sends and the screen follows, which is the whole point.
        const wrapper = mount(Show, {
            props: { ...baseProps(), signatureCaption: 'Outra legenda' },
        });
        wrappers.push(wrapper);

        expect(wrapper.text()).toContain('Outra legenda');
    });

    // ---------------------------------------------------------- §3 as listas

    it('renders a list block as a real list and not as a paragraph with dashes', () => {
        const wrapper = render([
            section({
                body: 'Destacam-se as seguintes medidas:\n— Apoio à planificação textual.\n— Reforço da leitura em voz alta.',
                blocks: [
                    {
                        kind: 'list',
                        lead: 'Destacam-se as seguintes medidas:',
                        items: ['Apoio à planificação textual.', 'Reforço da leitura em voz alta.'],
                    },
                ] as Block[],
            }),
        ]);

        const items = wrapper.findAll('ul li');

        expect(items).toHaveLength(2);
        expect(items[0].text()).toBe('Apoio à planificação textual.');
        expect(items[1].text()).toBe('Reforço da leitura em voz alta.');
    });

    it('never prints the item marker the composers write', () => {
        const wrapper = render([
            section({
                body: 'Destacam-se as seguintes medidas:\n— Apoio à planificação textual.',
                blocks: [
                    {
                        kind: 'list',
                        lead: 'Destacam-se as seguintes medidas:',
                        items: ['Apoio à planificação textual.'],
                    },
                ] as Block[],
            }),
        ]);

        // «— » is a convention between the composers and the document builder.
        // A reader of the finished document never meets it.
        expect(wrapper.text()).not.toContain('— Apoio');
    });

    it('keeps the lead-in as a sentence above the list, not as an item of it', () => {
        const wrapper = render([
            section({
                blocks: [
                    {
                        kind: 'list',
                        lead: 'Destacam-se as seguintes medidas:',
                        items: ['Apoio à planificação textual.'],
                    },
                ] as Block[],
            }),
        ]);

        expect(wrapper.text()).toContain('Destacam-se as seguintes medidas:');
        expect(wrapper.findAll('ul li')).toHaveLength(1);
    });

    it('leaves a list with no lead-in without an empty first item', () => {
        const wrapper = render([
            section({
                blocks: [
                    { kind: 'list', lead: null, items: ['Apoio à planificação textual.'] },
                ] as Block[],
            }),
        ]);

        expect(wrapper.findAll('ul li')).toHaveLength(1);
    });

    // ------------------------------------------------------ §4 os parágrafos

    it('still renders prose as prose', () => {
        const wrapper = render();

        expect(wrapper.findAll('ul li')).toHaveLength(0);
        expect(wrapper.text()).toContain('Foram registadas duas intervenções pedagógicas.');
    });

    it('loses nothing when a section mixes paragraphs and a list', () => {
        const wrapper = render([
            section({
                blocks: [
                    { kind: 'paragraph', text: 'A turma manteve o desempenho do período anterior.' },
                    {
                        kind: 'list',
                        lead: 'Destacam-se as seguintes medidas:',
                        items: ['Apoio à planificação textual.'],
                    },
                    { kind: 'paragraph', text: 'Nenhuma medida foi concluída até à data.' },
                ] as Block[],
            }),
        ]);

        const text = wrapper.text();

        expect(text).toContain('A turma manteve o desempenho do período anterior.');
        expect(text).toContain('Destacam-se as seguintes medidas:');
        expect(text).toContain('Apoio à planificação textual.');
        expect(text).toContain('Nenhuma medida foi concluída até à data.');
    });

    it('keeps the order the document has', () => {
        const wrapper = render([
            section({
                blocks: [
                    { kind: 'paragraph', text: 'Primeiro parágrafo.' },
                    { kind: 'list', lead: null, items: ['Um item isolado.'] },
                    { kind: 'paragraph', text: 'Último parágrafo.' },
                ] as Block[],
            }),
        ]);

        const text = wrapper.text();

        expect(text.indexOf('Primeiro parágrafo.')).toBeLessThan(text.indexOf('Um item isolado.'));
        expect(text.indexOf('Um item isolado.')).toBeLessThan(text.indexOf('Último parágrafo.'));
    });

    // ----------------------------------------------------------- §5 escaping

    it('prints what a teacher typed as text and never as markup', () => {
        const wrapper = render([
            section({
                blocks: [
                    { kind: 'paragraph', text: '<b>negrito</b> & «aspas»' },
                    { kind: 'list', lead: '<i>Medidas</i>', items: ['<img src=x onerror=1>'] },
                ] as Block[],
            }),
        ]);

        const html = wrapper.html();

        expect(html).not.toContain('<b>negrito</b>');
        expect(html).not.toContain('<i>Medidas</i>');
        expect(html).not.toContain('<img src=x');
        expect(wrapper.text()).toContain('<b>negrito</b> & «aspas»');
        expect(wrapper.text()).toContain('<img src=x onerror=1>');
    });
});

// ------------------------------------------------------------ §6 a sentinela

/**
 * DELIBERATELY NARROW. The document has its own sentinel in
 * `ReportDocumentProseTest`; this one watches the single string that outlived
 * its removal from the document because no test on this side was looking.
 */
describe('reports/Show — the preview sentinel', () => {
    it('never prints the parenthetical gender the document dropped', () => {
        const cases = [
            [section()],
            [
                section({
                    blocks: [
                        {
                            kind: 'list',
                            lead: 'Destacam-se as seguintes medidas:',
                            items: ['Apoio à planificação textual.'],
                        },
                    ] as Block[],
                }),
            ],
        ];

        for (const sections of cases) {
            const wrapper = render(sections);

            expect(wrapper.text()).not.toContain('O(A) professor(a)');
            expect(wrapper.text()).not.toContain('professor(a)');
        }
    });
});

/**
 * IMPRIMIR, AO LADO DO PDF E DO WORD (§45).
 *
 * O que sai da impressora é a PRÉ-VISUALIZAÇÃO — o mesmo documento que o
 * professor tem à frente, com o mesmo timbre, as mesmas secções e o mesmo
 * fecho. Uma composição escrita só para o papel seria uma terceira coisa a
 * manter em dia com o PDF e com o .docx, e a primeira a divergir.
 */
describe('reports/Show — imprimir', () => {
    function exportable() {
        return {
            ...baseProps(),
            can: { update: false, finalize: false, delete: false, export: true, derive: false },
        };
    }

    it('oferece Imprimir junto do PDF e do Word', () => {
        const wrapper = mount(Show, { props: exportable() });
        wrappers.push(wrapper);

        const text = wrapper.text();

        expect(text).toContain('Imprimir');
        expect(text).toContain('PDF');
        expect(text).toContain('Word');
    });

    it('não oferece Imprimir a quem não pode exportar', () => {
        // A mesma porta que o PDF e o Word: esconder a ação não é o que guarda
        // nada — a rota decide no servidor —, é só não abrir uma porta que dá
        // para um 403.
        expect(render().text()).not.toContain('Imprimir');
    });

    it('marca a pré-visualização com o gancho que a folha de impressão usa', () => {
        // `report-print` é um gancho estável. Um seletor pelas classes
        // utilitárias partir-se-ia na primeira vez que alguém mexesse na
        // largura da coluna.
        expect(render().find('.report-print').exists()).toBe(true);
    });
});
