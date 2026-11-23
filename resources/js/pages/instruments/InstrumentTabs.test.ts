import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import InstrumentTabs from './InstrumentTabs.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
}));

describe('instruments/InstrumentTabs', () => {
    it('mostra os quatro separadores na ordem pedida, com ligações ao elemento', () => {
        const wrapper = mount(InstrumentTabs, { props: { ulid: 'abc', active: 'grid' } });
        const labels = wrapper.findAll('a, span[aria-current]').map((node) => node.text());

        expect(labels).toEqual(['Grelha de correção', 'Resultados', 'Comentários', 'Imprimir / PDF']);
        expect(wrapper.find('a[href="/instruments/abc/resultados"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/instruments/abc/comentarios"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/instruments/abc/impressao"]').exists()).toBe(true);
    });

    it.each([
        ['grid', 'Grelha de correção'],
        ['results', 'Resultados'],
        ['comments', 'Comentários'],
    ] as const)('o separador ativo (%s) não é uma ligação e marca a página corrente', (active, label) => {
        const wrapper = mount(InstrumentTabs, { props: { ulid: 'abc', active } });
        const current = wrapper.findAll('[aria-current="page"]');

        expect(current).toHaveLength(1);
        expect(current[0].text()).toBe(label);
        expect(current[0].element.tagName).toBe('SPAN');
    });

    it('um elemento anulado não tem «Imprimir / PDF»', () => {
        const wrapper = mount(InstrumentTabs, { props: { ulid: 'abc', active: 'comments', cancelled: true } });

        expect(wrapper.text()).not.toContain('Imprimir / PDF');
        expect(wrapper.text()).toContain('Comentários');
    });
});
