import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Index from './Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { get: vi.fn() },
}));

const wrappers: VueWrapper[] = [];

function mountIndex(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(Index, {
        props: {
            reports: [],
            total: 0,
            filters: { type: null, status: null, class_id: null },
            availableTypes: [],
            statuses: [],
            classes: [],
            canOpenMeasuresMap: false,
            ...overrides,
        },
    });
    wrappers.push(wrapper);

    return wrapper;
}

describe('reports/Index.vue — Documentos da turma', () => {
    afterEach(() => {
        wrappers.forEach((wrapper) => wrapper.unmount());
        wrappers.length = 0;
    });

    it('shows the measures map card when the module is open', () => {
        const wrapper = mountIndex({ canOpenMeasuresMap: true });

        expect(wrapper.text()).toContain('Documentos da turma');
        expect(wrapper.text()).toContain('Mapa de Medidas Educativas');
        expect(wrapper.find('a[href="/reports/turma/mapa-de-medidas"]').exists()).toBe(true);
    });

    it('hides the section entirely when it is not available', () => {
        const wrapper = mountIndex({ canOpenMeasuresMap: false });

        expect(wrapper.text()).not.toContain('Documentos da turma');
        expect(wrapper.find('a[href="/reports/turma/mapa-de-medidas"]').exists()).toBe(false);
    });
});
