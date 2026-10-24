import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import LandingFooter from './LandingFooter.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        inheritAttrs: false,
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('a', { ...attrs, href: '#' }, slots.default?.()),
    }),
    usePage: () => ({
        url: '/',
        props: { appVersion: '0.0.0', legalEntity: { name: null } },
    }),
}));

describe('LandingFooter', () => {
    it('assina a marca com o slogan oficial', () => {
        const text = mount(LandingFooter, {
            props: { authenticated: false },
        }).text();

        expect(text).toContain('A tecnologia apoia. O professor transforma.');
        expect(text).not.toContain('Mais simples. Mais tempo.');
    });
});
