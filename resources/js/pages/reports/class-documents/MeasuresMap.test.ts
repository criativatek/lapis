import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import MeasuresMap from './MeasuresMap.vue';

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

const classA = { ulid: 'class-a', label: '7.º A', subject: 'Português', academic_year: '2026/2027' };
const classB = { ulid: 'class-b', label: '8.º B', subject: 'Matemática', academic_year: '2026/2027' };

const item = (label: string, overrides: Record<string, unknown> = {}) => ({
    label,
    framing: null,
    status: null,
    group: false,
    ...overrides,
});

function makeDocument(overrides: Record<string, unknown> = {}) {
    return {
        title: 'Mapa de Medidas Educativas',
        subtitle: '7.º A · Português · Ano letivo 2026/2027',
        identity: { name: 'Escola Teste', header_lines: ['Rua A'], footer_note: null, is_configured: true, logo_url: null },
        issued_at: '2026-10-19T18:30:00+01:00',
        issued_label: 'Emitido a 19/10/2026, 18:30',
        columns: { implemented: true, planned: true },
        column_labels: { implemented: 'Implementadas', planned: 'A implementar' },
        summary: { students: 6, students_with_measures: 2, implemented: 2, planned: 1 },
        summary_line: '6 alunos · 2 com medidas · 2 implementadas · 1 a implementar',
        class_wide: { implemented: [], planned: [] },
        students: [
            {
                number: 1,
                name: 'Ana Silva',
                implemented: [
                    item('Reforço das aprendizagens', {
                        framing: 'Medida seletiva',
                        status: 'Em curso',
                        group: true,
                    }),
                ],
                planned: [],
            },
            { number: null, name: 'Rui Costa', implemented: [], planned: [item('Apoio tutorial')] },
        ],
        excluded_note: null,
        criteria: 'Implementadas: medidas em curso, concluídas ou suspensas.',
        empty_message: 'Não existem medidas educativas registadas.',
        notice: 'Documento gerado a partir dos registos à data de emissão; não é um relatório finalizado.',
        is_empty: false,
        ...overrides,
    };
}

function mountMap(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(MeasuresMap, {
        props: {
            classes: [classA, classB],
            schoolClass: classA,
            filters: { implemented: true, planned: true },
            document: makeDocument(),
            ...overrides,
        },
    });
    wrappers.push(wrapper);

    return wrapper;
}

describe('reports/class-documents/MeasuresMap.vue', () => {
    afterEach(() => {
        wrappers.forEach((wrapper) => wrapper.unmount());
        wrappers.length = 0;
        routerGet.mockReset();
    });

    it('renders the students in prop order, each name once in the table', () => {
        const wrapper = mountMap();
        const rows = wrapper.findAll('table tbody tr');

        expect(rows).toHaveLength(2);
        expect(rows[0].find('th').text()).toBe('N.º 1 · Ana Silva');
        // No number → just the name.
        expect(rows[1].find('th').text()).toBe('Rui Costa');
        expect(wrapper.find('table').text().match(/Ana Silva/g)).toHaveLength(1);
    });

    it('renders the document header and summary from the props', () => {
        const wrapper = mountMap();

        expect(wrapper.find('h1').text()).toBe('Mapa de Medidas Educativas');
        expect(wrapper.text()).toContain('7.º A · Português · Ano letivo 2026/2027');
        expect(wrapper.text()).toContain('Emitido a 19/10/2026, 18:30');
        expect(wrapper.text()).toContain('6 alunos · 2 com medidas');
        expect(wrapper.text()).toContain('Escola Teste');
    });

    it('joins framing, status and «em grupo» on the secondary line', () => {
        const wrapper = mountMap();
        const firstRow = wrapper.findAll('table tbody tr')[0];

        expect(firstRow.text()).toContain('Reforço das aprendizagens');
        expect(firstRow.text()).toContain('Medida seletiva · Em curso · em grupo');
    });

    it('shows an em dash for an empty column and keeps both columns when both are on', () => {
        const wrapper = mountMap();
        const headers = wrapper.findAll('table thead th').map((th) => th.text());

        expect(headers).toEqual(['Aluno', 'Implementadas', 'A implementar']);

        const cells = wrapper.findAll('table tbody tr')[0].findAll('td');
        expect(cells[1].text()).toBe('—');
    });

    it('hides a column, header and cells, when its filter is off', () => {
        const wrapper = mountMap({
            filters: { implemented: true, planned: false },
            document: makeDocument({ columns: { implemented: true, planned: false } }),
        });

        expect(wrapper.findAll('table thead th').map((th) => th.text())).toEqual(['Aluno', 'Implementadas']);
        expect(wrapper.findAll('table tbody tr')[0].findAll('td')).toHaveLength(1);
        expect(wrapper.find('table').text()).not.toContain('A implementar');
    });

    it('shows the class-wide section only when it has items in a shown column', () => {
        expect(mountMap().text()).not.toContain('Medidas dirigidas a toda a turma');

        const wrapper = mountMap({
            document: makeDocument({
                class_wide: { implemented: [item('Diferenciação pedagógica', { status: 'Em curso' })], planned: [] },
            }),
        });

        expect(wrapper.text()).toContain('Medidas dirigidas a toda a turma');
        expect(wrapper.text()).toContain('Diferenciação pedagógica');
        expect(wrapper.text()).toContain('Em curso');
    });

    it('shows the empty message instead of the table when the document is empty', () => {
        const wrapper = mountMap({ document: makeDocument({ students: [], is_empty: true }) });

        expect(wrapper.text()).toContain('Não existem medidas educativas registadas.');
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('closes the sheet with the criteria, the excluded note and the disclaimer', () => {
        const wrapper = mountMap({ document: makeDocument({ excluded_note: '1 medida desta turma não está disponível.' }) });

        expect(wrapper.text()).toContain('Implementadas: medidas em curso, concluídas ou suspensas.');
        expect(wrapper.text()).toContain('1 medida desta turma não está disponível.');
        expect(wrapper.text()).toContain('não é um relatório finalizado');
    });

    it('stacks the same data for phones and keeps it out of print', () => {
        const wrapper = mountMap();
        const stacked = wrapper.find('ul.sm\\:hidden');

        expect(stacked.classes()).toContain('print:hidden');
        expect(stacked.findAll(':scope > li')).toHaveLength(2);
        expect(wrapper.find('table').classes()).toContain('print:table');
    });

    it('toggles a column through router.get with the right params', async () => {
        const wrapper = mountMap();

        await wrapper.find('#measures-map-planned').trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/reports/turma/class-a/mapa-de-medidas',
            { implementadas: '1', a_implementar: '0' },
            { replace: true, preserveScroll: true, preserveState: true },
        );
    });

    it('disables the last checked box so both can never be off', () => {
        const wrapper = mountMap({ filters: { implemented: true, planned: false } });

        expect(wrapper.find('#measures-map-implemented').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#measures-map-planned').attributes('disabled')).toBeUndefined();

        const both = mountMap();
        expect(both.find('#measures-map-implemented').attributes('disabled')).toBeUndefined();
        expect(both.find('#measures-map-planned').attributes('disabled')).toBeUndefined();
    });

    it('lists the classes with year and navigates keeping the current filters', async () => {
        const wrapper = mountMap({ filters: { implemented: false, planned: true } });
        const select = wrapper.find('select');

        expect(select.findAll('option').map((option) => option.text())).toEqual([
            '7.º A · Português · 2026/2027',
            '8.º B · Matemática · 2026/2027',
        ]);
        expect((select.element as HTMLSelectElement).value).toBe('class-a');

        await select.setValue('class-b');

        expect(routerGet).toHaveBeenCalledWith('/reports/turma/class-b/mapa-de-medidas', {
            implementadas: '0',
            a_implementar: '1',
        });
    });

    it('points the PDF link, a plain anchor, at the current filters', () => {
        const wrapper = mountMap({ filters: { implemented: true, planned: false } });
        const pdf = wrapper.findAll('a').find((a) => a.text() === 'PDF');

        expect(pdf?.attributes('href')).toBe(
            '/reports/turma/class-a/mapa-de-medidas/pdf?implementadas=1&a_implementar=0',
        );
    });

    it('offers print and the link back to Estratégias e Medidas only with a document', async () => {
        const wrapper = mountMap();
        const print = vi.spyOn(window, 'print').mockImplementation(() => undefined);

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Imprimir')!
            .trigger('click');
        await wrapper.vm.$nextTick();

        expect(print).toHaveBeenCalledOnce();
        expect(wrapper.find('a[href="/classes/class-a/interventions"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Não fica guardado como relatório.');

        print.mockRestore();
    });

    it('asks for a class when none is chosen, with no document and no actions', () => {
        const wrapper = mountMap({ schoolClass: null, document: null });

        expect(wrapper.text()).toContain('Escolha uma turma para gerar o mapa.');
        expect(wrapper.find('.document-print').exists()).toBe(false);
        expect(wrapper.find('table').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('PDF');
        expect(wrapper.find('#measures-map-planned').exists()).toBe(false);
    });

    it('shows an empty state when the teacher has no classes', () => {
        const wrapper = mountMap({ classes: [], schoolClass: null, document: null });

        expect(wrapper.text()).toContain('Ainda não leciona nenhuma turma.');
        expect(wrapper.find('select').exists()).toBe(false);
    });
});
