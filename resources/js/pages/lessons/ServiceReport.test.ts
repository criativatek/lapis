import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import ServiceReport from './ServiceReport.vue';

const routerGet = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { get: routerGet },
    Head: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
    Link: defineComponent({
        inheritAttrs: false,
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('a', attrs, slots.default?.()),
    }),
}));

type Props = InstanceType<typeof ServiceReport>['$props'];

function report(overrides: Record<string, unknown> = {}): NonNullable<Props['report']> {
    return {
        totals: {
            planned: 5,
            counted_as_taught: 3,
            subject_development: 2,
            teacher_absent: 0,
            class_external_activity: 1,
            not_held: 1,
            not_recorded: 1,
        },
        given_percentage: 60,
        upcoming: 2,
        causes: [{ cause: 'strike', label: 'Greve', count: 1 }],
        evolution: [{ month: '2026-10', planned: 5, given: 3, not_held: 1, not_recorded: 1, given_percentage: 60 }],
        personal: {
            not_held_recorded: 1,
            not_held_by_others: 0,
            causes: [{ cause: 'strike', label: 'Greve', count: 1 }],
            strike: { total: 1, unspecified: 0, adhered: 1, not_adhered: 0, not_informed: 0 },
            teacher_absent: 0,
        },
        ...overrides,
    } as NonNullable<Props['report']>;
}

function mountPage(props: Partial<Props> = {}) {
    return mount(ServiceReport, {
        props: {
            classes: [{ ulid: 'c1', label: '7.º A', subject: 'Inglês' }],
            selectedClass: 'c1',
            filters: { from: '2026-09-01', to: '2027-06-30' },
            academicYear: '2026/2027',
            report: report(),
            ...props,
        },
    });
}

describe('lessons/ServiceReport', () => {
    beforeEach(() => routerGet.mockReset());

    it('shows the percentage with the given and planned lessons and the unrecorded note', () => {
        const wrapper = mountPage();

        expect(wrapper.get('[data-testid="percentage"]').text()).toBe('60 %');
        expect(wrapper.get('[data-testid="summary"]').text()).toContain('3 dadas em 5 previstas');
        expect(wrapper.get('[data-testid="unrecorded-note"]').text()).toContain('1 aula ainda sem registo');
        expect(wrapper.get('[data-testid="summary"]').text()).toContain('Mais 2 aulas previstas');
    });

    it('separates the lessons actually taught from the class activities inside «dadas»', () => {
        const wrapper = mountPage();

        expect(wrapper.get('[data-testid="given-breakdown"]').text()).toBe('Dadas = 2 lecionadas + 1 com a turma noutra atividade letiva.');
    });

    it('splits the planned lessons into five non-overlapping groups that add up to the planned total', () => {
        const wrapper = mountPage({ report: report({ totals: { ...report().totals, planned: 6, teacher_absent: 1 } }) });
        const rows = wrapper.findAll('[data-testid="breakdown"] li').map((row) => {
            const cells = row.findAll(':scope > span');

            return [cells[0].text().replace(/\s+/g, ' '), Number(cells[1].text())] as const;
        });

        expect(rows).toEqual([
            ['Lecionadas', 2],
            ['Turma noutra atividade letiva (contam como dadas)', 1],
            ['Professor ausente (não contam como dadas)', 1],
            ['Aulas não realizadas (não contam como dadas)', 1],
            ['Ainda sem registo', 1],
        ]);
        expect(wrapper.get('[data-testid="breakdown"]').text()).toContain('As 6 previstas, uma a uma');
        expect(rows.reduce((total, [, count]) => total + count, 0)).toBe(6);
    });

    it('hides the unrecorded note when every lesson is recorded', () => {
        const wrapper = mountPage({
            report: report({ totals: { ...report().totals, not_recorded: 0 } }),
        });

        expect(wrapper.find('[data-testid="unrecorded-note"]').exists()).toBe(false);
    });

    it('shows the aggregated causes and, in the personal block, the specification only when it exists', () => {
        const wrapper = mountPage();

        expect(wrapper.get('[data-testid="causes"]').text()).toContain('Greve');
        expect(wrapper.get('[data-testid="strike-lines"]').text()).toContain('Aderi à greve');
        expect(wrapper.get('[data-testid="strike-lines"]').text()).not.toContain('Não aderi');
    });

    it('shows no strike specification and no personal block for a colleague who recorded nothing', () => {
        const wrapper = mountPage({
            report: report({
                personal: {
                    not_held_recorded: 0,
                    not_held_by_others: 1,
                    causes: [],
                    strike: { total: 0, unspecified: 0, adhered: 0, not_adhered: 0, not_informed: 0 },
                    teacher_absent: 0,
                },
            }),
        });

        expect(wrapper.find('[data-testid="personal"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="strike-lines"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="causes"]').text()).toContain('Greve');
    });

    it('counts a strike recorded without specification on its own line', () => {
        const wrapper = mountPage({
            report: report({
                personal: {
                    not_held_recorded: 1,
                    not_held_by_others: 0,
                    causes: [{ cause: 'strike', label: 'Greve', count: 1 }],
                    strike: { total: 1, unspecified: 1, adhered: 0, not_adhered: 0, not_informed: 0 },
                    teacher_absent: 0,
                },
            }),
        });

        expect(wrapper.get('[data-testid="strike-lines"]').text()).toContain('Só «Greve», sem especificar');
    });

    it('shows a dash instead of a percentage when there is nothing planned, and an empty state', () => {
        const wrapper = mountPage({ report: report({ totals: { ...report().totals, planned: 0 }, given_percentage: null }) });

        expect(wrapper.find('[data-testid="percentage"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Sem aulas neste intervalo');
    });

    it('asks the server for another class', async () => {
        const wrapper = mountPage({
            classes: [
                { ulid: 'c1', label: '7.º A', subject: 'Inglês' },
                { ulid: 'c2', label: '8.º B', subject: 'Inglês' },
            ],
        });

        await wrapper.get('select').setValue('c2');

        expect(routerGet).toHaveBeenCalledWith(
            '/lessons/service-report',
            expect.objectContaining({ class: 'c2' }),
            expect.anything(),
        );
    });

    it('lists the evolution by month', () => {
        const wrapper = mountPage();

        expect(wrapper.get('[data-testid="evolution"]').text()).toContain('outubro de 2026');
    });
});
