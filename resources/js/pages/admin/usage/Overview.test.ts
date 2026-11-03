import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { UsageOverviewProps } from '@/types/usage';
import { common, online } from './fixtures';
import Overview from './Overview.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn(), reload: vi.fn() },
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
            people: { active_7: [], active_30: [], active_period: [], new_accounts: [], first_useful_done: [], first_useful_pending: [] },
        },
        weeks: [
            { start: '2026-09-07', end: '2026-09-13', status: 'no_coverage', active_users: null, active_user_days: null, actions: null, items: null },
            { start: '2026-09-14', end: '2026-09-20', status: 'complete', active_users: 5, active_user_days: 12, actions: 20, items: 40 },
            { start: '2026-10-05', end: '2026-10-11', status: 'in_progress', active_users: 3, active_user_days: 6, actions: 7, items: 8 },
        ],
        months: [{ month: '2026-09', label: 'setembro de 2026', status: 'partial_coverage', active_users: 5 }],
        features: [{ key: 'lessons', label: 'Aulas', users: 5, users_with_actions: 3, actions: 20, items: 40, items_created: 0, items_changed: 0, items_unchanged: 0, failures: 0 }],
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
        online: online(),
        ...overrides,
    };
}

async function render(overrides: Partial<UsageOverviewProps> = {}) {
    const wrapper = mount(Overview, { props: props(overrides) });

    await flushPromises();

    return wrapper;
}

describe('Utilização — visão geral', () => {
    it('mostra o cartão «Ligados agora» com a contagem e a lista', async () => {
        const wrapper = await render();
        const text = wrapper.text();

        expect(text).toContain('Ligados agora');
        expect(text).toContain('Independente dos filtros abaixo');
        expect(wrapper.get('[data-test="online-active"]').text()).toBe('1');
        expect(text).toContain('ativos nos últimos 15 min');
        expect(text).toContain('2 com sessão nas últimas 2 h');
        expect(text).toContain('às 10:00');
        expect(text).toContain('Ana Silva');
        expect(text).toContain('ana@exemplo.pt');
        expect(text).toContain('há 3 min');
        expect(text).toContain('há 47 min');
    });

    it('liga à ficha só quando a pessoa tem estado agregado e marca o pessoal interno', async () => {
        const wrapper = await render();

        const ana = wrapper.findAll('a').find((link) => link.text() === 'Ana Silva');
        expect(ana?.attributes('href')).toBe('/admin/usage/users/u1');
        expect(wrapper.findAll('a').some((link) => link.text() === 'Rui Costa')).toBe(false);
        const rows = wrapper.findAll('section[aria-labelledby="online-title"] li');

        expect(rows).toHaveLength(2);
        expect(rows[0].text()).not.toContain('Interno');
        expect(rows[1].text()).toContain('Interno');
    });

    it('diz «agora» para menos de um minuto', async () => {
        const wrapper = await render({
            online: online({ active_count: 1, people: [{ ulid: null, name: 'Bia', email: 'bia@exemplo.pt', internal: false, minutes_ago: 0, active: true }] }),
        });

        expect(wrapper.text()).toContain('agora');
    });

    it('com ninguém ligado diz-o, em vez de mostrar uma lista vazia', async () => {
        const wrapper = await render({ online: online({ active_count: 0, people: [] }) });

        expect(wrapper.text()).toContain('Ninguém com sessão iniciada neste momento.');
        expect(wrapper.get('[data-test="online-active"]').text()).toBe('0');
    });

    it('«Atualizar» recarrega só a prop online', async () => {
        const wrapper = await render();
        const { router } = await import('@inertiajs/vue3');

        await wrapper.findAll('button').find((button) => button.text() === 'Atualizar')!.trigger('click');

        expect(router.reload).toHaveBeenCalledWith({ only: ['online'] });
    });

    it('mostra a discriminação dos elementos afetados quando as ações a trazem', async () => {
        const wrapper = await render({
            features: [{ key: 'import_export', label: 'Importar', users: 1, users_with_actions: 1, actions: 1, items: 3, items_created: 2, items_changed: 1, items_unchanged: 3, failures: 0 }],
        });

        expect(wrapper.text()).toContain('criados 2 · alterados 1 · inalterados 3');
    });

    it('não inventa uma discriminação quando nenhuma ação a trouxe', async () => {
        const wrapper = await render();

        expect(wrapper.text()).not.toContain('inalterados');
    });

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

    it('mostra quem são as pessoas de um cartão, com ligação à ficha', async () => {
        const base = props();
        const wrapper = await render({
            headline: {
                ...base.headline,
                people: {
                    ...base.headline.people,
                    active_7: [
                        { ulid: '01JABC', name: 'Ana Silva', email: 'ana@exemplo.pt', internal: false },
                        { ulid: null, name: 'Rui Costa', email: 'rui@exemplo.pt', internal: true },
                    ],
                },
            },
        });

        const details = wrapper.findAll('details');

        expect(details).toHaveLength(1);
        expect(details[0].find('summary').text()).toBe('Ver quem');
        expect(details[0].find('a').attributes('href')).toContain('/admin/usage/users/01JABC');
        expect(details[0].find('a').text()).toBe('Ana Silva');
        expect(details[0].text()).toContain('Rui Costa');
        expect(details[0].text()).toContain('Interno');
        expect(details[0].findAll('a')).toHaveLength(1);
    });

    it('sem pessoas não mostra «Ver quem»', async () => {
        const wrapper = await render();

        expect(wrapper.text()).not.toContain('Ver quem');
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
