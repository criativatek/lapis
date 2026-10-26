import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import HeaderActions from './HeaderActions.vue';

/**
 * A ponta direita da barra: a pesquisa só existe com `lessons` legível, o
 * sino diz quantas respostas do suporte esperam, e o menu da conta está
 * sempre lá.
 */
const mocks = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { post: vi.fn(), flushAll: vi.fn() },
    usePage: () => ({ props: mocks.props }),
}));

vi.mock('@/components/ui/dropdown-menu', () => {
    const passthrough = (tag: string) =>
        defineComponent({ inheritAttrs: false, setup: (_, { slots }) => () => h(tag, slots.default?.()) });

    return {
        DropdownMenu: passthrough('div'),
        DropdownMenuContent: passthrough('div'),
        DropdownMenuTrigger: passthrough('div'),
    };
});

vi.mock('@/components/UserMenuContent.vue', () => ({
    default: defineComponent({ props: ['user'], setup: () => () => h('div', { 'data-user-menu': '' }) }),
}));

function actions(overrides: Record<string, unknown> = {}) {
    mocks.props = {
        auth: { user: { id: 1, name: 'Ana Ribeiro', email: 'ana@exemplo.test' } },
        modules: ['classes', 'lessons'],
        readOnlyModules: [],
        supportAwaitingReply: 0,
        ...overrides,
    };

    return mount(HeaderActions);
}

describe('HeaderActions', () => {
    it('leva à pesquisa nos sumários quando as aulas são legíveis', () => {
        const search = actions().find('[data-testid="header-search"]');

        expect(search.attributes('href')).toBe('/lessons?view=semana&foco=pesquisa');
        expect(search.attributes('aria-label')).toBe('Pesquisar nos sumários');
    });

    it('não oferece a pesquisa numa organização sem o módulo das aulas', () => {
        expect(actions({ modules: ['classes'] }).find('[data-testid="header-search"]').exists()).toBe(false);
    });

    it('ainda a oferece quando as aulas estão só de leitura', () => {
        expect(actions({ modules: [], readOnlyModules: ['lessons'] }).find('[data-testid="header-search"]').exists()).toBe(true);
    });

    it('o sino diz quantas respostas do suporte esperam, e só marca quando há alguma', () => {
        const quiet = actions().find('[data-testid="header-support"]');

        expect(quiet.attributes('href')).toBe('/support');
        expect(quiet.attributes('aria-label')).toBe('Suporte');
        expect(quiet.find('[data-testid="header-support-dot"]').exists()).toBe(false);

        expect(actions({ supportAwaitingReply: 1 }).find('[data-testid="header-support"]').attributes('aria-label')).toBe('Suporte: 1 resposta por ler');

        const several = actions({ supportAwaitingReply: 3 }).find('[data-testid="header-support"]');

        expect(several.attributes('aria-label')).toBe('Suporte: 3 respostas por ler');
        expect(several.find('[data-testid="header-support-dot"]').exists()).toBe(true);
    });

    it('abre o menu da conta a partir das iniciais', () => {
        const wrapper = actions();

        expect(wrapper.find('[data-testid="header-account"]').text()).toBe('AR');
        expect(wrapper.find('[data-user-menu]').exists()).toBe(true);
    });
});
