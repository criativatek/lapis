import { enableAutoUnmount, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { UsageUserDetailProps } from '@/types/usage';
import { common, signal } from './fixtures';
import User from './User.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));

enableAutoUnmount(afterEach);

function props(): UsageUserDetailProps {
    return {
        ...common(),
        user: {
            ulid: 'u1',
            name: 'Ana Martins',
            email: 'ana@example.pt',
            joined_on: '2026-09-25',
            status: 'Conta criada durante o período',
            organizations: [{ name: 'Escola Azul', plan: 'Pro', classification: 'test' }],
            flags: { test: true, internal: false, unclassified: false },
        },
        first_steps: [
            { key: 'first_class', label: 'Primeira turma', observed_on: '2026-09-26', note: null },
            { key: 'first_lesson', label: 'Primeira aula', observed_on: null, note: 'Conta anterior à recolha — pode ter acontecido antes.' },
        ],
        class_available_since: { date: '2026-09-25', label: 'Turma disponível desde — configuração, não prova de utilização' },
        signals: [signal],
        not_evaluated: [{ key: 'drop', label: 'Quebra de regularidade' }],
        summary: { active_days: 3, active_weeks: 2, actions: 5, items: 9, failures: 0, last_activity_on: '2026-10-08', observation_days: 17, period_days: 30 },
        weeks: [{ start: '2026-10-05', end: '2026-10-11', status: 'in_progress', active_days: 2, actions: 3, items: 4, failures: 0, features: [{ key: 'lessons', label: 'Aulas' }] }],
        features: [{ key: 'lessons', label: 'Aulas', active_days: 2, view_days: 1, actions: 3, items: 4, items_created: 3, items_changed: 1, items_unchanged: 2, failures: 0 }],
    };
}

describe('Utilização — detalhe', () => {
    it('mostra datas observadas, o que ainda não foi observado e a nota de conta anterior', () => {
        const text = mount(User, { props: props() }).text();

        expect(text).toContain('26/09/2026');
        expect(text).toContain('Ainda não observado');
        expect(text).toContain('Conta anterior à recolha');
        expect(text).toContain('Turma disponível desde 25/09/2026 — configuração, não prova de utilização');
        expect(text).toContain('Escola Azul — Pro (teste)');
        expect(text).toContain('3 de 17');
        expect(text).toContain('Por avaliar (recolha insuficiente)');
        expect(text).toContain('Mostra apenas contagens. Não inclui conteúdos pedagógicos nem o histórico de cliques.');
    });

    it('mostra por baixo dos elementos afetados o que foi criado, alterado e inalterado', () => {
        expect(mount(User, { props: props() }).text()).toContain('criados 3 · alterados 1 · inalterados 2');
    });

    it('volta ao acompanhamento com os filtros', () => {
        const link = mount(User, { props: props() })
            .findAll('a')
            .find((anchor) => anchor.text().includes('Voltar ao acompanhamento'));

        expect(link?.attributes('href')).toBe('/admin/usage/users?period=30');
    });
});
