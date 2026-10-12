import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { UsageOverviewProps } from '@/types/usage';
import { common } from './fixtures';
import Overview from './Overview.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));

// O canvas não existe em jsdom: o stub devolve a configuração para se poder inspecionar.
vi.mock('@/components/charts/StatChart.vue', () => ({
    __esModule: true,
    default: defineComponent({
        props: { config: { type: Object, required: true } },
        setup: (props) => () => h('pre', { 'data-test': 'chart' }, JSON.stringify((props.config as { data: unknown }).data)),
    }),
}));

enableAutoUnmount(afterEach);

function props(overrides: Partial<UsageOverviewProps> = {}): UsageOverviewProps {
    return {
        ...common(),
        headline: {
            active_7: { value: 4, observed_days: 5, window_days: 7 },
            active_30: { value: null, observed_days: 0, window_days: 30 },
            active_period: 6,
            new_accounts: 9,
            first_useful_record: { done: 4, of: 9, before_collection: 3 },
        },
        weeks: [
            { start: '2026-09-07', end: '2026-09-13', status: 'no_coverage', active_users: null, active_user_days: null, actions: null, items: null },
            { start: '2026-09-14', end: '2026-09-20', status: 'complete', active_users: 5, active_user_days: 12, actions: 20, items: 40 },
            { start: '2026-10-05', end: '2026-10-11', status: 'in_progress', active_users: 3, active_user_days: 6, actions: 7, items: 8 },
        ],
        months: [{ month: '2026-09', label: 'setembro de 2026', status: 'partial_coverage', active_users: 5 }],
        features: [{ key: 'lessons', label: 'Aulas', users: 5, users_with_actions: 3, actions: 20, items: 40, failures: 0 }],
        signals: [{ key: 'no_return', label: 'Sem regresso', description: 'Conta sem regresso.', count: 2, not_evaluated: 1 }],
        cohorts: [
            {
                week_start: '2026-09-21',
                week_end: '2026-09-27',
                size: 9,
                weeks: [
                    { offset: 0, status: 'complete', returned: 9 },
                    { offset: 1, status: 'complete', returned: 4 },
                    { offset: 2, status: 'in_progress', returned: 2 },
                    { offset: 3, status: 'not_started', returned: null },
                ],
            },
        ],
        ...overrides,
    };
}

async function render(overrides: Partial<UsageOverviewProps> = {}) {
    const wrapper = mount(Overview, { props: props(overrides) });

    await flushPromises();

    return wrapper;
}

describe('Utilização — visão geral', () => {
    it('mostra «sem recolha» e nenhuma barra para uma semana sem cobertura', async () => {
        const wrapper = await render();
        const data = JSON.parse(wrapper.get('[data-test="chart"]').text());

        expect(data.datasets[0].data).toEqual([null, 5, 3]);
        expect(data.labels[0]).toEqual(['07/09–13/09', 'sem recolha']);
        expect(wrapper.text()).toContain('Sem recolha');
        // Nunca um zero no lugar de «sem recolha».
        expect(data.datasets[0].data).not.toContain(0);
    });

    it('traz sempre a tabela equivalente, com — para o que é null', async () => {
        const wrapper = await render();
        const row = wrapper.findAll('tbody tr').find((tr) => tr.text().includes('07/09–13/09'));

        expect(row?.text()).toContain('—');
        expect(row?.text()).toContain('Sem recolha');
    });

    it('só mostra percentagem com denominador', async () => {
        const wrapper = await render();

        expect(wrapper.text()).toContain('4 de 9 · 44%');
        expect(wrapper.text()).toContain('3 contas anteriores à recolha, fora do denominador');

        const empty = await render({ headline: { ...props().headline, first_useful_record: { done: 0, of: 0, before_collection: 2 } } });

        expect(empty.text()).not.toContain('0 de 0');
        expect(empty.text()).not.toContain('· 0%');
    });

    it('explica a cobertura parcial dos mosaicos', async () => {
        const wrapper = await render();

        expect(wrapper.text()).toContain('recolha em 5 de 7 dias');
    });

    it('coorte que ainda não decorreu diz «ainda não decorreu»', async () => {
        const wrapper = await render();

        expect(wrapper.text()).toContain('ainda não decorreu');
        expect(wrapper.text()).toContain('4 de 9 (44%)');
        expect(wrapper.text()).toContain('2 até agora');
        expect(wrapper.text()).toContain('em curso');
    });

    it('semana da coorte sem recolha diz «sem recolha», nunca «0 de N»; parcial assinala-o', async () => {
        const wrapper = await render({
            cohorts: [
                {
                    week_start: '2026-09-07',
                    week_end: '2026-09-13',
                    size: 3,
                    weeks: [
                        { offset: 0, status: 'partial_coverage', returned: 3 },
                        { offset: 1, status: 'no_coverage', returned: null },
                    ],
                },
            ],
        });

        const table = wrapper.text();
        expect(table).toContain('sem recolha');
        expect(table).toContain('recolha parcial');
        expect(table).not.toContain('0 de 3');
    });

    it('liga cada sinal à lista filtrada, mantendo os filtros', async () => {
        const wrapper = await render();
        const link = wrapper.findAll('a').find((anchor) => anchor.text() === 'Ver lista');

        expect(link?.attributes('href')).toBe('/admin/usage/users?period=30&signal=no_return');
        expect(wrapper.text()).toContain('1 por avaliar (recolha insuficiente)');
        expect(wrapper.text()).toContain('Sugestões de verificação, não diagnósticos. Nada é enviado automaticamente.');
    });
});
