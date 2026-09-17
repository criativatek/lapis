import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { login } from '@/routes';
import Register from './Register.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    Form: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('form', slots.default?.({ errors: {}, processing: false })),
    }),
    Link: defineComponent({
        inheritAttrs: false,
        setup:
            (_, { attrs, slots }) =>
            () => {
                const href = attrs.href as string | { url: string } | undefined;

                return h(
                    'a',
                    {
                        ...attrs,
                        href:
                            typeof href === 'object' && href !== null
                                ? href.url
                                : href,
                    },
                    slots.default?.(),
                );
            },
    }),
}));

describe('Register — path for an existing account', () => {
    it('keeps «Já tem conta? Entrar» after «Criar conta», pointing at the real login', () => {
        const wrapper = mount(Register, { props: { passwordRules: '' } });

        const entrar = wrapper.get('[data-test="register-login-link"]');
        expect(entrar.text()).toBe('Entrar');
        expect(entrar.attributes('href')).toBe(login().url);
        expect(entrar.element.parentElement?.textContent).toContain(
            'Já tem conta?',
        );

        const submit = wrapper.get('[data-test="register-user-button"]');
        expect(
            submit.element.compareDocumentPosition(entrar.element) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });
});
