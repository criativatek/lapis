import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ModuleUnavailable from './ModuleUnavailable.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
    };
});

describe('ModuleUnavailable', () => {
    it('explica o limite do plano sem linguagem de erro técnico', () => {
        const text = mount(ModuleUnavailable, {
            props: { canManagePlan: true },
        }).text();

        expect(text).toContain(
            'Esta funcionalidade não está incluída no plano atual.',
        );
        expect(text).not.toContain('403');
    });

    it('oferece «Ver planos» a quem pode mudar o plano', () => {
        const wrapper = mount(ModuleUnavailable, {
            props: { canManagePlan: true },
        });

        expect(wrapper.find('a[href="/settings/plan"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/dashboard"]').exists()).toBe(true);
    });

    it('não oferece «Ver planos» a um membro que não gere o plano', () => {
        const wrapper = mount(ModuleUnavailable, {
            props: { canManagePlan: false },
        });

        expect(wrapper.find('a[href="/settings/plan"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('responsável da sua organização');
    });
});
