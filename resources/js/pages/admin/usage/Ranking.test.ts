import { enableAutoUnmount, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { UsageRankingProps, UsageRankingRow } from '@/types/usage';
import { common } from './fixtures';
import Ranking from './Ranking.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));

enableAutoUnmount(afterEach);

const SUPPORT = 'O ranking mostra regularidade de utilização, não qualidade de ensino.';

function row(overrides: Partial<UsageRankingRow>): UsageRankingRow {
    return {
        position: 1,
        tied: false,
        ulid: 'u1',
        name: 'Ana Martins',
        email: 'ana@example.pt',
        plans: ['Pro'],
        joined_on: '2026-08-01',
        account_age_days: 71,
        observation_days: 30,
        period_days: 30,
        partial_observation: false,
        active_days: 12,
        actions: 20,
        items: 40,
        active_weeks: 3,
        observed_weeks: 4,
        features: 2,
        ...overrides,
    };
}

function props(): UsageRankingProps {
    return {
        ...common(),
        support_text: SUPPORT,
        ranking: {
            data: [
                row({ position: 1 }),
                row({ position: 3, tied: true, ulid: 'u2', name: 'Rui Costa', partial_observation: true, observation_days: 10 }),
                row({ position: 3, tied: true, ulid: 'u3', name: 'Eva Dias' }),
            ],
            links: [],
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 3,
            from: 1,
            to: 3,
        },
        total_ranked: 3,
        unranked: 5,
        sort_options: [
            { key: 'active_days', label: 'Dias ativos no período' },
            { key: 'features', label: 'Funcionalidades utilizadas (indicador complementar)' },
        ],
        age_options: [{ key: 'all', label: 'Todas as contas' }],
    };
}

describe('Utilização — ranking', () => {
    it('mostra o texto de apoio literal no topo', () => {
        expect(mount(Ranking, { props: props() }).text()).toContain(SUPPORT);
    });

    it('mostra «=3» num empate e «1» sem empate', () => {
        const text = mount(Ranking, { props: props() }).text();

        expect(text).toContain('=3');
        expect(text).not.toContain('=1');
    });

    it('marca a observação parcial com os dias observados', () => {
        expect(mount(Ranking, { props: props() }).text()).toContain('observação parcial: 10 de 30 dias');
    });

    it('diz quantas pessoas não são ordenadas e mantém os elementos à parte', () => {
        const text = mount(Ranking, { props: props() }).text();

        expect(text).toContain('5 pessoas sem atividade observada no período não são ordenadas.');
        expect(text).toContain('40 elementos afetados');
        expect(text).toContain('3 de 4');
    });
});
