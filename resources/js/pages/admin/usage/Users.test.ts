import { enableAutoUnmount, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { UsageUsersProps } from '@/types/usage';
import { common, signal } from './fixtures';
import Users from './Users.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));

enableAutoUnmount(afterEach);

function props(): UsageUsersProps {
    return {
        ...common(),
        sort_options: [{ key: 'last_activity', label: 'Última atividade' }],
        users: {
            data: [
                {
                    ulid: 'u1',
                    name: 'Ana Martins',
                    email: 'ana@example.pt',
                    joined_on: '2026-09-25',
                    plans: ['Pro'],
                    organizations: 1,
                    flags: { test: false, internal: false },
                    last_activity_on: null,
                    active_days: 0,
                    actions: 0,
                    items: 0,
                    features: [],
                    signals: [signal],
                    status: 'Sem atividade observada no período',
                    observation_days: 17,
                    period_days: 30,
                },
            ],
            links: [],
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 1,
            from: 1,
            to: 1,
        },
    };
}

describe('Utilização — acompanhamento', () => {
    it('mostra o estado descritivo, a razão do sinal e a sua janela', () => {
        const text = mount(Users, { props: props() }).text();

        expect(text).toContain('Sem atividade observada no período');
        expect(text).toContain('Não voltou nas duas semanas seguintes à adesão.');
        expect(text).toContain('28/09–11/10');
        expect(text).toContain('Sem atividade observada desde o início da recolha');
        expect(text).toContain('0 de 17 dias observados');
        expect(text).toContain('1 pessoa');
    });

    it('abre o detalhe da pessoa mantendo os filtros', () => {
        const wrapper = mount(Users, { props: props() });

        expect(wrapper.find('a[href^="/admin/usage/users/u1"]').attributes('href')).toBe('/admin/usage/users/u1?period=30');
    });

    it('nunca usa palavras de juízo', () => {
        expect(mount(Users, { props: props() }).text()).not.toMatch(/inativ|abandon|risco|\bmau\b/i);
    });
});
