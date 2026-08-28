import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import ContextualHelp from './ContextualHelp.vue';

vi.mock('@inertiajs/vue3', () => ({
    // Inertia resolves an object `href` (a Wayfinder route definition) down to
    // its url — the stub has to as well, or every href assertion below reads
    // "[object Object]" and proves nothing about where the link goes.
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => {
            const href = attrs.href as string | { url: string } | undefined;

            return h(
                'a',
                { ...attrs, href: typeof href === 'object' && href !== null ? href.url : href },
                slots.default?.(),
            );
        },
    }),
}));

const wrappers: VueWrapper[] = [];

function mountHelp(articles?: { id: string; title: string; summary: string }[]) {
    const wrapper = mount(ContextualHelp, { props: { articles } });

    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('ContextualHelp — given articles', () => {
    it('shows the "Precisa de ajuda?" heading and a link per article, pointing at its /help/{id}', () => {
        const wrapper = mountHelp([
            { id: 'assessment.profiles', title: 'Configurar perfis, domínios e pesos', summary: 'Domínios e pesos.' },
            { id: 'instruments.create', title: 'Criar um elemento de avaliação', summary: 'Testes e fichas.' },
        ]);

        expect(wrapper.text()).toContain('Precisa de ajuda?');

        const links = wrapper.findAll('a');
        expect(links).toHaveLength(2);
        expect(links[0].text()).toContain('Configurar perfis, domínios e pesos');
        expect(links[0].attributes('href')).toBe('/help/assessment.profiles');
        expect(links[1].attributes('href')).toBe('/help/instruments.create');
        expect(wrapper.text()).toContain('Domínios e pesos.');
    });

    it('shows at most 3 links even when given more articles', () => {
        const wrapper = mountHelp([
            { id: 'a', title: 'A', summary: 's' },
            { id: 'b', title: 'B', summary: 's' },
            { id: 'c', title: 'C', summary: 's' },
            { id: 'd', title: 'D', summary: 's' },
        ]);

        expect(wrapper.findAll('a')).toHaveLength(3);
    });
});

describe('ContextualHelp — given no articles', () => {
    it('renders nothing for an empty array', () => {
        const wrapper = mountHelp([]);

        expect(wrapper.text()).toBe('');
        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('renders nothing when the prop is omitted entirely', () => {
        const wrapper = mountHelp(undefined);

        expect(wrapper.text()).toBe('');
    });
});
